<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use mysqli;
use CatalogSuite\Repositories\HierarchyRepository;
use CatalogSuite\Http\CatalogApiException;

final class HierarchyService
{
    private ?bool $hasLegacyTemplatingColumn = null;
    private HierarchyRepository $repository;

    public function __construct(private mysqli $connection)
    {
        $this->repository = new HierarchyRepository($connection);
    }

    /**
     * Returns the full category/series tree alongside select options for series.
     *
     * @return array<string, mixed>
     */
    public function listHierarchy(): array
    {
        $this->ensureTypstTemplatingColumn();
        $hasLegacyColumn = $this->hasLegacyTemplatingColumn();
        $tree = $this->buildHierarchyTree($hasLegacyColumn);

        /**
         * @param array<string, mixed> $node
         *
         * @return array<string, mixed>
         */
        $transform = function (array $node) use (&$transform): array {
            $children = [];
            foreach ($node['children'] as $child) {
                $children[] = $transform($child);
            }

            return [
                'id' => $node['id'],
                'name' => $node['name'],
                'type' => $node['type'],
                'displayOrder' => $node['displayOrder'],
                'parentId' => $node['parentId'],
                'typstTemplatingEnabled' => (bool) ($node['typstTemplatingEnabled'] ?? false),
                'children' => $children,
            ];
        };

        $hierarchy = array_map($transform, $tree);
        $seriesOptions = $this->fetchSeriesOptions();

        return [
            'hierarchy' => $hierarchy,
            'seriesOptions' => $seriesOptions,
        ];
    }

    /**
     * Creates or updates a node.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function saveNode(array $payload): array
    {
        $nodeId = isset($payload['id']) ? (int) $payload['id'] : null;
        $name = isset($payload['name']) ? trim((string) $payload['name']) : '';
        $type = isset($payload['type']) ? (string) $payload['type'] : '';
        $parentId = array_key_exists('parentId', $payload)
            ? ($payload['parentId'] !== null ? (int) $payload['parentId'] : null)
            : null;
        $displayOrder = isset($payload['displayOrder']) ? (int) $payload['displayOrder'] : 0;

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }
        if (!in_array($type, ['category', 'series'], true)) {
            $errors['type'] = 'Type must be "category" or "series".';
        }
        if ($type === 'series' && $parentId === null) {
            $errors['parentId'] = 'Series must have a parent category.';
        }
        if ($nodeId !== null && $parentId !== null && $nodeId === $parentId) {
            $errors['parentId'] = 'Parent cannot be the node itself.';
        }
        if ($errors !== []) {
            throw new CatalogApiException('VALIDATION_ERROR', 'Field validation failed.', 400, $errors);
        }

        if ($parentId !== null) {
            $parent = $this->loadCategory($parentId);
            if ($parent === null) {
                throw new CatalogApiException('PARENT_NOT_FOUND', 'Parent node not found.', 404);
            }
            if ($parent['type'] !== 'category') {
                throw new CatalogApiException(
                    'VALIDATION_ERROR',
                    'Parent node must be a category.',
                    400,
                    ['parentId' => 'Parent node must be a category.']
                );
            }
        }

        if ($nodeId !== null) {
            $existing = $this->loadCategory($nodeId);
            if ($existing === null) {
                throw new CatalogApiException('NODE_NOT_FOUND', 'Node not found.', 404);
            }

            if ($existing['type'] === 'series' && $type !== 'series') {
                $childCount = $this->countProductsForSeries($nodeId);
                if ($childCount > 0) {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Cannot convert series with products into category.',
                        409
                    );
                }
            }

            $this->repository->updateNode($parentId, $name, $type, $displayOrder, $nodeId);
            $result = $this->loadCategory($nodeId);
        } else {
            $newId = $this->insertCategoryNode($parentId, $name, $type, $displayOrder);
            $result = $this->loadCategory($newId);
        }

        return [
            'id' => $result['id'],
            'name' => $result['name'],
            'type' => $result['type'],
            'displayOrder' => $result['display_order'],
        ];
    }

    /**
     * Deletes a node when no dependencies remain.
     */
    public function deleteNode(int $nodeId): void
    {
        $node = $this->loadCategory($nodeId);
        if ($node === null) {
            throw new CatalogApiException('NODE_NOT_FOUND', 'Node not found.', 404);
        }

        $childCount = $this->repository->countChildren($nodeId);

        if ($childCount > 0) {
            throw new CatalogApiException(
                'CONFLICT',
                'Cannot delete node with child nodes.',
                409,
                ['id' => 'Node still has children.']
            );
        }

        if ($node['type'] === 'series') {
            $productCount = $this->countProductsForSeries($nodeId);
            if ($productCount > 0) {
                throw new CatalogApiException(
                    'CONFLICT',
                    'Cannot delete series containing products.',
                    409,
                    ['id' => 'Series contains products.']
                );
            }
        }

        $this->repository->deleteNode($nodeId);
    }

    /**
     * Builds the hierarchical tree of categories/series.
     *
     * @param bool $includeLegacy Whether to include legacy latex_templating_enabled in the select.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildHierarchyTree(bool $includeLegacy): array
    {
        $rows = $this->repository->fetchHierarchyRows($includeLegacy);

        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = [];
        /** @var array<int|null, array<int, array<string, mixed>>> $children */
        $children = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;

            $node = [
                'id' => $id,
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'displayOrder' => (int) $row['display_order'],
                'parentId' => $parentId,
                'typstTemplatingEnabled' => ((int) ($row['typst_templating_enabled'] ?? ($includeLegacy ? (int) ($row['latex_templating_enabled'] ?? 0) : 0))) === 1,
                'children' => [],
            ];
            $nodes[$id] = $node;
            $children[$parentId][] = &$nodes[$id];
        }

        foreach ($nodes as $id => &$node) {
            $node['children'] = $children[$id] ?? [];
        }
        unset($node);

        return $children[null] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchSeriesOptions(): array
    {
        $options = [];
        foreach ($this->repository->fetchSeriesOptionRows() as $row) {
            $options[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }

        return $options;
    }

    /**
     * Loads a category by ID.
     *
     * @return array<string, mixed>|null
     */
    private function loadCategory(int $nodeId): ?array
    {
        $hasLegacyColumn = $this->hasLegacyTemplatingColumn();
        $row = $this->repository->findNode($nodeId, $hasLegacyColumn);

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'display_order' => (int) $row['display_order'],
            'typst_templating_enabled' => (int) (
                $row['typst_templating_enabled']
                ?? ($hasLegacyColumn ? (int) ($row['latex_templating_enabled'] ?? 0) : 0)
            ),
            'latex_templating_enabled' => $hasLegacyColumn ? (int) ($row['latex_templating_enabled'] ?? 0) : 0,
        ];
    }

    private function countProductsForSeries(int $seriesId): int
    {
        return $this->repository->countProductsForSeries($seriesId);
    }

    private function insertCategoryNode(
        ?int $parentId,
        string $name,
        string $type,
        int $displayOrder
    ): int {
        return $this->repository->insertNode($parentId, $name, $type, $displayOrder);
    }

    /**
     * Persists the Latex templating toggle for a series.
     */
    public function setTypstTemplatingEnabled(int $seriesId, bool $enabled): array
    {
        $this->ensureTypstTemplatingColumn();
        $series = $this->loadCategory($seriesId);
        if ($series === null || ($series['type'] ?? '') !== 'series') {
            throw new CatalogApiException('SERIES_NOT_FOUND', 'Series not found.', 404);
        }

        $flag = $enabled ? 1 : 0;
        $hasLegacyColumn = $this->hasLegacyTemplatingColumn();
        $this->repository->updateTemplatingEnabled($seriesId, $flag, $hasLegacyColumn);

        $updated = $this->loadCategory($seriesId);

        return [
            'seriesId' => $seriesId,
            'typstTemplatingEnabled' => $updated
                ? ((int) ($updated['typst_templating_enabled'] ?? $flag)) === 1
                : $enabled,
        ];
    }

    private function ensureTypstTemplatingColumn(): void
    {
        $column = 'typst_templating_enabled';
        if (!$this->hasCategoryColumn($column)) {
            $this->repository->addTypstTemplatingColumn();
        }
        // If legacy latex column exists, keep it in sync to preserve prior data.
        $legacyColumn = 'latex_templating_enabled';
        $this->hasLegacyTemplatingColumn = $this->hasCategoryColumn($legacyColumn);
        if ($this->hasLegacyTemplatingColumn) {
            $this->repository->syncLegacyTemplatingFlags();
        }
    }

    /**
     * Returns whether the legacy latex templating column exists on category.
     */
    private function hasLegacyTemplatingColumn(): bool
    {
        if ($this->hasLegacyTemplatingColumn === null) {
            $this->hasLegacyTemplatingColumn = $this->hasCategoryColumn('latex_templating_enabled');
        }

        return $this->hasLegacyTemplatingColumn;
    }

    /**
     * Builds the category select column list with optional legacy flags.
     */
    private static function getCategorySelectColumns(bool $includeLegacy): string
    {
        return HierarchyRepository::getCategorySelectColumns($includeLegacy);
    }

    /**
     * Checks whether a column exists on the category table.
     */
    private function hasCategoryColumn(string $column): bool
    {
        return $this->repository->hasCategoryColumn($column);
    }
}
