<?php

declare(strict_types=1);

namespace RocketWeb\CmsImportExport\Test\Integration\HyvaCms;

use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use RocketWeb\CmsImportExport\Model\Service\HyvaCms\ReferenceCollector;

/**
 * The collector reads a stored Hyva CMS content tree, so the fixtures below use the real shape rather than a
 * simplified one: content is an object keyed by uid, children is a plain list, and a field value sits flat on its
 * node rather than under a fields wrapper.
 */
class ReferenceCollectorTest extends TestCase
{
    private ?ReferenceCollector $collector = null;

    protected function setUp(): void
    {
        $this->collector = Bootstrap::getObjectManager()->create(ReferenceCollector::class);
    }

    public function testEveryKindIsPresentSoCallersNeedNoKeyCheck(): void
    {
        $references = $this->collector->collect(null);

        $this->assertSame(
            [
                ReferenceCollector::KIND_CMS_BLOCK,
                ReferenceCollector::KIND_DIRECTIVE,
                ReferenceCollector::KIND_INSTANCE_COMPONENT,
                ReferenceCollector::KIND_MENU
            ],
            array_keys($references)
        );

        foreach ($references as $identifiers) {
            $this->assertSame([], $identifiers);
        }
    }

    public function getMalformedContentCases(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'invalid json' => ['{not json'],
            'json scalar' => ['42'],
            'json without a content key' => ['{"version":{"author":"someone"}}']
        ];
    }

    /**
     * One unreadable draft must not abort an export of every page and block in the catalogue.
     *
     * @dataProvider getMalformedContentCases
     */
    public function testMalformedContentYieldsNothingAndDoesNotThrow(?string $contentJson): void
    {
        $references = $this->collector->collect($contentJson);

        $this->assertCount(4, $references);
        foreach ($references as $identifiers) {
            $this->assertSame([], $identifiers);
        }
    }

    /**
     * Hyva's own ContentProcessor::processForExport() iterates the top level only, so a reference nested inside a
     * row is exactly the case that gets lost. It is the reason this collector exists.
     */
    public function testReferenceNestedInsideChildrenIsCollected(): void
    {
        $content = json_encode([
            'content' => [
                'uid-row' => [
                    'uid' => 'uid-row',
                    'component' => 'row',
                    'children' => [
                        [
                            'uid' => 'uid-block',
                            'component' => 'cms_block',
                            'children' => [],
                            'block_identifier' => 'nested-block'
                        ]
                    ]
                ]
            ]
        ]);

        $references = $this->collector->collect($content);

        $this->assertSame(['nested-block'], $references[ReferenceCollector::KIND_CMS_BLOCK]);
    }

    public function testMenuInstanceComponentAndDirectiveAreCollected(): void
    {
        $content = json_encode([
            'content' => [
                'uid-menu' => [
                    'component' => 'hyva_menu_widget',
                    'menu_identifier' => 'main-menu'
                ],
                'uid-instance' => [
                    'component' => 'instance/promo-banner'
                ],
                'uid-html' => [
                    'component' => 'html',
                    'html' => 'before {{widget type="Foo\Bar" template="x.phtml"}} after'
                ]
            ]
        ]);

        $references = $this->collector->collect($content);

        $this->assertSame(['main-menu'], $references[ReferenceCollector::KIND_MENU]);
        $this->assertSame(['promo-banner'], $references[ReferenceCollector::KIND_INSTANCE_COMPONENT]);
        $this->assertSame(
            ['{{widget type="Foo\Bar" template="x.phtml"}}'],
            $references[ReferenceCollector::KIND_DIRECTIVE]
        );
    }

    /**
     * A store specific reference that goes uncollected is a blank on one store view only, which is the hardest
     * kind of gap to find.
     */
    public function testStoreContentIsWalked(): void
    {
        $content = json_encode([
            'content' => [],
            'store_content' => [
                '3' => [
                    'uid-block' => [
                        'component' => 'cms_block',
                        'block_identifier' => 'store-scoped-block'
                    ]
                ]
            ]
        ]);

        $references = $this->collector->collect($content);

        $this->assertSame(['store-scoped-block'], $references[ReferenceCollector::KIND_CMS_BLOCK]);
    }

    /**
     * The result lands in a git tracked file, so a set that reorders or repeats between runs produces phantom
     * diffs and defeats the point of the module.
     */
    public function testResultIsDeduplicatedSortedAndStable(): void
    {
        $content = json_encode([
            'content' => [
                'uid-a' => ['component' => 'cms_block', 'block_identifier' => 'zebra'],
                'uid-b' => ['component' => 'cms_block', 'block_identifier' => 'alpha'],
                'uid-c' => [
                    'component' => 'row',
                    'children' => [
                        ['component' => 'cms_block', 'block_identifier' => 'zebra']
                    ]
                ]
            ]
        ]);

        $first = $this->collector->collect($content);
        $second = $this->collector->collect($content);

        $this->assertSame(['alpha', 'zebra'], $first[ReferenceCollector::KIND_CMS_BLOCK]);
        $this->assertSame($first, $second);
    }

    /**
     * The version key holds editing metadata rather than content, and it carries strings that would otherwise
     * look like references.
     */
    public function testVersionMetadataIsNotWalked(): void
    {
        $content = json_encode([
            'content' => [],
            'version' => [
                'author' => 'someone',
                'block_identifier' => 'should-not-be-collected'
            ]
        ]);

        $references = $this->collector->collect($content);

        $this->assertSame([], $references[ReferenceCollector::KIND_CMS_BLOCK]);
    }

    /**
     * A draft can depend on an entity the published copy does not, and both editions travel in the same file.
     */
    public function testCollectFromAllMergesEveryEdition(): void
    {
        $draft = json_encode([
            'content' => ['uid-a' => ['component' => 'cms_block', 'block_identifier' => 'draft-only']]
        ]);
        $published = json_encode([
            'content' => ['uid-b' => ['component' => 'cms_block', 'block_identifier' => 'published-only']]
        ]);

        $references = $this->collector->collectFromAll([$draft, $published, null]);

        $this->assertSame(['draft-only', 'published-only'], $references[ReferenceCollector::KIND_CMS_BLOCK]);
    }

    /**
     * Content is merchant authored and nests without a declared limit, so the walk has to stop on its own rather
     * than exhaust the stack.
     */
    public function testDescentStopsAtTheDepthBoundWithoutThrowing(): void
    {
        $node = ['component' => 'cms_block', 'block_identifier' => 'too-deep'];
        for ($level = 0; $level < 120; $level++) {
            $node = ['component' => 'row', 'children' => [$node]];
        }

        $references = $this->collector->collect(json_encode(['content' => ['uid-root' => $node]]));

        $this->assertSame([], $references[ReferenceCollector::KIND_CMS_BLOCK]);
    }
}
