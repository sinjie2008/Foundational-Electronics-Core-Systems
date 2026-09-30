<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use mysqli;
use CatalogSuite\Http\CatalogApiException;

final class LegacySpecSearchService
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private const ROOTS = [
        ['id' => 'general', 'name' => 'General Product', 'default' => true],
        ['id' => 'automotive', 'name' => 'Automotive Product', 'default' => false],
    ];

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private const CATEGORY_GROUPS = [
        'general' => [
            [
                'group' => 'EMC Components',
                'categories' => [
                    ['id' => 'ferrite_chip_bead', 'name' => 'Ferrite Chip Bead'],
                    ['id' => 'ferrite_chip_bead_large', 'name' => 'Ferrite Chip Bead (Large Current)'],
                    ['id' => 'chip_inductor', 'name' => 'Chip Inductor'],
                ],
            ],
            [
                'group' => 'Transformer',
                'categories' => [
                    ['id' => 'planar_transformer', 'name' => 'Planar Transformer'],
                ],
            ],
        ],
        'automotive' => [
            [
                'group' => 'Magnetic Components',
                'categories' => [
                    ['id' => 'magnetic_core', 'name' => 'Magnetic Core'],
                ],
            ],
            [
                'group' => 'Wireless Power Transfer',
                'categories' => [
                    ['id' => 'wireless_power_transfer', 'name' => 'Wireless Power Transfer'],
                ],
            ],
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const FACET_LABELS = [
        'series' => 'Series',
        'inductance' => 'Inductance',
        'current_rating' => 'Current Rating',
        'core_size' => 'Core Size',
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    private const PRODUCTS = [
        [
            'root_id' => 'general',
            'category_ids' => ['ferrite_chip_bead'],
            'sku' => 'ZIK300-RC-10',
            'series' => 'ZIK300-RC-10',
            'attributes' => [
                'inductance' => '2.2uH',
                'current_rating' => '10A',
                'core_size' => '8.0 x 5.0 x 4.0 mm',
            ],
        ],
        [
            'root_id' => 'general',
            'category_ids' => ['chip_inductor'],
            'sku' => 'ZIK200-LC-01',
            'series' => 'ZIK200-LC-01',
            'attributes' => [
                'inductance' => '1.0uH',
                'current_rating' => '4A',
                'core_size' => '4.0 x 4.0 x 2.0 mm',
            ],
        ],
        [
            'root_id' => 'general',
            'category_ids' => ['planar_transformer'],
            'sku' => 'TRF-PT-500',
            'series' => 'TRF-PT-500',
            'attributes' => [
                'inductance' => '10uH',
                'current_rating' => '25A',
                'core_size' => '12.0 x 12.0 x 6.0 mm',
            ],
        ],
        [
            'root_id' => 'automotive',
            'category_ids' => ['magnetic_core'],
            'sku' => 'AUTO-MAG-25',
            'series' => 'AUTO-MAG-25',
            'attributes' => [
                'inductance' => '4.7uH',
                'current_rating' => '15A',
                'core_size' => '10.0 x 8.0 x 5.0 mm',
            ],
        ],
        [
            'root_id' => 'automotive',
            'category_ids' => ['wireless_power_transfer'],
            'sku' => 'WPT-450-MX-01',
            'series' => 'WPT-450-MX-01',
            'attributes' => [
                'inductance' => '3.3uH',
                'current_rating' => '20A',
                'core_size' => '15.0 x 15.0 x 4.5 mm',
            ],
        ],
    ];

    public function __construct(private mysqli $connection)
    {
    }

    /**
     * Returns the available root categories (radio group).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRootCategories(): array
    {
        return self::ROOTS;
    }

    /**
     * Returns grouped category checkboxes for the given root.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listProductCategoryGroups(string $rootId): array
    {
        $this->assertValidRoot($rootId);

        return self::CATEGORY_GROUPS[$rootId] ?? [];
    }

    /**
     * Returns facet cards (Series + ACF attributes) for the selected categories.
     *
     * @param array<int, string> $categoryIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFacets(string $rootId, array $categoryIds): array
    {
        $this->assertValidRoot($rootId);
        if ($categoryIds === []) {
            throw new CatalogApiException(
                'VALIDATION_ERROR',
                'At least one product category must be selected to load Mechanical Parameters.',
                400,
                ['category_ids' => 'Select at least one product category.']
            );
        }

        $products = $this->filterProductsByEnvelope($rootId, $categoryIds, []);

        return $this->buildFacetDefinitions($products);
    }

    /**
     * Returns product rows for the full filter envelope.
     *
     * @param array<int, string> $categoryIds
     * @param array<string, array<int, string>> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchProducts(string $rootId, array $categoryIds, array $filters): array
    {
        $this->assertValidRoot($rootId);
        if ($categoryIds === []) {
            throw new CatalogApiException(
                'VALIDATION_ERROR',
                'At least one product category must be selected before searching products.',
                400,
                ['category_ids' => 'Select at least one product category.']
            );
        }

        $normalizedFilters = $this->normalizeFilters($filters);
        $products = $this->filterProductsByEnvelope($rootId, $categoryIds, $normalizedFilters);

        return array_map(
            static function (array $product): array {
                $sku = (string) $product['sku'];

                return [
                    'sku' => $sku,
                    'series' => (string) $product['series'],
                    'attributes' => $product['attributes'],
                    'editUrl' => 'catalog.php?sku=' . rawurlencode($sku),
                ];
            },
            $products
        );
    }

    /**
     * Ensures a valid root identifier was supplied.
     */
    private function assertValidRoot(string $rootId): void
    {
        foreach (self::ROOTS as $root) {
            if ((string) $root['id'] === $rootId) {
                return;
            }
        }

        throw new CatalogApiException(
            'VALIDATION_ERROR',
            'Unknown root category supplied.',
            400,
            ['root_id' => 'Unknown root category.']
        );
    }

    /**
     * Normalizes category identifiers to trimmed unique strings.
     *
     * @param array<int, string> $categoryIds
     *
     * @return array<int, string>
     */
    private function normalizeCategoryIds(array $categoryIds): array
    {
        $normalized = [];
        foreach ($categoryIds as $id) {
            $string = trim((string) $id);
            if ($string === '') {
                continue;
            }
            $normalized[$string] = true;
        }

        return array_keys($normalized);
    }

    /**
     * Normalizes the facet filters to arrays of strings.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<string, array<int, string>>
     */
    private function normalizeFilters(array $filters): array
    {
        $normalized = [];
        foreach ($filters as $key => $values) {
            if (!is_string($key) || !array_key_exists($key, self::FACET_LABELS)) {
                continue;
            }
            if (!is_array($values)) {
                continue;
            }
            $set = [];
            foreach ($values as $value) {
                $string = trim((string) $value);
                if ($string === '') {
                    continue;
                }
                $set[$string] = true;
            }
            if ($set !== []) {
                $normalized[$key] = array_keys($set);
            }
        }

        return $normalized;
    }

    /**
     * Filters the mock catalog by the root/category/facet selections.
     *
     * @param array<int, string> $categoryIds
     * @param array<string, array<int, string>> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterProductsByEnvelope(string $rootId, array $categoryIds, array $filters): array
    {
        $selectedCategories = $this->normalizeCategoryIds($categoryIds);

        return array_values(array_filter(
            self::PRODUCTS,
            function (array $product) use ($rootId, $selectedCategories, $filters): bool {
                if (($product['root_id'] ?? null) !== $rootId) {
                    return false;
                }

                if ($selectedCategories !== []) {
                    $productCategories = array_map('strval', $product['category_ids'] ?? []);
                    $overlap = array_intersect($productCategories, $selectedCategories);
                    if ($overlap === []) {
                        return false;
                    }
                }

                foreach ($filters as $key => $values) {
                    if ($values === []) {
                        continue;
                    }
                    if ($key === 'series') {
                        $candidate = (string) ($product['series'] ?? '');
                    } else {
                        $candidate = (string) ($product['attributes'][$key] ?? '');
                    }
                    if ($candidate === '' || !in_array($candidate, $values, true)) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    /**
     * Builds facet definitions (Series + custom attributes) from filtered rows.
     *
     * @param array<int, array<string, mixed>> $products
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildFacetDefinitions(array $products): array
    {
        $definitions = [];

        foreach (self::FACET_LABELS as $key => $label) {
            $values = [];
            foreach ($products as $product) {
                if ($key === 'series') {
                    $values[] = (string) ($product['series'] ?? '');
                    continue;
                }
                $values[] = (string) ($product['attributes'][$key] ?? '');
            }
            $filteredValues = array_values(array_filter($values, static fn ($value) => $value !== ''));
            $unique = array_values(array_unique($filteredValues, SORT_STRING));
            sort($unique, SORT_NATURAL | SORT_FLAG_CASE);

            $definitions[] = [
                'key' => $key,
                'label' => $label,
                'values' => $unique,
            ];
        }

        return $definitions;
    }
}
