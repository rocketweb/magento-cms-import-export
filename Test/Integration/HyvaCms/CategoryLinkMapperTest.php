<?php

declare(strict_types=1);

namespace RocketWeb\CmsImportExport\Test\Integration\HyvaCms;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use RocketWeb\CmsImportExport\Model\Service\HyvaCms\CategoryLinkMapper;

/**
 * Category ids are assigned per environment, so a menu that stores one points at a different category after an
 * import. These tests pin the swap to url_path and the reporting of anything that does not resolve.
 *
 * The mapper is plain catalog code with no Hyva dependency, so this suite runs everywhere rather than skipping
 * on an install without Hyva CMS.
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CategoryLinkMapperTest extends TestCase
{
    private ?CategoryLinkMapper $mapper = null;
    private ?CategoryRepositoryInterface $categoryRepository = null;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->mapper = $objectManager->create(CategoryLinkMapper::class);
        $this->categoryRepository = $objectManager->create(CategoryRepositoryInterface::class);
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/category.php
     */
    public function testCategoryLinkRoundTripsThroughItsUrlPath(): void
    {
        $categoryId = 333;
        $urlPath = $this->givenCategoryUrlPath($categoryId);

        $content = $this->buildContent(['type' => 'category', 'value' => (string)$categoryId]);

        $warnings = [];
        $exported = $this->mapper->idsToPaths($content, $warnings);
        $this->assertSame([], $warnings);
        $this->assertSame($urlPath, $this->getLinkValue($exported));

        $warnings = [];
        $imported = $this->mapper->pathsToIds($exported, $warnings);
        $this->assertSame([], $warnings);
        $this->assertSame((string)$categoryId, $this->getLinkValue($imported));
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/category.php
     */
    public function testCategoryTreeIdsRoundTrip(): void
    {
        $categoryId = 333;
        $urlPath = $this->givenCategoryUrlPath($categoryId);

        $content = json_encode([
            'content' => [
                ['uid' => 'tree', 'component' => 'hyva_menu_category_tree', 'category_ids' => (string)$categoryId]
            ]
        ]);

        $warnings = [];
        $exported = $this->mapper->idsToPaths($content, $warnings);
        $this->assertStringContainsString($urlPath, (string)$exported);

        $imported = json_decode((string)$this->mapper->pathsToIds($exported, $warnings), true);
        $this->assertSame((string)$categoryId, $imported['content'][0]['category_ids']);
    }

    public function testUnknownPathIsReportedAndLeftAlone(): void
    {
        $content = $this->buildContent(['type' => 'category', 'value' => 'no/such/category']);

        $warnings = [];
        $result = $this->mapper->pathsToIds($content, $warnings);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('no/such/category', $warnings[0]);
        $this->assertSame('no/such/category', $this->getLinkValue($result));
    }

    /**
     * A CMS page link already carries a portable identifier, so it has to come through untouched.
     */
    public function testNonCategoryLinksAreNotRewritten(): void
    {
        $content = $this->buildContent(['type' => 'cms_page', 'value' => 'about-us']);

        $warnings = [];
        $this->assertSame($content, $this->mapper->idsToPaths($content, $warnings));
        $this->assertSame($content, $this->mapper->pathsToIds($content, $warnings));
        $this->assertSame([], $warnings);
    }

    public function testEmptyContentIsReturnedUnchanged(): void
    {
        $warnings = [];

        $this->assertNull($this->mapper->idsToPaths(null, $warnings));
        $this->assertSame('', $this->mapper->pathsToIds('', $warnings));
        $this->assertSame('not json', $this->mapper->idsToPaths('not json', $warnings));
        $this->assertSame([], $warnings);
    }

    /**
     * The fixture sets no url key, and url_path generation depends on rewrite observers that this suite has no
     * reason to rely on, so the value under test is written explicitly.
     */
    private function givenCategoryUrlPath(int $categoryId): string
    {
        $urlPath = 'rw-mapper-test-category';
        $category = $this->categoryRepository->get($categoryId);
        $category->setData('url_key', $urlPath);
        $category->setData('url_path', $urlPath);
        $this->categoryRepository->save($category);

        return $urlPath;
    }

    /**
     * @param array<string, string> $link
     */
    private function buildContent(array $link): string
    {
        return (string)json_encode([
            'content' => [
                ['uid' => 'item', 'component' => 'hyva_menu_item', 'link' => $link]
            ]
        ]);
    }

    private function getLinkValue(?string $contentJson): ?string
    {
        return json_decode((string)$contentJson, true)['content'][0]['link']['value'] ?? null;
    }
}
