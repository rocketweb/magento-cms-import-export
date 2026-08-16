<?php declare(strict_types=1);
/*
 * RocketWeb
 *
 *  NOTICE OF LICENSE
 *
 *  This source file is subject to the Open Software License (OSL 3.0)
 *  that is bundled with this package in the file LICENSE.txt.
 *  It is also available through the world-wide-web at this URL:
 *  http://opensource.org/licenses/osl-3.0.php
 *
 *  @category  RocketWeb
 *  @copyright Copyright (c) 2020 RocketWeb (http://rocketweb.com)
 *  @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *  @author    Rocket Web Inc.
 */

namespace RocketWeb\CmsImportExport\Model\Service\HyvaCms;

use Magento\Framework\ObjectManagerInterface;

/**
 * Writes Hyva CMS content and its per-entity Tailwind CSS back onto an existing native CMS page or block.
 *
 * Everything keys on the native cms_page_id / cms_block_id, so the native row has to be saved before any of this
 * runs. That id is also what the entity_ref_id column of the Tailwind tables holds, not the id of the Hyva row.
 *
 * Lazy resolution through the object manager is the same exception ContentReader documents: no Hyva class may
 * appear in a type hint, a constructor signature or the module sequence, because this module has to keep running
 * on a plain Magento install. Callers guard on isAvailable() and hand over plain arrays.
 *
 * Content is written through Hyva\CmsLiveviewEditor\Model\Provider rather than the repositories, because the
 * provider creates the Hyva row when it is absent and dispatches clean_cache_by_tags on publish, neither of which
 * a plain repository save does.
 *
 * Content and CSS are both written published first and draft second, and both orders are load bearing:
 *  - Provider::saveContent() with publish = true writes published_content AND draft_content, so a draft saved
 *    first is overwritten by the publish that follows it.
 *  - JitCssRepository::saveStyles() deletes the draft rows along with the published ones when it is called for
 *    the published edition, so draft CSS saved first is deleted by the publish that follows it.
 * An edition with no rows is skipped rather than saved empty, because saving it would delete rows that the
 * payload never meant to replace.
 */
class ContentWriter
{
    private const PROVIDER = \Hyva\CmsLiveviewEditor\Model\Provider::class;
    private const PAGE_REPOSITORY = \Hyva\CmsMagento\Api\PageRepositoryInterface::class;
    private const BLOCK_REPOSITORY = \Hyva\CmsMagento\Api\BlockRepositoryInterface::class;
    private const JIT_CSS_REPOSITORY = \Hyva\CmsLiveviewEditor\Model\Tailwind\JitCssRepository::class;
    private const ENTITY_TYPE_PAGE = 'cms_page';
    private const ENTITY_TYPE_BLOCK = 'cms_block';
    private const EDITION_PUBLISHED = 'published';
    private const EDITION_DRAFT = 'draft';
    private const EDITIONS_IN_WRITE_ORDER = [self::EDITION_PUBLISHED, self::EDITION_DRAFT];

    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * Whether Hyva CMS is installed and its content can be written at all.
     */
    public function isAvailable(): bool
    {
        return interface_exists(self::PAGE_REPOSITORY);
    }

    /**
     * @param int $cmsPageId the native cms_page.page_id, not the id of the Hyva row
     * @param array<string, mixed> $payload the decoded .hyva.json sibling file
     */
    public function writePage(int $cmsPageId, array $payload): void
    {
        $this->write(self::ENTITY_TYPE_PAGE, $cmsPageId, $payload);
    }

    /**
     * @param int $cmsBlockId the native cms_block.block_id, not the id of the Hyva row
     * @param array<string, mixed> $payload the decoded .hyva.json sibling file
     */
    public function writeBlock(int $cmsBlockId, array $payload): void
    {
        $this->write(self::ENTITY_TYPE_BLOCK, $cmsBlockId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function write(string $entityType, int $entityId, array $payload): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $tailwindcss = $payload['tailwindcss'] ?? [];

        $this->writeContent($entityType, $entityId, $payload);
        $this->writeTailwindcss($entityType, $entityId, is_array($tailwindcss) ? $tailwindcss : []);
        $this->writeFlags($entityType, $entityId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeContent(string $entityType, int $entityId, array $payload): void
    {
        $provider = $this->objectManager->get(self::PROVIDER);
        $published = $payload['published_content'] ?? null;
        $draft = $payload['draft_content'] ?? null;

        if (is_string($published) && $published !== '') {
            $provider->saveContent($entityType, $entityId, $published, true);
        }

        if (!is_string($draft) || $draft === '') {
            return;
        }

        $provider->saveContent($entityType, $entityId, $draft, false);
    }

    /**
     * The flat exported rows are grouped back into the theme to css map saveStyles() expects, one map per edition.
     *
     * @param array<int, array{theme: string, edition: string, css: string}> $rows
     */
    private function writeTailwindcss(string $entityType, int $entityId, array $rows): void
    {
        $themeMaps = array_fill_keys(self::EDITIONS_IN_WRITE_ORDER, []);
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['edition'], $row['theme'], $row['css'])) {
                continue;
            }

            $edition = (string)$row['edition'];
            if (!isset($themeMaps[$edition])) {
                continue;
            }

            $themeMaps[$edition][(string)$row['theme']] = (string)$row['css'];
        }

        $jitCssRepository = $this->objectManager->get(self::JIT_CSS_REPOSITORY);
        foreach (self::EDITIONS_IN_WRITE_ORDER as $edition) {
            if ($themeMaps[$edition] === []) {
                continue;
            }

            $jitCssRepository->saveStyles($entityType, $entityId, $themeMaps[$edition], $edition);
        }
    }

    /**
     * saveContent() forces is_liveview_enabled true on publish and never touches is_tailwindcss_jit_enabled, so
     * both flags are restored from the payload afterwards. Only keys the payload actually carries are applied, so
     * that a hand written file cannot silently switch a flag off by omitting it.
     *
     * @param array<string, mixed> $payload
     */
    private function writeFlags(string $entityType, int $entityId, array $payload): void
    {
        $isPage = $entityType === self::ENTITY_TYPE_PAGE;
        $repository = $this->objectManager->get($isPage ? self::PAGE_REPOSITORY : self::BLOCK_REPOSITORY);
        $entity = $isPage ? $repository->getByCmsPageId($entityId) : $repository->getByCmsBlockId($entityId);
        if ($entity === null) {
            return;
        }

        if (array_key_exists('is_liveview_enabled', $payload)) {
            $entity->setIsLiveviewEnabled((bool)$payload['is_liveview_enabled']);
        }

        if (array_key_exists('is_tailwindcss_jit_enabled', $payload)) {
            $entity->setIsTailwindcssJitEnabled((bool)$payload['is_tailwindcss_jit_enabled']);
        }

        $repository->save($entity);
    }
}
