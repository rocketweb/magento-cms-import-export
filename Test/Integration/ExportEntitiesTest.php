<?php

declare(strict_types=1);

namespace Rocketweb\CmsImportExport\Test\Integration;

use Magento\Framework\Exception\FileSystemException;
use Magento\TestFramework\App\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use RocketWeb\CmsImportExport\Model\Service\DumpCmsDataService;

/**
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ExportEntitiesTest extends TestCase
{
    protected ?DumpCmsDataService $exporter;
    protected ?string $exportDirPath;
    protected ?WriteInterface $varDirectory;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $fileSystem = $objectManager->create(Filesystem::class);
        $this->exporter = $objectManager->create(DumpCmsDataService::class);
        $this->varDirectory = $fileSystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $this->exportDirPath = $this->varDirectory->getAbsolutePath() . 'sync_cms_data';
    }

    public function getExecuteCases(): array
    {
        return [
            ['block'],
            ['page'],
        ];
    }

    /**
     * @return void
     * @throws FileSystemException
     */
    protected function tearDown(): void
    {
        if ($this->varDirectory->isExist($this->exportDirPath))
            $this->varDirectory->delete($this->exportDirPath);
    }

    /**
     * @param string $type
     * @return void
     * @throws FileSystemException
     * @dataProvider getExecuteCases
     * @magentoDataFixture Magento/Cms/_files/block.php
     * @magentoDataFixture Magento/Cms/_files/noroute.php
     */
    public function testCmsExportedCorrectly(string $type)
    {
        $this->exporter->execute([$type], null, false);
        //validate that the export folder exists
        self::assertTrue(
            $this->varDirectory->isExist($this->exportDirPath)
        );
        if ($type === 'block') {
            $filepath = sprintf(
                '%s/%s/%s',
                $this->exportDirPath,
                'cms/blocks',
                'fixture_block---default.json'
            );
            //validate file was created successfully
            self::assertTrue(
                $this->varDirectory->isFile($filepath)
            );
            $decoded = json_decode(
                $this->varDirectory->readFile($filepath),
                true
            );
            //validate file structure was created successfully
            self::assertArrayHasKey('identifier', $decoded);
            self::assertTrue($decoded['identifier'] === 'fixture_block');
        }

        if ($type === 'page') {
            $filepath = sprintf(
                '%s/%s/%s',
                $this->exportDirPath,
                'cms/pages',
                'no-route---_all_.json'
            );
            //validate file was created successfully
            self::assertTrue(
                $this->varDirectory->isFile($filepath)
            );
            $decoded = json_decode(
                $this->varDirectory->readFile($filepath),
                true
            );
            //validate file structure was created successfully
            self::assertArrayHasKey('identifier', $decoded);
            self::assertTrue($decoded['identifier'] === 'no-route');
        }
    }
}
