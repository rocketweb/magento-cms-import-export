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
    private const MENU_REPOSITORY = \Hyva\MenuBuilder\Api\MenuRepositoryInterface::class;
    private const MENU_FACTORY = \Hyva\MenuBuilder\Model\MenuFactory::class;
    private const JIT_CSS_REPOSITORY = \Hyva\CmsLiveviewEditor\Model\Tailwind\JitCssRepository::class;
    private const ENTITY_TYPE_PAGE = 'cms_page';
    private const ENTITY_TYPE_BLOCK = 'cms_block';
    private const ENTITY_TYPE_MENU = 'menu';
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

    /**
     * @var \Hyva\MenuBuilder\Api\MenuRepositoryInterface|null
     */
    private readonly ?object $menuRepository;

    /**
     * @var \Hyva\MenuBuilder\Model\MenuFactory|null
     */
    private readonly ?object $menuFactory;

    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        private readonly \RocketWeb\CmsImportExport\Model\Service\HyvaCms\CategoryLinkMapper $categoryLinkMapper
    ) {
        $hyvaCmsInstalled = interface_exists(self::PAGE_REPOSITORY)
            && interface_exists(self::BLOCK_REPOSITORY)
            && class_exists(self::PROVIDER)
            && class_exists(self::JIT_CSS_REPOSITORY);

        $this->provider = $hyvaCmsInstalled ? $objectManager->get(self::PROVIDER) : null;
        $this->pageRepository = $hyvaCmsInstalled ? $objectManager->get(self::PAGE_REPOSITORY) : null;
        $this->blockRepository = $hyvaCmsInstalled ? $objectManager->get(self::BLOCK_REPOSITORY) : null;
        $this->jitCssRepository = $hyvaCmsInstalled ? $objectManager->get(self::JIT_CSS_REPOSITORY) : null;

        // Menu Builder ships as its own package, so it is present or absent independently of Hyva CMS.
        $menuInstalled = $hyvaCmsInstalled
            && interface_exists(self::MENU_REPOSITORY)
            && class_exists(self::MENU_FACTORY);
        $this->menuRepository = $menuInstalled ? $objectManager->get(self::MENU_REPOSITORY) : null;
        $this->menuFactory = $menuInstalled ? $objectManager->get(self::MENU_FACTORY) : null;
    }

    public function isAvailable(): bool
    {
        return $this->provider !== null;
    }

    public function isMenuAvailable(): bool
    {
        return $this->menuRepository !== null;
    }

    /**
     * Creates or updates the menu row itself, so that writeMenu() has an id to hang content on. Matching is by
     * identifier within the payload's own store scope, which is what makes a re-import an update rather than a
     * duplicate. Content is deliberately not set here: Provider::saveContent() has to do that, because only it
     * clears the entity cache and dispatches the publish tags.
     *
     * @param array<string, mixed> $payload the decoded menu json file
     * @param array<int, int> $storeIds resolved from the store codes in the file name
     * @return int|null the saved menu id, null when Menu Builder is absent
     */
    public function saveMenuRow(array $payload, array $storeIds): ?int
    {
        if (!$this->isMenuAvailable()) {
            return null;
        }

        $identifier = (string)($payload['identifier'] ?? '');
        try {
            $menu = $this->menuRepository->getByIdentifier($identifier, (int)reset($storeIds));
        } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
            $menu = $this->menuFactory->create();
        }

        $menu->setTitle((string)($payload['title'] ?? $identifier));
        $menu->setIdentifier($identifier);
        $menu->setIsActive((bool)($payload['is_active'] ?? true));
        $menu->setStores($storeIds);

        if (array_key_exists('preview_url_key', $payload)) {
            $menu->setPreviewUrlKey($payload['preview_url_key'] === null ? null : (string)$payload['preview_url_key']);
        }

        if (array_key_exists('is_tailwindcss_jit_enabled', $payload)) {
            $menu->setIsTailwindcssJitEnabled((bool)$payload['is_tailwindcss_jit_enabled']);
        }

        return (int)$this->menuRepository->save($menu)->getId();
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
     * A menu carries its own title, identifier, store assignment and flags on the same row as its content, and
     * the importer saves that row through saveMenuRow() before calling this.
     *
     * is_active is written again at the end because MenuProvider::saveContent() forces it true on publish, the
     * way it forces is_liveview_enabled true for a page. An inactive menu would otherwise come back enabled.
     *
     * @param int $menuId the id of the menu row the importer just saved
     * @param array<string, mixed> $payload the decoded menu json file
     */
    public function writeMenu(int $menuId, array $payload, array &$warnings = []): void
    {
        if (!$this->isMenuAvailable()) {
            return;
        }

        $tailwindcss = $payload['tailwindcss'] ?? [];

        foreach (['draft_content', 'published_content'] as $key) {
            if (!isset($payload[$key]) || !is_string($payload[$key])) {
                continue;
            }

            $payload[$key] = $this->categoryLinkMapper->pathsToIds($payload[$key], $warnings);
        }

        $warnings = array_values(array_unique($warnings));

        $this->writeContent(self::ENTITY_TYPE_MENU, $menuId, $payload);
        $this->writeTailwindcss(self::ENTITY_TYPE_MENU, $menuId, is_array($tailwindcss) ? $tailwindcss : []);

        if (!array_key_exists('is_active', $payload)) {
            return;
        }

        $menu = $this->menuRepository->get($menuId);
        $menu->setIsActive((bool)$payload['is_active']);
        $this->menuRepository->save($menu);
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
