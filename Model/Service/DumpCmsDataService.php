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

namespace RocketWeb\CmsImportExport\Model\Service;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Directory\WriteInterface;

class DumpCmsDataService
{
    public const STORE_SCOPE_ALL = '_all_';
    private const CSS_SIZE_WARNING_THRESHOLD = 60000;
    private \Magento\Cms\Api\PageRepositoryInterface $pageRepository;
    private \Magento\Cms\Api\BlockRepositoryInterface $blockRepository;
    private \Magento\Framework\Api\SearchCriteriaBuilder $criteriaBuilder;
    private \Magento\Framework\Filesystem\DirectoryList $directoryList;
    private \Magento\Framework\Filesystem $filesystem;
    private \Magento\Framework\Serialize\SerializerInterface $serializer;
    private \Magento\Catalog\Model\CategoryList $categoryList;
    private \Magento\Store\Model\StoreManagerInterface $storeManager;
    private \RocketWeb\CmsImportExport\Model\Service\HyvaCms\ContentReader $hyvaContentReader;
    private array $blockIdentifiers = [];
    private array $blocksMapping = [];

    public function __construct(
        \Magento\Cms\Api\PageRepositoryInterface $pageRepository,
        \Magento\Cms\Api\BlockRepositoryInterface $blockRepository,
        \Magento\Catalog\Model\CategoryList $categoryList,
        \Magento\Framework\Api\SearchCriteriaBuilder $criteriaBuilder,
        \Magento\Framework\Filesystem\DirectoryList $directoryList,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \RocketWeb\CmsImportExport\Model\Service\HyvaCms\ContentReader $hyvaContentReader
    ) {
        $this->pageRepository = $pageRepository;
        $this->blockRepository = $blockRepository;
        $this->criteriaBuilder = $criteriaBuilder;
        $this->directoryList = $directoryList;
        $this->filesystem = $filesystem;
        $this->serializer = $serializer;
        $this->categoryList = $categoryList;
        $this->storeManager = $storeManager;
        $this->hyvaContentReader = $hyvaContentReader;
    }

    public function execute(array $types, ?array $identifiers, bool $removeAll, bool $hyvaCms = false)
    {
        $varDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $varPath = $this->directoryList->getPath(DirectoryList::VAR_DIR);
        $workingDirPath = $varPath . '/sync_cms_data';
        if ($varDirectory->isExist($workingDirPath) && $removeAll) {
            $varDirectory->delete($workingDirPath);
        }

        if ($hyvaCms && !$this->hyvaContentReader->isAvailable()) {
            echo "Warning: --hyva-cms was requested but Hyva CMS is not installed, "
                . "no .hyva.json files will be written and the native export continues\n";
        }

        foreach ($types as $type) {
            if ($type == 'block') {
                $this->dumpBlocks($workingDirPath . '/cms/blocks/', $varDirectory, $identifiers, $hyvaCms);
            } else if ($type == 'page') {
                $this->dumpPages($workingDirPath . '/cms/pages/', $varDirectory, $identifiers, $hyvaCms);
            } else if ($type == 'menu') {
                $this->dumpMenus($workingDirPath . '/cms/menus/', $varDirectory, $identifiers);
            }
        }
    }

    /**
     * A menu has no native CMS row behind it, so it exports as a single json file rather than the html, json and
     * hyva.json trio the other types use. That also makes --hyva-cms meaningless here: the Hyva content is the
     * whole entity, so --type=menu always carries it.
     */
    private function dumpMenus(string $path, WriteInterface $varDirectory, ?array $identifiers): void
    {
        if (!$this->hyvaContentReader->isMenuAvailable()) {
            echo "Warning: menus were requested but Hyva Menu Builder is not installed, nothing was dumped\n";
            return;
        }

        foreach ($this->hyvaContentReader->readMenus($identifiers) as $menu) {
            $payload = $menu['payload'];
            $identifier = trim((string)$payload['identifier']);
            $storeCodes = $this->getStoreCodes($menu['stores']);
            $payload['stores'] = $storeCodes;

            $this->warnOnLargeCss($identifier, $payload['tailwindcss']);
            $filePath = $path . str_replace('/', '---', $identifier) . '---' . implode('---', $storeCodes) . '.json';
            $this->write($varDirectory, $filePath, $this->serializer->serialize($payload));
        }
    }

    private function write(WriteInterface $writeDirectory, string $filePath, string $content): void
    {
        $stream = $writeDirectory->openFile($filePath, 'w+');
        $stream->lock();
        $stream->write($content);
        $stream->unlock();
        $stream->close();
    }

    private function replaceBlockIds(string $content): string
    {
        preg_match_all('/block_id=\"([0-9]+)\"/', $content, $blockIds);
        if (isset($blockIds[1])) {
            $searchCriteria = $this->criteriaBuilder;
            $searchCriteria->addFilter('block_id', $blockIds[1], 'in');
            $blocksList = $this->blockRepository->getList($searchCriteria->create());
            $blocks = $blocksList->getItems();
            foreach ($blocks as $block) {
                if (!isset($this->blocksMapping[$block->getId()])) {
                    $this->blocksMapping[$block->getId()] = $block->getIdentifier();
                }
            }
            foreach ($blockIds[1] as $blockId) {
                $identifier = $this->blocksMapping[$blockId];
                $content = str_replace("block_id=\"$blockId\"", "block_id=\"$identifier\"", $content);
            }
        }

        return $content;
    }

    private function getStoreCodes($stores): array
    {
        $storeCodes = [];
        if (!$stores) {
            return [self::STORE_SCOPE_ALL];
        } else {
            foreach ($stores as $storeId) {
                if ($storeId == 0) {
                    return [self::STORE_SCOPE_ALL];
                }
                try {
                    $store = $this->storeManager->getStore($storeId);
                    $storeCodes[] = $store->getCode();
                } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
                    echo $exception->getMessage() . "\n";
                }
            }
        }

        return $storeCodes;
    }

    /**
     * Warns about CSS rows that are approaching the 64KB limit of the text column they are imported back into.
     *
     * @param string $identifier
     * @param array<int, array{theme: string, edition: string, css: string}> $tailwindcss
     */
    private function warnOnLargeCss(string $identifier, array $tailwindcss): void
    {
        foreach ($tailwindcss as $row) {
            $size = strlen($row['css']);
            if ($size < self::CSS_SIZE_WARNING_THRESHOLD) {
                continue;
            }

            echo sprintf(
                "%s CSS for theme %s is %d bytes, approaching the 64KB column limit\n",
                $identifier,
                $row['theme'],
                $size
            );
        }
    }

    private function dumpPages(
        string $path,
        WriteInterface $varDirectory,
        ?array $identifiers,
        bool $hyvaCms
    ): void {
        $searchCriteria = $this->criteriaBuilder;
        if ($identifiers) {
            $searchCriteria->addFilter('identifier', $identifiers, 'in');
        }

        $pagesList = $this->pageRepository->getList($searchCriteria->create());
        $pages = $pagesList->getItems();

        foreach ($pages as $page) {
            $identifier = str_replace('/', '---', trim($page->getIdentifier()));
            if (strpos($identifier, '.html') !== false) {
                $identifier = str_replace('.html', '_html', $identifier);
            }

            $hyvaData = $hyvaCms && $this->hyvaContentReader->isAvailable()
                ? $this->hyvaContentReader->readPage((int)$page->getId())
                : null;

            $storeCodes = $this->getStoreCodes($page->getStores());
            $htmlPath = $path . $identifier . '---' . implode('---', $storeCodes) . '.html';
            $pageContent = $hyvaData === null ? $this->replaceBlockIds($page->getContent()) : '';
            $this->write($varDirectory, $htmlPath, $pageContent);
            $jsonPath = $path . $identifier . '---' . implode('---', $storeCodes) . '.json';
            $jsonContent = [
                'title' => $page->getTitle(),
                'is_active' => $page->isActive(),
                'page_layout' => $page->getPageLayout(),
                'identifier' => $page->getIdentifier(),
                'stores' => $storeCodes,
                'content_heading' => $page->getContentHeading(),

            ];
            if ($page->getIsTailwindcssJitEnabled() !== null) {
                $jsonContent['is_tailwindcss_jit_enabled'] = $page->getIsTailwindcssJitEnabled();
            }
            $this->write($varDirectory, $jsonPath, $this->serializer->serialize($jsonContent));
            if ($hyvaData !== null) {
                $this->warnOnLargeCss($identifier, $hyvaData['tailwindcss']);
                $hyvaPath = $path . $identifier . '---' . implode('---', $storeCodes) . '.hyva.json';
                $this->write($varDirectory, $hyvaPath, $this->serializer->serialize($hyvaData));
            }
        }
    }

    private function dumpBlocks(
        string $path,
        WriteInterface $varDirectory,
        ?array $identifiers,
        bool $hyvaCms
    ): void {
        $searchCriteria = $this->criteriaBuilder;
        if ($identifiers) {
            $searchCriteria->addFilter('identifier', $identifiers, 'in');
        }

        $blocksList = $this->blockRepository->getList($searchCriteria->create());
        $blocks = $blocksList->getItems();

        foreach ($blocks as $block) {
            if (strpos($block->getIdentifier(), 'series_build_cms_') !== false
                || strpos($block->getIdentifier(), '-block-') !== false
            ) {
                // Skipping all generated CMS blocks from old system
                continue;
            }
            $this->blockIdentifiers[$block->getId()] = $block->getIdentifier();
            $hyvaData = $hyvaCms && $this->hyvaContentReader->isAvailable()
                ? $this->hyvaContentReader->readBlock((int)$block->getId())
                : null;

            $storeCodes = $this->getStoreCodes($block->getStores());
            $htmlPath = $path . trim($block->getIdentifier()) . '---' . implode('---', $storeCodes) . '.html';
            $this->write($varDirectory, $htmlPath, $hyvaData === null ? $block->getContent() : '');
            $jsonPath = $path . trim($block->getIdentifier()) . '---' . implode('---', $storeCodes) . '.json';
            $jsonContent = [
                'title' => $block->getTitle(),
                'identifier' => $block->getIdentifier(),
                'stores' => $storeCodes,
                'is_active' => $block->isActive()
            ];
            if ($block->getIsTailwindcssJitEnabled() !== null) {
                $jsonContent['is_tailwindcss_jit_enabled'] = $block->getIsTailwindcssJitEnabled();
            }
            $this->write($varDirectory, $jsonPath, $this->serializer->serialize($jsonContent));
            if ($hyvaData !== null) {
                $this->warnOnLargeCss(trim($block->getIdentifier()), $hyvaData['tailwindcss']);
                $hyvaPath = $path . trim($block->getIdentifier())
                    . '---' . implode('---', $storeCodes) . '.hyva.json';
                $this->write($varDirectory, $hyvaPath, $this->serializer->serialize($hyvaData));
            }
        }
    }
}
