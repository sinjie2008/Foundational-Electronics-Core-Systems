<?php
declare(strict_types=1);

namespace CatalogSuite\Repositories;

use mysqli;

/**
 * Persists and reads LaTeX template records.
 */
final class LatexTemplateRepository
{
    public function __construct(private mysqli $connection)
    {
    }

    /**
     * Returns all templates in their existing display order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchTemplates(): array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, title, description, pdf_path, created_at, updated_at
             FROM latex_template
             ORDER BY updated_at DESC, id DESC'
        );
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    /**
     * Inserts a template and returns the inserted ID.
     */
    public function insertTemplate(string $title, string $description, string $latex): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO latex_template (title, description, latex_source, created_at, updated_at)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->bind_param('sss', $title, $description, $latex);
        $stmt->execute();
        $templateId = (int) $this->connection->insert_id;
        $stmt->close();

        return $templateId;
    }

    /**
     * Updates a template's source and description.
     */
    public function updateTemplate(int $templateId, string $title, string $description, string $latex): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE latex_template
             SET title = ?, description = ?, latex_source = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('sssi', $title, $description, $latex, $templateId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Deletes a template row.
     */
    public function deleteTemplate(int $templateId): void
    {
        $stmt = $this->connection->prepare('DELETE FROM latex_template WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $templateId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Updates the stored PDF path for a template.
     */
    public function updatePdfPath(int $templateId, string $relativePath): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE latex_template
             SET pdf_path = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('si', $relativePath, $templateId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Loads one template by ID.
     *
     * @return array<string, mixed>|null
     */
    public function findTemplate(int $templateId): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, title, description, latex_source, pdf_path, created_at, updated_at
             FROM latex_template
             WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $templateId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }
}
