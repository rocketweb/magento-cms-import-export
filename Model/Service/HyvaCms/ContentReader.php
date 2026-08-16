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
 * Reads Hyva CMS content and its per-entity Tailwind CSS for a native CMS page or block.
 *
 * This module must keep running on a plain Magento install where Hyva CMS is absent, so no Hyva class may
 * appear in a type hint, a constructor signature or the module sequence. That leaves lazy resolution through
 * the object manager as the only option, and it is why the service-locator pattern this project otherwise
 * forbids is used here. The exception is confined to this class: callers guard on isAvailable() and receive
 * plain arrays.
 *
 * The Tailwind CSS is read through Hyva\CmsLiveviewEditor\Model\Tailwind\JitCssRepository rather than the
 * PageTailwindcssRepositoryInterface / BlockTailwindcssRepositoryInterface pair, because only the former is
 * aware of the UNIQUE (entity_ref_id, theme, edition) key that the importer has to write back through.
 */
class ContentReader
{
    private const PAGE_REPOSITORY = \Hyva\CmsMagento\Api\PageRepositoryInterface::class;
    private const BLOCK_REPOSITORY = \Hyva\CmsMagento\Api\BlockRepositoryInterface::class;
    private const JIT_CSS_REPOSITORY = \Hyva\CmsLiveviewEditor\Model\Tailwind\JitCssRepository::class;
    private const ENTITY_TYPE_PAGE = 'cms_page';
    private const ENTITY_TYPE_BLOCK = 'cms_block';
    private const EDITIONS = ['published', 'draft'];

    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly ReferenceCollector $referenceCollector
    ) {
    }

    /**
     * Whether Hyva CMS is installed and its content can be read at all.
     *
     * Every type this class resolves is checked, not just the first one. The repositories live in Hyva_CmsMagento
     * and JitCssRepository lives in Hyva_CmsLiveviewEditor, and a single check covers both only for as long as
     * the two keep shipping inside one package.
     */
    public function isAvailable(): bool
    {
        return interface_exists(self::PAGE_REPOSITORY)
            && interface_exists(self::BLOCK_REPOSITORY)
            && class_exists(self::JIT_CSS_REPOSITORY);
    }

    /**
     * @return array{
     *     is_liveview_enabled: bool,
     *     is_tailwindcss_jit_enabled: bool,
     *     draft_content: string|null,
     *     published_content: string|null,
     *     references: array<string, array<int, string>>,
     *     tailwindcss: array<int, array{theme: string, edition: string, css: string}>
     * }|null null when Hyva CMS is absent or the page is not Hyva managed
     */
    public function readPage(int $cmsPageId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $page = $this->objectManager->get(self::PAGE_REPOSITORY)->getByCmsPageId($cmsPageId);
        if ($page === null) {
            return null;
        }

        return $this->buildContent($page, self::ENTITY_TYPE_PAGE, $cmsPageId);
    }

    /**
     * @return array{
     *     is_liveview_enabled: bool,
     *     is_tailwindcss_jit_enabled: bool,
     *     draft_content: string|null,
     *     published_content: string|null,
     *     references: array<string, array<int, string>>,
     *     tailwindcss: array<int, array{theme: string, edition: string, css: string}>
     * }|null null when Hyva CMS is absent or the block is not Hyva managed
     */
    public function readBlock(int $cmsBlockId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $block = $this->objectManager->get(self::BLOCK_REPOSITORY)->getByCmsBlockId($cmsBlockId);
        if ($block === null) {
            return null;
        }

        return $this->buildContent($block, self::ENTITY_TYPE_BLOCK, $cmsBlockId);
    }

    /**
     * Both flags are cast because isLiveviewEnabled() is declared nullable while isTailwindcssJitEnabled() is
     * not, and the exported payload has to carry the same JSON type for both on every run.
     *
     * References are collected from the draft and the published copy together, since a draft may already depend
     * on an entity the published copy does not, and both editions travel in the same exported file.
     *
     * @param object $entity Hyva\CmsMagento\Api\Data\PageInterface or BlockInterface
     */
    private function buildContent(object $entity, string $entityType, int $entityId): array
    {
        $draftContent = $entity->getDraftContent();
        $publishedContent = $entity->getPublishedContent();

        return [
            'is_liveview_enabled' => (bool)$entity->isLiveviewEnabled(),
            'is_tailwindcss_jit_enabled' => (bool)$entity->isTailwindcssJitEnabled(),
            'draft_content' => $draftContent,
            'published_content' => $publishedContent,
            'references' => $this->referenceCollector->collectFromAll([$draftContent, $publishedContent]),
            'tailwindcss' => $this->readTailwindcss($entityType, $entityId)
        ];
    }

    /**
     * Rows are sorted by theme then edition so that the exported file stays byte identical between runs and
     * does not produce phantom diffs in the repository it is committed to.
     *
     * @return array<int, array{theme: string, edition: string, css: string}>
     */
    private function readTailwindcss(string $entityType, int $entityId): array
    {
        $jitCssRepository = $this->objectManager->get(self::JIT_CSS_REPOSITORY);

        $rows = [];
        foreach (self::EDITIONS as $edition) {
            foreach ($jitCssRepository->getAllThemeStyles($entityType, $entityId, $edition) as $theme => $css) {
                if ($css === '' || $css === null) {
                    continue;
                }

                $rows[] = ['theme' => (string)$theme, 'edition' => $edition, 'css' => $css];
            }
        }

        usort(
            $rows,
            static fn (array $left, array $right): int
                => [$left['theme'], $left['edition']] <=> [$right['theme'], $right['edition']]
        );

        return $rows;
    }
}
