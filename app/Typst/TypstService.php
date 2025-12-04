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
    private string $assetStorageDir;
    /** @var array<string, string> */
    private array $copiedAssets = [];

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Db::connection();
        $config = Config::get('app');
        $this->pdfUrlPrefix = rtrim((string) ($config['typst']['pdf_url_prefix'] ?? 'storage/typst-pdfs'), '/');
        $this->assetStorageDir = rtrim(__DIR__ . '/../../public/storage/typst-assets', "/\\");
        
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
    public function createGlobalTemplate(string $title, string $description, string $typstCode, ?string $pdfPath = null): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO typst_templates (title, description, typst_content, is_global, series_id, last_pdf_path, last_pdf_generated_at)
             VALUES (?, ?, ?, 1, NULL, ?, ?)'
        );
        $generatedAt = $pdfPath ? date('Y-m-d H:i:s') : null;
        $stmt->bind_param('sssss', $title, $description, $typstCode, $pdfPath, $generatedAt);
        $stmt->execute();

        return $this->getGlobalTemplate((int) $this->db->insert_id) ?? [];
    }

    /**
     * Update global template.
     */
    public function updateGlobalTemplate(int $id, string $title, string $description, string $typstCode, ?string $pdfPath = null): ?array
    {
        $sql = 'UPDATE typst_templates SET title = ?, description = ?, typst_content = ?, updated_at = CURRENT_TIMESTAMP';
        $params = [$title, $description, $typstCode];
        $types = 'sss';

        if ($pdfPath !== null) {
            $sql .= ', last_pdf_path = ?, last_pdf_generated_at = ?';
            $params[] = $pdfPath;
            $params[] = date('Y-m-d H:i:s');
            $types .= 'ss';
        }

        $sql .= ' WHERE id = ? AND is_global = 1';
        $params[] = $id;
        $types .= 'i';

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
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
    public function createSeriesTemplate(int $seriesId, string $title, string $description, string $typstCode, ?string $pdfPath = null): array
    {
        error_log("TypstService::createSeriesTemplate pdfPath=" . var_export($pdfPath, true));
        $stmt = $this->db->prepare(
            'INSERT INTO typst_templates (title, description, typst_content, is_global, series_id, last_pdf_path, last_pdf_generated_at)
             VALUES (?, ?, ?, 0, ?, ?, ?)'
        );
        $generatedAt = $pdfPath ? date('Y-m-d H:i:s') : null;
        $stmt->bind_param('sssiss', $title, $description, $typstCode, $seriesId, $pdfPath, $generatedAt);
        $stmt->execute();

        return $this->getTemplate((int) $this->db->insert_id) ?? [];
    }

    /**
     * Update series template.
     */
    public function updateSeriesTemplate(int $id, int $seriesId, string $title, string $description, string $typstCode, ?string $pdfPath = null): ?array
    {
        error_log("TypstService::updateSeriesTemplate pdfPath=" . var_export($pdfPath, true));
        $sql = 'UPDATE typst_templates SET title = ?, description = ?, typst_content = ?, updated_at = CURRENT_TIMESTAMP';
        $params = [$title, $description, $typstCode];
        $types = 'sss';

        if ($pdfPath !== null) {
            $sql .= ', last_pdf_path = ?, last_pdf_generated_at = ?';
            $params[] = $pdfPath;
            $params[] = date('Y-m-d H:i:s');
            $types .= 'ss';
        }

        $sql .= ' WHERE id = ? AND series_id = ?';
        $params[] = $id;
        $params[] = $seriesId;
        $types .= 'ii';

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
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
        // Prepare build directory up front so assets can be staged for relative loading.
        $buildDir = __DIR__ . '/../../storage/typst-build';
        if (!is_dir($buildDir)) {
            mkdir($buildDir, 0777, true);
        }

        $jobId = uniqid('typst_');

        // 1. Generate Data Header
        $dataHeaderResult = $this->generateDataHeader($seriesId, $buildDir);
        $dataHeader = $dataHeaderResult['header'];
        $safeData = $dataHeaderResult['safeData'];
        $placeholderValues = $this->buildPlaceholderMap($safeData);
        
        // 2. Combine Header and Code
        // We prepend the data variables so they are available in the user's script and replace {{placeholders}} with concrete values.
        $renderedTypst = $this->replacePlaceholders($typstCode, $placeholderValues);
        $fullSource = $dataHeader . "\n" . $renderedTypst;

        // Stage inline assets referenced directly in the Typst code (e.g., #image("foo.png")).
        $this->stageInlineAssets($renderedTypst, $buildDir);

        // 3. Save to Temp File
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
        $finalPdfPath = str_replace('\\', '/', realpath($storageDir) . '/' . $finalPdfName);
        rename($pdfFile, $finalPdfPath);
        
        // Cleanup
        @unlink($inputFile);
        
        return [
            'url' => $this->pdfUrlPrefix . '/' . $finalPdfName,
            'path' => $finalPdfPath
        ];
    }

    /**
     * Build the Typst data header and return sanitized data for placeholder mapping.
     *
     * @param string|null $seriesId
     * @param string $buildDir Directory where assets should be staged for Typst.
     * @return array{header: string, safeData: array<string, mixed>}
     */
    private function generateDataHeader(?int $seriesId, string $buildDir): array
    {
        $header = "// Auto-generated Data Header\n";
        
        // Global Variables
        $globalVars = $this->listGlobalVariables();
        foreach ($globalVars as $var) {
            $resolvedValue = $this->resolveAssetPath($var['value'], $buildDir, true);
            // Sanitize key to be a valid identifier if possible, or use a dictionary
            // Typst identifiers: letters, numbers, underscores, hyphens (but hyphens are minus)
            // Best to put them in a dictionary: #let globals = (...)
            // But for backward compatibility with "simple" replacement, maybe top level vars?
            // Let's use a dictionary `globals`.
        }
        
        // Construct a `data` dictionary
        $data = ['globals' => []];
        foreach ($globalVars as $var) {
            $data['globals'][$var['key']] = $this->resolveAssetPath($var['value'], $buildDir);
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
                    $data['metadata'][$m['key']] = $this->resolveAssetPath($m['value'], $buildDir, true);
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
                        $resolved = $this->resolveAssetPath($attr['value'] ?? '', $buildDir, true);
                        $pData['attributes'][$attr['field_key']] = $resolved;
                        // Also put at top level of product for easy access
                        $pData[$attr['field_key']] = $resolved;
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

        return [
            'header' => $header,
            'safeData' => $safeData,
        ];
    }

    /**
     * Flatten key/value placeholders ({{key}}) for easy Typst authoring.
     *
     * @param array<string, mixed> $safeData
     * @return array<string, string>
     */
    private function buildPlaceholderMap(array $safeData): array
    {
        $map = [];

        foreach ($safeData['globals'] ?? [] as $key => $value) {
            $map[$key] = $this->stringifyPlaceholderValue($value);
        }

        foreach ($safeData['metadata'] ?? [] as $key => $value) {
            $map[$key] = $this->stringifyPlaceholderValue($value);
        }

        // Use first product's attributes for quick access (series page is product-centric).
        $firstProduct = $safeData['products'][0] ?? null;
        if (is_array($firstProduct)) {
            foreach (($firstProduct['attributes'] ?? []) as $key => $value) {
                $map[$key] = $this->stringifyPlaceholderValue($value);
            }
        }

        return $map;
    }

    /**
     * Stage assets referenced directly in Typst code (e.g., #image("path")) into the build directory.
     */
    private function stageInlineAssets(string $typstCode, string $buildDir): void
    {
        if (trim($typstCode) === '') {
            return;
        }
        $matches = [];
        preg_match_all('/#image\\s*\\(\\s*"([^"]+)"/', $typstCode, $matches);
        $paths = array_unique($matches[1] ?? []);
        foreach ($paths as $path) {
            $this->resolveAssetPath($path, $buildDir, true);
        }
    }

    /**
     * Replace {{key}} placeholders with concrete values prior to Typst compilation.
     *
     * @param array<string, string> $values
     */
    private function replacePlaceholders(string $typstCode, array $values): string
    {
        return (string) preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', function ($matches) use ($values) {
            $key = $matches[1];
            if (!array_key_exists($key, $values)) {
                return $matches[0];
            }
            return $values[$key];
        }, $typstCode);
    }

    /**
     * Normalize placeholder values to strings.
     *
     * @param mixed $value
     */
    private function stringifyPlaceholderValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
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

    /**
     * Resolve and stage asset paths (e.g., images) into the Typst build directory, returning a path Typst can load.
     *
     * @param mixed $value
     */
    private function resolveAssetPath($value, string $buildDir, bool $createPlaceholder = false)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $normalized = str_replace('\\', '/', $value);
        // Skip URLs
        if (filter_var($normalized, FILTER_VALIDATE_URL)) {
            return $normalized;
        }

        $projectRoot = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
        $candidates = [];

        // Absolute path as-is
        if (str_starts_with($normalized, '/')) {
            $candidates[] = $normalized;
        } elseif (preg_match('/^[A-Za-z]:\\//', $normalized) === 1) { // Windows absolute
            $candidates[] = $normalized;
        }

        // Relative to project root, public, and storage/media
        $candidates[] = $projectRoot . '/' . ltrim($normalized, '/');
        $candidates[] = $projectRoot . '/public/' . ltrim($normalized, '/');
        $candidates[] = $projectRoot . '/storage/media/' . ltrim($normalized, '/');
        $candidates[] = $projectRoot . '/public/storage/' . ltrim($normalized, '/');
        $candidates[] = $projectRoot . '/storage/' . ltrim($normalized, '/');

        $sourcePath = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $sourcePath = $candidate;
                break;
            }
        }

        if ($sourcePath === null) {
            // Create a placeholder image if requested to avoid compilation failures.
            if ($createPlaceholder) {
                $relativePath = ltrim($normalized, '/');
                $destPath = $buildDir . '/' . $relativePath;
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0777, true);
                }
                if (!is_file($destPath)) {
                    $base64Png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
                    file_put_contents($destPath, base64_decode($base64Png));
                }
                return $relativePath;
            }
            return $value;
        }

        $relativePath = ltrim(str_replace('\\', '/', $normalized), '/');
        $destPath = $buildDir . '/' . $relativePath;

        if (!isset($this->copiedAssets[$sourcePath])) {
            $destDir = dirname($destPath);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0777, true);
            }
            @copy($sourcePath, $destPath);
            $this->copiedAssets[$sourcePath] = $destPath;
        }

        // Return a path Typst can read relative to the build directory.
        return $relativePath;
    }

    /**
     * Persist an uploaded image into the public Typst assets directory and return its relative path.
     *
     * @param array<string, mixed> $fileUpload
     */
    private function storeUploadedAsset(array $fileUpload): string
    {
        $error = (int) ($fileUpload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed.');
        }

        $tmpPath = (string) ($fileUpload['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('Invalid upload payload.');
        }

        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            throw new RuntimeException('Uploaded file is not a valid image.');
        }

        $original = (string) ($fileUpload['name'] ?? 'upload');
        $base = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo($original, PATHINFO_FILENAME)) ?: 'asset';
        $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
        $extension = $extension !== '' ? '.' . $extension : '';
        $unique = str_replace('.', '_', uniqid('typst_', true));
        $filename = $base . '_' . $unique . $extension;

        if (!is_dir($this->assetStorageDir) && !mkdir($this->assetStorageDir, 0777, true) && !is_dir($this->assetStorageDir)) {
            throw new RuntimeException('Unable to create asset storage directory.');
        }

        $destination = $this->assetStorageDir . '/' . $filename;
        if (!move_uploaded_file($tmpPath, $destination)) {
            throw new RuntimeException('Failed to store uploaded asset.');
        }

        return 'typst-assets/' . $filename;
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

    /**
     * Create or update a global variable and optionally persist an uploaded image asset.
     *
     * @param array<string, mixed>|null $fileUpload
     */
    public function saveGlobalVariable(string $key, string $inputType, string $value, ?int $id = null, ?array $fileUpload = null): ?array
    {
        $key = trim($key);
        $normalizedType = $this->normalizeVariableType($inputType);
        $valueToStore = $value;

        if ($normalizedType === 'image' && $fileUpload !== null && ($fileUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int) ($fileUpload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('File upload failed.');
            }
            $valueToStore = $this->storeUploadedAsset($fileUpload);
        }

        if ($id) {
            $stmt = $this->db->prepare(
                'UPDATE typst_variables
                 SET field_key = ?, field_type = ?, field_value = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND is_global = 1'
            );
            $stmt->bind_param('sssi', $key, $normalizedType, $valueToStore, $id);
            $stmt->execute();
            return $this->getGlobalVariable($id);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO typst_variables (field_key, field_type, field_value, is_global, series_id)
             VALUES (?, ?, ?, 1, NULL)'
        );
        $stmt->bind_param('sss', $key, $normalizedType, $valueToStore);
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
        $preview = $this->buildAssetPreview((string) ($row['field_value'] ?? ''));
        return [
            'id' => (int) $row['id'],
            'key' => (string) $row['field_key'],
            'type' => (string) $row['field_type'],
            'value' => $row['field_value'] ?? '',
            'previewUrl' => $preview['url'],
            'previewDataUrl' => $preview['dataUri'],
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
        // If path contains 'public', strip everything before it.
        // Simply use the prefix + basename, assuming all PDFs are in the configured directory.
        return $this->pdfUrlPrefix . '/' . basename($path);
    }

    /**
     * Attempt to convert a stored file/image path into a web-accessible URL or data URI for previews.
     *
     * @return array{url: string|null, dataUri: string|null}
     */
    private function buildAssetPreview(string $value): array
    {
        if ($value === '') {
            return ['url' => null, 'dataUri' => null];
        }

        // If already a URL, return as-is.
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return ['url' => $value, 'dataUri' => null];
        }

        $normalized = str_replace('\\', '/', $value);
        $projectRoot = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
        $publicRoot = realpath(__DIR__ . '/../../public') ?: (__DIR__ . '/../../public');

        $candidates = [];

        // Absolute path as-is
        if (str_starts_with($normalized, '/')) {
            $candidates[] = $normalized;
        } elseif (preg_match('/^[A-Za-z]:\\//', $normalized) === 1) { // Windows absolute
            $candidates[] = $normalized;
        }

        // Relative paths to try
        $candidates[] = $projectRoot . '/' . ltrim($normalized, '/');
        $candidates[] = $publicRoot . '/' . ltrim($normalized, '/');
        $candidates[] = $publicRoot . '/storage/' . ltrim($normalized, '/');
        $candidates[] = $projectRoot . '/storage/' . ltrim($normalized, '/');
        $candidates[] = $projectRoot . '/storage/media/' . ltrim($normalized, '/');

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real === false || !is_file($real)) {
                continue;
            }
            $real = str_replace('\\', '/', $real);
            $publicRootNorm = str_replace('\\', '/', $publicRoot);
            if (str_starts_with($real, $publicRootNorm)) {
                $relative = ltrim(substr($real, strlen($publicRootNorm)), '/');
                $webPath = ltrim($relative, '/');
                return ['url' => $webPath === '' ? null : $webPath, 'dataUri' => null];
            }

            // Not under public, fall back to data URI (limit size to avoid huge payloads).
            $dataUri = $this->fileToDataUri($real, 1024 * 1024); // 1MB cap
            if ($dataUri !== null) {
                return ['url' => null, 'dataUri' => $dataUri];
            }
        }

        // Last resort: treat value as relative URL
        $fallback = ltrim($normalized, '/');
        return ['url' => $fallback === '' ? null : $fallback, 'dataUri' => null];
    }

    /**
     * Convert a file into a data URI if size within limit.
     */
    private function fileToDataUri(string $path, int $maxBytes): ?string
    {
        if (!is_file($path) || filesize($path) === false || filesize($path) > $maxBytes) {
            return null;
        }
        $mime = function_exists('mime_content_type') ? @mime_content_type($path) : 'application/octet-stream';
        if ($mime === false || $mime === '') {
            $mime = 'application/octet-stream';
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        return 'data:' . $mime . ';base64,' . base64_encode($contents);
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
