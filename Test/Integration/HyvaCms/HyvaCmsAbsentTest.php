<?php

declare(strict_types=1);

namespace RocketWeb\CmsImportExport\Test\Integration\HyvaCms;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\TestFramework\App\Filesystem;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use RocketWeb\CmsImportExport\Model\Service\DumpCmsDataService;
use RocketWeb\CmsImportExport\Model\Service\HyvaCms\ContentReader;
use RocketWeb\CmsImportExport\Model\Service\HyvaCms\ContentWriter;
use RocketWeb\CmsImportExport\Model\Service\HyvaCms\PayloadValidator;

/**
 * Hyva CMS is optional, and this suite runs on an install that does not have it. That makes these tests the real
 * proof of the degraded path: the flag has to stay inert rather than fatal, and the export has to keep producing
 * exactly what it produced before the flag existed.
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class HyvaCmsAbsentTest extends TestCase
{
    private ?ContentReader $reader = null;
    private ?ContentWriter $writer = null;
    private ?PayloadValidator $validator = null;
    private ?DumpCmsDataService $exporter = null;
    private ?WriteInterface $varDirectory = null;
    private ?string $exportDirPath = null;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->reader = $objectManager->create(ContentReader::class);
        $this->writer = $objectManager->create(ContentWriter::class);
        $this->validator = $objectManager->create(PayloadValidator::class);
        $this->exporter = $objectManager->create(DumpCmsDataService::class);

        $fileSystem = $objectManager->create(Filesystem::class);
        $this->varDirectory = $fileSystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $this->exportDirPath = $this->varDirectory->getAbsolutePath() . 'sync_cms_data';
    }

    /**
     * @throws FileSystemException
     */
    protected function tearDown(): void
    {
        if ($this->varDirectory === null) {
            return;
        }

        if ($this->varDirectory->isExist($this->exportDirPath)) {
            $this->varDirectory->delete($this->exportDirPath);
        }
    }

    public function testHyvaCmsIsNotInstalledInThisSuite(): void
    {
        $this->assertFalse(
            interface_exists(\Hyva\CmsMagento\Api\PageRepositoryInterface::class),
            'This suite proves the degraded path, so it only means anything while Hyva CMS is absent.'
        );
    }

    public function testReaderAndWriterReportThemselvesUnavailable(): void
    {
        $this->assertFalse($this->reader->isAvailable());
        $this->assertFalse($this->writer->isAvailable());
    }

    public function testReaderReturnsNullRatherThanFataling(): void
    {
        $this->assertNull($this->reader->readPage(1));
        $this->assertNull($this->reader->readBlock(1));
    }

    public function testWriterIsANoOpRatherThanFataling(): void
    {
        $payload = [
            'is_liveview_enabled' => true,
            'is_tailwindcss_jit_enabled' => true,
            'draft_content' => '{"content":{}}',
            'published_content' => '{"content":{}}',
            'tailwindcss' => [['theme' => 'frontend/Magento/luma', 'edition' => 'published', 'css' => '.a{}']]
        ];

        $this->writer->writePage(1, $payload);
        $this->writer->writeBlock(1, $payload);

        $this->assertFalse($this->writer->isAvailable());
    }

    /**
     * The menu and instance component tables ship with modules that are not installed here, and an absent module
     * is precisely the case where a reference cannot resolve, so a missing table has to read as "nothing of that
     * kind exists" rather than as a reason to skip the check.
     */
    public function testValidatorReportsUnresolvableReferencesWhenTheTablesAreAbsent(): void
    {
        $payload = [
            'published_content' => json_encode([
                'content' => [
                    'uid-menu' => ['component' => 'hyva_menu_widget', 'menu_identifier' => 'missing-menu'],
                    'uid-instance' => ['component' => 'instance/missing-component']
                ]
            ]),
            'tailwindcss' => []
        ];

        $warnings = $this->validator->validate('page "some-page"', $payload);

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('missing-menu', implode("\n", $warnings));
        $this->assertStringContainsString('missing-component', implode("\n", $warnings));
    }

    public function testValidatorWarnsAboutAThemeThisInstallDoesNotHave(): void
    {
        $payload = [
            'published_content' => '{"content":{}}',
            'tailwindcss' => [
                ['theme' => 'frontend/Acme/nonexistent', 'edition' => 'published', 'css' => '.a{color:red}']
            ]
        ];

        $warnings = $this->validator->validate('page "some-page"', $payload);

        $this->assertStringContainsString('frontend/Acme/nonexistent', implode("\n", $warnings));
    }

    /**
     * The single most important property of the flag: with Hyva CMS absent it changes nothing at all, so an
     * install that never had Hyva keeps the behaviour it always had.
     *
     * @throws FileSystemException
     * @magentoDataFixture Magento/Cms/_files/block.php
     * @magentoDataFixture Magento/Cms/_files/noroute.php
     */
    public function testExportWithTheFlagOnIsIdenticalToTheFlagOff(): void
    {
        $this->exporter->execute(['block', 'page'], null, true);
        $withoutFlag = $this->readExportTree();

        $this->exporter->execute(['block', 'page'], null, true, true);
        $withFlag = $this->readExportTree();

        $this->assertNotEmpty($withoutFlag, 'The fixtures must produce an export, or this proves nothing.');
        $this->assertSame($withoutFlag, $withFlag);
    }

    /**
     * @throws FileSystemException
     * @magentoDataFixture Magento/Cms/_files/block.php
     * @magentoDataFixture Magento/Cms/_files/noroute.php
     */
    public function testNoSiblingFileIsWrittenWhenHyvaCmsIsAbsent(): void
    {
        $this->exporter->execute(['block', 'page'], null, true, true);

        foreach (array_keys($this->readExportTree()) as $path) {
            $this->assertStringEndsNotWith('.hyva.json', $path);
        }
    }

    /**
     * The native content is only redundant for an entity Hyva CMS actually renders. With Hyva absent nothing is
     * Hyva managed, so every .html has to keep carrying the content it always did.
     *
     * @throws FileSystemException
     * @magentoDataFixture Magento/Cms/_files/block.php
     * @magentoDataFixture Magento/Cms/_files/noroute.php
     */
    public function testNativeContentIsKeptWhenHyvaCmsIsAbsent(): void
    {
        $this->exporter->execute(['block', 'page'], null, true, true);

        $htmlFiles = array_filter(
            $this->readExportTree(),
            static fn (string $path): bool => str_ends_with($path, '.html'),
            ARRAY_FILTER_USE_KEY
        );

        $this->assertNotEmpty($htmlFiles, 'The fixtures must produce html files, or this proves nothing.');
        foreach ($htmlFiles as $path => $contents) {
            $this->assertNotSame('', $contents, "$path lost its native content");
        }
    }

    /**
     * @return array<string, string> relative path to contents, sorted so comparisons are order independent
     * @throws FileSystemException
     */
    private function readExportTree(): array
    {
        $tree = [];
        foreach ($this->varDirectory->readRecursively('sync_cms_data') as $path) {
            if (!$this->varDirectory->isFile($path)) {
                continue;
            }

            $tree[$path] = $this->varDirectory->readFile($path);
        }
        ksort($tree);

        return $tree;
    }
}
