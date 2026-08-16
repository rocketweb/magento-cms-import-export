<?php

declare(strict_types=1);

namespace RocketWeb\CmsImportExport\Test\Integration\HyvaCms;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use RocketWeb\CmsImportExport\Model\Service\HyvaCms\ContentReader;
use RocketWeb\CmsImportExport\Model\Service\HyvaCms\ContentWriter;

/**
 * Covers the path that only exists when Hyva CMS is installed.
 *
 * Hyva Commerce is a licensed package, so a public CI install cannot pull it and every test here skips there. They
 * run on a developer install that has it. The two orderings asserted below are the reason this file exists: both
 * are silent when wrong, and the loss only shows up later as a missing draft.
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class HyvaCmsPresentTest extends TestCase
{
    private const PROVIDER = \Hyva\CmsLiveviewEditor\Model\Provider::class;
    private const ENTITY_TYPE_PAGE = 'cms_page';

    private ?ContentReader $reader = null;
    private ?ContentWriter $writer = null;
    private ?PageRepositoryInterface $pageRepository = null;

    protected function setUp(): void
    {
        if (!interface_exists(\Hyva\CmsMagento\Api\PageRepositoryInterface::class)) {
            $this->markTestSkipped('Hyva CMS is not installed, the layer under test does not exist here.');
        }

        $objectManager = Bootstrap::getObjectManager();
        $this->reader = $objectManager->create(ContentReader::class);
        $this->writer = $objectManager->create(ContentWriter::class);
        $this->pageRepository = $objectManager->create(PageRepositoryInterface::class);
    }

    public function testReaderAndWriterReportThemselvesAvailable(): void
    {
        $this->assertTrue($this->reader->isAvailable());
        $this->assertTrue($this->writer->isAvailable());
    }

    /**
     * @magentoDataFixture Magento/Cms/_files/pages.php
     */
    public function testReadReturnsNullForAPageThatIsNotHyvaManaged(): void
    {
        $this->assertNull($this->reader->readPage($this->getNativePageId('page100')));
    }

    /**
     * @magentoDataFixture Magento/Cms/_files/pages.php
     */
    public function testWrittenContentComesBackUnchanged(): void
    {
        $pageId = $this->getNativePageId('page100');
        $published = $this->buildContent('published-heading');
        $draft = $this->buildContent('draft-heading');

        $this->writer->writePage($pageId, [
            'is_liveview_enabled' => true,
            'is_tailwindcss_jit_enabled' => true,
            'published_content' => $published,
            'draft_content' => $draft,
            'tailwindcss' => []
        ]);

        $payload = $this->reader->readPage($pageId);

        $this->assertNotNull($payload);
        $this->assertTrue($payload['is_liveview_enabled']);
        $this->assertSame($published, $payload['published_content']);
        $this->assertSame($draft, $payload['draft_content']);
    }

    /**
     * Provider::saveContent() with publish true writes published_content AND draft_content, so a draft written
     * first is overwritten by the publish that follows it. The writer orders published first for that reason.
     *
     * @magentoDataFixture Magento/Cms/_files/pages.php
     */
    public function testDraftSurvivesThePublishedWrite(): void
    {
        $pageId = $this->getNativePageId('page100');
        $published = $this->buildContent('published-heading');
        $draft = $this->buildContent('draft-heading');

        $this->writer->writePage($pageId, [
            'published_content' => $published,
            'draft_content' => $draft,
            'tailwindcss' => []
        ]);

        $payload = $this->reader->readPage($pageId);

        $this->assertNotSame(
            $payload['published_content'],
            $payload['draft_content'],
            'The draft was overwritten by the publish, so the write order is reversed.'
        );
        $this->assertStringContainsString('draft-heading', $payload['draft_content']);
    }

    /**
     * JitCssRepository::saveStyles() deletes the draft rows along with the published ones when it is called for
     * the published edition, so draft CSS written first is deleted. Four rows in, four rows out.
     *
     * @magentoDataFixture Magento/Cms/_files/pages.php
     */
    public function testDraftCssRowsSurviveThePublishedWrite(): void
    {
        $pageId = $this->getNativePageId('page100');
        $rows = [
            ['theme' => 'frontend/Magento/luma', 'edition' => 'published', 'css' => '/*pub-luma*/.a{}'],
            ['theme' => 'frontend/Magento/blank', 'edition' => 'published', 'css' => '/*pub-blank*/.a{}'],
            ['theme' => 'frontend/Magento/luma', 'edition' => 'draft', 'css' => '/*draft-luma*/.a{}'],
            ['theme' => 'frontend/Magento/blank', 'edition' => 'draft', 'css' => '/*draft-blank*/.a{}']
        ];

        $this->writer->writePage($pageId, [
            'published_content' => $this->buildContent('heading'),
            'draft_content' => $this->buildContent('heading'),
            'tailwindcss' => $rows
        ]);

        $stored = $this->reader->readPage($pageId)['tailwindcss'];

        $this->assertCount(4, $stored, 'A draft row was deleted, so the CSS write order is reversed.');
        $this->assertSame(
            ['frontend/Magento/blank|draft', 'frontend/Magento/blank|published',
             'frontend/Magento/luma|draft', 'frontend/Magento/luma|published'],
            array_map(static fn (array $row): string => $row['theme'] . '|' . $row['edition'], $stored)
        );
    }

    /**
     * Re-importing the same payload must not hit the UNIQUE (entity_ref_id, theme, edition) key.
     *
     * @magentoDataFixture Magento/Cms/_files/pages.php
     */
    public function testWritingTwiceIsIdempotent(): void
    {
        $pageId = $this->getNativePageId('page100');
        $payload = [
            'published_content' => $this->buildContent('heading'),
            'draft_content' => $this->buildContent('heading'),
            'tailwindcss' => [
                ['theme' => 'frontend/Magento/luma', 'edition' => 'published', 'css' => '.a{}']
            ]
        ];

        $this->writer->writePage($pageId, $payload);
        $first = $this->reader->readPage($pageId);

        $this->writer->writePage($pageId, $payload);
        $second = $this->reader->readPage($pageId);

        $this->assertSame($first, $second);
    }

    /**
     * The Tailwind tables key on the native cms_page_id, not on the id of the Hyva row. The two differ, and
     * passing the wrong one silently stores rows that nothing reads.
     *
     * @magentoDataFixture Magento/Cms/_files/pages.php
     */
    public function testCssIsKeyedOnTheNativePageId(): void
    {
        $pageId = $this->getNativePageId('page100');
        $this->writer->writePage($pageId, [
            'published_content' => $this->buildContent('heading'),
            'draft_content' => $this->buildContent('heading'),
            'tailwindcss' => [
                ['theme' => 'frontend/Magento/luma', 'edition' => 'published', 'css' => '.marker{}']
            ]
        ]);

        $jitCssRepository = Bootstrap::getObjectManager()
            ->create(\Hyva\CmsLiveviewEditor\Model\Tailwind\JitCssRepository::class);

        $this->assertSame(
            ['frontend/Magento/luma' => '.marker{}'],
            $jitCssRepository->getAllThemeStyles(self::ENTITY_TYPE_PAGE, $pageId, 'published')
        );
    }

    private function buildContent(string $headingText): string
    {
        return json_encode([
            'componentPath' => 'elements/ROOT.phtml',
            'contentType' => self::ENTITY_TYPE_PAGE,
            'content' => [
                'uid-heading' => [
                    'uid' => 'uid-heading',
                    'component' => 'heading',
                    'children' => [],
                    'tag' => 'h2',
                    'text' => $headingText
                ]
            ]
        ]);
    }

    private function getNativePageId(string $identifier): int
    {
        /** @var PageInterface $page */
        $page = Bootstrap::getObjectManager()
            ->create(\Magento\Cms\Api\GetPageByIdentifierInterface::class)
            ->execute($identifier, 0);

        return (int)$page->getId();
    }
}
