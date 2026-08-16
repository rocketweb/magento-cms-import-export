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
 * Hyva CMS is optional, so no Hyva type may reach a constructor signature or the module sequence: DI resolves
 * arguments eagerly and would fatal on an install without it. The dependencies are therefore resolved once here,
 * only when every class is present, and used as ordinary properties afterwards. Absent, they stay null and every
 * public method returns null. A bridge package requiring hyva-themes/commerce-module-cms would remove this, at
 * the cost of a second composer package.
 *
 * CSS goes through JitCssRepository rather than the Page/BlockTailwindcssRepositoryInterface pair, because only
 * it knows the UNIQUE (entity_ref_id, theme, edition) key the importer writes back through.
 */
class ContentReader
{
    private const PAGE_REPOSITORY = \Hyva\CmsMagento\Api\PageRepositoryInterface::class;
    private const BLOCK_REPOSITORY = \Hyva\CmsMagento\Api\BlockRepositoryInterface::class;
    private const JIT_CSS_REPOSITORY = \Hyva\CmsLiveviewEditor\Model\Tailwind\JitCssRepository::class;
    private const ENTITY_TYPE_PAGE = 'cms_page';
    private const ENTITY_TYPE_BLOCK = 'cms_block';
    private const EDITIONS = ['published', 'draft'];

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

    public function __construct(
        ObjectManagerInterface $objectManager,
        private readonly ReferenceCollector $referenceCollector
    ) {
        $hyvaCmsInstalled = interface_exists(self::PAGE_REPOSITORY)
            && interface_exists(self::BLOCK_REPOSITORY)
            && class_exists(self::JIT_CSS_REPOSITORY);

        $this->pageRepository = $hyvaCmsInstalled ? $objectManager->get(self::PAGE_REPOSITORY) : null;
        $this->blockRepository = $hyvaCmsInstalled ? $objectManager->get(self::BLOCK_REPOSITORY) : null;
        $this->jitCssRepository = $hyvaCmsInstalled ? $objectManager->get(self::JIT_CSS_REPOSITORY) : null;
    }

    public function isAvailable(): bool
    {
        return $this->pageRepository !== null;
    }

    /**
     * @return array<string, mixed>|null the payload described on buildContent(), null when not Hyva managed
     */
    public function readPage(int $cmsPageId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $page = $this->pageRepository->getByCmsPageId($cmsPageId);
        if ($page === null) {
            return null;
        }

        return $this->buildContent($page, self::ENTITY_TYPE_PAGE, $cmsPageId);
    }

    /**
     * @return array<string, mixed>|null the payload described on buildContent(), null when not Hyva managed
     */
    public function readBlock(int $cmsBlockId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $block = $this->blockRepository->getByCmsBlockId($cmsBlockId);
        if ($block === null) {
            return null;
        }

        return $this->buildContent($block, self::ENTITY_TYPE_BLOCK, $cmsBlockId);
    }

    /**
     * Both flags are cast because isLiveviewEnabled() is nullable and isTailwindcssJitEnabled() is not, and the
     * exported JSON has to carry the same type for both on every run. References cover draft and published
     * together, since a draft can depend on an entity the published copy does not.
     *
     * @param object $entity Hyva\CmsMagento\Api\Data\PageInterface or BlockInterface
     * @return array{is_liveview_enabled: bool, is_tailwindcss_jit_enabled: bool, draft_content: string|null,
     *     published_content: string|null, references: array<string, array<int, string>>,
     *     tailwindcss: array<int, array{theme: string, edition: string, css: string}>}
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
     * Sorted so the exported file stays byte identical between runs and does not produce phantom diffs in git.
     *
     * @return array<int, array{theme: string, edition: string, css: string}>
     */
    private function readTailwindcss(string $entityType, int $entityId): array
    {
        $rows = [];
        foreach (self::EDITIONS as $edition) {
            foreach ($this->jitCssRepository->getAllThemeStyles($entityType, $entityId, $edition) as $theme => $css) {
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
