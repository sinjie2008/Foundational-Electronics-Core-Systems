<?php
declare(strict_types=1);

namespace CatalogSuite\Repositories;

use mysqli;

/** Internal transactional manifest persistence. Never exposed as a public write API. */
final class CatalogV1ImportRepository
{
    private const TABLES = ['category', 'product', 'series_custom_field', 'series_custom_field_value', 'product_custom_field_value',
        'catalog_content_block', 'catalog_asset', 'catalog_collection', 'catalog_collection_item', 'catalog_path_alias'];

    public function __construct(private mysqli $db)
    {
    }

    public function find(string $table, array $identity): ?array
    {
        $this->identifiers($table, array_keys($identity));
        $where = implode(' AND ', array_map(static fn (string $key): string => "`{$key}` <=> ?", array_keys($identity)));
        $stmt = $this->db->prepare("SELECT * FROM `{$table}` WHERE {$where} LIMIT 1");
        $stmt->execute(array_values($identity));
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row;
    }

    public function upsert(string $table, array $identity, array $values): array
    {
        $existing = $this->find($table, $identity);
        if ($values !== []) {
            $this->identifiers($table, array_keys($values));
        }
        if ($existing !== null) {
            if ($values !== []) {
                $set = implode(', ', array_map(static fn (string $key): string => "`{$key}` = ?", array_keys($values)));
                $where = implode(' AND ', array_map(static fn (string $key): string => "`{$key}` <=> ?", array_keys($identity)));
                $stmt = $this->db->prepare("UPDATE `{$table}` SET {$set} WHERE {$where}");
                $stmt->execute([...array_values($values), ...array_values($identity)]);
                $stmt->close();
            }
        } else {
            $values = [...$identity, ...$values];
            $keys = implode(', ', array_map(static fn (string $key): string => "`{$key}`", array_keys($values)));
            $stmt = $this->db->prepare("INSERT INTO `{$table}` ({$keys}) VALUES (" . implode(',', array_fill(0, count($values), '?')) . ')');
            $stmt->execute(array_values($values));
            $stmt->close();
        }
        return $this->find($table, $identity) ?? throw new \RuntimeException('Unable to reload imported record.');
    }

    private function identifiers(string $table, array $keys): void
    {
        if (!in_array($table, self::TABLES, true) || $keys === []) {
            throw new \LogicException('Unsupported import persistence target.');
        }
        foreach ($keys as $key) {
            if (!is_string($key) || preg_match('/^[a-z_]+$/D', $key) !== 1) {
                throw new \LogicException('Invalid import persistence column.');
            }
        }
    }
}
