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

use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\App\Filesystem\DirectoryList;

class ImportCmsDataService
{
    private const STORE_SCOPE_ADMIN = 'admin';
    private \Magento\Cms\Api\PageRepositoryInterface $pageRepository;
    private \Magento\Cms\Api\BlockRepositoryInterface $blockRepository;
    private \Magento\Framework\Serialize\SerializerInterface $serializer;
    private \Magento\Framework\Filesystem\Directory\ReadInterface $directoryRead;
    private \Magento\Cms\Api\Data\BlockInterfaceFactory $blockFactory;
    private \Magento\Cms\Api\Data\PageInterfaceFactory $pageFactory;
    private \Magento\Store\Api\StoreRepositoryInterface $storeRepository;
    private string $varPath;

    public function __construct(
        \Magento\Cms\Api\PageRepositoryInterface $pageRepository,
        \Magento\Cms\Api\Data\PageInterfaceFactory $pageFactory,
        \Magento\Cms\Api\BlockRepositoryInterface $blockRepository,
        \Magento\Cms\Api\Data\BlockInterfaceFactory $blockFactory,
        \Magento\Framework\Filesystem\DirectoryList $directoryList,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        \Magento\Store\Api\StoreRepositoryInterface $storeRepository,
        private readonly \Magento\Cms\Api\GetBlockByIdentifierInterface $getBlockByIdentifier,
        private readonly \Magento\Cms\Api\GetPageByIdentifierInterface $getPageByIdentifier
    ) {
        $this->pageRepository = $pageRepository;
        $this->blockRepository = $blockRepository;
        $this->serializer = $serializer;
        $this->directoryRead = $filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $this->varPath = $directoryList->getPath(DirectoryList::VAR_DIR) . '/';
        $this->blockFactory = $blockFactory;
        $this->pageFactory = $pageFactory;
        $this->storeRepository = $storeRepository;
    }

    public function execute(array $types, ?array $identifiers, bool $importAll, ?string $storeCode = null)
    {
        $workingDirPath = 'sync_cms_data';

        if (!$this->directoryRead->isExist($this->varPath . $workingDirPath)) {
            throw new \Exception('The sync folder does not exists! Path: ' . $workingDirPath);
        }

        if (!$identifiers && !$importAll) {
            throw new \Exception('If you want to import all entries at once, use --importAll flag');
        }

        foreach ($types as $type) {
            $typeDirPath = $workingDirPath . sprintf('/cms/%ss/', $type);
            if (!$this->directoryRead->isExist($this->varPath . $typeDirPath)) {
                throw new \Exception('The ' . $type . ' folder does not exists! Path: ' . $typeDirPath);
            }

            if ($type == 'block') {
                $this->importBlocks($typeDirPath, $identifiers, $storeCode);
            } else if ($type == 'page') {
                $this->importPages($typeDirPath, $identifiers, $storeCode);
            }
        }
    }

    private function getStoreIds($storeCodes): array
    {
        $storeIds = [];
        if (is_array($storeCodes) && count($storeCodes)) {
            foreach ($storeCodes as $storeCode) {
                try {
                    if ($storeCode === DumpCmsDataService::STORE_SCOPE_ALL) {
                        $storeCode = self::STORE_SCOPE_ADMIN;
                    }
                    $store = $this->storeRepository->get($storeCode);
                    $storeIds[] = $store->getId();
                } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
                    echo $exception->getMessage() . "\n";
                }
            }
        }

        return $storeIds;
    }

    private function importBlocks(string $dirPath, ?array $identifiers, ?string $storeCode = null): void
    {
        $filePaths = $this->directoryRead->read($this->varPath . $dirPath);
        foreach ($filePaths as $filePath) {
            if (strpos($filePath, '.html') === false) {
                // Processing only .html files as we will fetch json from them
                continue;
            }
            $identifier = str_replace($dirPath, '', $filePath);
            $identifier = str_replace('.html', '', $identifier);
            $identifier = substr_replace($identifier, '', strpos($identifier, '---'));
            if ($identifiers !== null && !in_array($identifier, $identifiers)) {
                // If we have a list of items, we skip if its not in the list
                continue;
            }
            if ($storeCode !== null && ($this->getStoreCode($filePath) !== $storeCode)) {
                // Skip identifiers not assigned to specific store when storeCode parameter is set
                echo sprintf(
                    'Skipping identifier %s because requested update only for store %s %s',
                    $identifier,
                    $storeCode,
                    PHP_EOL
                );
                continue;
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
            $storeIds = $this->getStoreIds($jsonData['stores']);
            try {
                $block = $this->getBlockByIdentifier->execute($identifier, (int)reset($storeIds));
                $this->validateStoreAssociation($filePath, $block, $storeIds, 'Block');
            } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
                $block = $this->blockFactory->create();
            }

            $block->setTitle($jsonData['title']);
            $block->setContent($content);
            $block->setIdentifier($jsonData['identifier']);
            $block->setIsActive((bool)$jsonData['is_active']);
            $block->setStores($storeIds);
            if (isset($jsonData['is_tailwindcss_jit_enabled'])) {
                $block->setIsTailwindcssJitEnabled($jsonData['is_tailwindcss_jit_enabled']);
            }

            try {
                $this->blockRepository->save($block);
            } catch (\Exception $exception) {
                echo $exception->getMessage() . ', Block ID: ' . $identifier . "\n";
            }
        }
    }

    private function importPages(string $dirPath, ?array $identifiers, ?string $storeCode = null): void
    {
        $filePaths = $this->directoryRead->read($this->varPath . $dirPath);
        foreach ($filePaths as $filePath) {
            if (strpos($filePath, '.html') === false) {
                // Processing only .html files as we will fetch json from them
                continue;
            }
            $identifier = str_replace($dirPath, '', $filePath);
            $identifier = str_replace('.html', '', $identifier);
            $identifier = substr_replace($identifier, '', strrpos($identifier, '---'));
            $identifier = str_replace('---', '/', $identifier);
            $identifier = str_replace('_html', '.html', $identifier);
            if ($identifiers !== null && !in_array($identifier, $identifiers)) {
                // If we have a list of items, we skip if its not in the list
                continue;
            }

            if ($storeCode !== null && ($this->getStoreCode($filePath) !== $storeCode)) {
                // Skip identifiers not assigned to specific store when storeCode parameter is set
                echo sprintf(
                    'Skipping identifier %s because requested update only for store %s %s',
                    $identifier,
                    $storeCode,
                    PHP_EOL
                );
                continue;
            }

            $content = $this->directoryRead->readFile($filePath);
            $jsonData = $this->directoryRead->readFile(str_replace('.html', '.json', $filePath));
            $jsonData = $this->serializer->unserialize($jsonData);
            $storeIds = $this->getStoreIds($jsonData['stores']);
            try {
                $page = $this->getPageByIdentifier->execute($identifier, (int)reset($storeIds));
                $this->validateStoreAssociation($filePath, $page, $storeIds, 'Page');
            } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
                $page = $this->pageFactory->create();
            }
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
            $page->setStores($storeIds);
            if (isset($jsonData['is_tailwindcss_jit_enabled'])) {
                $page->setIsTailwindcssJitEnabled($jsonData['is_tailwindcss_jit_enabled']);
            }

            try {
                $this->pageRepository->save($page);
            } catch (\Exception $exception) {
                echo $exception->getMessage() . ' | Page ID: ' . $identifier . "\n";
            }
        }
    }

    /**
     * We are validating here is store association is correct
     * string $filePath - HTML filename, may contain either store code or _all_
     * BlockInterface | PageInterface $entity - either block or page if already exists
     * array $storeIds - array of stores to associate from JSON file
     * string $entityType - either "block" or "page", for accurate messaging
     *
     * We load store by store code specified in $filePath
     * Further we validate it against the data we have in JSON and if currently existing block/page
     */
    private function validateStoreAssociation(
        string $filePath,
        BlockInterface | PageInterface $entity,
        array $storeIds,
        string $entityType
    ) : void {
        $exceptionMessage = sprintf('%s with path %s has inconsistent store data', $entityType, $filePath);
        if (count($storeIds) > 1) {
            throw new \LogicException($exceptionMessage);
        }
        $storeCode = $this->getStoreCode($filePath);
        $storeId = (int)reset($storeIds);
        $currentStoreIds = $entity->getStoreId();
        if ($storeCode === '_all_') {
            if ($storeId !== 0 || count($currentStoreIds) > 1 || (int)reset($currentStoreIds) !== 0) {
                throw new \LogicException($exceptionMessage);
            }
            return ;
        }
        $store = $this->storeRepository->get($storeId);
        if ($store->getCode() !== $storeCode) {
            throw new \LogicException($exceptionMessage);
        }

        if (array_diff($currentStoreIds, $storeIds) !== []) {
            throw new \LogicException($exceptionMessage);
        }
    }

    private function getStoreCode(string $filePath) : string
    {
        $storeCode = str_replace('.html', '', $filePath);
        $storeCode = substr($storeCode, strrpos($storeCode, '---') + 3);
        return $storeCode;
    }
}
