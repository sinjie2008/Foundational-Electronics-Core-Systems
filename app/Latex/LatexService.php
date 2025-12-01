<?php
declare(strict_types=1);

namespace App\Latex;

use App\Support\Config;
use App\Support\Db;
use mysqli;
use RuntimeException;
use App\Catalog\CatalogService;

/**
 * Service for managing LaTeX templates and global variables.
 */
final class LatexService
{
    private mysqli $db;
    private string $pdfUrlPrefix;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Db::connection();
        $config = Config::get('app');
        $this->pdfUrlPrefix = rtrim((string) ($config['latex']['pdf_url_prefix'] ?? ''), '/');
    }

    /**
     * List global templates ordered by most recent update.
     *
     * @return list<array<string, mixed>>
     */
    public function listGlobalTemplates(): array
    {
        $templates = [];
        $result = $this->db->query(
            'SELECT id, title, description, latex_code, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM latex_templates
             WHERE is_global = 1
               AND (series_id IS NULL OR series_id = 0)
             ORDER BY updated_at DESC, id DESC'
        );
        while ($row = $result->fetch_assoc()) {
            $templates[] = $this->normalizeTemplateRow($row);
        }
        return $templates;
    }

    /**
     * Fetch a single global template by id.
     */
    public function getGlobalTemplate(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, title, description, latex_code, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM latex_templates
             WHERE id = ? AND is_global = 1
             LIMIT 1'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ? $this->normalizeTemplateRow($row) : null;
    }

    /**
     * Create a new global template and return the persisted record.
     */
    public function createGlobalTemplate(string $title, string $description, string $latexCode): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO latex_templates (title, description, latex_code, is_global, series_id)
             VALUES (?, ?, ?, 1, NULL)'
        );
        $stmt->bind_param('sss', $title, $description, $latexCode);
        $stmt->execute();

        return $this->getGlobalTemplate((int) $this->db->insert_id) ?? [];
    }

    /**
     * Update an existing global template and return the persisted record.
     */
    public function updateGlobalTemplate(int $id, string $title, string $description, string $latexCode): ?array
    {
        $stmt = $this->db->prepare(
            'UPDATE latex_templates
             SET title = ?, description = ?, latex_code = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND is_global = 1'
        );
        $stmt->bind_param('sssi', $title, $description, $latexCode, $id);
        $stmt->execute();

        return $this->getGlobalTemplate($id);
    }

    /**
     * Delete a global template by id.
     */
    public function deleteGlobalTemplate(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM latex_templates WHERE id = ? AND is_global = 1 LIMIT 1');
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    /**
     * List templates for a specific series (including global templates).
     */
    public function listSeriesTemplates(int $seriesId): array
    {
        $templates = [];
        $stmt = $this->db->prepare(
            'SELECT id, title, description, latex_code, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM latex_templates
             WHERE series_id = ? OR (is_global = 1 AND (series_id IS NULL OR series_id = 0))
             ORDER BY is_global DESC, updated_at DESC'
        );
        $stmt->bind_param('i', $seriesId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $templates[] = $this->normalizeTemplateRow($row);
        }
        return $templates;
    }

    /**
     * Fetch a single template by id (global or series).
     */
    public function getTemplate(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, title, description, latex_code, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM latex_templates
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ? $this->normalizeTemplateRow($row) : null;
    }

    /**
     * Create a new series template.
     */
    public function createSeriesTemplate(int $seriesId, string $title, string $description, string $latexCode): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO latex_templates (title, description, latex_code, is_global, series_id)
             VALUES (?, ?, ?, 0, ?)'
        );
        $stmt->bind_param('sssi', $title, $description, $latexCode, $seriesId);
        $stmt->execute();

        return $this->getTemplate((int) $this->db->insert_id) ?? [];
    }

    /**
     * Update an existing series template.
     */
    public function updateSeriesTemplate(int $id, int $seriesId, string $title, string $description, string $latexCode): ?array
    {
        $stmt = $this->db->prepare(
            'UPDATE latex_templates
             SET title = ?, description = ?, latex_code = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND series_id = ?'
        );
        $stmt->bind_param('sssii', $title, $description, $latexCode, $id, $seriesId);
        $stmt->execute();

        return $this->getTemplate($id);
    }

    /**
     * Compile LaTeX for a series.
     */
    public function compileLatex(string $latex, int $seriesId): array
    {
        // 1. Fetch Data
        $catalogService = new CatalogService($this->db);
        $seriesDetails = $catalogService->getSeriesDetails($seriesId);
        if (!$seriesDetails) {
            throw new RuntimeException("Series not found");
        }
        
        // 2. Perform Substitutions
        // Metadata
        foreach ($seriesDetails['metadata'] as $meta) {
            $latex = str_replace($meta['key'], $meta['value'], $latex);
        }
        
        // Custom Fields (Definitions)
        foreach ($seriesDetails['customFields'] as $field) {
             $latex = str_replace($field['key'], $field['label'], $latex);
        }

        // Products Loop
        if (preg_match('/\\\\begin\{spec_rows\}(.*?)\\\\end\{spec_rows\}/s', $latex, $matches)) {
            $rowTemplate = $matches[1];
            $productsLatex = '';
            
            $stmt = $this->db->prepare("SELECT id, name, sku FROM product WHERE series_id = ? ORDER BY sku ASC");
            $stmt->bind_param('i', $seriesId);
            $stmt->execute();
            $productsResult = $stmt->get_result();
            
            while ($product = $productsResult->fetch_assoc()) {
                $row = $rowTemplate;
                $row = str_replace('product_name', $product['name'], $row);
                $row = str_replace('product_sku', $product['sku'], $row);
                
                // Fetch product attributes
                $pStmt = $this->db->prepare("
                    SELECT f.field_key, v.value 
                    FROM product_custom_field_value v
                    JOIN series_custom_field f ON v.series_custom_field_id = f.id
                    WHERE v.product_id = ?
                ");
                $pStmt->bind_param('i', $product['id']);
                $pStmt->execute();
                $attrs = $pStmt->get_result();
                while ($attr = $attrs->fetch_assoc()) {
                    $row = str_replace($attr['field_key'], $attr['value'] ?? '', $row);
                }
                $pStmt->close();
                
                $productsLatex .= $row;
            }
            $stmt->close();
            
            $latex = str_replace($matches[0], $productsLatex, $latex);
        }

        // 3. Save to Temp File
        $buildDir = __DIR__ . '/../../../storage/latex-build';
        if (!is_dir($buildDir)) mkdir($buildDir, 0777, true);
        
        $jobId = uniqid('latex_');
        $texFile = $buildDir . '/' . $jobId . '.tex';
        file_put_contents($texFile, $latex);
        
        // 4. Compile
        $cmd = "pdflatex -interaction=nonstopmode -output-directory=" . escapeshellarg($buildDir) . " " . escapeshellarg($texFile);
        exec($cmd, $output, $returnVar);
        
        $pdfFile = $buildDir . '/' . $jobId . '.pdf';
        if (!file_exists($pdfFile)) {
             // Clean up tex file even if failed, or keep for debugging?
             // Keep for debugging if failed
             throw new RuntimeException("PDF Compilation failed. Log: " . implode("\n", $output));
        }
        
        // 5. Move to Public Storage
        $storageDir = __DIR__ . '/../../../public/storage/latex-pdfs';
        if (!is_dir($storageDir)) mkdir($storageDir, 0777, true);
        
        $finalPdfName = 'series_' . $seriesId . '_' . date('YmdHis') . '.pdf';
        $finalPdfPath = $storageDir . '/' . $finalPdfName;
        rename($pdfFile, $finalPdfPath);
        
        // Cleanup
        @unlink($texFile);
        @unlink($buildDir . '/' . $jobId . '.log');
        @unlink($buildDir . '/' . $jobId . '.aux');

        return [
            'url' => '/storage/latex-pdfs/' . $finalPdfName,
            'path' => $finalPdfPath
        ];
    }

    /**
     * List global variables ordered by key name.
     *
     * @return list<array<string, mixed>>
     */
    public function listGlobalVariables(): array
    {
        $variables = [];
        $result = $this->db->query(
            'SELECT id, field_key, field_type, field_value, is_global, series_id, created_at, updated_at
             FROM latex_variables
             WHERE is_global = 1
               AND (series_id IS NULL OR series_id = 0)
             ORDER BY field_key ASC'
        );
        while ($row = $result->fetch_assoc()) {
            $variables[] = $this->normalizeVariableRow($row);
        }
        return $variables;
    }

    /**
     * Create or update a global variable and return the persisted record.
     */
    public function saveGlobalVariable(string $key, string $inputType, string $value, ?int $id = null): ?array
    {
        $normalizedType = $this->normalizeVariableType($inputType);

        if ($id) {
            $stmt = $this->db->prepare(
                'UPDATE latex_variables
                 SET field_key = ?, field_type = ?, field_value = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND is_global = 1'
            );
            $stmt->bind_param('sssi', $key, $normalizedType, $value, $id);
            $stmt->execute();
            return $this->getGlobalVariable($id);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO latex_variables (field_key, field_type, field_value, is_global, series_id)
             VALUES (?, ?, ?, 1, NULL)'
        );
        $stmt->bind_param('sss', $key, $normalizedType, $value);
        $stmt->execute();

        return $this->getGlobalVariable((int) $this->db->insert_id);
    }

    /**
     * Delete a global variable by id.
     */
    public function deleteGlobalVariable(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM latex_variables WHERE id = ? AND is_global = 1 LIMIT 1');
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    /**
     * Fetch a single global variable by id.
     */
    public function getGlobalVariable(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, field_key, field_type, field_value, is_global, series_id, created_at, updated_at
             FROM latex_variables
             WHERE id = ? AND is_global = 1
             LIMIT 1'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ? $this->normalizeVariableRow($row) : null;
    }

    /**
     * Normalize a latex_templates row into an API-friendly shape.
     *
     * @param array<string, mixed> $row
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
        if ($trimmed === 'file' || $trimmed === 'image') {
            return 'image';
        }
        return 'text';
    }
    /**
     * Build the full URL for a PDF file path.
     */
    private function buildPdfUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }
        
        // If the path is already a URL, return it
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        // Clean up the path to ensure it's relative
        $relativePath = ltrim(str_replace('\\', '/', $path), '/');
        
        return $this->pdfUrlPrefix . '/' . $relativePath;
    }
}
