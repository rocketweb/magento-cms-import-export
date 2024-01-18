<?php

declare(strict_types=1);

namespace RocketWeb\CmsImportExport\Test\Integration;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Block;
use Magento\Cms\Model\BlockFactory;
use Magento\Cms\Model\Page;
use Magento\Cms\Model\PageFactory;
use Magento\Framework\Exception\FileSystemException;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\App\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use RocketWeb\CmsImportExport\Model\Service\ImportCmsDataService;

/**
 * @magentoAppIsolation disabled
 * @magentoDbIsolation disabled
 */
class ImportByStoreScopeEntitiesTest extends TestCase
{
    protected ?ImportCmsDataService $importer;
    protected ?string $exportDirPath;
    protected ?BlockRepositoryInterface $blockRepository;
    protected ?PageRepositoryInterface $pageRepository;
    protected ?StoreRepositoryInterface $storeRepository;
    protected ?WriteInterface $varDirectory;
    protected ?BlockFactory $blockFactory;
    protected ?PageFactory $pageFactory;
    protected ?StoreManagerInterface $storeManager;
    /**
     * @return void
     * @throws FileSystemException
     */
    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $fileSystem = $objectManager->create(Filesystem::class);
        $this->importer = $objectManager->create(ImportCmsDataService::class);
        $this->varDirectory = $fileSystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $this->blockRepository = $objectManager->create(BlockRepositoryInterface::class);
        $this->storeRepository = $objectManager->create(StoreRepositoryInterface::class);
        $this->pageRepository = $objectManager->create(PageRepositoryInterface::class);
        $this->blockFactory = $objectManager->create(BlockFactory::class);
        $this->pageFactory = $objectManager->create(PageFactory::class);
        $this->storeManager = $objectManager->create(StoreManagerInterface::class);
        $this->exportDirPath = $this->varDirectory->getAbsolutePath() . 'sync_cms_data';
    }

    public function getExecuteCases(): array
    {
        return [
            ['block', 'import_gopher_cms_block_multistore', ['second_store_view']],
            ['page', 'import_gopher_cms_page_multistore', ['second_store_view']],
        ];
    }

    /**
     * @param string $type
     * @param string $identifier
     * @param array $scopes
     * @return void
     * @magentoAppArea adminhtml
     * @magentoDataFixture RocketWeb_CmsImportExport::_files/multiple_websites_with_store_groups_stores.php
     * @dataProvider getExecuteCases
     * @throws \Exception
     */
    public function testCmsImportedCorrectlyByScope(
        string $type,
        string $identifier,
        array $scopes
    ) {
        // @codingStandardsIgnoreStart
        //remove blocks / pages first if they exist
        try {
            if ($type === 'block') {
                $block = $this->blockFactory->create();
                $block->load($identifier, 'identifier');
                $this->blockRepository->delete($block);
            } else {
                $page = $this->pageFactory->create();
                $page->load($identifier, 'identifier');
                $this->pageRepository->delete($page);
            }
        } catch (\Exception $e) { //means they don't exist, move on
        }

        $selectedStore = $this->storeRepository->get(current($scopes));
        $this->checkAndCreateFiles($type, $identifier, $scopes);
        if ($this->storeManager->getStore()->getId() != '0') {
            $this->storeManager->setCurrentStore(0);
        }
        $this->importer->execute([$type], [$identifier], false);
        if ($type === 'block') {
            /** @var Block $page */
            $block = $this->blockFactory->create();
            $block->load($identifier, 'identifier');
            self::assertIsObject($block);
            self::assertNotEmpty($block->getId());
            self::assertContains($selectedStore->getId(), $block->getStores());
            self::assertEquals($block->getIdentifier(), $identifier);
        } else {
            /** @var Page $page */
            $page = $this->pageFactory->create();
            $page->load($identifier, 'identifier');
            self::assertIsObject($page);
            self::assertNotEmpty($page->getId());
            self::assertContains($selectedStore->getId(), $page->getStores());
            self::assertEquals($page->getIdentifier(), $identifier);
        }
        // @codingStandardsIgnoreEnd
    }

    private function checkAndCreateFiles(string $type, string $identifier, array $scopes): void
    {
        if (!$this->varDirectory->isExist($this->exportDirPath)) {
            $this->varDirectory->create($this->exportDirPath);
        }

        $blockContent = '{"title":"CMS Block Title","identifier":"import_gopher_cms_block_multistore","stores":["second_store_view"],"is_active":true,"is_tailwindcss_jit_enabled":"1"}';
        $blockHtmlContent = '<h1>Fixture Block Title</h1>
<a href="{{store url=""}}">store url</a>
<p>Config value: "{{config path="web/unsecure/base_url"}}".</p>
<p>Custom variable: "{{customvar code="variable_code"}}".</p>
';
        $pageContent = '{"title":"Cms Page 100","is_active":true,"page_layout":"1column","identifier":"import_gopher_cms_page_multistore","stores":["second_store_view"],"content_heading":"<h2>Cms Page 100 Title<\/h2>","is_tailwindcss_jit_enabled":"1"}';
        $pageHtmlContent = '<h1>Cms Page 100 Title</h1>';
        $jsonFilename = sprintf(
            "$identifier---%s.json",
            implode('---', $scopes)
        );
        $htmlFilename = sprintf(
            "$identifier---%s.html",
            implode('---', $scopes)
        );
        $jsonFilePath = $this->exportDirPath . ($type === 'block' ? '/cms/blocks/' : '/cms/pages/') . $jsonFilename;
        $htmlFilePath = $this->exportDirPath . ($type === 'block' ? '/cms/blocks/' : '/cms/pages/') . $htmlFilename;
        if (!$this->varDirectory->isFile($jsonFilePath)) {
            $this->varDirectory->writeFile(
                $jsonFilePath,
                $type === 'block' ? $blockContent : $pageContent
            );
        }

        if (!$this->varDirectory->isFile($htmlFilePath)) {
            $this->varDirectory->writeFile(
                $htmlFilePath,
                $type === 'block' ? $blockHtmlContent : $pageHtmlContent
            );
        }
    }
}
