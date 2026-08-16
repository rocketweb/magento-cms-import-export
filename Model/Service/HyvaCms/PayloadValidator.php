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

use Magento\Framework\App\ResourceConnection;
use Magento\Theme\Model\ResourceModel\Theme\CollectionFactory as ThemeCollectionFactory;

/**
 * Checks an imported Hyva CMS payload against the install it landed in and reports what will not work.
 *
 * Nothing here fails an import, because both failures it reports are otherwise silent on the storefront:
 * Element::renderComponentNotFound() returns an empty string outside preview, and CSS for an unregistered theme
 * is never selected.
 *
 * References are re-derived from the imported content rather than read from the payload's references key, so a
 * hand edited file cannot misreport what it depends on. A missing table counts as "nothing of that kind exists",
 * since Hyva_MenuBuilder and Hyva_CmsInstanceComponents ship separately and an absent module is exactly the case
 * where the reference cannot resolve. A target only counts as resolved when it is active: all three kinds render
 * as nothing when disabled.
 */
class PayloadValidator
{
    private const CMS_BLOCK_TABLE = 'cms_block';
    private const MENU_TABLE = 'hyva_commerce_cms_menu';
    private const INSTANCE_COMPONENT_TABLE = 'hyva_cms_instance_component';
    private const MISSING_REASON = [
        ReferenceCollector::KIND_CMS_BLOCK => 'no active CMS block carries that identifier',
        ReferenceCollector::KIND_MENU => 'no active Hyva CMS menu carries that identifier',
        ReferenceCollector::KIND_INSTANCE_COMPONENT => 'no active instance component carries that identifier'
    ];

    private bool $deltaWarningEmitted = false;
    private ?array $registeredThemes = null;

    public function __construct(
        private readonly ReferenceCollector $referenceCollector,
        private readonly ResourceConnection $resourceConnection,
        private readonly ThemeCollectionFactory $themeCollectionFactory
    ) {
    }

    /**
     * @param string $entityLabel names the entity the payload was applied to, for example page "contact-us"
     * @param array<string, mixed> $payload the decoded .hyva.json sibling file
     * @return array<int, string> one unprefixed line per problem, empty when there is nothing to report
     */
    public function validate(string $entityLabel, array $payload): array
    {
        $references = $this->referenceCollector->collectFromAll([
            $payload['draft_content'] ?? null,
            $payload['published_content'] ?? null
        ]);

        $tailwindcss = $payload['tailwindcss'] ?? [];

        return array_merge(
            $this->validateReferences($entityLabel, $references),
            $this->validateThemes($entityLabel, is_array($tailwindcss) ? $tailwindcss : [])
        );
    }

    /**
     * Directives are collected by the reference collector but not checked here, because a widget or block
     * directive resolves through the whole layout and widget stack rather than through one identifier column.
     *
     * @param array<string, array<int, string>> $references
     * @return array<int, string>
     */
    private function validateReferences(string $entityLabel, array $references): array
    {
        $existing = [
            ReferenceCollector::KIND_CMS_BLOCK => $this->findActive(
                self::CMS_BLOCK_TABLE,
                $references[ReferenceCollector::KIND_CMS_BLOCK] ?? []
            ),
            ReferenceCollector::KIND_MENU => $this->findActive(
                self::MENU_TABLE,
                $references[ReferenceCollector::KIND_MENU] ?? []
            ),
            ReferenceCollector::KIND_INSTANCE_COMPONENT => $this->findActive(
                self::INSTANCE_COMPONENT_TABLE,
                $references[ReferenceCollector::KIND_INSTANCE_COMPONENT] ?? []
            )
        ];

        $warnings = [];
        foreach ($existing as $kind => $identifiers) {
            foreach ($references[$kind] ?? [] as $reference) {
                if (in_array($reference, $identifiers, true)) {
                    continue;
                }

                $warnings[] = sprintf(
                    '%s references %s "%s" but %s, it will render as nothing',
                    $entityLabel,
                    $kind,
                    $reference,
                    self::MISSING_REASON[$kind]
                );
            }
        }

        return $warnings;
    }

    /**
     * @param array<int, string> $identifiers
     * @return array<int, string> the subset of $identifiers that resolves and is active in this install
     */
    private function findActive(string $table, array $identifiers): array
    {
        if ($identifiers === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName($table);
        if (!$connection->isTableExists($tableName)) {
            return [];
        }

        $select = $connection->select()
            ->from($tableName, ['identifier'])
            ->where('identifier IN (?)', $identifiers)
            ->where('is_active = ?', 1);

        return array_map('strval', $connection->fetchCol($select));
    }

    /**
     * @param array<int, array{theme: string, edition: string, css: string}> $rows
     * @return array<int, string>
     */
    private function validateThemes(string $entityLabel, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $warnings = [];
        if (!$this->deltaWarningEmitted) {
            $this->deltaWarningEmitted = true;
            $warnings[] = 'imported Tailwind CSS is a delta against the compiled styles.css of the theme it was'
                . ' generated for, so it is only correct while the target theme build matches the source. Promoting'
                . ' one codebase between environments is what this supports, moving content between unrelated'
                . ' projects is not.';
        }

        $registeredThemes = $this->getRegisteredThemes();
        foreach ($this->collectThemes($rows) as $theme) {
            if (in_array($theme, $registeredThemes, true)) {
                continue;
            }

            $warnings[] = sprintf(
                '%s carries Tailwind CSS for theme %s which is not registered here, so that CSS will never load.'
                . ' Registered frontend themes: %s',
                $entityLabel,
                $theme,
                $registeredThemes === [] ? '(none)' : implode(', ', $registeredThemes)
            );
        }

        return $warnings;
    }

    /**
     * @param array<int, array{theme: string, edition: string, css: string}> $rows
     * @return array<int, string>
     */
    private function collectThemes(array $rows): array
    {
        $themes = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['theme'])) {
                continue;
            }

            $themes[] = (string)$row['theme'];
        }

        $themes = array_values(array_unique($themes));
        sort($themes, SORT_STRING);

        return $themes;
    }

    /**
     * @return array<int, string> full paths such as frontend/Cenveo/cms
     */
    private function getRegisteredThemes(): array
    {
        if ($this->registeredThemes !== null) {
            return $this->registeredThemes;
        }

        $collection = $this->themeCollectionFactory->create();
        $collection->addAreaFilter(\Magento\Framework\App\Area::AREA_FRONTEND);

        $themes = [];
        foreach ($collection as $theme) {
            $fullPath = $theme->getFullPath();
            if ($fullPath === null || $fullPath === '') {
                continue;
            }

            $themes[] = $fullPath;
        }

        sort($themes, SORT_STRING);
        $this->registeredThemes = $themes;

        return $this->registeredThemes;
    }
}
