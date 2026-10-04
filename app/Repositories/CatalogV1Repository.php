<?php
declare(strict_types=1);

namespace CatalogSuite\Repositories;

use mysqli;

/** All public value queries join their owning series and visibility definition. */
final class CatalogV1Repository
{
    public function __construct(private mysqli $db)
    {
    }

    public function rows(string $sql, array $parameters = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($parameters);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function publicNodes(): array
    {
        return $this->rows('SELECT id, parent_id, slug, name, type, description, subtitle, anchor_id, target_url, display_order FROM category WHERE is_published = 1 AND slug IS NOT NULL ORDER BY display_order, id');
    }

    public function aliases(): array
    {
        return $this->rows('SELECT path, node_id, is_canonical FROM catalog_path_alias');
    }

    public function fields(int $seriesId, string $scope): array
    {
        return $this->rows('SELECT id, field_key, label, field_type, field_scope, default_value, unit, sort_order, is_required,
                is_filterable, is_sortable, is_table_column, is_searchable, filter_type, config_json, group_key, group_label
            FROM series_custom_field WHERE series_id = ? AND field_scope = ? AND is_public_portal_hidden = 0 ORDER BY sort_order, id', [$seriesId, $scope]);
    }

    public function metadata(int $seriesId): array
    {
        return $this->rows("SELECT f.id, f.field_key, f.field_type, COALESCE(v.value, f.default_value) AS value
            FROM series_custom_field f LEFT JOIN series_custom_field_value v ON v.series_custom_field_id = f.id AND v.series_id = f.series_id
            WHERE f.series_id = ? AND f.field_scope = 'series_metadata' AND f.is_public_portal_hidden = 0 ORDER BY f.sort_order, f.id", [$seriesId]);
    }

    public function blocks(string $ownerType, int $ownerId): array
    {
        return $this->rows('SELECT id, block_key, block_type, title, anchor_id, payload_json, display_order, is_navigation FROM catalog_content_block
            WHERE owner_type = ? AND owner_id = ? AND is_public = 1 ORDER BY display_order, id', [$ownerType, $ownerId]);
    }

    public function assets(string $ownerType, int $ownerId): array
    {
        return $this->rows('SELECT * FROM catalog_asset WHERE owner_type = ? AND owner_id = ? AND is_public = 1 ORDER BY display_order, id', [$ownerType, $ownerId]);
    }

    public function asset(int $id): ?array
    {
        return $this->rows('SELECT * FROM catalog_asset WHERE id = ? AND is_public = 1', [$id])[0] ?? null;
    }

    public function product(int $id): ?array
    {
        return $this->rows('SELECT id, series_id, sku, name, description FROM product WHERE id = ? AND is_published = 1', [$id])[0] ?? null;
    }

    public function collections(): array
    {
        return $this->rows('SELECT id, collection_key, title, description, target_url, config_json, display_order FROM catalog_collection WHERE is_public = 1 ORDER BY display_order, id');
    }

    public function collectionItems(int $id): array
    {
        return $this->rows('SELECT id, item_key, node_id, asset_id, title, subtitle, display_order FROM catalog_collection_item WHERE collection_id = ? AND is_public = 1 ORDER BY display_order, id', [$id]);
    }

    public static function like(string $value): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%';
    }

    /** SQL expressions use database IDs, never a client-supplied SQL identifier. */
    public static function valueExpression(array $field): string
    {
        $id = (int) $field['id'];
        $value = "(SELECT COALESCE(v.value, f.default_value) FROM series_custom_field f
            LEFT JOIN product_custom_field_value v ON v.series_custom_field_id = f.id AND v.product_id = p.id
            WHERE f.id = {$id} AND f.series_id = p.series_id AND f.field_scope = 'product_attribute' AND f.is_public_portal_hidden = 0)";
        return $value;
    }

    public static function numericExpression(string $expression): string
    {
        return "CASE WHEN TRIM({$expression}) REGEXP '^[+-]?([0-9]{1,45}([.][0-9]{0,20})?|[.][0-9]{1,20})$' THEN CAST({$expression} AS DECIMAL(65,20)) ELSE NULL END";
    }

    public function partWhere(int $seriesId, array $criteria): array
    {
        $where = 'p.series_id = ? AND p.is_published = 1';
        $parameters = [$seriesId];
        if ($criteria['search'] !== '') {
            $where .= " AND (p.sku LIKE ? ESCAPE '!' OR p.name LIKE ? ESCAPE '!' OR p.description LIKE ? ESCAPE '!' OR
                EXISTS (SELECT 1 FROM series_custom_field f LEFT JOIN product_custom_field_value v
                    ON v.series_custom_field_id = f.id AND v.product_id = p.id
                    WHERE f.series_id = p.series_id AND f.field_scope = 'product_attribute' AND f.is_public_portal_hidden = 0
                    AND f.is_searchable = 1 AND f.field_type <> 'file' AND COALESCE(v.value, f.default_value) LIKE ? ESCAPE '!'))";
            array_push($parameters, ...array_fill(0, 4, self::like($criteria['search'])));
        }
        foreach ($criteria['filters'] as $filter) {
            $expression = self::valueExpression($filter['field']);
            if ($filter['field']['filter_type'] === 'range') {
                $expression = self::numericExpression($expression);
                foreach (['min' => '>=', 'max' => '<='] as $key => $operator) {
                    if (isset($filter['values'][$key])) {
                        $where .= " AND {$expression} {$operator} CAST(? AS DECIMAL(65,20))";
                        $parameters[] = (string) $filter['values'][$key];
                    }
                }
            } else {
                $where .= ' AND ' . $expression . ' IN (' . implode(',', array_fill(0, count($filter['values']), '?')) . ')';
                array_push($parameters, ...$filter['values']);
            }
        }
        return [$where, $parameters];
    }

    public function parts(int $seriesId, array $criteria): array
    {
        [$where, $parameters] = $this->partWhere($seriesId, $criteria);
        $total = (int) $this->rows('SELECT COUNT(*) AS total FROM product p WHERE ' . $where, $parameters)[0]['total'];
        $order = 'p.sku ASC, p.id ASC';
        if ($criteria['sort'] !== null) {
            $expression = self::valueExpression($criteria['sort']);
            if ($criteria['sort']['field_type'] === 'number') {
                $expression = self::numericExpression($expression);
            }
            $direction = $criteria['direction'] === 'desc' ? 'DESC' : 'ASC';
            $order = "({$expression}) IS NULL ASC, {$expression} {$direction}, " . $order;
        }
        $rows = $this->rows('SELECT p.id, p.sku, p.name, p.description FROM product p WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?',
            [...$parameters, $criteria['per_page'], ($criteria['page'] - 1) * $criteria['per_page']]);
        return ['rows' => $rows, 'total' => $total];
    }

    public function partValues(int $seriesId, array $partIds): array
    {
        if ($partIds === []) {
            return [];
        }
        return $this->rows("SELECT p.id AS product_id, f.field_key, f.field_type, COALESCE(v.value, f.default_value) AS value
            FROM product p JOIN series_custom_field f ON f.series_id = p.series_id
            LEFT JOIN product_custom_field_value v ON v.product_id = p.id AND v.series_custom_field_id = f.id
            WHERE p.series_id = ? AND p.is_published = 1 AND f.field_scope = 'product_attribute' AND f.is_public_portal_hidden = 0
            AND p.id IN (" . implode(',', array_fill(0, count($partIds), '?')) . ') ORDER BY f.sort_order, f.id', [$seriesId, ...$partIds]);
    }

    public function facets(int $seriesId, array $criteria, array $fields): array
    {
        [$where, $parameters] = $this->partWhere($seriesId, $criteria);
        $facets = [];
        foreach ($fields as $field) {
            if (!(bool) $field['is_filterable'] || $field['field_type'] === 'file') {
                continue;
            }
            $expression = self::valueExpression($field);
            $order = $field['field_type'] === 'number' ? self::numericExpression('value') . ', value' : 'value';
            $values = $this->rows("SELECT value, COUNT(*) AS count FROM (SELECT {$expression} AS value FROM product p WHERE {$where}) facet
                WHERE value IS NOT NULL AND value <> '' GROUP BY value ORDER BY {$order}", $parameters);
            foreach ($values as &$value) {
                $value['count'] = (int) $value['count'];
            }
            unset($value);
            $facets[] = ['field_key' => $field['field_key'], 'values' => $values];
        }
        return $facets;
    }

    public function search(array $nodeIds, string $query, int $page, int $perPage): array
    {
        if ($nodeIds === [] || $query === '') {
            return ['rows' => [], 'total' => 0];
        }
        $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));
        $term = self::like($query);
        $sql = "SELECT c.id, c.type, c.name, NULL AS sku, c.id AS node_id FROM category c WHERE c.id IN ({$placeholders}) AND
            (c.name LIKE ? ESCAPE '!' OR c.description LIKE ? ESCAPE '!' OR EXISTS (
                SELECT 1 FROM series_custom_field f LEFT JOIN series_custom_field_value v ON v.series_custom_field_id = f.id AND v.series_id = f.series_id
                WHERE f.series_id = c.id AND f.field_scope = 'series_metadata' AND f.is_public_portal_hidden = 0 AND f.is_searchable = 1
                AND f.field_type <> 'file' AND COALESCE(v.value, f.default_value) LIKE ? ESCAPE '!'))
            UNION ALL SELECT p.id, 'product' AS type, p.name, p.sku, p.series_id AS node_id FROM product p
                WHERE p.is_published = 1 AND p.series_id IN ({$placeholders}) AND (p.sku LIKE ? ESCAPE '!' OR p.name LIKE ? ESCAPE '!'
                    OR p.description LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM series_custom_field f LEFT JOIN product_custom_field_value v
                    ON v.product_id = p.id AND v.series_custom_field_id = f.id WHERE f.series_id = p.series_id
                    AND f.field_scope = 'product_attribute' AND f.is_public_portal_hidden = 0 AND f.is_searchable = 1
                    AND f.field_type <> 'file' AND COALESCE(v.value, f.default_value) LIKE ? ESCAPE '!'))";
        $parameters = [...$nodeIds, $term, $term, $term, ...$nodeIds, $term, $term, $term, $term];
        $total = (int) $this->rows('SELECT COUNT(*) AS total FROM (' . $sql . ') matches', $parameters)[0]['total'];
        $rows = $this->rows('SELECT * FROM (' . $sql . ') matches ORDER BY type, name, id LIMIT ? OFFSET ?', [...$parameters, $perPage, ($page - 1) * $perPage]);
        return ['rows' => $rows, 'total' => $total];
    }
}
