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
 * The object manager is used for the reason ContentReader documents, and in the same shape.
 *
 * Content goes through Provider rather than the repositories, because it creates the Hyva row when absent and
 * dispatches clean_cache_by_tags on publish, neither of which a repository save does.
 *
 * Published is written before draft in both cases, and both orders are load bearing. Provider::saveContent() with
 * publish = true writes published_content AND draft_content. JitCssRepository::saveStyles() deletes the draft rows
 * along with the published ones. Either reversed, the draft is lost.
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

    /**
     * @var \Hyva\CmsLiveviewEditor\Model\Provider|null
     */
    private readonly ?object $provider;

    /**
     * @var \Hyva\CmsMagento\Api\PageRepositoryInterface|null
     */
    private readonly ?object $pageRepository;

    /**
     * @var \Hyva\CmsMagento\Api\BlockRepositoryInterface|null
     */
    private readonly ?object $blockRepository;

    /**
     * @var \Hyva\CmsLiveviewEditor\Model\Tailwind\JitCssRepository|null
     */
    private readonly ?object $jitCssRepository;

    public function __construct(ObjectManagerInterface $objectManager)
    {
        $hyvaCmsInstalled = interface_exists(self::PAGE_REPOSITORY)
            && interface_exists(self::BLOCK_REPOSITORY)
            && class_exists(self::PROVIDER)
            && class_exists(self::JIT_CSS_REPOSITORY);

        $this->provider = $hyvaCmsInstalled ? $objectManager->get(self::PROVIDER) : null;
        $this->pageRepository = $hyvaCmsInstalled ? $objectManager->get(self::PAGE_REPOSITORY) : null;
        $this->blockRepository = $hyvaCmsInstalled ? $objectManager->get(self::BLOCK_REPOSITORY) : null;
        $this->jitCssRepository = $hyvaCmsInstalled ? $objectManager->get(self::JIT_CSS_REPOSITORY) : null;
    }

    public function isAvailable(): bool
    {
        return $this->provider !== null;
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
        $published = $payload['published_content'] ?? null;
        $draft = $payload['draft_content'] ?? null;

        if (is_string($published) && $published !== '') {
            $this->provider->saveContent($entityType, $entityId, $published, true);
        }

        if (!is_string($draft) || $draft === '') {
            return;
        }

        $this->provider->saveContent($entityType, $entityId, $draft, false);
    }

    /**
     * An edition with no rows is skipped rather than saved empty, which would delete rows the payload never meant
     * to replace.
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

        foreach (self::EDITIONS_IN_WRITE_ORDER as $edition) {
            if ($themeMaps[$edition] === []) {
                continue;
            }

            $this->jitCssRepository->saveStyles($entityType, $entityId, $themeMaps[$edition], $edition);
        }
    }

    /**
     * saveContent() forces is_liveview_enabled true on publish and never touches is_tailwindcss_jit_enabled, so
     * both are restored afterwards. Only keys the payload carries are applied, so an omitted key cannot switch a
     * flag off.
     *
     * @param array<string, mixed> $payload
     */
    private function writeFlags(string $entityType, int $entityId, array $payload): void
    {
        $hasLiveviewFlag = array_key_exists('is_liveview_enabled', $payload);
        $hasJitFlag = array_key_exists('is_tailwindcss_jit_enabled', $payload);
        if (!$hasLiveviewFlag && !$hasJitFlag) {
            return;
        }

        $isPage = $entityType === self::ENTITY_TYPE_PAGE;
        $repository = $isPage ? $this->pageRepository : $this->blockRepository;
        $entity = $isPage ? $repository->getByCmsPageId($entityId) : $repository->getByCmsBlockId($entityId);
        if ($entity === null) {
            return;
        }

        if ($hasLiveviewFlag) {
            $entity->setIsLiveviewEnabled((bool)$payload['is_liveview_enabled']);
        }

        if ($hasJitFlag) {
            $entity->setIsTailwindcssJitEnabled((bool)$payload['is_tailwindcss_jit_enabled']);
        }

        $repository->save($entity);
    }
}
