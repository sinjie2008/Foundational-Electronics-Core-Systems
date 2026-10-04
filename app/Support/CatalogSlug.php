<?php
declare(strict_types=1);

namespace CatalogSuite\Support;

use mysqli;
use mysqli_sql_exception;

/** Allocates a route segment once; changing a display name never regenerates it. */
final class CatalogSlug
{
    public function __construct(private mysqli $db)
    {
    }

    public function available(): bool
    {
        $result = $this->db->query("SHOW COLUMNS FROM category LIKE 'slug'");
        return $result->num_rows > 0;
    }

    public static function normalize(string $name): string
    {
        $value = mb_strtolower(trim($name), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? '';
        return rtrim(mb_substr(trim($value, '-'), 0, 150, 'UTF-8'), '-') ?: 'node';
    }

    public function assign(int $id): void
    {
        $stmt = $this->db->prepare('SELECT name, slug FROM category WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null || $row['slug'] !== null) {
            return;
        }
        $base = self::normalize((string) $row['name']);
        for ($suffix = 1; ; $suffix++) {
            $slug = $base . ($suffix === 1 ? '' : '-' . $suffix);
            try {
                $stmt = $this->db->prepare('UPDATE category SET slug = ? WHERE id = ? AND slug IS NULL');
                $stmt->execute([$slug, $id]);
                $stmt->close();
                return;
            } catch (mysqli_sql_exception $error) {
                if ($error->getCode() !== 1062) {
                    throw $error;
                }
            }
        }
    }

    public function backfill(): void
    {
        $rows = $this->db->query('SELECT id FROM category WHERE slug IS NULL ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $row) {
            $this->assign((int) $row['id']);
        }
    }
}
