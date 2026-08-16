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

/**
 * Collects the cross entity identifiers a Hyva CMS content tree depends on.
 *
 * A missing dependency renders as nothing at all, with no exception and no log entry, so recording what an entity
 * depends on at export time is what lets the importer say so out loud.
 *
 * The walk starts at content and store_content and descends through every nested array, because the levels carry
 * different shapes: content is an object keyed by uid, children is a plain list, and a field value sits flat on
 * its node rather than under a fields wrapper. Hyva's own ContentProcessor::processForExport() iterates content
 * only and loses every reference below the first level. The version key holds editing metadata and is skipped.
 *
 * The result is diagnostic. The importer re-derives references from the content it actually imported and compares,
 * so a hand edited file cannot misreport its own dependencies.
 */
class ReferenceCollector
{
    public const KIND_CMS_BLOCK = 'cms_block';
    public const KIND_DIRECTIVE = 'directive';
    public const KIND_INSTANCE_COMPONENT = 'instance_component';
    public const KIND_MENU = 'menu';

    private const KINDS = [
        self::KIND_CMS_BLOCK,
        self::KIND_DIRECTIVE,
        self::KIND_INSTANCE_COMPONENT,
        self::KIND_MENU
    ];
    private const FIELD_KINDS = [
        'block_identifier' => self::KIND_CMS_BLOCK,
        'menu_identifier' => self::KIND_MENU
    ];
    private const ROOT_KEYS = ['content', 'store_content'];
    private const COMPONENT_KEY = 'component';
    private const INSTANCE_PREFIX = 'instance/';
    private const DIRECTIVE_PATTERN = '/\{\{(?:widget|block|media)\b.*?\}\}/s';
    private const MAX_DEPTH = 100;

    /**
     * @return array<string, array<int, string>> every kind is present, empty ones included
     */
    public function collect(?string $contentJson): array
    {
        return $this->collectFromAll([$contentJson]);
    }

    /**
     * Draft and published content are merged here rather than by the caller, so that the sort and deduplicate
     * invariant that keeps the exported file byte identical between runs lives in one place.
     *
     * @param array<int, string|null> $contentJsonList
     * @return array<string, array<int, string>> every kind is present, empty ones included
     */
    public function collectFromAll(array $contentJsonList): array
    {
        $references = array_fill_keys(self::KINDS, []);
        foreach ($contentJsonList as $contentJson) {
            $this->collectInto($contentJson, $references);
        }

        foreach ($references as $kind => $identifiers) {
            $identifiers = array_values(array_unique($identifiers));
            sort($identifiers, SORT_STRING);
            $references[$kind] = $identifiers;
        }

        return $references;
    }

    /**
     * Malformed content yields nothing instead of throwing, so one unreadable draft cannot abort a whole export.
     *
     * @param array<string, array<int, string>> $references
     */
    private function collectInto(?string $contentJson, array &$references): void
    {
        if ($contentJson === null || $contentJson === '') {
            return;
        }

        $content = json_decode($contentJson, true);
        if (!is_array($content)) {
            return;
        }

        foreach (self::ROOT_KEYS as $rootKey) {
            if (!isset($content[$rootKey]) || !is_array($content[$rootKey])) {
                continue;
            }

            $this->walk($content[$rootKey], $references, 0);
        }
    }

    /**
     * Merchant authored content nests without a declared limit, so the descent stops at MAX_DEPTH and anything
     * below goes uncollected. It is the tighter of the two guards, json_decode refusing 512 levels being the other.
     *
     * @param array<array-key, mixed> $node
     * @param array<string, array<int, string>> $references
     */
    private function walk(array $node, array &$references, int $depth): void
    {
        if ($depth >= self::MAX_DEPTH) {
            return;
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->walk($value, $references, $depth + 1);
                continue;
            }

            if (!is_string($value) || $value === '') {
                continue;
            }

            $this->collectFromValue($key, $value, $references);
        }
    }

    /**
     * @param array<string, array<int, string>> $references
     */
    private function collectFromValue(int|string $key, string $value, array &$references): void
    {
        if (isset(self::FIELD_KINDS[$key])) {
            $references[self::FIELD_KINDS[$key]][] = $value;
        }

        if ($key === self::COMPONENT_KEY && str_starts_with($value, self::INSTANCE_PREFIX)) {
            $references[self::KIND_INSTANCE_COMPONENT][] = substr($value, strlen(self::INSTANCE_PREFIX));
        }

        if (preg_match_all(self::DIRECTIVE_PATTERN, $value, $matches) < 1) {
            return;
        }

        foreach ($matches[0] as $directive) {
            $references[self::KIND_DIRECTIVE][] = $directive;
        }
    }
}
