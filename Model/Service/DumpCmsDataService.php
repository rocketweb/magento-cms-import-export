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
    private \Magento\Cms\Api\PageRepositoryInterface $pageRepository;
    private \Magento\Cms\Api\BlockRepositoryInterface $blockRepository;
    private \Magento\Framework\Api\SearchCriteriaBuilder $criteriaBuilder;
    private \Magento\Framework\Filesystem\DirectoryList $directoryList;
    private \Magento\Framework\Filesystem $filesystem;
    private \Magento\Framework\Serialize\SerializerInterface $serializer;
    private \Magento\Catalog\Model\CategoryList $categoryList;
    private array $blockIdentifiers = [];
    private array $blocksMapping = [];

    public function __construct(
        \Magento\Cms\Api\PageRepositoryInterface $pageRepository,
        \Magento\Cms\Api\BlockRepositoryInterface $blockRepository,
        \Magento\Catalog\Model\CategoryList $categoryList,
        \Magento\Framework\Api\SearchCriteriaBuilder $criteriaBuilder,
        \Magento\Framework\Filesystem\DirectoryList $directoryList,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\Serialize\SerializerInterface $serializer
    ) {
        $this->pageRepository = $pageRepository;
        $this->blockRepository = $blockRepository;
        $this->criteriaBuilder = $criteriaBuilder;
        $this->directoryList = $directoryList;
        $this->filesystem = $filesystem;
        $this->serializer = $serializer;
        $this->categoryList = $categoryList;
    }

    public function execute(array $types, ?array $identifiers)
    {
        $varDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $varPath = $this->directoryList->getPath(DirectoryList::VAR_DIR);
        $workingDirPath = $varPath . '/sync_cms_data';
        $this->blocksMapping = $this->getBlocksMapping();
        if ($varDirectory->isExist($workingDirPath)) {
            $varDirectory->delete($workingDirPath);
        }

        foreach ($types as $type) {
            if ($type == 'block') {
                $this->dumpBlocks($workingDirPath . '/cms/blocks/', $varDirectory, $identifiers);
            } else if ($type == 'page') {
                $this->dumpPages($workingDirPath . '/cms/pages/', $varDirectory, $identifiers);
            }
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

    private function getBlocksMapping(): array
    {
        $blocksMapping = [];
        $searchCriteria = $this->criteriaBuilder;
        $blocksList = $this->blockRepository->getList($searchCriteria->create());
        $blocks = $blocksList->getItems();
        foreach ($blocks as $block) {
            $blocksMapping[$block->getId()] = $block->getIdentifier();
        }

        return $blocksMapping;
    }

    private function replaceBlockIds(string $content): string
    {
        preg_match_all('/block_id=\"([0-9]+)\"/', $content, $blockIds);
        if (isset($blockIds[1])) {
            foreach ($blockIds[1] as $blockId) {
                if (isset($this->blocksMapping[$blockId])) {
                    $identifier = $this->blocksMapping[$blockId];
                    $content = str_replace("block_id=\"$blockId\"", "block_id=\"$identifier\"", $content);
                }
            }
        }

        return $content;
    }

    private function dumpPages(string $path, WriteInterface $varDirectory, ?array $identifiers): void
    {
        $searchCriteria = $this->criteriaBuilder;
        if ($identifiers) {
            $searchCriteria->addFilter('identifier', $identifiers, 'in');
        }

        $pagesList = $this->pageRepository->getList($searchCriteria->create());
        $pages = $pagesList->getItems();

        foreach ($pages as $page) {
            $identifier = str_replace('/', '|', trim($page->getIdentifier()));
            if (strpos($identifier, '.html') !== false) {
                $identifier = str_replace('.html', '_html', $identifier);
            }
            $htmlPath = $path . $identifier . '.html';
            $pageContent = $this->replaceBlockIds($page->getContent());
            $this->write($varDirectory, $htmlPath, $pageContent);
            $jsonPath = $path . $identifier . '.json';
            $jsonContent = [
                'title' => $page->getTitle(),
                'is_active' => $page->isActive(),
                'page_layout' => $page->getPageLayout(),
                'identifier' => $page->getIdentifier(),
                'content_heading' => $page->getContentHeading(),

            ];
            $this->write($varDirectory, $jsonPath, $this->serializer->serialize($jsonContent));
        }
    }

    private function dumpBlocks(string $path, WriteInterface $varDirectory, ?array $identifiers): void
    {
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
            $htmlPath = $path . trim($block->getIdentifier()) . '.html';
            $this->write($varDirectory, $htmlPath, $block->getContent());
            $jsonPath = $path . trim($block->getIdentifier()) . '.json';
            $jsonContent = [
                'title' => $block->getTitle(),
                'identifier' => $block->getIdentifier(),
                'stores' => [1],
                'is_active' => $block->isActive()
            ];
            $this->write($varDirectory, $jsonPath, $this->serializer->serialize($jsonContent));
        }
    }
}
