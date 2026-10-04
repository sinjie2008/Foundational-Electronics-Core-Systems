<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Services\CatalogService;
use CatalogSuite\Repositories\LatexRepository;
use CatalogSuite\Support\Config;
use CatalogSuite\Support\Db;
use mysqli;
use RuntimeException;

/**
 * Service for managing LaTeX templates and global variables.
 */
final class LatexService
{
    private LatexRepository $latex;
    private CatalogService $catalog;
    private string $buildStorageDir;
    private string $pdfStorageDir;
    private string $pdflatexBinary;
    private string $pdfUrlPrefix;

    /**
     * Create the service with injectable persistence.
     */
    public function __construct(?mysqli $db = null, ?LatexRepository $latex = null, ?CatalogService $catalog = null)
    {
        $connection = $db ?? Db::connection();
        $this->latex = $latex ?? new LatexRepository($connection);
        $this->catalog = $catalog ?? new CatalogService($connection);
        $config = Config::get('app');
        $projectRoot = rtrim(
            (string) ($config['project_root'] ?? dirname(__DIR__, 2)),
            "/\\"
        );
        $storageConfig = (array) ($config['storage'] ?? []);
        $this->buildStorageDir = rtrim(
            (string) ($storageConfig['api_latex_build'] ?? dirname($projectRoot) . '/storage/latex-build'),
            "/\\"
        );
        $this->pdfStorageDir = rtrim(
            (string) ($storageConfig['api_latex_pdfs'] ?? dirname($projectRoot) . '/public/storage/latex-pdfs'),
            "/\\"
        );
        $latexConfig = (array) ($config['latex'] ?? []);
        $binaryEnvironment = (string) ($latexConfig['pdflatex_env'] ?? 'CATALOG_PDFLATEX_BIN');
        $configuredBinary = getenv($binaryEnvironment);
        $this->pdflatexBinary = is_string($configuredBinary) && $configuredBinary !== ''
            ? $configuredBinary
            : (string) ($latexConfig['default_binary'] ?? 'pdflatex');
        $this->pdfUrlPrefix = rtrim((string) ($latexConfig['pdf_url_prefix'] ?? '/storage/latex-pdfs'), '/');
    }

    /**
     * List global templates ordered by most recent update.
     *
     * @return list<array<string, mixed>>
     */
    public function listGlobalTemplates(): array
    {
        return array_map(fn (array $row): array => $this->normalizeTemplateRow($row), $this->latex->listGlobalTemplates());
    }

    /**
     * Fetch a single global template by id.
     */
    public function getGlobalTemplate(int $id): ?array
    {
        $row = $this->latex->findGlobalTemplate($id);
        return $row === null ? null : $this->normalizeTemplateRow($row);
    }

    /**
     * Create a new global template and return the persisted record.
     */
    public function createGlobalTemplate(string $title, string $description, string $latexCode): array
    {
        $id = $this->latex->insertGlobalTemplate($title, $description, $latexCode);
        return $this->getGlobalTemplate($id) ?? [];
    }

    /**
     * Update an existing global template and return the persisted record.
     */
    public function updateGlobalTemplate(int $id, string $title, string $description, string $latexCode): ?array
    {
        $this->latex->updateGlobalTemplate($id, $title, $description, $latexCode);
        return $this->getGlobalTemplate($id);
    }

    /**
     * Delete a global template by id.
     */
    public function deleteGlobalTemplate(int $id): bool
    {
        return $this->latex->deleteGlobalTemplate($id);
    }

    /**
     * List templates for a specific series (including global templates).
     *
     * @return list<array<string, mixed>>
     */
    public function listSeriesTemplates(int $seriesId): array
    {
        return array_map(fn (array $row): array => $this->normalizeTemplateRow($row), $this->latex->listSeriesTemplates($seriesId));
    }

    /**
     * Fetch a single template by id (global or series).
     */
    public function getTemplate(int $id): ?array
    {
        $row = $this->latex->findTemplate($id);
        return $row === null ? null : $this->normalizeTemplateRow($row);
    }

    /**
     * Create a new series template.
     */
    public function createSeriesTemplate(int $seriesId, string $title, string $description, string $latexCode): array
    {
        $id = $this->latex->insertSeriesTemplate($seriesId, $title, $description, $latexCode);
        return $this->getTemplate($id) ?? [];
    }

    /**
     * Update an existing series template.
     */
    public function updateSeriesTemplate(int $id, int $seriesId, string $title, string $description, string $latexCode): ?array
    {
        $this->latex->updateSeriesTemplate($id, $seriesId, $title, $description, $latexCode);
        return $this->getTemplate($id);
    }

    /**
     * Compile LaTeX for a series.
     *
     * @return array{url: string, path: string}
     */
    public function compileLatex(string $latex, int $seriesId): array
    {
        $seriesDetails = $this->catalog->getSeriesDetails($seriesId, false);
        if (!$seriesDetails) {
            throw new RuntimeException('Series not found');
        }

        foreach ($seriesDetails['metadata'] as $meta) {
            $latex = str_replace($meta['key'], $meta['value'], $latex);
        }
        foreach ($seriesDetails['customFields'] as $field) {
            $latex = str_replace($field['key'], $field['label'], $latex);
        }

        if (preg_match('/\\\\begin\{spec_rows\}(.*?)\\\\end\{spec_rows\}/s', $latex, $matches)) {
            $rowTemplate = $matches[1];
            $productsLatex = '';
            foreach ($this->latex->getSeriesProducts($seriesId) as $product) {
                $row = $rowTemplate;
                $row = str_replace('product_name', $product['name'], $row);
                $row = str_replace('product_sku', $product['sku'], $row);
                foreach ($this->latex->getProductAttributes((int) $product['id']) as $attribute) {
                    $row = str_replace($attribute['field_key'], $attribute['value'] ?? '', $row);
                }
                $productsLatex .= $row;
            }

            $latex = str_replace($matches[0], $productsLatex, $latex);
        }

        $buildDir = $this->buildStorageDir;
        if (!is_dir($buildDir)) {
            mkdir($buildDir, 0777, true);
        }

        $jobId = uniqid('latex_');
        $texFile = $buildDir . '/' . $jobId . '.tex';
        file_put_contents($texFile, $latex);

        $cmd = escapeshellarg($this->pdflatexBinary) . ' -interaction=nonstopmode -output-directory=' . escapeshellarg($buildDir) . ' ' . escapeshellarg($texFile);
        exec($cmd, $output, $returnVar);

        $pdfFile = $buildDir . '/' . $jobId . '.pdf';
        if (!file_exists($pdfFile)) {
            throw new RuntimeException("PDF Compilation failed. Log: " . implode("\n", $output));
        }

        $storageDir = $this->pdfStorageDir;
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }

        $finalPdfName = 'series_' . $seriesId . '_' . date('YmdHis') . '.pdf';
        $finalPdfPath = $storageDir . '/' . $finalPdfName;
        rename($pdfFile, $finalPdfPath);

        @unlink($texFile);
        @unlink($buildDir . '/' . $jobId . '.log');
        @unlink($buildDir . '/' . $jobId . '.aux');

        return [
            'url' => $this->pdfUrlPrefix . '/' . $finalPdfName,
            'path' => $finalPdfPath,
        ];
    }

    /**
     * List global variables ordered by key name.
     *
     * @return list<array<string, mixed>>
     */
    public function listGlobalVariables(): array
    {
        return array_map(fn (array $row): array => $this->normalizeVariableRow($row), $this->latex->listGlobalVariables());
    }

    /**
     * Create or update a global variable and return the persisted record.
     */
    public function saveGlobalVariable(string $key, string $inputType, string $value, ?int $id = null): ?array
    {
        $normalizedType = $this->normalizeVariableType($inputType);
        if ($id) {
            $this->latex->updateGlobalVariable($id, $key, $normalizedType, $value);
            return $this->getGlobalVariable($id);
        }

        $id = $this->latex->insertGlobalVariable($key, $normalizedType, $value);
        return $this->getGlobalVariable($id);
    }

    /**
     * Delete a global variable by id.
     */
    public function deleteGlobalVariable(int $id): bool
    {
        return $this->latex->deleteGlobalVariable($id);
    }

    /**
     * Fetch a single global variable by id.
     */
    public function getGlobalVariable(int $id): ?array
    {
        $row = $this->latex->findGlobalVariable($id);
        return $row === null ? null : $this->normalizeVariableRow($row);
    }

    /**
     * Normalize a latex_templates row into an API-friendly shape.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeTemplateRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'latex' => (string) ($row['latex_code'] ?? ''),
            'isGlobal' => (bool) $row['is_global'],
            'seriesId' => isset($row['series_id']) ? (int) $row['series_id'] : null,
            'lastPdfPath' => $row['last_pdf_path'] ?? null,
            'lastPdfGeneratedAt' => $row['last_pdf_generated_at'] ?? null,
            'downloadUrl' => $this->buildPdfUrl($row['last_pdf_path'] ?? null),
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * Normalize a latex_variables row into an API-friendly shape.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeVariableRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'key' => (string) $row['field_key'],
            'type' => (string) $row['field_type'],
            'value' => $row['field_value'] ?? '',
            'isGlobal' => (bool) $row['is_global'],
            'seriesId' => isset($row['series_id']) ? (int) $row['series_id'] : null,
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * Map UI type to storage type.
     */
    private function normalizeVariableType(string $inputType): string
    {
        $trimmed = strtolower(trim($inputType));
        return ($trimmed === 'file' || $trimmed === 'image') ? 'image' : 'text';
    }

    /**
     * Build the full URL for a PDF file path.
     */
    private function buildPdfUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $relativePath = ltrim(str_replace('\\', '/', $path), '/');
        return $this->pdfUrlPrefix . '/' . $relativePath;
    }

}
