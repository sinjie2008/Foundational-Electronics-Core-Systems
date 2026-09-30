<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Support\Config;

use mysqli;
use CatalogSuite\Repositories\LatexTemplateRepository;
use Throwable;
use CatalogSuite\Http\CatalogApiException;

final class LatexTemplateService
{
    private string $pdfStorageDir;
    private LatexTemplateRepository $repository;

    public function __construct(
        private mysqli $connection,
        ?string $pdfStorageDir = null
    ) {
        $config = Config::get('app');
        $projectRoot = rtrim(
            (string) ($config['project_root'] ?? dirname(__DIR__, 2)),
            "/\\"
        );
        $storageConfig = (array) ($config['storage'] ?? []);
        $this->pdfStorageDir = rtrim(
            $pdfStorageDir ?? (string) ($storageConfig['latex_pdfs'] ?? $projectRoot . '/storage/latex-pdfs'),
            "/\\"
        );
        $this->repository = new LatexTemplateRepository($connection);
    }

    /**
     * Lists all LaTeX templates ordered by most recently updated.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTemplates(): array
    {
        $templates = [];
        foreach ($this->repository->fetchTemplates() as $row) {
            $templates[] = $this->mapRow($row, false);
        }

        return $templates;
    }

    /**
     * Fetches a single template.
     *
     * @return array<string, mixed>
     */
    public function getTemplate(int $templateId, bool $includeLatex = true): array
    {
        $row = $this->fetchRawTemplate($templateId);
        if ($row === null) {
            throw new CatalogApiException('LATEX_TEMPLATE_NOT_FOUND', 'Template not found.', 404);
        }

        return $this->mapRow($row, $includeLatex);
    }

    /**
     * Creates a template.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createTemplate(array $payload): array
    {
        [$title, $description, $latex] = $this->normalizePayload($payload);
        $templateId = $this->repository->insertTemplate($title, $description, $latex);

        $correlationId = $this->generateCorrelationId(); // Correlates mutation log entries.
        $this->logOperation('create', $templateId, $correlationId);

        return $this->getTemplate($templateId);
    }

    /**
     * Updates a template.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateTemplate(int $templateId, array $payload): array
    {
        $this->requireTemplate($templateId);
        [$title, $description, $latex] = $this->normalizePayload($payload);
        $this->repository->updateTemplate($templateId, $title, $description, $latex);

        $correlationId = $this->generateCorrelationId();
        $this->logOperation('update', $templateId, $correlationId);

        return $this->getTemplate($templateId);
    }

    /**
     * Deletes a template and any cached PDF.
     */
    public function deleteTemplate(int $templateId): void
    {
        $row = $this->requireTemplate($templateId);
        $pdfPath = isset($row['pdf_path']) ? (string) $row['pdf_path'] : null;
        if ($pdfPath !== null) {
            $this->deletePdfFile($pdfPath);
        }

        $this->repository->deleteTemplate($templateId);

        $correlationId = $this->generateCorrelationId();
        $this->logOperation('delete', $templateId, $correlationId);
    }

    /**
     * Persists the generated PDF path.
     *
     * @return array<string, mixed>
     */
    public function updatePdfPath(int $templateId, string $relativePath, ?string $previousPath = null): array
    {
        $this->requireTemplate($templateId);
        if ($previousPath !== null && $previousPath !== $relativePath) {
            $this->deletePdfFile($previousPath);
        }

        $this->repository->updatePdfPath($templateId, $relativePath);

        $correlationId = $this->generateCorrelationId();
        $this->logOperation('pdf_update', $templateId, $correlationId);

        return $this->getTemplate($templateId, false);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRawTemplate(int $templateId): ?array
    {
        return $this->repository->findTemplate($templateId);
    }

    /**
     * Ensures a template exists.
     *
     * @return array<string, mixed>
     */
    private function requireTemplate(int $templateId): array
    {
        $row = $this->fetchRawTemplate($templateId);
        if ($row === null) {
            throw new CatalogApiException('LATEX_TEMPLATE_NOT_FOUND', 'Template not found.', 404);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{0:string,1:?string,2:string}
     */
    private function normalizePayload(array $payload): array
    {
        $title = isset($payload['title']) ? trim((string) $payload['title']) : '';
        $descriptionRaw = array_key_exists('description', $payload) ? $payload['description'] : null;
        $description = $descriptionRaw !== null ? trim((string) $descriptionRaw) : null;
        $description = ($description !== null && $description !== '') ? $description : null;
        $latex = isset($payload['latex']) ? (string) $payload['latex'] : '';

        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Title is required.';
        }
        if ($latex === '') {
            $errors['latex'] = 'LaTeX source is required.';
        }
        if ($errors !== []) {
            throw new CatalogApiException('LATEX_VALIDATION_ERROR', 'Template validation failed.', 400, $errors);
        }

        return [$title, $description, $latex];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapRow(array $row, bool $includeLatex = true): array
    {
        $payload = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description' => isset($row['description']) ? ($row['description'] !== null ? (string) $row['description'] : null) : null,
            'pdfPath' => isset($row['pdf_path']) ? ($row['pdf_path'] !== null ? (string) $row['pdf_path'] : null) : null,
            'downloadUrl' => $this->buildDownloadUrl(isset($row['pdf_path']) ? $row['pdf_path'] : null),
            'createdAt' => $this->formatTimestamp(isset($row['created_at']) ? $row['created_at'] : null),
            'updatedAt' => $this->formatTimestamp(isset($row['updated_at']) ? $row['updated_at'] : null),
        ];

        if ($includeLatex && array_key_exists('latex_source', $row)) {
            $payload['latex'] = (string) $row['latex_source'];
        }

        return $payload;
    }

    private function buildDownloadUrl(?string $relativePath): ?string
    {
        if ($relativePath === null || $relativePath === '') {
            return null;
        }

        $normalized = '/' . ltrim(str_replace('\\', '/', $relativePath), '/');

        return $normalized;
    }

    private function formatTimestamp(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (Throwable) {
            return $value;
        }
    }

    private function deletePdfFile(?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '') {
            return;
        }
        $normalizedRelative = ltrim(str_replace('\\', '/', $relativePath), '/');
        $prefix = ltrim((string) (Config::get('app')['latex']['pdf_url_prefix'] ?? '/storage/latex-pdfs'), '/');
        if (!str_starts_with($normalizedRelative, $prefix)) {
            return;
        }

        $suffix = ltrim(substr($normalizedRelative, strlen($prefix)), '/');
        if ($suffix === '') {
            return;
        }

        $absolute = $this->pdfStorageDir . '/' . $suffix;
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function generateCorrelationId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function logOperation(string $action, int $templateId, string $correlationId): void
    {
        error_log(
            sprintf(
                '[LatexTemplate] action=%s templateId=%d correlationId=%s',
                $action,
                $templateId,
                $correlationId
            )
        );
    }
}
