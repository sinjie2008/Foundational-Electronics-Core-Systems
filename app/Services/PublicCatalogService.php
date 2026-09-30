<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Services\HierarchyService;
use CatalogSuite\Services\SeriesFieldService;
use CatalogSuite\Services\SeriesAttributeService;
use CatalogSuite\Services\ProductService;

final class PublicCatalogService
{
    public function __construct(
        private HierarchyService $hierarchyService,
        private SeriesFieldService $seriesFieldService,
        private SeriesAttributeService $seriesAttributeService,
        private ProductService $productService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSnapshot(): array
    {
        $hierarchyPayload = $this->hierarchyService->listHierarchy();
        $hierarchy = $hierarchyPayload['hierarchy'] ?? [];
        $seriesIds = $this->collectSeriesIds($hierarchy);

        if ($seriesIds === []) {
            return [
                'generatedAt' => gmdate('c'),
                'hierarchy' => $hierarchy,
            ];
        }

        $productFields = $this->seriesFieldService->fetchFieldsForSeriesIds($seriesIds, SeriesFieldService::SCOPE_PRODUCT);
        $metadataDefinitions = $this->seriesFieldService->fetchFieldsForSeriesIds($seriesIds, SeriesFieldService::SCOPE_SERIES);
        $metadataPayloads = $this->seriesAttributeService->fetchMetadataPayloads($seriesIds);
        $productsBySeries = $this->productService->fetchProductsForSeriesIds($seriesIds, $productFields);

        $seriesSnapshots = [];
        foreach ($seriesIds as $seriesId) {
            $seriesSnapshots[$seriesId] = [
                'metadata' => [
                    'definitions' => $metadataDefinitions[$seriesId] ?? [],
                    'values' => $metadataPayloads[$seriesId]['values'] ?? [],
                ],
                'productFields' => $productFields[$seriesId] ?? [],
                'products' => $productsBySeries[$seriesId] ?? [],
            ];
        }

        $enrich = function (array $node) use (&$enrich, $seriesSnapshots): array {
            $children = [];
            foreach ($node['children'] ?? [] as $child) {
                $children[] = $enrich($child);
            }

            $payload = [
                'id' => $node['id'],
                'name' => $node['name'],
                'type' => $node['type'],
                'displayOrder' => $node['displayOrder'],
                'parentId' => $node['parentId'],
                'children' => $children,
            ];

            if ($node['type'] === 'series') {
                $seriesId = $node['id'];
                $snapshot = $seriesSnapshots[$seriesId] ?? [
                    'metadata' => ['definitions' => [], 'values' => []],
                    'productFields' => [],
                    'products' => [],
                ];
                $payload['metadata'] = $snapshot['metadata'];
                $payload['productFields'] = $snapshot['productFields'];
                $payload['products'] = $snapshot['products'];
            }

            return $payload;
        };

        $enrichedHierarchy = array_map($enrich, $hierarchy);

        return [
            'generatedAt' => gmdate('c'),
            'hierarchy' => $enrichedHierarchy,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $hierarchy
     *
     * @return array<int>
     */
    private function collectSeriesIds(array $hierarchy): array
    {
        $seriesIds = [];
        $stack = $hierarchy;
        while ($stack !== []) {
            $node = array_shift($stack);
            if (($node['type'] ?? null) === 'series' && isset($node['id'])) {
                $seriesIds[] = (int) $node['id'];
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                foreach ($node['children'] as $child) {
                    $stack[] = $child;
                }
            }
        }

        return array_values(array_unique($seriesIds));
    }
}
