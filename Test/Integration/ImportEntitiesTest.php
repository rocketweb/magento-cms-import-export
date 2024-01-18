<?php

declare(strict_types=1);

namespace RocketWeb\CmsImportExport\Test\Integration;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\GetBlockByIdentifierInterface;
use Magento\Cms\Api\GetPageByIdentifierInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\App\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use RocketWeb\CmsImportExport\Model\Service\DumpCmsDataService;
use RocketWeb\CmsImportExport\Model\Service\ImportCmsDataService;

/**
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 * @magentoAppArea adminhtml
 * @magentoDataFixture RocketWeb_CmsImportExport::_files/custom_block.php
 * @magentoDataFixture RocketWeb_CmsImportExport::_files/custom_page.php
 */
class ImportEntitiesTest extends TestCase
{
    protected ?DumpCmsDataService $exporter;
    protected ?ImportCmsDataService $importer;
    protected ?string $exportDirPath;
    protected ?WriteInterface $varDirectory;
    protected ?GetBlockByIdentifierInterface $getBlock;
    protected ?GetPageByIdentifierInterface $getPage;
    protected ?BlockRepositoryInterface $blockRepository;
    protected ?PageRepositoryInterface $pageRepository;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $fileSystem = $objectManager->create(Filesystem::class);
        $this->exporter = $objectManager->create(DumpCmsDataService::class);
        $this->importer = $objectManager->create(ImportCmsDataService::class);
        $this->varDirectory = $fileSystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $this->exportDirPath = $this->varDirectory->getAbsolutePath() . 'sync_cms_data';
        $this->getBlock = $objectManager->create(GetBlockByIdentifierInterface::class);
        $this->getPage = $objectManager->create(GetPageByIdentifierInterface::class);
        $this->blockRepository = $objectManager->create(BlockRepositoryInterface::class);
        $this->pageRepository = $objectManager->create(PageRepositoryInterface::class);
    }

    public function getExecuteCases(): array
    {
        return [
            ['block', 'import_gopher_cms_block'],
            ['page', 'import_gopher_cms_page'],
        ];
    }

    /**
     * @return void
     * @throws NoSuchEntityException|LocalizedException
     */
    public function tearDown(): void
    {
        $this->deleteTestBlock();
        $this->deleteTestPage();
    }

    /**
     * @param string $type
     * @param string $identifier
     * @return void
     * @throws \Exception
     * @dataProvider getExecuteCases
     */
    public function testCmsImportedCorrectly(string $type, string $identifier)
    {
        $this->exporter->execute([$type], null, false);
        //validate file was created successfully
        if ($type === 'block') {
            $blockFilepath = sprintf(
                '%s/%s/%s',
                $this->exportDirPath,
                'cms/blocks',
                "$identifier---default.json"
            );

            self::assertTrue(
                $this->varDirectory->isFile($blockFilepath),
                'block file not found'
            );
            $this->deleteTestBlock();
        } else {
            $pageFilepath = sprintf(
                '%s/%s/%s',
                $this->exportDirPath,
                'cms/pages',
                "$identifier---default.json"
            );
            self::assertTrue(
                $this->varDirectory->isFile($pageFilepath),
                'page file not found'
            );
            $this->deleteTestPage();
        }

        $this->importer->execute([$type], [$identifier], false);
        if ($type === 'block') {
            $block = $this->getBlock->execute($identifier, 1);
            self::assertIsObject($block);
            self::assertNotEmpty($block->getId());
            self::assertEquals($block->getIdentifier(), $identifier);
        } else {
            $page = $this->getPage->execute($identifier, 1);
            self::assertIsObject($page);
            self::assertNotEmpty($page->getId());
            self::assertEquals($page->getIdentifier(), $identifier);
        }
    }

    /**
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function deleteTestBlock(): void
    {
        $block = $this->getBlock->execute('import_gopher_cms_block', 1);
        $this->blockRepository->delete($block);
    }

    /**
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function deleteTestPage(): void
    {
        $page = $this->getPage->execute('import_gopher_cms_page', 1);
        $this->pageRepository->delete($page);
    }
}
