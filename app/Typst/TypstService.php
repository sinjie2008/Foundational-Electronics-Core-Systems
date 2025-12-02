<?php
declare(strict_types=1);

namespace App\Typst;

use App\Support\Config;
use App\Support\Db;
use mysqli;
use RuntimeException;
use App\Catalog\CatalogService;

/**
 * Service for managing Typst templates and global variables.
 */
final class TypstService
{
    private mysqli $db;
    private string $pdfUrlPrefix;
    private string $typstBinary;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Db::connection();
        $config = Config::get('app');
        $this->pdfUrlPrefix = rtrim((string) ($config['typst']['pdf_url_prefix'] ?? 'storage/typst-pdfs'), '/');
        
        // Determine Typst binary location
        $localBin = __DIR__ . '/../../bin/typst.exe';
        if (file_exists($localBin)) {
            $this->typstBinary = $localBin;
        } else {
            $this->typstBinary = 'typst'; // Fallback to PATH
        }
    }

    /**
     * List global templates.
     */
    public function listGlobalTemplates(): array
    {
        $templates = [];
        $result = $this->db->query(
            'SELECT id, title, description, typst_content, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM typst_templates
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
     * Get a global template.
     */
    public function getGlobalTemplate(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, title, description, typst_content, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM typst_templates
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
     * Create global template.
     */
    public function createGlobalTemplate(string $title, string $description, string $typstCode): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO typst_templates (title, description, typst_content, is_global, series_id)
             VALUES (?, ?, ?, 1, NULL)'
        );
        $stmt->bind_param('sss', $title, $description, $typstCode);
        $stmt->execute();

        return $this->getGlobalTemplate((int) $this->db->insert_id) ?? [];
    }

    /**
     * Update global template.
     */
    public function updateGlobalTemplate(int $id, string $title, string $description, string $typstCode): ?array
    {
        $stmt = $this->db->prepare(
            'UPDATE typst_templates
             SET title = ?, description = ?, typst_content = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND is_global = 1'
        );
        $stmt->bind_param('sssi', $title, $description, $typstCode, $id);
        $stmt->execute();

        return $this->getGlobalTemplate($id);
    }

    /**
     * Delete global template.
     */
    public function deleteGlobalTemplate(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM typst_templates WHERE id = ? AND is_global = 1 LIMIT 1');
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    /**
     * List series templates.
     */
    public function listSeriesTemplates(int $seriesId): array
    {
        $templates = [];
        $stmt = $this->db->prepare(
            'SELECT id, title, description, typst_content, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM typst_templates
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
     * Get template (global or series).
     */
    public function getTemplate(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, title, description, typst_content, is_global, series_id, last_pdf_path, last_pdf_generated_at, created_at, updated_at
             FROM typst_templates
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
     * Create series template.
     */
    public function createSeriesTemplate(int $seriesId, string $title, string $description, string $typstCode): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO typst_templates (title, description, typst_content, is_global, series_id)
             VALUES (?, ?, ?, 0, ?)'
        );
        $stmt->bind_param('sssi', $title, $description, $typstCode, $seriesId);
        $stmt->execute();

        return $this->getTemplate((int) $this->db->insert_id) ?? [];
    }

    /**
     * Update series template.
     */
    public function updateSeriesTemplate(int $id, int $seriesId, string $title, string $description, string $typstCode): ?array
    {
        $stmt = $this->db->prepare(
            'UPDATE typst_templates
             SET title = ?, description = ?, typst_content = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND series_id = ?'
        );
        $stmt->bind_param('sssii', $title, $description, $typstCode, $id, $seriesId);
        $stmt->execute();

        return $this->getTemplate($id);
    }

    /**
     * Compile Typst code.
     * 
     * @param string $typstCode The user's Typst code.
     * @param int|null $seriesId If provided, injects series data.
     */
    public function compileTypst(string $typstCode, ?int $seriesId = null): array
    {
        // 1. Generate Data Header
        $dataHeader = $this->generateDataHeader($seriesId);
        
        // 2. Combine Header and Code
        // We prepend the data variables so they are available in the user's script.
        $fullSource = $dataHeader . "\n" . $typstCode;

        // 3. Save to Temp File
        $buildDir = __DIR__ . '/../../storage/typst-build';
        if (!is_dir($buildDir)) mkdir($buildDir, 0777, true);
        
        $jobId = uniqid('typst_');
        $inputFile = $buildDir . '/' . $jobId . '.typ';
        file_put_contents($inputFile, $fullSource);
        
        // 4. Compile
        // Command: typst compile <input> <output>
        // We set the root to the project root so images can be resolved relative to it if needed?
        // Actually, Typst defaults root to input file dir. We might want to set --root.
        // Let's assume images are absolute or relative to public.
        // For now, simple compilation.
        
        $pdfFile = $buildDir . '/' . $jobId . '.pdf';
        
        // Escape paths
        $cmd = escapeshellcmd($this->typstBinary) . " compile " . escapeshellarg($inputFile) . " " . escapeshellarg($pdfFile);
        
        // Capture output
        $output = [];
        $returnVar = 0;
        exec($cmd . " 2>&1", $output, $returnVar);
        
        if ($returnVar !== 0 || !file_exists($pdfFile)) {
             // Clean up?
             // throw new RuntimeException("Typst Compilation failed.\nCommand: $cmd\nOutput: " . implode("\n", $output));
             // Return error structure instead of throwing, so UI can show it?
             // The LatexService threw exception.
             throw new RuntimeException("Typst Compilation failed:\n" . implode("\n", $output));
        }
        
        // 5. Move to Public Storage
        $storageDir = __DIR__ . '/../../public/storage/typst-pdfs';
        if (!is_dir($storageDir)) mkdir($storageDir, 0777, true);
        
        $finalPdfName = ($seriesId ? 'series_' . $seriesId : 'global') . '_' . date('YmdHis') . '.pdf';
        $finalPdfPath = $storageDir . '/' . $finalPdfName;
        rename($pdfFile, $finalPdfPath);
        
        // Cleanup
        @unlink($inputFile);
        
        return [
            'url' => $this->pdfUrlPrefix . '/' . $finalPdfName,
            'path' => $finalPdfPath
        ];
    }

    private function generateDataHeader(?int $seriesId): string
    {
        $header = "// Auto-generated Data Header\n";
        
        // Global Variables
        $globalVars = $this->listGlobalVariables();
        foreach ($globalVars as $var) {
            // Sanitize key to be a valid identifier if possible, or use a dictionary
            // Typst identifiers: letters, numbers, underscores, hyphens (but hyphens are minus)
            // Best to put them in a dictionary: #let globals = (...)
            // But for backward compatibility with "simple" replacement, maybe top level vars?
            // Let's use a dictionary `globals`.
        }
        
        // Construct a `data` dictionary
        $data = ['globals' => []];
        foreach ($globalVars as $var) {
            $data['globals'][$var['key']] = $var['value'];
        }

        if ($seriesId) {
            $catalogService = new CatalogService($this->db);
            $seriesDetails = $catalogService->getSeriesDetails($seriesId);
            
            if ($seriesDetails) {
                $data['series'] = [
                    'name' => $seriesDetails['name'] ?? '',
                    'id' => $seriesDetails['id'] ?? '',
                    // Flatten metadata
                ];
                
                $data['metadata'] = [];
                foreach ($seriesDetails['metadata'] ?? [] as $m) {
                    $data['metadata'][$m['key']] = $m['value'];
                }
                
                $data['products'] = [];
                
                // Fetch products (logic copied from LatexService)
                $stmt = $this->db->prepare("SELECT id, name, sku FROM product WHERE series_id = ? ORDER BY sku ASC");
                $stmt->bind_param('i', $seriesId);
                $stmt->execute();
                $productsResult = $stmt->get_result();
                
                while ($product = $productsResult->fetch_assoc()) {
                    $pData = [
                        'name' => $product['name'],
                        'sku' => $product['sku'],
                        'attributes' => []
                    ];
                    
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
                        $pData['attributes'][$attr['field_key']] = $attr['value'] ?? '';
                        // Also put at top level of product for easy access
                        $pData[$attr['field_key']] = $attr['value'] ?? '';
                    }
                    $pStmt->close();
                    
                    $data['products'][] = $pData;
                }
                $stmt->close();
            }
        }

        // Convert $data to Typst syntax
        // #let data = ( ... )
        $safeData = $this->sanitizeTypstStructure($data);
        $header .= "#let data = " . $this->toTypstValue($safeData) . "\n";
        
        // Helper aliases for easier access
        $header .= "#let globals = data.globals\n";
        if ($seriesId) {
            $header .= "#let series = data.series\n";
            $header .= "#let metadata = data.metadata\n";
            $header .= "#let products = data.products\n";
        }

        return $header;
    }

    /**
     * Convert a PHP value into Typst literal syntax.
     *
     * @param mixed $value
     */
    private function toTypstValue($value)
    {
        if (is_array($value)) {
            // Check if associative (dictionary) or sequential (array)
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if (empty($value)) return "()";
            
            $items = [];
            if ($isAssoc) {
                foreach ($value as $k => $v) {
                    $items[] = $k . ": " . $this->toTypstValue($v);
                }
                return "(" . implode(", ", $items) . ")";
            } else {
                foreach ($value as $v) {
                    $items[] = $this->toTypstValue($v);
                }
                return "(" . implode(", ", $items) . ")";
            }
        } elseif (is_string($value)) {
            // Escape string
            return '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $value) . '"';
        } elseif (is_bool($value)) {
            return $value ? "true" : "false";
        } elseif (is_numeric($value)) {
            return $value;
        } elseif (is_null($value)) {
            return "none";
        }
        return '""';
    }

    /**
     * Recursively sanitize associative keys to Typst-safe identifiers.
     *
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeTypstStructure($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $isAssoc = array_keys($value) !== range(0, count($value) - 1);
        if (!$isAssoc) {
            return array_map([$this, 'sanitizeTypstStructure'], $value);
        }

        $result = [];
        $usedKeys = [];
        foreach ($value as $key => $item) {
            $baseKey = $this->sanitizeTypstKey((string) $key);
            $safeKey = $this->ensureUniqueTypstKey($baseKey, $usedKeys);
            $usedKeys[$safeKey] = true; // track to avoid collisions
            $result[$safeKey] = $this->sanitizeTypstStructure($item);
        }

        return $result;
    }

    /**
     * Normalize a string into a Typst-safe identifier.
     */
    private function sanitizeTypstKey(string $key): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
        $normalized = ltrim((string) $normalized, '_');
        if ($normalized === '') {
            $normalized = 'key';
        }
        if (preg_match('/^[0-9]/', $normalized) === 1) {
            $normalized = '_' . $normalized;
        }

        return $normalized;
    }

    /**
     * Ensure sanitized keys stay unique within the same dictionary by suffixing when needed.
     *
     * @param array<string, bool> $usedKeys
     */
    private function ensureUniqueTypstKey(string $baseKey, array $usedKeys): string
    {
        $candidate = $baseKey;
        $suffix = 1;
        while (isset($usedKeys[$candidate])) {
            $candidate = $baseKey . '_' . $suffix;
            $suffix++;
        }
        return $candidate;
    }

    // Variable CRUD methods...
    public function listGlobalVariables(): array
    {
        $variables = [];
        $result = $this->db->query(
            'SELECT id, field_key, field_type, field_value, is_global, series_id, created_at, updated_at
             FROM typst_variables
             WHERE is_global = 1
               AND (series_id IS NULL OR series_id = 0)
             ORDER BY field_key ASC'
        );
        while ($row = $result->fetch_assoc()) {
            $variables[] = $this->normalizeVariableRow($row);
        }
        return $variables;
    }

    public function saveGlobalVariable(string $key, string $inputType, string $value, ?int $id = null): ?array
    {
        $normalizedType = $this->normalizeVariableType($inputType);

        if ($id) {
            $stmt = $this->db->prepare(
                'UPDATE typst_variables
                 SET field_key = ?, field_type = ?, field_value = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND is_global = 1'
            );
            $stmt->bind_param('sssi', $key, $normalizedType, $value, $id);
            $stmt->execute();
            return $this->getGlobalVariable($id);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO typst_variables (field_key, field_type, field_value, is_global, series_id)
             VALUES (?, ?, ?, 1, NULL)'
        );
        $stmt->bind_param('sss', $key, $normalizedType, $value);
        $stmt->execute();

        return $this->getGlobalVariable((int) $this->db->insert_id);
    }

    public function deleteGlobalVariable(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM typst_variables WHERE id = ? AND is_global = 1 LIMIT 1');
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    public function getGlobalVariable(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, field_key, field_type, field_value, is_global, series_id, created_at, updated_at
             FROM typst_variables
             WHERE id = ? AND is_global = 1
             LIMIT 1'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ? $this->normalizeVariableRow($row) : null;
    }

    private function normalizeTemplateRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'typst' => (string) ($row['typst_content'] ?? ''),
            'isGlobal' => (bool) $row['is_global'],
            'seriesId' => isset($row['series_id']) ? (int) $row['series_id'] : null,
            'lastPdfPath' => $row['last_pdf_path'] ?? null,
            'lastPdfGeneratedAt' => $row['last_pdf_generated_at'] ?? null,
            'downloadUrl' => $this->buildPdfUrl($row['last_pdf_path'] ?? null),
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }

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

    private function normalizeVariableType(string $inputType): string
    {
        $trimmed = strtolower(trim($inputType));
        if ($trimmed === 'file' || $trimmed === 'image') {
            return 'image';
        }
        return 'text';
    }

    private function buildPdfUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }
        $relativePath = ltrim(str_replace('\\', '/', $path), '/');
        // Assuming path is absolute, we need to make it relative to public
        // But here we stored absolute path in DB? LatexService did:
        // $finalPdfPath = $storageDir . '/' . $finalPdfName;
        // And buildPdfUrl did: $relativePath = ltrim(str_replace('\\', '/', $path), '/');
        // This seems to assume $path is relative?
        // Wait, LatexService: $finalPdfPath = $storageDir . '/' . $finalPdfName; (Absolute)
        // Then buildPdfUrl: $relativePath = ltrim(str_replace('\\', '/', $path), '/');
        // If path is "C:/.../public/storage/...", this doesn't make it a URL.
        // It seems LatexService logic was a bit flawed or relied on $pdfUrlPrefix being absolute?
        // Or maybe $path stored in DB was relative?
        // LatexService: rename($pdfFile, $finalPdfPath); -> $finalPdfPath is Absolute.
        // I will fix this in TypstService to return a proper URL.
        
        // If path contains 'public', strip everything before it.
        // Simply use the prefix + basename, assuming all PDFs are in the configured directory.
        return $this->pdfUrlPrefix . '/' . basename($path);
    }

    /**
     * Delete template by id (global or series).
     */
    public function deleteTemplate(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM typst_templates WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

}
