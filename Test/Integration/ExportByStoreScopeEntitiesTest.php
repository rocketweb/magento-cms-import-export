<?php

declare(strict_types=1);

namespace RocketWeb\CmsImportExport\Test\Integration;

use Magento\Framework\Exception\FileSystemException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\App\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use RocketWeb\CmsImportExport\Model\Service\DumpCmsDataService;

/**
 * @magentoAppIsolation disabled
 * @magentoDbIsolation disabled
 * @magentoAppArea adminhtml
 */
class ExportByStoreScopeEntitiesTest extends TestCase
{
    protected ?DumpCmsDataService $exporter;
    protected ?string $exportDirPath;
    protected ?WriteInterface $varDirectory;

    /**
     * @return void
     * @throws FileSystemException
     */
    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $fileSystem = $objectManager->create(Filesystem::class);
        $this->exporter = $objectManager->create(DumpCmsDataService::class);
        $this->varDirectory = $fileSystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $this->exportDirPath = $this->varDirectory->getAbsolutePath() . 'sync_cms_data';
        $storeManager = $objectManager->create(StoreManagerInterface::class);
        if ($storeManager->getStore()->getId() != '0') {
            $storeManager->setCurrentStore(0);
        }
    }

    public function getExecuteCases(): array
    {
        return [
            ['block', 'import_gopher_cms_block_multistore', ['default', 'second_store_view']],
            ['page', 'import_gopher_cms_page_multistore', ['default', 'second_store_view']],
        ];
    }

    /**
     * @param string $type
     * @param string $identifier
     * @param array $scopes
     * @return void
     * @throws FileSystemException
     * @magentoDataFixture RocketWeb_CmsImportExport::_files/multiple_websites_with_store_groups_stores.php
     * @dataProvider getExecuteCases
     * @magentoDataFixture RocketWeb_CmsImportExport::_files/multi_store_block.php
     * @magentoDataFixture RocketWeb_CmsImportExport::_files/multi_store_page.php
     */
    public function testCmsExportedCorrectlyByScope(
        string $type,
        string $identifier,
        array $scopes
    ) {
        $this->exporter->execute([$type], [$identifier], false);
        //validate that the export folder exists
        self::assertTrue(
            $this->varDirectory->isExist($this->exportDirPath),
            __CLASS__ . ' Export directory does not exist'
        );

        $filename = sprintf(
            "$identifier---%s.json",
            implode('---', $scopes)
        );
        $filepath = sprintf(
            '%s/%s/%s',
            $this->exportDirPath,
            $type === 'block' ? 'cms/blocks' : 'cms/pages',
            $filename
        );
        //validate file was created successfully
        self::assertTrue(
            $this->varDirectory->isFile($filepath),
            __CLASS__ . " $filename does not exist"
        );

        $decoded = json_decode(
            $this->varDirectory->readFile($filepath),
            true
        );
        //validate file structure was created successfully
        self::assertArrayHasKey(
            'identifier',
            $decoded,
            __CLASS__ . " $filename does not have the correct structure"
        );
        self::assertTrue(
            $decoded['identifier'] === $identifier,
            __CLASS__ . " Invalid identifiers, file: {$decoded['identifier']}, provided $identifier"
        );
    }
}
