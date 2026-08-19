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

namespace RocketWeb\CmsImportExport\Model\Service\HyvaCms;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;

/**
 * Swaps category entity ids for url paths on the way out, and back again on the way in.
 *
 * A menu addresses a CMS page by identifier and a product by SKU, both of which mean the same thing on every
 * install. A category link is the exception: it stores the entity id, and ids are assigned per environment, so
 * an exported menu would point at whatever category happens to hold that id on the target.
 *
 * url_path is the key used here rather than name or url_key. Names collide freely, and Magento only enforces
 * url_key uniqueness between siblings, so two branches can each hold "envelopes". url_path is the concatenation
 * of the ancestor url keys, so it is unique by construction and still readable in a diff.
 *
 * Paths are read at the default store scope, because that is the value every store inherits unless it has been
 * overridden, and a menu file is not store specific beyond the scope in its name.
 *
 * Anything that does not resolve is reported and left as it stands. That matches how a missing block or theme is
 * handled elsewhere in this module: the import completes, and the warning says what will not render.
 */
class CategoryLinkMapper
{
    private const LINK_TYPE_KEY = 'type';
    private const LINK_TYPE_CATEGORY = 'category';
    private const LINK_VALUE_KEY = 'value';
    private const CATEGORY_TREE_IDS_KEY = 'category_ids';
    private const ROOT_KEYS = ['content', 'store_content'];
    private const MAX_DEPTH = 100;

    /**
     * @var array<int, string>|null
     */
    private ?array $pathById = null;

    /**
     * @var array<string, int>|null
     */
    private ?array $idByPath = null;

    public function __construct(
        private readonly CollectionFactory $categoryCollectionFactory
    ) {
    }

    /**
     * Export direction: every category id becomes its url path.
     *
     * @param string|null $contentJson
     * @param array<int, string> $warnings collected by reference, one line per id that has no category
     * @return string|null the rewritten json, or the input unchanged when there is nothing to rewrite
     */
    public function idsToPaths(?string $contentJson, array &$warnings): ?string
    {
        return $this->rewrite($contentJson, $warnings, true);
    }

    /**
     * Import direction: every url path becomes the local category id.
     *
     * @param string|null $contentJson
     * @param array<int, string> $warnings collected by reference, one line per path this install does not have
     * @return string|null the rewritten json, or the input unchanged when there is nothing to rewrite
     */
    public function pathsToIds(?string $contentJson, array &$warnings): ?string
    {
        return $this->rewrite($contentJson, $warnings, false);
    }

    /**
     * @param array<int, string> $warnings
     */
    private function rewrite(?string $contentJson, array &$warnings, bool $toPaths): ?string
    {
        if ($contentJson === null || $contentJson === '') {
            return $contentJson;
        }

        $content = json_decode($contentJson, true);
        if (!is_array($content)) {
            return $contentJson;
        }

        $changed = false;
        foreach (self::ROOT_KEYS as $rootKey) {
            if (!isset($content[$rootKey]) || !is_array($content[$rootKey])) {
                continue;
            }

            $content[$rootKey] = $this->walk($content[$rootKey], $warnings, $toPaths, $changed, 0);
        }

        if (!$changed) {
            return $contentJson;
        }

        return json_encode($content, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<array-key, mixed> $node
     * @param array<int, string> $warnings
     * @return array<array-key, mixed>
     */
    private function walk(array $node, array &$warnings, bool $toPaths, bool &$changed, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return $node;
        }

        if ($this->isCategoryLink($node)) {
            $mapped = $this->mapOne((string)$node[self::LINK_VALUE_KEY], $warnings, $toPaths);
            if ($mapped !== null) {
                $node[self::LINK_VALUE_KEY] = $mapped;
                $changed = true;
            }

            return $node;
        }

        if (isset($node[self::CATEGORY_TREE_IDS_KEY]) && is_string($node[self::CATEGORY_TREE_IDS_KEY])) {
            $mapped = $this->mapList($node[self::CATEGORY_TREE_IDS_KEY], $warnings, $toPaths);
            if ($mapped !== null) {
                $node[self::CATEGORY_TREE_IDS_KEY] = $mapped;
                $changed = true;
            }
        }

        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            $node[$key] = $this->walk($value, $warnings, $toPaths, $changed, $depth + 1);
        }

        return $node;
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private function isCategoryLink(array $node): bool
    {
        return ($node[self::LINK_TYPE_KEY] ?? null) === self::LINK_TYPE_CATEGORY
            && isset($node[self::LINK_VALUE_KEY])
            && (is_string($node[self::LINK_VALUE_KEY]) || is_int($node[self::LINK_VALUE_KEY]));
    }

    /**
     * The comma separated list a category tree component stores. A list is rewritten whole or not at all, so that
     * a partial failure cannot silently drop entries from a tree.
     *
     * @param array<int, string> $warnings
     */
    private function mapList(string $value, array &$warnings, bool $toPaths): ?string
    {
        $mapped = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }

            $one = $this->mapOne($item, $warnings, $toPaths);
            if ($one === null) {
                return null;
            }

            $mapped[] = $one;
        }

        return $mapped === [] ? null : implode(',', $mapped);
    }

    /**
     * @param array<int, string> $warnings
     */
    private function mapOne(string $value, array &$warnings, bool $toPaths): ?string
    {
        if ($toPaths) {
            $path = $this->getPathById()[(int)$value] ?? null;
            if ($path === null) {
                $warnings[] = sprintf('category link %s has no category on this install, left as it is', $value);
                return null;
            }

            return $path;
        }

        $id = $this->getIdByPath()[$value] ?? null;
        if ($id === null) {
            $warnings[] = sprintf('category path "%s" does not exist on this install, left as it is', $value);
            return null;
        }

        return (string)$id;
    }

    /**
     * @return array<int, string>
     */
    private function getPathById(): array
    {
        if ($this->pathById === null) {
            $this->loadCategories();
        }

        return $this->pathById;
    }

    /**
     * @return array<string, int>
     */
    private function getIdByPath(): array
    {
        if ($this->idByPath === null) {
            $this->loadCategories();
        }

        return $this->idByPath;
    }

    private function loadCategories(): void
    {
        $this->pathById = [];
        $this->idByPath = [];

        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId(0);
        $collection->addAttributeToSelect('url_path');

        foreach ($collection as $category) {
            $path = $category->getData('url_path');
            if (!is_string($path) || $path === '') {
                continue;
            }

            $id = (int)$category->getId();
            $this->pathById[$id] = $path;
            $this->idByPath[$path] = $id;
        }
    }
}
