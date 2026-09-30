<?php
declare(strict_types=1);

namespace CatalogSuite\Repositories;

use mysqli;

/**
 * Stores LaTeX templates and variables and reads series data for compilation.
 */
final class LatexRepository
{
    private mysqli $db;

    /**
     * Create the repository with the application's database connection.
     */
    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * List global LaTeX templates.
     *
     * @return list<array<string, mixed>>
     */
    public function listGlobalTemplates(): array
    {
        return $this->fetchAll(
            'SELECT id, title, description, latex_code, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM latex_templates WHERE is_global = 1 AND (series_id IS NULL OR series_id = 0)
             ORDER BY updated_at DESC, id DESC'
        );
    }

    /**
     * Fetch a global template by id.
     *
     * @return array<string, mixed>|null
     */
    public function findGlobalTemplate(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, title, description, latex_code, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM latex_templates WHERE id = ? AND is_global = 1 LIMIT 1',
            'i',
            [$id]
        );
    }

    /**
     * Insert a global template and return its database id.
     */
    public function insertGlobalTemplate(string $title, string $description, string $latexCode): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO latex_templates (title, description, latex_code, is_global, series_id) VALUES (?, ?, ?, 1, NULL)'
        );
        $stmt->bind_param('sss', $title, $description, $latexCode);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Update a global template.
     */
    public function updateGlobalTemplate(int $id, string $title, string $description, string $latexCode): void
    {
        $stmt = $this->db->prepare(
            'UPDATE latex_templates SET title = ?, description = ?, latex_code = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND is_global = 1'
        );
        $stmt->bind_param('sssi', $title, $description, $latexCode, $id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Delete a global template.
     */
    public function deleteGlobalTemplate(int $id): bool
    {
        return $this->execute('DELETE FROM latex_templates WHERE id = ? AND is_global = 1 LIMIT 1', 'i', [$id]);
    }

    /**
     * List templates owned by a series together with global templates.
     *
     * @return list<array<string, mixed>>
     */
    public function listSeriesTemplates(int $seriesId): array
    {
        return $this->fetchAll(
            'SELECT id, title, description, latex_code, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM latex_templates WHERE series_id = ? OR (is_global = 1 AND (series_id IS NULL OR series_id = 0))
             ORDER BY is_global DESC, updated_at DESC',
            'i',
            [$seriesId]
        );
    }

    /**
     * Fetch a template by id.
     *
     * @return array<string, mixed>|null
     */
    public function findTemplate(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, title, description, latex_code, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM latex_templates WHERE id = ? LIMIT 1',
            'i',
            [$id]
        );
    }

    /**
     * Insert a series template and return its database id.
     */
    public function insertSeriesTemplate(int $seriesId, string $title, string $description, string $latexCode): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO latex_templates (title, description, latex_code, is_global, series_id) VALUES (?, ?, ?, 0, ?)'
        );
        $stmt->bind_param('sssi', $title, $description, $latexCode, $seriesId);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Update a series-owned template.
     */
    public function updateSeriesTemplate(int $id, int $seriesId, string $title, string $description, string $latexCode): void
    {
        $stmt = $this->db->prepare(
            'UPDATE latex_templates SET title = ?, description = ?, latex_code = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND series_id = ?'
        );
        $stmt->bind_param('sssii', $title, $description, $latexCode, $id, $seriesId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Retrieve products for a series in SKU order.
     *
     * @return list<array<string, mixed>>
     */
    public function getSeriesProducts(int $seriesId): array
    {
        return $this->fetchAll('SELECT id, name, sku FROM product WHERE series_id = ? ORDER BY sku ASC', 'i', [$seriesId]);
    }

    /**
     * Retrieve stored attribute values for a product.
     *
     * @return list<array<string, mixed>>
     */
    public function getProductAttributes(int $productId): array
    {
        return $this->fetchAll(
            'SELECT f.field_key, v.value FROM product_custom_field_value v
             JOIN series_custom_field f ON v.series_custom_field_id = f.id WHERE v.product_id = ?',
            'i',
            [$productId]
        );
    }

    /**
     * List global LaTeX variables.
     *
     * @return list<array<string, mixed>>
     */
    public function listGlobalVariables(): array
    {
        return $this->fetchAll(
            'SELECT id, field_key, field_type, field_value, is_global, series_id, created_at, updated_at
             FROM latex_variables WHERE is_global = 1 AND (series_id IS NULL OR series_id = 0) ORDER BY field_key ASC'
        );
    }

    /**
     * Update a global LaTeX variable.
     */
    public function updateGlobalVariable(int $id, string $key, string $type, string $value): void
    {
        $stmt = $this->db->prepare(
            'UPDATE latex_variables SET field_key = ?, field_type = ?, field_value = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND is_global = 1'
        );
        $stmt->bind_param('sssi', $key, $type, $value, $id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Insert a global LaTeX variable and return its database id.
     */
    public function insertGlobalVariable(string $key, string $type, string $value): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO latex_variables (field_key, field_type, field_value, is_global, series_id) VALUES (?, ?, ?, 1, NULL)'
        );
        $stmt->bind_param('sss', $key, $type, $value);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Delete a global LaTeX variable.
     */
    public function deleteGlobalVariable(int $id): bool
    {
        return $this->execute('DELETE FROM latex_variables WHERE id = ? AND is_global = 1 LIMIT 1', 'i', [$id]);
    }

    /**
     * Fetch a global LaTeX variable by id.
     *
     * @return array<string, mixed>|null
     */
    public function findGlobalVariable(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, field_key, field_type, field_value, is_global, series_id, created_at, updated_at
             FROM latex_variables WHERE id = ? AND is_global = 1 LIMIT 1',
            'i',
            [$id]
        );
    }

    /**
     * Fetch a list of rows with bound values.
     *
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($params !== []) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Fetch one row with bound values.
     *
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function findOne(string $sql, string $types, array $params): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }

    /**
     * Execute a bound-value mutation and return its result.
     *
     * @param list<mixed> $params
     */
    private function execute(string $sql, string $types, array $params): bool
    {
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }
}
