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

class ImportCmsDataService
{
    private \Magento\Cms\Api\PageRepositoryInterface $pageRepository;
    private \Magento\Cms\Api\BlockRepositoryInterface $blockRepository;
    private \Magento\Framework\Serialize\SerializerInterface $serializer;
    private \Magento\Framework\Filesystem\Directory\ReadInterface $directoryRead;
    private \Magento\Cms\Api\Data\BlockInterfaceFactory $blockFactory;
    private \Magento\Cms\Api\Data\PageInterfaceFactory $pageFactory;
    private string $varPath;

    public function __construct(
        \Magento\Cms\Api\PageRepositoryInterface $pageRepository,
        \Magento\Cms\Api\Data\PageInterfaceFactory $pageFactory,
        \Magento\Cms\Api\BlockRepositoryInterface $blockRepository,
        \Magento\Cms\Api\Data\BlockInterfaceFactory $blockFactory,
        \Magento\Framework\Filesystem\DirectoryList $directoryList,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\Serialize\SerializerInterface $serializer
    ) {
        $this->pageRepository = $pageRepository;
        $this->blockRepository = $blockRepository;
        $this->serializer = $serializer;
        $this->directoryRead = $filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $this->varPath = $directoryList->getPath(DirectoryList::VAR_DIR) . '/';
        $this->blockFactory = $blockFactory;
        $this->pageFactory = $pageFactory;
    }

    public function execute(array $types, ?array $identifiers)
    {
        $workingDirPath = 'sync_cms_data';

        if (!$this->directoryRead->isExist($this->varPath . $workingDirPath)) {
            throw new \Exception('The sync folder does not exists! Path: ' . $workingDirPath);
        }

        foreach ($types as $type) {
            $typeDirPath = $workingDirPath . sprintf('/cms/%ss/', $type);
            if (!$this->directoryRead->isExist($this->varPath . $typeDirPath)) {
                throw new \Exception('The ' . $type . ' folder does not exists! Path: ' . $typeDirPath);
            }

            if ($type == 'block') {
                $this->importBlocks($typeDirPath, $identifiers);
            } else if ($type == 'page') {
                $this->importPages($typeDirPath, $identifiers);
            }
        }
    }

    private function importBlocks(string $dirPath, ?array $identifiers): void
    {
        $filePaths = $this->directoryRead->read($this->varPath . $dirPath);
        foreach ($filePaths as $filePath) {
            if (strpos($filePath, '.html') === false) {
                // Processing only .html files as we will fetch json from them
                continue;
            }
            $identifier = str_replace($dirPath, '', $filePath);
            $identifier = str_replace('.html', '', $identifier);
            if ($identifiers !== null && !in_array($identifier, $identifiers)) {
                // If we have a list of items, we skip if its not in the list
                continue;
            }

            try {
                $block = $this->blockRepository->getById($identifier);
            } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
                $block = $this->blockFactory->create();
            }
            $content = $this->directoryRead->readFile($filePath);
            $jsonData = $this->directoryRead->readFile(str_replace('.html', '.json', $filePath));
            $jsonData = $this->serializer->unserialize($jsonData);
            /*$jsonContent = [
                'title' => $block->getTitle(),
                'identifier' => $block->getIdentifier(),
                'stores' => [1],
                'is_active' => $block->isActive()
            ];*/
            $block->setTitle($jsonData['title']);
            $block->setContent($content);
            $block->setIdentifier($jsonData['identifier']);
            $block->setStoreId($jsonData['stores'][0]);
            $block->setIsActive((bool)$jsonData['is_active']);
            if (isset($jsonData['is_tailwindcss_jit_enabled'])) {
                $block->setIsTailwindcssJitEnabled($jsonData['is_tailwindcss_jit_enabled']);
            }

            try {
                $this->blockRepository->save($block);
            } catch (\Exception $exception) {
                echo $exception->getMessage() . ' | Block ID: ' . $identifier . "\n";
            }
        }
    }

    private function importPages(string $dirPath, ?array $identifiers): void
    {
        $filePaths = $this->directoryRead->read($this->varPath . $dirPath);
        foreach ($filePaths as $filePath) {
            if (strpos($filePath, '.html') === false) {
                // Processing only .html files as we will fetch json from them
                continue;
            }
            $identifier = str_replace($dirPath, '', $filePath);
            $identifier = str_replace('|', '/', $identifier);
            $identifier = str_replace('.html', '', $identifier);
            $identifier = str_replace('_html', '.html', $identifier);
            if ($identifiers !== null && !in_array($identifier, $identifiers)) {
                // If we have a list of items, we skip if its not in the list
                continue;
            }

            try {
                $page = $this->pageRepository->getById($identifier);
            } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
                $page = $this->pageFactory->create();
            }
            $content = $this->directoryRead->readFile($filePath);
            $jsonData = $this->directoryRead->readFile(str_replace('.html', '.json', $filePath));
            $jsonData = $this->serializer->unserialize($jsonData);
            /*$jsonContent = [
                'title' => $page->getTitle(),
                'is_active' => $page->isActive(),
                'page_layout' => $page->getPageLayout(),
                'identifier' => $page->getIdentifier(),
                'content_heading' => $page->getContentHeading()
            ];*/
            $page->setTitle($jsonData['title']);
            $page->setContent($content);
            $page->setIdentifier($jsonData['identifier']);
            $page->setPageLayout($jsonData['page_layout']);
            $page->setContentHeading($jsonData['content_heading']);
            $page->setIsActive((bool)$jsonData['is_active']);
            if (isset($jsonData['is_tailwindcss_jit_enabled'])) {
                $page->setIsTailwindcssJitEnabled($jsonData['is_tailwindcss_jit_enabled']);
            }

            try {
                $this->pageRepository->save($page);
            } catch (\Exception $exception) {
                echo $exception->getMessage() . ' | Block ID: ' . $identifier . "\n";
            }
        }
    }
}
