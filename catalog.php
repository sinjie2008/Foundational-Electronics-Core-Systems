<?php
declare(strict_types=1);

/**
 * Catalog management entrypoint using object-oriented services.
 *
 * Responsibilities:
 * - Provide service classes for hierarchy, series fields, products, and CSV/metadata flows.
 * - Expose a bootstrapped HTTP handler for AJAX-driven UI consumption.
 * - Coordinate schema creation and initial data seeding.
 *
 * Specification reference: docs/spec.md (sections 2-4, 9-12).
 */

const CATALOG_SEED_NAME = 'initial_catalog_v1';
const CATALOG_CSV_STORAGE = __DIR__ . '/storage/csv';
const CATALOG_TRUNCATE_AUDIT_LOG = CATALOG_CSV_STORAGE . '/truncate_audit.jsonl';
const CATALOG_TRUNCATE_CONFIRM_TOKEN = 'TRUNCATE';
const CATALOG_TRUNCATE_LOCK_KEY = 'catalog_truncate_lock';
const CATALOG_TRUNCATE_REASON_MAX = 256;
const SERIES_FIELD_SCOPE_SERIES = 'series_metadata';
const SERIES_FIELD_SCOPE_PRODUCT = 'product_attribute';
const LATEX_PDF_STORAGE = __DIR__ . '/storage/latex-pdfs';
const LATEX_BUILD_WORKDIR = __DIR__ . '/storage/latex-build';
const LATEX_PDF_URL_PREFIX = '/storage/latex-pdfs';
const LATEX_PDFLATEX_ENV = 'CATALOG_PDFLATEX_BIN';
const LATEX_DEFAULT_PDFLATEX = 'pdflatex';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Exception type used for API error handling.
 */
class CatalogApiException extends Exception
{
    private int $statusCode;

    private string $errorCode;

    /** @var array<string, mixed> */
    private array $details;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $errorCode,
        string $message,
        int $statusCode = 400,
        array $details = []
    ) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->details = $details;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}

/**
 * Handles HTTP response emission for the catalog API.
 */
final class HttpResponder
{
    /**
     * Emits a JSON payload.
     *
     * @param array<string, mixed> $payload
     */
    public function sendJson(array $payload, int $statusCode = 200): void
    {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }

        try {
            echo json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            http_response_code(500);
            $fallback = [
                'success' => false,
                'errorCode' => 'ENCODING_ERROR',
                'message' => 'Unable to encode response payload.',
                'details' => ['error' => $exception->getMessage()],
            ];
            echo json_encode($fallback);
        }
    }

    /**
     * Emits an error response.
     *
     * @param array<string, mixed> $details
     */
    public function sendError(
        string $errorCode,
        string $message,
        int $statusCode = 400,
        array $details = []
    ): void {
        $this->sendJson(
            [
                'success' => false,
                'errorCode' => $errorCode,
                'message' => $message,
                'details' => $details,
            ],
            $statusCode
        );
    }

    public function sendFile(string $filePath, string $downloadName, string $contentType = 'text/csv'): void
    {
        if (!is_file($filePath)) {
            throw new CatalogApiException('CSV_NOT_FOUND', 'CSV file not found.', 404);
        }
        if (!headers_sent()) {
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }
        $result = readfile($filePath);
        if ($result === false) {
            throw new CatalogApiException('CSV_READ_ERROR', 'Unable to stream CSV file.', 500);
        }
        flush();
    }
}

/**
 * Provides helpers for interpreting HTTP request state.
 */
final class HttpRequestReader
{
    /**
     * Ensures the request method matches expectations.
     *
     * @throws CatalogApiException When the HTTP verb is unexpected.
     */
    public function requireMethod(string $expected, string $actual): void
    {
        if (strcasecmp($expected, $actual) !== 0) {
            throw new CatalogApiException(
                'METHOD_NOT_ALLOWED',
                sprintf(
                    'Expected HTTP %s but received %s.',
                    strtoupper($expected),
                    strtoupper($actual)
                ),
                405,
                [
                    'expected' => strtoupper($expected),
                    'actual' => strtoupper($actual),
                ]
            );
        }
    }

    /**
     * Reads and decodes the JSON request body.
     *
     * @return array<string, mixed>
     *
     * @throws CatalogApiException When the payload is invalid JSON.
     */
    public function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CatalogApiException(
                'INVALID_JSON',
                'Unable to parse JSON payload.',
                400,
                ['error' => $exception->getMessage()]
            );
        }

        if (!is_array($data)) {
            throw new CatalogApiException(
                'INVALID_JSON',
                'JSON payload must decode to an object.',
                400
            );
        }

        return $data;
    }
}

/**
 * Creates configured mysqli connections.
 */
final class DatabaseFactory
{
    public function __construct(private string $configPath)
    {
        if (!is_file($this->configPath)) {
            throw new RuntimeException('Database configuration file not found: ' . $this->configPath);
        }
    }

    /**
     * Builds a mysqli connection using db_config.php settings.
     */
    public function createConnection(): mysqli
    {
        /** @var array<string, mixed> $config */
        $config = require $this->configPath;

        $connection = new mysqli(
            (string) $config['host'],
            (string) $config['username'],
            (string) $config['password'],
            '',
            (int) $config['port']
        );

        if ($connection->connect_errno !== 0) {
            throw new RuntimeException('Database connection failed: ' . $connection->connect_error);
        }

        $charset = (string) ($config['charset'] ?? 'utf8mb4');
        $connection->set_charset($charset);

        $databaseName = (string) $config['database'];
        $escapedName = $connection->real_escape_string($databaseName);
        $escapedCharset = $connection->real_escape_string($charset);
        $escapedCollation = $connection->real_escape_string('utf8mb4_unicode_ci');

        $connection->query(
            sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
                $escapedName,
                $escapedCharset,
                $escapedCollation
            )
        );
        $connection->select_db($databaseName);

        return $connection;
    }
}
/**
 * Handles schema creation and seed data provisioning.
 */
final class Seeder
{
    public function __construct(private mysqli $connection)
    {
    }

    /**
     * Ensures all required tables exist.
     */
    public function ensureSchema(): void
    {
        $schemaStatements = [
            <<<SQL
            CREATE TABLE IF NOT EXISTS category (
                id INT AUTO_INCREMENT PRIMARY KEY,
                parent_id INT NULL,
                name VARCHAR(255) NOT NULL,
                type ENUM('category', 'series') NOT NULL DEFAULT 'category',
                display_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_category_parent FOREIGN KEY (parent_id) REFERENCES category(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL,
                        <<<SQL
            CREATE TABLE IF NOT EXISTS product (
                id INT AUTO_INCREMENT PRIMARY KEY,
                series_id INT NOT NULL,
                sku VARCHAR(128) NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_product_series FOREIGN KEY (series_id) REFERENCES category(id) ON DELETE CASCADE,
                UNIQUE KEY idx_product_series_sku (series_id, sku)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL,
                        <<<SQL
            CREATE TABLE IF NOT EXISTS series_custom_field (
                id INT AUTO_INCREMENT PRIMARY KEY,
                series_id INT NOT NULL,
                field_key VARCHAR(64) NOT NULL,
                label VARCHAR(255) NOT NULL,
                field_type ENUM('text') NOT NULL DEFAULT 'text',
                field_scope ENUM('series_metadata', 'product_attribute') NOT NULL DEFAULT 'product_attribute',
                default_value TEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_required TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_series_custom_field_series FOREIGN KEY (series_id) REFERENCES category(id) ON DELETE CASCADE,
                UNIQUE KEY idx_series_field_key (series_id, field_scope, field_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL,
                        <<<SQL
            CREATE TABLE IF NOT EXISTS product_custom_field_value (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                series_custom_field_id INT NOT NULL,
                value TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_product_custom_field_product FOREIGN KEY (product_id) REFERENCES product(id) ON DELETE CASCADE,
                CONSTRAINT fk_product_custom_field_series_field FOREIGN KEY (series_custom_field_id) REFERENCES series_custom_field(id) ON DELETE CASCADE,
                UNIQUE KEY idx_product_field_unique (product_id, series_custom_field_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS series_custom_field_value (
                id INT AUTO_INCREMENT PRIMARY KEY,
                series_id INT NOT NULL,
                series_custom_field_id INT NOT NULL,
                value TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_series_value_series FOREIGN KEY (series_id) REFERENCES category(id) ON DELETE CASCADE,
                CONSTRAINT fk_series_value_field FOREIGN KEY (series_custom_field_id) REFERENCES series_custom_field(id) ON DELETE CASCADE,
                UNIQUE KEY idx_series_value_unique (series_id, series_custom_field_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL,
                        <<<SQL
            CREATE TABLE IF NOT EXISTS latex_template (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                latex_source LONGTEXT NOT NULL,
                pdf_path VARCHAR(512) NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL,
                        <<<SQL
            CREATE TABLE IF NOT EXISTS seed_migration (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                executed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY idx_seed_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL,
        ];

        foreach ($schemaStatements as $statement) {
            $this->connection->query($statement);
        }

        $this->ensureColumnExists(
            'series_custom_field',
            'field_scope',
            "field_scope ENUM('series_metadata', 'product_attribute') NOT NULL DEFAULT 'product_attribute' AFTER field_type"
        );
        $this->ensureColumnExists(
            'series_custom_field',
            'default_value',
            'default_value TEXT NULL AFTER field_scope'
        );

        $this->connection->query(
            sprintf(
                "UPDATE series_custom_field SET field_scope = '%s' WHERE field_scope IS NULL OR field_scope = ''",
                SERIES_FIELD_SCOPE_PRODUCT
            )
        );

        $this->ensureSeriesMetadataDefaults();
        $this->ensureSeriesFieldScopeIndex();
    }

    /**
     * Applies the initial seed if it has not been executed.
     */
    public function seedInitialData(): void
    {
        if ($this->isSeedApplied(CATALOG_SEED_NAME)) {
            return;
        }

        $this->connection->begin_transaction();

        try {
            $tree = $this->getSeedTree();
            $seriesFieldCache = [];
            foreach ($tree as $index => $node) {
                $this->insertNodeRecursive(null, $node, $index + 1, $seriesFieldCache);
            }

            $stmt = $this->connection->prepare('INSERT INTO seed_migration (name) VALUES (?)');
            $seedName = CATALOG_SEED_NAME;
            $stmt->bind_param('s', $seedName);
            $stmt->execute();
            $stmt->close();

            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollback();
            throw $exception;
        }
    }

    private function isSeedApplied(string $seedName): bool
    {
        $stmt = $this->connection->prepare('SELECT COUNT(1) AS total FROM seed_migration WHERE name = ?');
        $stmt->bind_param('s', $seedName);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        return $count > 0;
    }

    /**
     * Inserts hierarchy nodes recursively.
     *
     * @param array<string, mixed> $node
     * @param array<int, array<string, array<string, int>>> $seriesFieldCache
     */
    private function insertNodeRecursive(
        ?int $parentId,
        array $node,
        int $displayOrder,
        array &$seriesFieldCache
    ): void {
        $nodeId = $this->insertCategoryNode(
            $parentId,
            (string) $node['name'],
            (string) $node['type'],
            $displayOrder
        );

        if ($node['type'] === 'series') {
            $seriesFieldCache[$nodeId] = [
                SERIES_FIELD_SCOPE_PRODUCT => [],
                SERIES_FIELD_SCOPE_SERIES => [],
            ];

            $productFieldMap =& $seriesFieldCache[$nodeId][SERIES_FIELD_SCOPE_PRODUCT];
            foreach ($node['fields'] ?? [] as $fieldIndex => $fieldDefinition) {
                $fieldId = $this->insertSeriesField(
                    $nodeId,
                    $fieldDefinition,
                    $fieldIndex + 1,
                    SERIES_FIELD_SCOPE_PRODUCT
                );
                $productFieldMap[$fieldDefinition['field_key']] = $fieldId;
            }

            $metadataFieldMap =& $seriesFieldCache[$nodeId][SERIES_FIELD_SCOPE_SERIES];
            foreach ($node['metadataFields'] ?? [] as $metaIndex => $metadataDefinition) {
                $fieldId = $this->insertSeriesField(
                    $nodeId,
                    $metadataDefinition,
                    $metaIndex + 1,
                    SERIES_FIELD_SCOPE_SERIES
                );
                $metadataFieldMap[$metadataDefinition['field_key']] = $fieldId;
            }

            foreach ($node['metadataValues'] ?? [] as $metaKey => $metaValue) {
                $fieldId = $metadataFieldMap[$metaKey] ?? null;
                if ($fieldId !== null) {
                    $this->insertSeriesMetadataValue(
                        $nodeId,
                        $fieldId,
                        $metaValue !== null ? (string) $metaValue : null
                    );
                }
            }

            foreach ($node['products'] ?? [] as $productDefinition) {
                $productId = $this->insertProduct($nodeId, $productDefinition);
                foreach ($productDefinition['custom_values'] ?? [] as $fieldKey => $fieldValue) {
                    $fieldId = $productFieldMap[$fieldKey] ?? null;
                    if ($fieldId !== null) {
                        $this->insertProductCustomValue($productId, $fieldId, $fieldValue);
                    }
                }
            }
        }

        foreach ($node['children'] ?? [] as $childIndex => $childNode) {
            $this->insertNodeRecursive($nodeId, $childNode, $childIndex + 1, $seriesFieldCache);
        }
    }

    private function insertCategoryNode(
        ?int $parentId,
        string $name,
        string $type,
        int $displayOrder
    ): int {
        $stmt = $this->connection->prepare(
            'INSERT INTO category (parent_id, name, type, display_order) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('issi', $parentId, $name, $type, $displayOrder);
        $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        return $newId;
    }

    /**
     * @param array<string, mixed> $fieldDefinition
     */
    private function insertSeriesField(
        int $seriesId,
        array $fieldDefinition,
        int $sortOrder,
        string $fieldScope = SERIES_FIELD_SCOPE_PRODUCT
    ): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO series_custom_field (
                series_id,
                field_key,
                label,
                field_type,
                field_scope,
                default_value,
                sort_order,
                is_required
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $fieldKey = (string) $fieldDefinition['field_key'];
        $label = (string) $fieldDefinition['label'];
        $fieldType = (string) ($fieldDefinition['field_type'] ?? 'text');
        $normalizedScope = $this->normalizeFieldScope($fieldDefinition['field_scope'] ?? $fieldScope);
        $defaultValue = array_key_exists('default_value', $fieldDefinition)
            ? ($fieldDefinition['default_value'] !== null ? (string) $fieldDefinition['default_value'] : null)
            : null;
        $isRequired = (bool) ($fieldDefinition['is_required'] ?? false);
        $requiredValue = $isRequired ? 1 : 0;

        $stmt->bind_param(
            'isssssii',
            $seriesId,
            $fieldKey,
            $label,
            $fieldType,
            $normalizedScope,
            $defaultValue,
            $sortOrder,
            $requiredValue
        );
        $stmt->execute();
        $insertId = (int) $stmt->insert_id;
        $stmt->close();

        return $insertId;
    }

    /**
     * @param array<string, mixed> $productDefinition
     */
    private function insertProduct(int $seriesId, array $productDefinition): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO product (series_id, sku, name, description) VALUES (?, ?, ?, ?)'
        );
        $sku = (string) $productDefinition['sku'];
        $name = (string) $productDefinition['name'];
        $description = isset($productDefinition['description'])
            ? (string) $productDefinition['description']
            : null;
        $stmt->bind_param('isss', $seriesId, $sku, $name, $description);
        $stmt->execute();
        $productId = (int) $stmt->insert_id;
        $stmt->close();

        return $productId;
    }

    private function insertProductCustomValue(int $productId, int $fieldId, ?string $value): void
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO product_custom_field_value (product_id, series_custom_field_id, value)
             VALUES (?, ?, ?)'
        );
        $stmt->bind_param('iis', $productId, $fieldId, $value);
        $stmt->execute();
        $stmt->close();
    }

    private function insertSeriesMetadataValue(int $seriesId, int $fieldId, ?string $value): void
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO series_custom_field_value (series_id, series_custom_field_id, value)
             VALUES (?, ?, ?)'
        );
        $stmt->bind_param('iis', $seriesId, $fieldId, $value);
        $stmt->execute();
        $stmt->close();
    }

    private function ensureSeriesMetadataDefaults(): void
    {
        $seriesResult = $this->connection->query(
            "SELECT id FROM category WHERE type = 'series'"
        );
        if ($seriesResult === false) {
            return;
        }

        while ($seriesRow = $seriesResult->fetch_assoc()) {
            $seriesId = (int) $seriesRow['id'];
            $maxSortOrder = $this->getMaxSortOrderForScope($seriesId, SERIES_FIELD_SCOPE_SERIES);
            foreach ($this->getDefaultSeriesMetadataFieldSeeds() as $index => $definition) {
                $fieldKey = (string) $definition['field_key'];
                $fieldId = $this->findFieldIdByKey($seriesId, $fieldKey, SERIES_FIELD_SCOPE_SERIES);
                if ($fieldId === null) {
                    $fieldId = $this->insertSeriesField(
                        $seriesId,
                        $definition,
                        $maxSortOrder + $index + 1,
                        SERIES_FIELD_SCOPE_SERIES
                    );
                }
                $defaultValue = array_key_exists('default_value', $definition)
                    ? ($definition['default_value'] !== null ? (string) $definition['default_value'] : null)
                    : null;
                $this->ensureMetadataValueExists($seriesId, $fieldId, $defaultValue);
            }
        }
        $seriesResult->close();
    }

    private function ensureSeriesFieldScopeIndex(): void
    {
        $sql = <<<SQL
            SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'series_custom_field'
              AND index_name = 'idx_series_field_key'
        SQL;
        $result = $this->connection->query($sql);
        $columns = null;
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            $columns = isset($row['columns']) ? (string) $row['columns'] : null;
            $result->close();
        }
        if ($columns === 'series_id,field_scope,field_key') {
            return;
        }
        $tmpIndex = 'idx_series_field_scope_key_tmp';

        $tmpCheck = $this->connection->query(
            sprintf(
                "SELECT COUNT(1) AS total FROM information_schema.statistics WHERE table_schema = DATABASE()
                 AND table_name = 'series_custom_field' AND index_name = '%s'",
                $this->connection->real_escape_string($tmpIndex)
            )
        );
        if ($tmpCheck instanceof mysqli_result) {
            $count = (int) ($tmpCheck->fetch_assoc()['total'] ?? 0);
            $tmpCheck->close();
            if ($count > 0) {
                $this->connection->query("DROP INDEX {$tmpIndex} ON series_custom_field");
            }
        }

        $this->connection->query(
            "ALTER TABLE series_custom_field ADD UNIQUE KEY {$tmpIndex} (series_id, field_scope, field_key)"
        );
        $this->connection->query('DROP INDEX idx_series_field_key ON series_custom_field');
        $this->connection->query(
            "ALTER TABLE series_custom_field RENAME INDEX {$tmpIndex} TO idx_series_field_key"
        );
    }

    private function getMaxSortOrderForScope(int $seriesId, string $scope): int
    {
        $stmt = $this->connection->prepare(
            'SELECT MAX(sort_order) AS max_sort FROM series_custom_field WHERE series_id = ? AND field_scope = ?'
        );
        $stmt->bind_param('is', $seriesId, $scope);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return (int) ($row['max_sort'] ?? 0);
    }

    private function findFieldIdByKey(int $seriesId, string $fieldKey, string $scope): ?int
    {
        $stmt = $this->connection->prepare(
            'SELECT id FROM series_custom_field WHERE series_id = ? AND field_key = ? AND field_scope = ? LIMIT 1'
        );
        $stmt->bind_param('iss', $seriesId, $fieldKey, $scope);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row !== null ? (int) $row['id'] : null;
    }

    private function ensureMetadataValueExists(int $seriesId, int $fieldId, ?string $value): void
    {
        $stmt = $this->connection->prepare(
            'SELECT id FROM series_custom_field_value WHERE series_id = ? AND series_custom_field_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $seriesId, $fieldId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            $this->insertSeriesMetadataValue($seriesId, $fieldId, $value);
        }
    }

    private function normalizeFieldScope(?string $scope): string
    {
        $normalized = $scope !== null ? (string) $scope : '';
        $allowedScopes = [SERIES_FIELD_SCOPE_PRODUCT, SERIES_FIELD_SCOPE_SERIES];

        return in_array($normalized, $allowedScopes, true)
            ? $normalized
            : SERIES_FIELD_SCOPE_PRODUCT;
    }

    private function ensureColumnExists(string $table, string $column, string $definition): void
    {
        $stmt = $this->connection->prepare(
            'SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result->fetch_row()[0] ?? 0);
        $stmt->close();

        if ($count === 0) {
            $this->connection->query(sprintf('ALTER TABLE `%s` ADD COLUMN %s', $table, $definition));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getSeedTree(): array
    {
        return [
            [
                'name' => 'General Products',
                'type' => 'category',
                'children' => [
                    $this->buildSeriesSeed(
                        'C0 SERIES',
                        [
                            'metadata_values' => [
                                'series_voltage' => '16V - 25V',
                                'series_notes' => 'General-purpose capacitor line.',
                            ],
                            'products' => [
                                [
                                    'sku' => 'C0-100',
                                    'name' => 'Capacitor 100uF',
                                    'description' => 'Compact capacitor suitable for general electronics.',
                                    'custom_values' => [
                                        'voltage_rating' => '16V',
                                        'tolerance' => '+/-10%',
                                    ],
                                ],
                                [
                                    'sku' => 'C0-200',
                                    'name' => 'Capacitor 220uF',
                                    'description' => 'High capacity for power supplies.',
                                    'custom_values' => [
                                        'voltage_rating' => '25V',
                                        'tolerance' => '+/-20%',
                                    ],
                                ],
                            ],
                        ]
                    ),
                    $this->buildSeriesSeed(
                        'C1 SERIES',
                        [
                            'metadata_values' => [
                                'series_voltage' => '35V',
                                'series_notes' => 'Low ESR line for audio applications.',
                            ],
                            'products' => [
                                [
                                    'sku' => 'C1-300',
                                    'name' => 'Capacitor 330uF',
                                    'description' => 'Low ESR capacitor for audio applications.',
                                    'custom_values' => [
                                        'voltage_rating' => '35V',
                                        'tolerance' => '+/-5%',
                                    ],
                                ],
                            ],
                        ]
                    ),
                ],
            ],
            [
                'name' => 'EMC Components',
                'type' => 'category',
                'children' => [
                    $this->buildSeriesSeed(
                        'EM-Filter',
                        [
                            'metadata_values' => [
                                'series_voltage' => '250VAC',
                                'series_notes' => 'Electromagnetic interference suppression filters.',
                            ],
                            'products' => [
                                [
                                    'sku' => 'EM-F-01',
                                    'name' => 'Power Line Filter',
                                    'description' => 'Suppresses conducted emissions.',
                                    'custom_values' => [
                                        'voltage_rating' => '250VAC',
                                        'tolerance' => 'Standard',
                                    ],
                                ],
                            ],
                        ]
                    ),
                ],
            ],
        ];
    }

    /**
     * Helper for building a series node within seed data.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function buildSeriesSeed(string $seriesName, array $definition): array
    {
        $products = $definition['products'] ?? [];
        $productFields = $definition['product_fields']
            ?? $definition['fields']
            ?? $this->getDefaultProductFieldSeeds();
        $metadataFields = $definition['metadata_fields'] ?? $this->getDefaultSeriesMetadataFieldSeeds();
        $metadataValues = $definition['metadata_values'] ?? [];

        return [
            'name' => $seriesName,
            'type' => 'series',
            'fields' => $productFields,
            'metadataFields' => $metadataFields,
            'metadataValues' => $metadataValues,
            'products' => $products,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultProductFieldSeeds(): array
    {
        return [
            [
                'field_key' => 'voltage_rating',
                'label' => 'Voltage Rating',
                'field_type' => 'text',
                'is_required' => false,
            ],
            [
                'field_key' => 'tolerance',
                'label' => 'Tolerance',
                'field_type' => 'text',
                'is_required' => false,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultSeriesMetadataFieldSeeds(): array
    {
        return [
            [
                'field_key' => 'series_voltage',
                'label' => 'Voltage Range',
                'field_type' => 'text',
                'is_required' => false,
            ],
            [
                'field_key' => 'series_notes',
                'label' => 'Series Notes',
                'field_type' => 'text',
                'is_required' => false,
            ],
        ];
    }
}
/**
 * Provides hierarchy CRUD and retrieval operations.
 */
final class HierarchyService
{
    public function __construct(private mysqli $connection)
    {
    }

    /**
     * Returns the full category/series tree alongside select options for series.
     *
     * @return array<string, mixed>
     */
    public function listHierarchy(): array
    {
        $tree = $this->buildHierarchyTree();

        /**
         * @param array<string, mixed> $node
         *
         * @return array<string, mixed>
         */
        $transform = function (array $node) use (&$transform): array {
            $children = [];
            foreach ($node['children'] as $child) {
                $children[] = $transform($child);
            }

            return [
                'id' => $node['id'],
                'name' => $node['name'],
                'type' => $node['type'],
                'displayOrder' => $node['displayOrder'],
                'parentId' => $node['parentId'],
                'children' => $children,
            ];
        };

        $hierarchy = array_map($transform, $tree);
        $seriesOptions = $this->fetchSeriesOptions();

        return [
            'hierarchy' => $hierarchy,
            'seriesOptions' => $seriesOptions,
        ];
    }

    /**
     * Creates or updates a node.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function saveNode(array $payload): array
    {
        $nodeId = isset($payload['id']) ? (int) $payload['id'] : null;
        $name = isset($payload['name']) ? trim((string) $payload['name']) : '';
        $type = isset($payload['type']) ? (string) $payload['type'] : '';
        $parentId = array_key_exists('parentId', $payload)
            ? ($payload['parentId'] !== null ? (int) $payload['parentId'] : null)
            : null;
        $displayOrder = isset($payload['displayOrder']) ? (int) $payload['displayOrder'] : 0;

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }
        if (!in_array($type, ['category', 'series'], true)) {
            $errors['type'] = 'Type must be "category" or "series".';
        }
        if ($type === 'series' && $parentId === null) {
            $errors['parentId'] = 'Series must have a parent category.';
        }
        if ($nodeId !== null && $parentId !== null && $nodeId === $parentId) {
            $errors['parentId'] = 'Parent cannot be the node itself.';
        }
        if ($errors !== []) {
            throw new CatalogApiException('VALIDATION_ERROR', 'Field validation failed.', 400, $errors);
        }

        if ($parentId !== null) {
            $parent = $this->loadCategory($parentId);
            if ($parent === null) {
                throw new CatalogApiException('PARENT_NOT_FOUND', 'Parent node not found.', 404);
            }
            if ($parent['type'] !== 'category') {
                throw new CatalogApiException(
                    'VALIDATION_ERROR',
                    'Parent node must be a category.',
                    400,
                    ['parentId' => 'Parent node must be a category.']
                );
            }
        }

        if ($nodeId !== null) {
            $existing = $this->loadCategory($nodeId);
            if ($existing === null) {
                throw new CatalogApiException('NODE_NOT_FOUND', 'Node not found.', 404);
            }

            if ($existing['type'] === 'series' && $type !== 'series') {
                $childCount = $this->countProductsForSeries($nodeId);
                if ($childCount > 0) {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Cannot convert series with products into category.',
                        409
                    );
                }
            }

            $stmt = $this->connection->prepare(
                'UPDATE category SET parent_id = ?, name = ?, type = ?, display_order = ? WHERE id = ?'
            );
            $stmt->bind_param('issii', $parentId, $name, $type, $displayOrder, $nodeId);
            $stmt->execute();
            $stmt->close();
            $result = $this->loadCategory($nodeId);
        } else {
            $newId = $this->insertCategoryNode($parentId, $name, $type, $displayOrder);
            $result = $this->loadCategory($newId);
        }

        return [
            'id' => $result['id'],
            'name' => $result['name'],
            'type' => $result['type'],
            'displayOrder' => $result['display_order'],
        ];
    }

    /**
     * Deletes a node when no dependencies remain.
     */
    public function deleteNode(int $nodeId): void
    {
        $node = $this->loadCategory($nodeId);
        if ($node === null) {
            throw new CatalogApiException('NODE_NOT_FOUND', 'Node not found.', 404);
        }

        $stmt = $this->connection->prepare('SELECT COUNT(1) FROM category WHERE parent_id = ?');
        $stmt->bind_param('i', $nodeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $childCount = (int) ($result->fetch_row()[0] ?? 0);
        $stmt->close();

        if ($childCount > 0) {
            throw new CatalogApiException(
                'CONFLICT',
                'Cannot delete node with child nodes.',
                409,
                ['id' => 'Node still has children.']
            );
        }

        if ($node['type'] === 'series') {
            $productCount = $this->countProductsForSeries($nodeId);
            if ($productCount > 0) {
                throw new CatalogApiException(
                    'CONFLICT',
                    'Cannot delete series containing products.',
                    409,
                    ['id' => 'Series contains products.']
                );
            }
        }

        $stmt = $this->connection->prepare('DELETE FROM category WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $nodeId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Builds the hierarchical tree of categories/series.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildHierarchyTree(): array
    {
        $result = $this->connection->query(
            'SELECT id, parent_id, name, type, display_order FROM category ORDER BY display_order, id'
        );

        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = [];
        /** @var array<int|null, array<int, array<string, mixed>>> $children */
        $children = [];

        while ($row = $result->fetch_assoc()) {
            $id = (int) $row['id'];
            $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;

            $node = [
                'id' => $id,
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'displayOrder' => (int) $row['display_order'],
                'parentId' => $parentId,
                'children' => [],
            ];
            $nodes[$id] = $node;
            $children[$parentId][] = &$nodes[$id];
        }

        foreach ($nodes as $id => &$node) {
            $node['children'] = $children[$id] ?? [];
        }
        unset($node);

        return $children[null] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchSeriesOptions(): array
    {
        $result = $this->connection->query(
            "SELECT id, name FROM category WHERE type = 'series' ORDER BY name, id"
        );
        $options = [];
        while ($row = $result->fetch_assoc()) {
            $options[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }

        return $options;
    }

    /**
     * Loads a category by ID.
     *
     * @return array<string, mixed>|null
     */
    private function loadCategory(int $nodeId): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, parent_id, name, type, display_order FROM category WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $nodeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: null;
        $stmt->close();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'display_order' => (int) $row['display_order'],
        ];
    }

    private function countProductsForSeries(int $seriesId): int
    {
        $stmt = $this->connection->prepare('SELECT COUNT(1) FROM product WHERE series_id = ?');
        $stmt->bind_param('i', $seriesId);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result->fetch_row()[0] ?? 0);
        $stmt->close();

        return $count;
    }

    private function insertCategoryNode(
        ?int $parentId,
        string $name,
        string $type,
        int $displayOrder
    ): int {
        $stmt = $this->connection->prepare(
            'INSERT INTO category (parent_id, name, type, display_order) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('issi', $parentId, $name, $type, $displayOrder);
        $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        return $newId;
    }
}
/**
 * Manages series custom fields.
 */
final class SeriesFieldService
{
    public function __construct(private mysqli $connection)
    {
    }

    /**
     * Lists fields for a series.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFields(
        int $seriesId,
        string $fieldScope = SERIES_FIELD_SCOPE_PRODUCT
    ): array {
        $normalizedScope = $this->normalizeScope($fieldScope);
        $stmt = $this->connection->prepare(
            'SELECT id, field_key, label, field_type, field_scope, default_value, sort_order, is_required
             FROM series_custom_field
             WHERE series_id = ? AND field_scope = ?
             ORDER BY sort_order, id'
        );
        $stmt->bind_param('is', $seriesId, $normalizedScope);
        $stmt->execute();
        $result = $stmt->get_result();

        $fields = [];
        while ($row = $result->fetch_assoc()) {
            $fields[] = [
                'id' => (int) $row['id'],
                'fieldKey' => (string) $row['field_key'],
                'label' => (string) $row['label'],
                'fieldType' => (string) $row['field_type'],
                'fieldScope' => (string) $row['field_scope'],
                'defaultValue' => $row['default_value'],
                'sortOrder' => (int) $row['sort_order'],
                'isRequired' => ((int) $row['is_required']) === 1,
            ];
        }
        $stmt->close();

        return $fields;
    }

    /**
     * Creates or updates a series field.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function saveField(array $payload): array
    {
        $fieldId = isset($payload['id']) ? (int) $payload['id'] : null;
        $seriesId = isset($payload['seriesId']) ? (int) $payload['seriesId'] : null;
        $label = isset($payload['label']) ? trim((string) $payload['label']) : '';
        $fieldKey = isset($payload['fieldKey']) ? trim((string) $payload['fieldKey']) : '';
        $fieldType = isset($payload['fieldType']) ? (string) $payload['fieldType'] : 'text';
        $fieldScopeRaw = isset($payload['fieldScope']) ? (string) $payload['fieldScope'] : SERIES_FIELD_SCOPE_PRODUCT;
        $sortOrder = isset($payload['sortOrder']) ? (int) $payload['sortOrder'] : 0;
        $isRequired = isset($payload['isRequired']) ? (bool) $payload['isRequired'] : false;
        $defaultValue = array_key_exists('defaultValue', $payload) ? $payload['defaultValue'] : null;
        $normalizedDefaultValue = $defaultValue !== null ? (string) $defaultValue : null;
        $fieldScope = $this->normalizeScope($fieldScopeRaw);

        $errors = [];
        if ($seriesId === null || $seriesId <= 0) {
            $errors['seriesId'] = 'Series ID is required.';
        }
        if ($label === '') {
            $errors['label'] = 'Field label is required.';
        }
        if ($fieldKey === '') {
            $errors['fieldKey'] = 'Field key is required.';
        }
        if (!in_array($fieldType, ['text'], true)) {
            $errors['fieldType'] = 'Unsupported field type.';
        }
        if ($errors !== []) {
            throw new CatalogApiException('VALIDATION_ERROR', 'Field validation failed.', 400, $errors);
        }

        $this->assertSeriesExists($seriesId);
        $targetScope = $fieldScope;
        if ($fieldId !== null) {
            $existingField = $this->getFieldRow($fieldId);
            if ($existingField === null || (int) $existingField['series_id'] !== $seriesId) {
                throw new CatalogApiException('FIELD_NOT_FOUND', 'Series field not found.', 404);
            }
            $existingScope = (string) $existingField['field_scope'];
            if ($existingScope !== $fieldScope) {
                throw new CatalogApiException(
                    'FIELD_SCOPE_IMMUTABLE',
                    'Field scope cannot be changed after creation.',
                    400
                );
            }
            $targetScope = $existingScope;
        }

        $this->ensureUniqueFieldKey($seriesId, $fieldKey, $targetScope, $fieldId);

        if ($fieldId !== null) {
            $stmt = $this->connection->prepare(
                'UPDATE series_custom_field
                 SET label = ?, field_key = ?, field_type = ?, default_value = ?, sort_order = ?, is_required = ?
                 WHERE id = ? AND series_id = ?'
            );
            $requiredValue = $isRequired ? 1 : 0;
            $stmt->bind_param(
                'ssssiiii',
                $label,
                $fieldKey,
                $fieldType,
                $normalizedDefaultValue,
                $sortOrder,
                $requiredValue,
                $fieldId,
                $seriesId
            );
            $stmt->execute();
            $stmt->close();
            $id = $fieldId;
        } else {
            $stmt = $this->connection->prepare(
                'INSERT INTO series_custom_field (
                    series_id,
                    field_key,
                    label,
                    field_type,
                    field_scope,
                    default_value,
                    sort_order,
                    is_required
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $requiredValue = $isRequired ? 1 : 0;
            $stmt->bind_param(
                'isssssii',
                $seriesId,
                $fieldKey,
                $label,
                $fieldType,
                $targetScope,
                $normalizedDefaultValue,
                $sortOrder,
                $requiredValue
            );
            $stmt->execute();
            $id = (int) $stmt->insert_id;
            $stmt->close();
        }

        $fields = $this->listFields($seriesId, $targetScope);
        $field = null;
        foreach ($fields as $item) {
            if ($item['id'] === $id) {
                $field = $item;
                break;
            }
        }
        if ($field === null) {
            throw new CatalogApiException('SERVER_ERROR', 'Unable to load field after save.', 500);
        }

        return [
            'id' => $field['id'],
            'seriesId' => $seriesId,
            'fieldKey' => $field['fieldKey'],
            'label' => $field['label'],
            'fieldType' => $field['fieldType'],
            'fieldScope' => $field['fieldScope'],
            'defaultValue' => $field['defaultValue'],
            'sortOrder' => $field['sortOrder'],
            'isRequired' => $field['isRequired'],
        ];
    }

    public function deleteField(int $fieldId): void
    {
        $stmt = $this->connection->prepare(
            'SELECT id FROM series_custom_field WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $fieldId);
        $stmt->execute();
        $result = $stmt->get_result();
        $field = $result->fetch_assoc();
        $stmt->close();

        if ($field === null) {
            throw new CatalogApiException('FIELD_NOT_FOUND', 'Series field not found.', 404);
        }

        $stmt = $this->connection->prepare('DELETE FROM series_custom_field WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $fieldId);
        $stmt->execute();
        $stmt->close();
    }

    public function assertSeriesExists(int $seriesId): void
    {
        $stmt = $this->connection->prepare(
            "SELECT id FROM category WHERE id = ? AND type = 'series' LIMIT 1"
        );
        $stmt->bind_param('i', $seriesId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            throw new CatalogApiException('SERIES_NOT_FOUND', 'Series not found.', 404);
        }
    }

    private function getFieldRow(int $fieldId): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, series_id, field_scope FROM series_custom_field WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $fieldId);
        $stmt->execute();
        $result = $stmt->get_result();
        $field = $result->fetch_assoc() ?: null;
        $stmt->close();

        return $field ?: null;
    }

    private function ensureUniqueFieldKey(
        int $seriesId,
        string $fieldKey,
        string $fieldScope,
        ?int $excludeId = null
    ): void {
        $sql = 'SELECT COUNT(1) FROM series_custom_field WHERE series_id = ? AND field_scope = ? AND field_key = ?';
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
        }

        $stmt = $this->connection->prepare($sql);
        if ($excludeId !== null) {
            $stmt->bind_param('issi', $seriesId, $fieldScope, $fieldKey, $excludeId);
        } else {
            $stmt->bind_param('iss', $seriesId, $fieldScope, $fieldKey);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result->fetch_row()[0] ?? 0);
        $stmt->close();

        if ($count > 0) {
            throw new CatalogApiException(
                'VALIDATION_ERROR',
                'Field key must be unique within the series and scope.',
                400,
                ['fieldKey' => 'Field key must be unique within the series and scope.']
            );
        }
    }

    private function normalizeScope(?string $scope): string
    {
        $value = $scope !== null ? (string) $scope : '';
        $allowed = [SERIES_FIELD_SCOPE_PRODUCT, SERIES_FIELD_SCOPE_SERIES];

        return in_array($value, $allowed, true) ? $value : SERIES_FIELD_SCOPE_PRODUCT;
    }

    /**
     * Returns field definitions keyed by series ID.
     *
     * @param array<int> $seriesIds
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function fetchFieldsForSeriesIds(
        array $seriesIds,
        string $fieldScope = SERIES_FIELD_SCOPE_PRODUCT
    ): array {
        if ($seriesIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));
        $types = str_repeat('i', count($seriesIds)) . 's';
        $normalizedScope = $this->normalizeScope($fieldScope);

        $stmt = $this->connection->prepare(
            sprintf(
                'SELECT id, series_id, field_key, label, field_type, field_scope, default_value, sort_order, is_required
                 FROM series_custom_field
                 WHERE series_id IN (%s) AND field_scope = ?
                 ORDER BY sort_order, id',
                $placeholders
            )
        );

        $params = $seriesIds;
        $params[] = $normalizedScope;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        /** @var array<int, array<int, array<string, mixed>>> $map */
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $seriesId = (int) $row['series_id'];
            $map[$seriesId][] = [
                'id' => (int) $row['id'],
                'fieldKey' => (string) $row['field_key'],
                'label' => (string) $row['label'],
                'fieldType' => (string) $row['field_type'],
                'fieldScope' => (string) $row['field_scope'],
                'defaultValue' => $row['default_value'],
                'sortOrder' => (int) $row['sort_order'],
                'isRequired' => ((int) $row['is_required']) === 1,
            ];
        }
        $stmt->close();

        return $map;
    }

    /**
     * Returns a field map for a single series keyed by field ID.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSeriesFieldMap(
        int $seriesId,
        string $fieldScope = SERIES_FIELD_SCOPE_PRODUCT
    ): array {
        $fields = $this->listFields($seriesId, $fieldScope);
        $map = [];
        foreach ($fields as $field) {
            $map[$field['id']] = $field;
        }

        return $map;
    }
}

/**
 * Manages series-level metadata values.
 */
final class SeriesAttributeService
{
    public function __construct(
        private mysqli $connection,
        private SeriesFieldService $seriesFieldService
    ) {
    }

    public function getAttributes(int $seriesId): array
    {
        $this->seriesFieldService->assertSeriesExists($seriesId);
        $payloads = $this->fetchMetadataPayloads([$seriesId]);

        return $payloads[$seriesId] ?? [
            'seriesId' => $seriesId,
            'definitions' => $this->seriesFieldService->listFields($seriesId, SERIES_FIELD_SCOPE_SERIES),
            'values' => [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function saveAttributes(array $payload, bool $wrapInTransaction = true): array
    {
        $seriesId = isset($payload['seriesId']) ? (int) $payload['seriesId'] : null;
        $valuesPayload = isset($payload['values']) && is_array($payload['values'])
            ? $payload['values']
            : [];

        if ($seriesId === null || $seriesId <= 0) {
            throw new CatalogApiException(
                'VALIDATION_ERROR',
                'Series ID is required.',
                400,
                ['seriesId' => 'Series ID is required.']
            );
        }

        $this->seriesFieldService->assertSeriesExists($seriesId);
        $definitions = $this->seriesFieldService->listFields($seriesId, SERIES_FIELD_SCOPE_SERIES);
        $definitionMap = [];
        foreach ($definitions as $definition) {
            $definitionMap[$definition['fieldKey']] = $definition;
        }

        $errors = [];
        foreach ($valuesPayload as $fieldKey => $value) {
            if (!isset($definitionMap[$fieldKey])) {
                $errors[$fieldKey] = 'Unknown series metadata field.';
            }
        }

        foreach ($definitionMap as $fieldKey => $definition) {
            $value = array_key_exists($fieldKey, $valuesPayload) ? $valuesPayload[$fieldKey] : null;
            $normalized = $this->normalizeValue($value);
            if ($definition['isRequired'] && $normalized === null) {
                $errors[$fieldKey] = 'Field is required.';
            }
        }

        if ($errors !== []) {
            throw new CatalogApiException('VALIDATION_ERROR', 'Series metadata validation failed.', 400, $errors);
        }

        if ($wrapInTransaction) {
            $this->connection->begin_transaction();
        }
        try {
            foreach ($definitionMap as $definition) {
                $fieldKey = $definition['fieldKey'];
                $fieldId = (int) $definition['id'];
                $value = array_key_exists($fieldKey, $valuesPayload) ? $valuesPayload[$fieldKey] : null;
                $normalized = $this->normalizeValue($value);
                $this->upsertSeriesValue($seriesId, $fieldId, $normalized);
            }
            if ($wrapInTransaction) {
                $this->connection->commit();
            }
        } catch (Throwable $exception) {
            if ($wrapInTransaction) {
                $this->connection->rollback();
            }
            throw $exception;
        }

        return $this->getAttributes($seriesId);
    }

    /**
     * @param array<int> $seriesIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchMetadataPayloads(array $seriesIds): array
    {
        if ($seriesIds === []) {
            return [];
        }

        $definitionsBySeries = $this->seriesFieldService->fetchFieldsForSeriesIds(
            $seriesIds,
            SERIES_FIELD_SCOPE_SERIES
        );
        $valueMap = $this->fetchValueMapForSeries($seriesIds);

        $payloads = [];
        foreach ($seriesIds as $seriesId) {
            $definitions = $definitionsBySeries[$seriesId] ?? [];
            $values = [];
            $fieldValues = $valueMap[$seriesId] ?? [];
            foreach ($definitions as $definition) {
                $fieldId = (int) $definition['id'];
                $fieldKey = $definition['fieldKey'];
                $values[$fieldKey] = $fieldValues[$fieldId] ?? ($definition['defaultValue'] ?? null);
            }

            $payloads[$seriesId] = [
                'seriesId' => $seriesId,
                'definitions' => $definitions,
                'values' => $values,
            ];
        }

        return $payloads;
    }

    /**
     * @param array<int> $seriesIds
     *
     * @return array<int, array<int, string>>
     */
    private function fetchValueMapForSeries(array $seriesIds): array
    {
        if ($seriesIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));
        $types = str_repeat('i', count($seriesIds));

        $stmt = $this->connection->prepare(
            sprintf(
                'SELECT series_id, series_custom_field_id, value
                 FROM series_custom_field_value
                 WHERE series_id IN (%s)',
                $placeholders
            )
        );
        $stmt->bind_param($types, ...$seriesIds);
        $stmt->execute();
        $result = $stmt->get_result();

        /** @var array<int, array<int, string>> $map */
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $seriesId = (int) $row['series_id'];
            $fieldId = (int) $row['series_custom_field_id'];
            $map[$seriesId][$fieldId] = (string) ($row['value'] ?? '');
        }
        $stmt->close();

        return $map;
    }

    private function upsertSeriesValue(int $seriesId, int $fieldId, ?string $value): void
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO series_custom_field_value (series_id, series_custom_field_id, value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->bind_param('iis', $seriesId, $fieldId, $value);
        $stmt->execute();
        $stmt->close();
    }

    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
/**
 * Provides product CRUD operations tied to series metadata.
 */
final class ProductService
{
    public function __construct(
        private mysqli $connection,
        private SeriesFieldService $seriesFieldService
    ) {
    }

    /**
     * Returns products and series field metadata for a series.
     *
     * @return array<string, mixed>
     */
    public function listProducts(int $seriesId): array
    {
        $fields = $this->seriesFieldService->listFields($seriesId, SERIES_FIELD_SCOPE_PRODUCT);
        $products = $this->fetchProductsForSeries($seriesId, $fields);

        return [
            'fields' => $fields,
            'products' => $products,
        ];
    }

    /**
     * Returns products grouped by series for the provided series IDs.
     *
     * @param array<int> $seriesIds
     * @param array<int, array<int, array<string, mixed>>>|null $productFieldDefinitions
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function fetchProductsForSeriesIds(
        array $seriesIds,
        ?array $productFieldDefinitions = null
    ): array {
        if ($seriesIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));
        $types = str_repeat('i', count($seriesIds));
        $stmt = $this->connection->prepare(
            sprintf(
                'SELECT id, series_id, sku, name, description
                 FROM product
                 WHERE series_id IN (%s)
                 ORDER BY series_id, id',
                $placeholders
            )
        );
        $stmt->bind_param($types, ...$seriesIds);
        $stmt->execute();
        $result = $stmt->get_result();

        $productsBySeries = [];
        $productIndexMap = [];
        $productIds = [];
        while ($row = $result->fetch_assoc()) {
            $productId = (int) $row['id'];
            $seriesId = (int) $row['series_id'];
            $productsBySeries[$seriesId] ??= [];
            $productsBySeries[$seriesId][] = [
                'id' => $productId,
                'series_id' => $seriesId,
                'seriesId' => $seriesId,
                'sku' => (string) $row['sku'],
                'name' => (string) $row['name'],
                'description' => $row['description'],
                'customValues' => [],
            ];
            $productIndexMap[$productId] = [
                'seriesId' => $seriesId,
                'index' => count($productsBySeries[$seriesId]) - 1,
            ];
            $productIds[] = $productId;
        }
        $stmt->close();

        $fieldDefinitions = $productFieldDefinitions
            ?? $this->seriesFieldService->fetchFieldsForSeriesIds($seriesIds, SERIES_FIELD_SCOPE_PRODUCT);

        $fieldKeyLookup = [];
        foreach ($fieldDefinitions as $seriesId => $fields) {
            foreach ($fields as $field) {
                $fieldKeyLookup[$seriesId][$field['id']] = $field['fieldKey'];
            }
        }

        if ($productIds !== []) {
            $customValueMap = $this->fetchProductCustomValuesMap($productIds);
            foreach ($customValueMap as $productId => $values) {
                $indexInfo = $productIndexMap[$productId] ?? null;
                if ($indexInfo === null) {
                    continue;
                }
                $seriesId = $indexInfo['seriesId'];
                $fieldLookup = $fieldKeyLookup[$seriesId] ?? [];
                $normalized = [];
                foreach ($values as $fieldId => $value) {
                    $fieldKey = $fieldLookup[$fieldId] ?? null;
                    if ($fieldKey !== null) {
                        $normalized[$fieldKey] = $value;
                    }
                }
                $seriesIndex = $indexInfo['index'];
                if (isset($productsBySeries[$seriesId][$seriesIndex])) {
                    $productsBySeries[$seriesId][$seriesIndex]['customValues'] = $normalized;
                }
            }
        }

        foreach ($seriesIds as $seriesId) {
            $productsBySeries[$seriesId] ??= [];
        }

        return $productsBySeries;
    }

    /**
     * Creates or updates a product.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function saveProduct(array $payload): array
    {
        $productId = isset($payload['id']) ? (int) $payload['id'] : null;
        $seriesIdRaw = $payload['series_id'] ?? $payload['seriesId'] ?? null;
        $seriesId = $seriesIdRaw !== null ? (int) $seriesIdRaw : null;
        $sku = isset($payload['sku']) ? trim((string) $payload['sku']) : '';
        $name = isset($payload['name']) ? trim((string) $payload['name']) : '';
        $description = isset($payload['description']) ? (string) $payload['description'] : null;
        $customValuesPayload = $payload['custom_field_values'] ?? $payload['customValues'] ?? [];
        $customValues = is_array($customValuesPayload) ? $customValuesPayload : [];

        $errors = [];
        if ($seriesId === null || $seriesId <= 0) {
            $errors['series_id'] = 'Series ID is required.';
        }
        if ($sku === '') {
            $errors['sku'] = 'SKU is required.';
        }
        if ($name === '') {
            $errors['name'] = 'Product name is required.';
        }
        if ($errors !== []) {
            throw new CatalogApiException('VALIDATION_ERROR', 'Field validation failed.', 400, $errors);
        }

        $this->seriesFieldService->assertSeriesExists($seriesId);
        $fieldMap = $this->seriesFieldService->getSeriesFieldMap($seriesId, SERIES_FIELD_SCOPE_PRODUCT);

        foreach ($fieldMap as $field) {
            $fieldKey = $field['fieldKey'];
            $isRequired = $field['isRequired'];
            $value = $customValues[$fieldKey] ?? null;
            if ($isRequired && ($value === null || trim((string) $value) === '')) {
                throw new CatalogApiException(
                    'VALIDATION_ERROR',
                    'Custom field validation failed.',
                    400,
                    ['custom_field_values' => sprintf('%s is required.', $field['label'])]
                );
            }
        }

        $this->connection->begin_transaction();
        try {
            if ($productId !== null) {
                $existing = $this->fetchProductById($productId);
                if ($existing === null) {
                    throw new CatalogApiException('PRODUCT_NOT_FOUND', 'Product not found.', 404);
                }

                $stmt = $this->connection->prepare(
                    'UPDATE product SET sku = ?, name = ?, description = ? WHERE id = ? AND series_id = ?'
                );
                $stmt->bind_param('sssii', $sku, $name, $description, $productId, $seriesId);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $this->connection->prepare(
                    'INSERT INTO product (series_id, sku, name, description) VALUES (?, ?, ?, ?)'
                );
                $stmt->bind_param('isss', $seriesId, $sku, $name, $description);
                $stmt->execute();
                $productId = (int) $stmt->insert_id;
                $stmt->close();
            }

            $this->replaceProductCustomValues($productId, $customValues, $fieldMap);

            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollback();
            throw $exception;
        }

        $product = $this->fetchProductById($productId);
        if ($product === null) {
            throw new CatalogApiException('PRODUCT_NOT_FOUND', 'Product not found after save.', 404);
        }

        return $product;
    }

    public function deleteProduct(int $productId): void
    {
        $product = $this->fetchProductById($productId);
        if ($product === null) {
            throw new CatalogApiException('PRODUCT_NOT_FOUND', 'Product not found.', 404);
        }

        $stmt = $this->connection->prepare('DELETE FROM product WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private function fetchProductsForSeries(int $seriesId, array $fields): array
    {
        $fieldKeyById = [];
        foreach ($fields as $field) {
            $fieldKeyById[$field['id']] = $field['fieldKey'];
        }

        $stmt = $this->connection->prepare(
            'SELECT id, sku, name, description FROM product WHERE series_id = ? ORDER BY name, id'
        );
        $stmt->bind_param('i', $seriesId);
        $stmt->execute();
        $result = $stmt->get_result();

        $products = [];
        $productIds = [];
        while ($row = $result->fetch_assoc()) {
            $productId = (int) $row['id'];
            $products[$productId] = [
                'id' => $productId,
                'sku' => (string) $row['sku'],
                'name' => (string) $row['name'],
                'description' => $row['description'],
                'customValues' => [],
            ];
            $productIds[] = $productId;
        }
        $stmt->close();

        if ($productIds === []) {
            return array_values($products);
        }

        $valuesMap = $this->fetchProductCustomValuesMap($productIds);
        foreach ($valuesMap as $productId => $customValues) {
            if (!isset($products[$productId])) {
                continue;
            }
            $normalized = [];
            foreach ($customValues as $fieldId => $value) {
                $fieldKey = $fieldKeyById[$fieldId] ?? null;
                if ($fieldKey !== null) {
                    $normalized[$fieldKey] = $value;
                }
            }
            $products[$productId]['customValues'] = $normalized;
        }

        return array_values($products);
    }

    /**
     * @param array<string, mixed> $fieldMap
     */
    private function replaceProductCustomValues(
        int $productId,
        array $customValues,
        array $fieldMap
    ): void {
        $stmt = $this->connection->prepare(
            'DELETE FROM product_custom_field_value WHERE product_id = ?'
        );
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $stmt->close();

        foreach ($fieldMap as $field) {
            $fieldId = $field['id'];
            $fieldKey = $field['fieldKey'];
            $value = $customValues[$fieldKey] ?? null;
            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            $stmt = $this->connection->prepare(
                'INSERT INTO product_custom_field_value (product_id, series_custom_field_id, value)
                 VALUES (?, ?, ?)'
            );
            $stmt->bind_param('iis', $productId, $fieldId, $value);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Fetches product by ID with custom values.
     *
     * @return array<string, mixed>|null
     */
    private function fetchProductById(int $productId): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, series_id, sku, name, description FROM product WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return null;
        }

        $seriesId = (int) $row['series_id'];
        $fieldMap = $this->seriesFieldService->getSeriesFieldMap($seriesId, SERIES_FIELD_SCOPE_PRODUCT);
        $customValueMap = $this->fetchProductCustomValuesMap([$productId]);
        $rawValues = $customValueMap[$productId] ?? [];
        $normalized = [];
        foreach ($rawValues as $fieldId => $value) {
            $fieldKey = $fieldMap[$fieldId]['fieldKey'] ?? null;
            if ($fieldKey !== null) {
                $normalized[$fieldKey] = $value;
            }
        }

        return [
            'id' => (int) $row['id'],
            'series_id' => $seriesId,
            'seriesId' => $seriesId,
            'sku' => (string) $row['sku'],
            'name' => (string) $row['name'],
            'description' => $row['description'],
            'customValues' => $normalized,
        ];
    }

    /**
     * @param array<int> $productIds
     *
     * @return array<int, array<int, string>>
     */
    private function fetchProductCustomValuesMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types = str_repeat('i', count($productIds));

        $stmt = $this->connection->prepare(
            sprintf(
                'SELECT product_id, series_custom_field_id, value
                 FROM product_custom_field_value
                 WHERE product_id IN (%s)',
                $placeholders
            )
        );
        $stmt->bind_param($types, ...$productIds);
        $stmt->execute();
        $result = $stmt->get_result();

        /** @var array<int, array<int, string>> $map */
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $productId = (int) $row['product_id'];
            $fieldId = (int) $row['series_custom_field_id'];
            $map[$productId][$fieldId] = (string) $row['value'];
        }
        $stmt->close();

        return $map;
    }
}
final class PublicCatalogService
{
    public function __construct(
        private HierarchyService $hierarchyService,
        private SeriesFieldService $seriesFieldService,
        private SeriesAttributeService $seriesAttributeService,
        private ProductService $productService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSnapshot(): array
    {
        $hierarchyPayload = $this->hierarchyService->listHierarchy();
        $hierarchy = $hierarchyPayload['hierarchy'] ?? [];
        $seriesIds = $this->collectSeriesIds($hierarchy);

        if ($seriesIds === []) {
            return [
                'generatedAt' => gmdate('c'),
                'hierarchy' => $hierarchy,
            ];
        }

        $productFields = $this->seriesFieldService->fetchFieldsForSeriesIds($seriesIds, SERIES_FIELD_SCOPE_PRODUCT);
        $metadataDefinitions = $this->seriesFieldService->fetchFieldsForSeriesIds($seriesIds, SERIES_FIELD_SCOPE_SERIES);
        $metadataPayloads = $this->seriesAttributeService->fetchMetadataPayloads($seriesIds);
        $productsBySeries = $this->productService->fetchProductsForSeriesIds($seriesIds, $productFields);

        $seriesSnapshots = [];
        foreach ($seriesIds as $seriesId) {
            $seriesSnapshots[$seriesId] = [
                'metadata' => [
                    'definitions' => $metadataDefinitions[$seriesId] ?? [],
                    'values' => $metadataPayloads[$seriesId]['values'] ?? [],
                ],
                'productFields' => $productFields[$seriesId] ?? [],
                'products' => $productsBySeries[$seriesId] ?? [],
            ];
        }

        $enrich = function (array $node) use (&$enrich, $seriesSnapshots): array {
            $children = [];
            foreach ($node['children'] ?? [] as $child) {
                $children[] = $enrich($child);
            }

            $payload = [
                'id' => $node['id'],
                'name' => $node['name'],
                'type' => $node['type'],
                'displayOrder' => $node['displayOrder'],
                'parentId' => $node['parentId'],
                'children' => $children,
            ];

            if ($node['type'] === 'series') {
                $seriesId = $node['id'];
                $snapshot = $seriesSnapshots[$seriesId] ?? [
                    'metadata' => ['definitions' => [], 'values' => []],
                    'productFields' => [],
                    'products' => [],
                ];
                $payload['metadata'] = $snapshot['metadata'];
                $payload['productFields'] = $snapshot['productFields'];
                $payload['products'] = $snapshot['products'];
            }

            return $payload;
        };

        $enrichedHierarchy = array_map($enrich, $hierarchy);

        return [
            'generatedAt' => gmdate('c'),
            'hierarchy' => $enrichedHierarchy,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $hierarchy
     *
     * @return array<int>
     */
    private function collectSeriesIds(array $hierarchy): array
    {
        $seriesIds = [];
        $stack = $hierarchy;
        while ($stack !== []) {
            $node = array_shift($stack);
            if (($node['type'] ?? null) === 'series' && isset($node['id'])) {
                $seriesIds[] = (int) $node['id'];
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                foreach ($node['children'] as $child) {
                    $stack[] = $child;
                }
            }
        }

        return array_values(array_unique($seriesIds));
    }
}

final class SpecSearchService
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private const ROOTS = [
        ['id' => 'general', 'name' => 'General Product', 'default' => true],
        ['id' => 'automotive', 'name' => 'Automotive Product', 'default' => false],
    ];

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private const CATEGORY_GROUPS = [
        'general' => [
            [
                'group' => 'EMC Components',
                'categories' => [
                    ['id' => 'ferrite_chip_bead', 'name' => 'Ferrite Chip Bead'],
                    ['id' => 'ferrite_chip_bead_large', 'name' => 'Ferrite Chip Bead (Large Current)'],
                    ['id' => 'chip_inductor', 'name' => 'Chip Inductor'],
                ],
            ],
            [
                'group' => 'Transformer',
                'categories' => [
                    ['id' => 'planar_transformer', 'name' => 'Planar Transformer'],
                ],
            ],
        ],
        'automotive' => [
            [
                'group' => 'Magnetic Components',
                'categories' => [
                    ['id' => 'magnetic_core', 'name' => 'Magnetic Core'],
                ],
            ],
            [
                'group' => 'Wireless Power Transfer',
                'categories' => [
                    ['id' => 'wireless_power_transfer', 'name' => 'Wireless Power Transfer'],
                ],
            ],
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const FACET_LABELS = [
        'series' => 'Series',
        'inductance' => 'Inductance',
        'current_rating' => 'Current Rating',
        'core_size' => 'Core Size',
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    private const PRODUCTS = [
        [
            'root_id' => 'general',
            'category_ids' => ['ferrite_chip_bead'],
            'sku' => 'ZIK300-RC-10',
            'series' => 'ZIK300-RC-10',
            'attributes' => [
                'inductance' => '2.2uH',
                'current_rating' => '10A',
                'core_size' => '8.0 x 5.0 x 4.0 mm',
            ],
        ],
        [
            'root_id' => 'general',
            'category_ids' => ['chip_inductor'],
            'sku' => 'ZIK200-LC-01',
            'series' => 'ZIK200-LC-01',
            'attributes' => [
                'inductance' => '1.0uH',
                'current_rating' => '4A',
                'core_size' => '4.0 x 4.0 x 2.0 mm',
            ],
        ],
        [
            'root_id' => 'general',
            'category_ids' => ['planar_transformer'],
            'sku' => 'TRF-PT-500',
            'series' => 'TRF-PT-500',
            'attributes' => [
                'inductance' => '10uH',
                'current_rating' => '25A',
                'core_size' => '12.0 x 12.0 x 6.0 mm',
            ],
        ],
        [
            'root_id' => 'automotive',
            'category_ids' => ['magnetic_core'],
            'sku' => 'AUTO-MAG-25',
            'series' => 'AUTO-MAG-25',
            'attributes' => [
                'inductance' => '4.7uH',
                'current_rating' => '15A',
                'core_size' => '10.0 x 8.0 x 5.0 mm',
            ],
        ],
        [
            'root_id' => 'automotive',
            'category_ids' => ['wireless_power_transfer'],
            'sku' => 'WPT-450-MX-01',
            'series' => 'WPT-450-MX-01',
            'attributes' => [
                'inductance' => '3.3uH',
                'current_rating' => '20A',
                'core_size' => '15.0 x 15.0 x 4.5 mm',
            ],
        ],
    ];

    public function __construct(private mysqli $connection)
    {
    }

    /**
     * Returns the available root categories (radio group).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRootCategories(): array
    {
        return self::ROOTS;
    }

    /**
     * Returns grouped category checkboxes for the given root.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listProductCategoryGroups(string $rootId): array
    {
        $this->assertValidRoot($rootId);

        return self::CATEGORY_GROUPS[$rootId] ?? [];
    }

    /**
     * Returns facet cards (Series + ACF attributes) for the selected categories.
     *
     * @param array<int, string> $categoryIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFacets(string $rootId, array $categoryIds): array
    {
        $this->assertValidRoot($rootId);
        if ($categoryIds === []) {
            throw new CatalogApiException(
                'VALIDATION_ERROR',
                'At least one product category must be selected to load Mechanical Parameters.',
                400,
                ['category_ids' => 'Select at least one product category.']
            );
        }

        $products = $this->filterProductsByEnvelope($rootId, $categoryIds, []);

        return $this->buildFacetDefinitions($products);
    }

    /**
     * Returns product rows for the full filter envelope.
     *
     * @param array<int, string> $categoryIds
     * @param array<string, array<int, string>> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchProducts(string $rootId, array $categoryIds, array $filters): array
    {
        $this->assertValidRoot($rootId);
        if ($categoryIds === []) {
            throw new CatalogApiException(
                'VALIDATION_ERROR',
                'At least one product category must be selected before searching products.',
                400,
                ['category_ids' => 'Select at least one product category.']
            );
        }

        $normalizedFilters = $this->normalizeFilters($filters);
        $products = $this->filterProductsByEnvelope($rootId, $categoryIds, $normalizedFilters);

        return array_map(
            static function (array $product): array {
                $sku = (string) $product['sku'];

                return [
                    'sku' => $sku,
                    'series' => (string) $product['series'],
                    'attributes' => $product['attributes'],
                    'editUrl' => 'catalog.php?sku=' . rawurlencode($sku),
                ];
            },
            $products
        );
    }

    /**
     * Ensures a valid root identifier was supplied.
     */
    private function assertValidRoot(string $rootId): void
    {
        foreach (self::ROOTS as $root) {
            if ((string) $root['id'] === $rootId) {
                return;
            }
        }

        throw new CatalogApiException(
            'VALIDATION_ERROR',
            'Unknown root category supplied.',
            400,
            ['root_id' => 'Unknown root category.']
        );
    }

    /**
     * Normalizes category identifiers to trimmed unique strings.
     *
     * @param array<int, string> $categoryIds
     *
     * @return array<int, string>
     */
    private function normalizeCategoryIds(array $categoryIds): array
    {
        $normalized = [];
        foreach ($categoryIds as $id) {
            $string = trim((string) $id);
            if ($string === '') {
                continue;
            }
            $normalized[$string] = true;
        }

        return array_keys($normalized);
    }

    /**
     * Normalizes the facet filters to arrays of strings.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<string, array<int, string>>
     */
    private function normalizeFilters(array $filters): array
    {
        $normalized = [];
        foreach ($filters as $key => $values) {
            if (!is_string($key) || !array_key_exists($key, self::FACET_LABELS)) {
                continue;
            }
            if (!is_array($values)) {
                continue;
            }
            $set = [];
            foreach ($values as $value) {
                $string = trim((string) $value);
                if ($string === '') {
                    continue;
                }
                $set[$string] = true;
            }
            if ($set !== []) {
                $normalized[$key] = array_keys($set);
            }
        }

        return $normalized;
    }

    /**
     * Filters the mock catalog by the root/category/facet selections.
     *
     * @param array<int, string> $categoryIds
     * @param array<string, array<int, string>> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterProductsByEnvelope(string $rootId, array $categoryIds, array $filters): array
    {
        $selectedCategories = $this->normalizeCategoryIds($categoryIds);

        return array_values(array_filter(
            self::PRODUCTS,
            function (array $product) use ($rootId, $selectedCategories, $filters): bool {
                if (($product['root_id'] ?? null) !== $rootId) {
                    return false;
                }

                if ($selectedCategories !== []) {
                    $productCategories = array_map('strval', $product['category_ids'] ?? []);
                    $overlap = array_intersect($productCategories, $selectedCategories);
                    if ($overlap === []) {
                        return false;
                    }
                }

                foreach ($filters as $key => $values) {
                    if ($values === []) {
                        continue;
                    }
                    if ($key === 'series') {
                        $candidate = (string) ($product['series'] ?? '');
                    } else {
                        $candidate = (string) ($product['attributes'][$key] ?? '');
                    }
                    if ($candidate === '' || !in_array($candidate, $values, true)) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    /**
     * Builds facet definitions (Series + custom attributes) from filtered rows.
     *
     * @param array<int, array<string, mixed>> $products
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildFacetDefinitions(array $products): array
    {
        $definitions = [];

        foreach (self::FACET_LABELS as $key => $label) {
            $values = [];
            foreach ($products as $product) {
                if ($key === 'series') {
                    $values[] = (string) ($product['series'] ?? '');
                    continue;
                }
                $values[] = (string) ($product['attributes'][$key] ?? '');
            }
            $filteredValues = array_values(array_filter($values, static fn ($value) => $value !== ''));
            $unique = array_values(array_unique($filteredValues, SORT_STRING));
            sort($unique, SORT_NATURAL | SORT_FLAG_CASE);

            $definitions[] = [
                'key' => $key,
                'label' => $label,
                'values' => $unique,
            ];
        }

        return $definitions;
    }
}

final class LatexTemplateService
{
    private string $projectRoot;

    public function __construct(
        private mysqli $connection,
        private string $pdfStorageDir = LATEX_PDF_STORAGE
    ) {
        $this->projectRoot = rtrim(str_replace('\\', '/', __DIR__), '/');
    }

    /**
     * Lists all LaTeX templates ordered by most recently updated.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTemplates(): array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, title, description, pdf_path, created_at, updated_at
             FROM latex_template
             ORDER BY updated_at DESC, id DESC'
        );
        $stmt->execute();
        $result = $stmt->get_result();

        $templates = [];
        while ($row = $result->fetch_assoc()) {
            $templates[] = $this->mapRow($row, false);
        }
        $stmt->close();

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
        $stmt = $this->connection->prepare(
            'INSERT INTO latex_template (title, description, latex_source, created_at, updated_at)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->bind_param('sss', $title, $description, $latex);
        $stmt->execute();
        $templateId = (int) $this->connection->insert_id;
        $stmt->close();

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
        $stmt = $this->connection->prepare(
            'UPDATE latex_template
             SET title = ?, description = ?, latex_source = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('sssi', $title, $description, $latex, $templateId);
        $stmt->execute();
        $stmt->close();

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

        $stmt = $this->connection->prepare('DELETE FROM latex_template WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $templateId);
        $stmt->execute();
        $stmt->close();

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

        $stmt = $this->connection->prepare(
            'UPDATE latex_template
             SET pdf_path = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('si', $relativePath, $templateId);
        $stmt->execute();
        $stmt->close();

        $correlationId = $this->generateCorrelationId();
        $this->logOperation('pdf_update', $templateId, $correlationId);

        return $this->getTemplate($templateId, false);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRawTemplate(int $templateId): ?array
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
        $prefix = ltrim(LATEX_PDF_URL_PREFIX, '/');
        if (!str_starts_with($normalizedRelative, $prefix)) {
            return;
        }

        $absolute = $this->projectRoot . '/' . $normalizedRelative;
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

final class LatexBuildService
{
    private string $pdflatexPath;

    private string $storageDir;

    private string $workingDir;

    private string $relativePrefix;

    public function __construct(
        string $pdflatexPath,
        string $storageDir = LATEX_PDF_STORAGE,
        string $workingDir = LATEX_BUILD_WORKDIR
    ) {
        $this->pdflatexPath = $pdflatexPath !== '' ? $pdflatexPath : LATEX_DEFAULT_PDFLATEX;
        $this->storageDir = rtrim($storageDir, DIRECTORY_SEPARATOR);
        $this->workingDir = rtrim($workingDir, DIRECTORY_SEPARATOR);
        $this->relativePrefix = ltrim(LATEX_PDF_URL_PREFIX, '/');

        $this->ensureDirectory($this->storageDir);
        $this->ensureDirectory($this->workingDir);
    }

    /**
     * Compiles LaTeX into a PDF using MiKTeX.
     *
     * @return array<string, mixed>
     */
    public function build(int $templateId, string $latex): array
    {
        $timestamp = (new \DateTimeImmutable('now'))->format('YmdHis');
        $token = bin2hex(random_bytes(4));
        $baseName = sprintf('template_%d_%s_%s', $templateId, $timestamp, $token);
        $texPath = $this->workingDir . DIRECTORY_SEPARATOR . $baseName . '.tex';

        if (file_put_contents($texPath, $latex) === false) {
            throw new CatalogApiException(
                'LATEX_BUILD_ERROR',
                'Unable to write temporary LaTeX source file.',
                500
            );
        }

        $command = sprintf(
            '%s -interaction=nonstopmode -halt-on-error -output-directory %s %s',
            escapeshellarg($this->pdflatexPath),
            escapeshellarg($this->workingDir),
            escapeshellarg($texPath)
        );

        $correlationId = bin2hex(random_bytes(16));
        error_log(
            sprintf(
                '[LatexBuild] action=start templateId=%d correlationId=%s command=%s',
                $templateId,
                $correlationId,
                $command
            )
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $this->workingDir);
        if (!is_resource($process)) {
            $this->cleanupWorkingFiles($baseName);
            throw new CatalogApiException('LATEX_TOOL_MISSING', 'Unable to execute MiKTeX pdflatex.', 500);
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $pdfSource = $this->workingDir . DIRECTORY_SEPARATOR . $baseName . '.pdf';

        try {
            if ($exitCode !== 0 || !is_file($pdfSource)) {
                error_log(
                    sprintf(
                        '[LatexBuild] action=fail templateId=%d correlationId=%s exitCode=%d',
                        $templateId,
                        $correlationId,
                        $exitCode
                    )
                );
                throw new CatalogApiException(
                    'LATEX_BUILD_ERROR',
                    'MiKTeX compilation failed.',
                    500,
                    [
                        'stdout' => trim($stdout),
                        'stderr' => trim($stderr),
                        'exitCode' => $exitCode,
                        'correlationId' => $correlationId,
                    ]
                );
            }

            $pdfFilename = sprintf('%d-%s-%s.pdf', $templateId, $timestamp, $token);
            $targetPath = $this->storageDir . DIRECTORY_SEPARATOR . $pdfFilename;
            $this->movePdf($pdfSource, $targetPath);
            $relativePath = $this->relativePrefix . '/' . $pdfFilename;

            error_log(
                sprintf(
                    '[LatexBuild] action=success templateId=%d correlationId=%s file=%s',
                    $templateId,
                    $correlationId,
                    $targetPath
                )
            );

            return [
                'relativePath' => $relativePath,
                'absolutePath' => $targetPath,
                'stdout' => trim($stdout),
                'stderr' => trim($stderr),
                'exitCode' => $exitCode,
                'log' => trim($stdout . PHP_EOL . $stderr),
                'correlationId' => $correlationId,
            ];
        } finally {
            $this->cleanupWorkingFiles($baseName);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create directory: ' . $directory);
            }
        }
    }

    private function cleanupWorkingFiles(string $baseName): void
    {
        $extensions = ['tex', 'aux', 'log', 'out', 'toc', 'pdf'];
        foreach ($extensions as $extension) {
            $path = $this->workingDir . DIRECTORY_SEPARATOR . $baseName . '.' . $extension;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function movePdf(string $source, string $destination): void
    {
        if (@rename($source, $destination)) {
            return;
        }

        if (!@copy($source, $destination)) {
            throw new CatalogApiException(
                'LATEX_BUILD_ERROR',
                'Unable to move generated PDF into storage.',
                500
            );
        }
        @unlink($source);
    }
}

final class CatalogCsvService
{
    private string $storageDir;

    public function __construct(
        private mysqli $connection,
        private HierarchyService $hierarchyService,
        private SeriesFieldService $seriesFieldService
    ) {
        $this->storageDir = CATALOG_CSV_STORAGE;
        $this->ensureStorageDirectory();
    }

    private function ensureStorageDirectory(): void
    {
        if (!is_dir($this->storageDir)) {
            $directory = $this->storageDir;
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create CSV storage directory.');
            }
        }
    }

    private function sanitizeOriginalName(?string $name): string
    {
        $name = $name !== null ? basename((string) $name) : 'import.csv';
        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        if ($sanitized === null || $sanitized === '' || $sanitized === '.' || $sanitized === '..') {
            return 'import.csv';
        }

        return $sanitized;
    }

    private function currentTimestamp(): string
    {
        return (new \DateTimeImmutable('now'))->format('YmdHis');
    }

    private function buildFileId(string $type, string $timestamp, ?string $originalName = null): string
    {
        if ($type === 'export') {
            return $timestamp . '_export.csv';
        }

        $suffix = $originalName !== null ? '_' . $originalName : '';

        return $timestamp . '_import' . $suffix;
    }

    private function buildFilePath(string $fileId): string
    {
        return $this->storageDir . '/' . $fileId;
    }

    private function buildDownloadName(string $fileId): string
    {
        if (preg_match('/^(\\d{14})_export\\.csv$/', $fileId, $matches) === 1) {
            return 'catalog_' . $matches[1] . '.csv';
        }
        if (preg_match('/^(\\d{14})_import_(.+)$/', $fileId, $matches) === 1) {
            return $matches[2];
        }

        return $fileId;
    }

    private function assertValidFileId(string $fileId): void
    {
        if (
            preg_match('/^\\d{14}_(export|import)(?:_[A-Za-z0-9._-]+)?\\.csv$/', $fileId) !== 1
        ) {
            throw new CatalogApiException('CSV_NOT_FOUND', 'Invalid CSV identifier.', 404);
        }
    }

    public function listHistory(): array
    {
        $this->ensureStorageDirectory();
        $entries = @scandir($this->storageDir);
        if ($entries === false) {
            return ['files' => []];
        }

        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (preg_match('/^(\\d{14})_(export|import)(?:_(.+))?\\.csv$/', $entry, $matches) !== 1) {
                continue;
            }
            $filePath = $this->buildFilePath($entry);
            if (!is_file($filePath)) {
                continue;
            }
            $timestampRaw = $matches[1];
            $type = $matches[2];
            $dt = \DateTimeImmutable::createFromFormat('YmdHis', $timestampRaw) ?: new \DateTimeImmutable('@0');
            $files[] = [
                'id' => $entry,
                'name' => $type === 'export'
                    ? 'catalog_' . $timestampRaw . '.csv'
                    : ($matches[3] ?? $entry),
                'type' => $type,
                'timestamp' => $dt->format(\DateTimeInterface::ATOM),
                'size' => @filesize($filePath) ?: 0,
            ];
        }

        usort(
            $files,
            static function (array $a, array $b): int {
                return strcmp($b['id'], $a['id']);
            }
        );

        return [
            'files' => $files,
            'audits' => $this->readTruncateAudits(),
            'truncateInProgress' => $this->isTruncateInProgress(),
        ];
    }

    public function exportCatalog(): array
    {
        $this->ensureStorageDirectory();
        $this->assertTruncateNotRunning();
        $timestamp = $this->currentTimestamp();
        $fileId = $this->buildFileId('export', $timestamp);
        $filePath = $this->buildFilePath($fileId);

        $categories = $this->fetchAllCategories();
        $productFieldKeys = $this->fetchAllCustomFieldKeys(SERIES_FIELD_SCOPE_PRODUCT);
        $products = $this->fetchProductsWithSeries();
        $customValueMap = $this->fetchProductCustomValues();

        $handle = fopen($filePath, 'wb');
        if ($handle === false) {
            throw new CatalogApiException('CSV_WRITE_ERROR', 'Unable to create export file.', 500);
        }

        $header = ['category_path', 'product_name'];
        foreach ($productFieldKeys as $fieldKey) {
            $header[] = $fieldKey;
        }
        fputcsv($handle, $header);

        foreach ($products as $product) {
            $seriesId = (int) $product['series_id'];
            $categoryPath = $this->buildCategoryPath($categories, $seriesId);
            $productLabel = $product['sku'] !== '' ? $product['sku'] : ($product['name'] ?? '');
            $row = [
                $categoryPath,
                $productLabel ?? '',
            ];
            $productCustom = $customValueMap[$product['id']] ?? [];
            foreach ($productFieldKeys as $fieldKey) {
                $row[] = $productCustom[$fieldKey] ?? '';
            }
            fputcsv($handle, $row);
        }

        fclose($handle);

        return [
            'id' => $fileId,
            'name' => 'catalog_' . $timestamp . '.csv',
            'type' => 'export',
            'timestamp' => (\DateTimeImmutable::createFromFormat('YmdHis', $timestamp) ?: new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
            'size' => @filesize($filePath) ?: 0,
        ];
    }

    public function importFromUploadedFile(array $file): array
    {
        $this->ensureStorageDirectory();
        $this->assertTruncateNotRunning();
        if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new CatalogApiException('CSV_REQUIRED', 'CSV file upload is required.', 400);
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new CatalogApiException('CSV_REQUIRED', 'Uploaded CSV is invalid.', 400);
        }

        $originalName = $this->sanitizeOriginalName($file['name'] ?? 'import.csv');
        $timestamp = $this->currentTimestamp();
        $fileId = $this->buildFileId('import', $timestamp, $originalName);
        $destination = $this->buildFilePath($fileId);

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new CatalogApiException('CSV_UPLOAD_ERROR', 'Failed to store uploaded CSV.', 500);
        }

        $result = $this->processImport($destination, $fileId, $originalName);
        $result['fileId'] = $fileId;

        return $result;
    }

    public function importFromPath(string $filePath, string $originalName = 'import.csv'): array
    {
        $this->ensureStorageDirectory();
        $this->assertTruncateNotRunning();
        if (!is_file($filePath)) {
            throw new CatalogApiException('CSV_NOT_FOUND', 'Import source CSV not found.', 404);
        }

        $originalName = $this->sanitizeOriginalName($originalName);
        $timestamp = $this->currentTimestamp();
        $fileId = $this->buildFileId('import', $timestamp, $originalName);
        $destination = $this->buildFilePath($fileId);

        if (!copy($filePath, $destination)) {
            throw new CatalogApiException('CSV_UPLOAD_ERROR', 'Failed to copy CSV for import.', 500);
        }

        $result = $this->processImport($destination, $fileId, $originalName);
        $result['fileId'] = $fileId;

        return $result;
    }

    public function restoreCatalog(string $fileId): array
    {
        $this->ensureStorageDirectory();
        $this->assertTruncateNotRunning();
        $fileId = trim($fileId);
        $this->assertValidFileId($fileId);
        $filePath = $this->buildFilePath($fileId);
        if (!is_file($filePath)) {
            throw new CatalogApiException('CSV_NOT_FOUND', 'CSV file not found.', 404);
        }

        $result = $this->processImport($filePath, $fileId, $this->buildDownloadName($fileId));
        $result['fileId'] = $fileId;

        return $result;
    }

    public function streamFile(string $fileId, HttpResponder $responder): void
    {
        $fileId = trim($fileId);
        $this->assertValidFileId($fileId);

        $filePath = $this->buildFilePath($fileId);
        if (!is_file($filePath)) {
            throw new CatalogApiException('CSV_NOT_FOUND', 'CSV file not found.', 404);
        }

        $responder->sendFile($filePath, $this->buildDownloadName($fileId));
    }

    public function deleteFile(string $fileId): void
    {
        $fileId = trim($fileId);
        $this->assertValidFileId($fileId);

        $filePath = $this->buildFilePath($fileId);
        if (!is_file($filePath)) {
            throw new CatalogApiException('CSV_NOT_FOUND', 'CSV file not found.', 404);
        }

        if (!unlink($filePath)) {
            throw new CatalogApiException('CSV_DELETE_ERROR', 'Unable to delete CSV file.', 500);
        }
    }

    /**
     * @param array<int, string> $header
     * @return array{columns: array<string, int>, attributes: array<int, string>}
     */
    private function analyseHeader(array $header): array
    {
        $columns = [];
        $attributeColumns = [];

        foreach ($header as $index => $rawHeader) {
            $trimmed = trim((string) $rawHeader);
            if ($trimmed === '') {
                continue;
            }
            $lower = strtolower($trimmed);
            switch ($lower) {
                case 'category_path':
                    $columns['category_path'] = $index;
                    break;
                case 'product_name':
                    $columns['product_name'] = $index;
                    break;
                default:
                    $attributeColumns[$index] = $trimmed;
                    break;
            }
        }

        $required = ['category_path', 'product_name'];
        foreach ($required as $column) {
            if (!array_key_exists($column, $columns)) {
                throw new CatalogApiException(
                    'CSV_PARSE_ERROR',
                    sprintf('Missing required column "%s" in CSV header.', $column),
                    400
                );
            }
        }

        return ['columns' => $columns, 'attributes' => $attributeColumns];
    }
    private function processImport(string $filePath, string $fileId, string $originalName): array
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new CatalogApiException('CSV_PARSE_ERROR', 'Unable to read CSV file.', 400);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new CatalogApiException('CSV_PARSE_ERROR', 'CSV file is empty.', 400);
        }

        $analysis = $this->analyseHeader($header);
        $columns = $analysis['columns'];
        /** @var array<int, string> $attributeColumns */
        $attributeColumns = $analysis['attributes'];
        $attributeOrder = [];
        $order = 0;
        foreach ($attributeColumns as $index => $fieldKey) {
            $normalizedKey = trim((string) $fieldKey);
            if ($normalizedKey === '') {
                throw new CatalogApiException(
                    'CSV_PARSE_ERROR',
                    sprintf('Header column at index %d is blank.', $index),
                    400
                );
            }
            $attributeColumns[$index] = $normalizedKey;
            if (!array_key_exists($normalizedKey, $attributeOrder)) {
                $attributeOrder[$normalizedKey] = $order++;
            }
        }

        $existingProducts = $this->fetchExistingProductIds();
        $existingSeries = $this->fetchExistingSeriesIds();
        $existingCategories = $this->fetchExistingCategoryIds();

        $touchedProducts = [];
        $touchedSeries = [];
        $touchedCategories = [];

        $categoryCache = [];
        $seriesCache = [];
        $seriesFieldCache = [];
        $fieldSortSynchronized = [];

        $createdCategories = 0;
        $createdSeries = 0;

        $lineNumber = 1;

        $this->connection->begin_transaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $lineNumber++;
                if ($this->rowIsEmpty($row)) {
                    continue;
                }

                $categoryPath = trim((string) ($row[$columns['category_path']] ?? ''));
                $this->assertCsvValue($categoryPath !== '', 'category_path', $lineNumber);

                $segments = array_values(
                    array_filter(
                        array_map(
                            static fn (string $segment): string => trim($segment),
                            explode('>', $categoryPath)
                        ),
                        static fn (string $segment): bool => $segment !== ''
                    )
                );
                $this->assertCsvValue($segments !== [], 'category_path', $lineNumber);

                $seriesName = array_pop($segments);
                $this->assertCsvValue($seriesName !== null && $seriesName !== '', 'series_name', $lineNumber);

                $parentId = null;
                foreach ($segments as $segment) {
                    $parentId = $this->upsertCategory(
                        $parentId,
                        $segment,
                        $categoryCache,
                        $touchedCategories,
                        $createdCategories
                    );
                }

                $seriesId = $this->upsertSeries(
                    $parentId,
                    $seriesName,
                    0,
                    $seriesCache,
                    $touchedSeries,
                    $createdSeries
                );

                if (!isset($seriesFieldCache[$seriesId])) {
                    $seriesFieldCache[$seriesId] = [
                        SERIES_FIELD_SCOPE_PRODUCT => null,
                    ];
                }
                if ($seriesFieldCache[$seriesId][SERIES_FIELD_SCOPE_PRODUCT] === null) {
                    $seriesFieldCache[$seriesId][SERIES_FIELD_SCOPE_PRODUCT] = $this->buildSeriesFieldMap(
                        $seriesId,
                        SERIES_FIELD_SCOPE_PRODUCT
                    );
                }

                $productFieldMap =& $seriesFieldCache[$seriesId][SERIES_FIELD_SCOPE_PRODUCT];

                $customValues = [];
                foreach ($attributeColumns as $index => $fieldKey) {
                    $value = isset($row[$index]) ? trim((string) $row[$index]) : '';
                    $customValues[$fieldKey] = $value;
                    $desiredOrder = $attributeOrder[$fieldKey] ?? 0;
                    if (!isset($productFieldMap[$fieldKey])) {
                        $label = $this->deriveCustomFieldLabel($fieldKey);
                        $field = $this->seriesFieldService->saveField([
                            'seriesId' => $seriesId,
                            'fieldKey' => $fieldKey,
                            'label' => $label,
                            'fieldType' => 'text',
                            'fieldScope' => SERIES_FIELD_SCOPE_PRODUCT,
                            'isRequired' => false,
                            'sortOrder' => $desiredOrder,
                        ]);
                        $productFieldMap[$fieldKey] = $field;
                    } else {
                        $existingOrder = (int) ($productFieldMap[$fieldKey]['sortOrder'] ?? 0);
                        if (
                            $existingOrder !== $desiredOrder
                            && !isset($fieldSortSynchronized[$seriesId][$fieldKey])
                        ) {
                            $field = $this->seriesFieldService->saveField([
                                'id' => (int) $productFieldMap[$fieldKey]['id'],
                                'seriesId' => $seriesId,
                                'fieldKey' => $fieldKey,
                                'label' => $productFieldMap[$fieldKey]['label'],
                                'fieldType' => $productFieldMap[$fieldKey]['fieldType'],
                                'fieldScope' => SERIES_FIELD_SCOPE_PRODUCT,
                                'defaultValue' => $productFieldMap[$fieldKey]['defaultValue'] ?? null,
                                'isRequired' => (bool) ($productFieldMap[$fieldKey]['isRequired'] ?? false),
                                'sortOrder' => $desiredOrder,
                            ]);
                            $productFieldMap[$fieldKey] = $field;
                            $fieldSortSynchronized[$seriesId][$fieldKey] = true;
                        }
                    }
                }

                $productLabel = trim((string) ($row[$columns['product_name']] ?? ''));
                $this->assertCsvValue($productLabel !== '', 'product_name', $lineNumber);

                $productId = $this->upsertProduct(
                    $seriesId,
                    $productLabel,
                    $productLabel,
                    null,
                    $customValues,
                    $productFieldMap
                );

                $touchedProducts[$productId] = true;
                $touchedSeries[$seriesId] = true;
            }

            fclose($handle);

            $this->pruneMissingRecords(
                $existingProducts,
                $existingSeries,
                $existingCategories,
                $touchedProducts,
                $touchedSeries,
                $touchedCategories
            );

            $this->connection->commit();
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            $this->connection->rollback();
            throw $exception;
        }

        return [
            'importedProducts' => count($touchedProducts),
            'createdSeries' => $createdSeries,
            'createdCategories' => $createdCategories,
        ];
    }

    private function deriveCustomFieldLabel(string $fieldKey): string
    {
        $label = str_replace(['_', '.'], ' ', $fieldKey);
        $label = preg_replace('/\s+/', ' ', $label) ?? '';
        $label = trim($label);

        if ($label === '') {
            return 'Custom Field';
        }

        return ucwords($label);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildSeriesFieldMap(int $seriesId, string $scope): array
    {
        $fieldList = $this->seriesFieldService->listFields($seriesId, $scope);
        $fieldMap = [];
        foreach ($fieldList as $field) {
            $fieldMap[$field['fieldKey']] = $field;
        }

        return $fieldMap;
    }

    private function upsertCategory(
        ?int $parentId,
        string $name,
        array &$cache,
        array &$touchedCategories,
        int &$createdCategories
    ): int {
        $normalizedName = trim($name);
        $cacheKey = ($parentId === null ? 'root' : (string) $parentId) . '|' . strtolower($normalizedName);
        if (isset($cache[$cacheKey])) {
            $id = $cache[$cacheKey];
            $touchedCategories[$id] = true;
            return $id;
        }

        $stmt = $this->connection->prepare(
            "SELECT id FROM category WHERE parent_id <=> ? AND name = ? AND type = 'category' LIMIT 1"
        );
        $stmt->bind_param('is', $parentId, $normalizedName);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row) {
            $id = (int) $row['id'];
        } else {
            $insert = $this->connection->prepare(
                "INSERT INTO category (parent_id, name, type, display_order) VALUES (?, ?, 'category', 0)"
            );
            $insert->bind_param('is', $parentId, $normalizedName);
            $insert->execute();
            $id = (int) $insert->insert_id;
            $insert->close();
            $createdCategories++;
        }

        $cache[$cacheKey] = $id;
        $touchedCategories[$id] = true;

        return $id;
    }

    private function upsertSeries(
        ?int $parentId,
        string $name,
        int $displayOrder,
        array &$cache,
        array &$touchedSeries,
        int &$createdSeries
    ): int {
        $normalizedName = trim($name);
        $cacheKey = ($parentId === null ? 'root' : (string) $parentId) . '|' . strtolower($normalizedName);
        if (isset($cache[$cacheKey])) {
            $seriesId = $cache[$cacheKey];
            $touchedSeries[$seriesId] = true;
            return $seriesId;
        }

        $stmt = $this->connection->prepare(
            "SELECT id, display_order FROM category WHERE parent_id <=> ? AND name = ? AND type = 'series' LIMIT 1"
        );
        $stmt->bind_param('is', $parentId, $normalizedName);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row) {
            $seriesId = (int) $row['id'];
            if ((int) $row['display_order'] !== $displayOrder) {
                $update = $this->connection->prepare('UPDATE category SET display_order = ? WHERE id = ?');
                $update->bind_param('ii', $displayOrder, $seriesId);
                $update->execute();
                $update->close();
            }
        } else {
            $insert = $this->connection->prepare(
                "INSERT INTO category (parent_id, name, type, display_order) VALUES (?, ?, 'series', ?)"
            );
            $insert->bind_param('isi', $parentId, $normalizedName, $displayOrder);
            $insert->execute();
            $seriesId = (int) $insert->insert_id;
            $insert->close();
            $createdSeries++;
        }

        $cache[$cacheKey] = $seriesId;
        $touchedSeries[$seriesId] = true;

        return $seriesId;
    }

    /**
     * @param array<string, array<string, mixed>> $seriesFieldMap
     */
    private function upsertProduct(
        int $seriesId,
        string $sku,
        string $name,
        ?string $description,
        array $customValues,
        array $seriesFieldMap
    ): int {
        $select = $this->connection->prepare(
            'SELECT id FROM product WHERE series_id = ? AND sku = ? LIMIT 1'
        );
        $select->bind_param('is', $seriesId, $sku);
        $select->execute();
        $result = $select->get_result();
        $row = $result->fetch_assoc();
        $select->close();

        if ($row) {
            $productId = (int) $row['id'];
            $update = $this->connection->prepare(
                'UPDATE product SET name = ?, description = ? WHERE id = ?'
            );
            $update->bind_param('ssi', $name, $description, $productId);
            $update->execute();
            $update->close();
        } else {
            $insert = $this->connection->prepare(
                'INSERT INTO product (series_id, sku, name, description) VALUES (?, ?, ?, ?)'
            );
            $insert->bind_param('isss', $seriesId, $sku, $name, $description);
            $insert->execute();
            $productId = (int) $insert->insert_id;
            $insert->close();
        }

        $this->replaceProductCustomValues($productId, $seriesId, $customValues, $seriesFieldMap);

        return $productId;
    }

    /**
     * @param array<string, array<string, mixed>> $seriesFieldMap
     */
    private function replaceProductCustomValues(
        int $productId,
        int $seriesId,
        array $customValues,
        array $seriesFieldMap
    ): void {
        $delete = $this->connection->prepare('DELETE FROM product_custom_field_value WHERE product_id = ?');
        $delete->bind_param('i', $productId);
        $delete->execute();
        $delete->close();

        if ($customValues === []) {
            return;
        }

        $insert = $this->connection->prepare(
            'INSERT INTO product_custom_field_value (product_id, series_custom_field_id, value) VALUES (?, ?, ?)'
        );
        foreach ($customValues as $fieldKey => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (!isset($seriesFieldMap[$fieldKey])) {
                continue;
            }
            $fieldId = (int) $seriesFieldMap[$fieldKey]['id'];
            $insert->bind_param('iis', $productId, $fieldId, $value);
            $insert->execute();
        }
        $insert->close();
    }

    private function fetchExistingProductIds(): array
    {
        $result = $this->connection->query('SELECT id FROM product');
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        $result->free();

        return $ids;
    }

    private function fetchExistingSeriesIds(): array
    {
        $result = $this->connection->query("SELECT id FROM category WHERE type = 'series'");
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        $result->free();

        return $ids;
    }

    private function fetchExistingCategoryIds(): array
    {
        $result = $this->connection->query("SELECT id FROM category WHERE type = 'category'");
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        $result->free();

        return $ids;
    }

    private function deleteProducts(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        foreach ($this->chunkIds($ids) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->connection->prepare("DELETE FROM product WHERE id IN ($placeholders)");
            $types = str_repeat('i', count($chunk));
            $stmt->bind_param($types, ...$chunk);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function deleteSeries(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        foreach ($this->chunkIds($ids) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->connection->prepare(
                "DELETE FROM category WHERE type = 'series' AND id IN ($placeholders)"
            );
            $types = str_repeat('i', count($chunk));
            $stmt->bind_param($types, ...$chunk);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function deleteCategories(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        foreach ($this->chunkIds($ids) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->connection->prepare(
                "DELETE FROM category WHERE type = 'category' AND id IN ($placeholders)"
            );
            $types = str_repeat('i', count($chunk));
            $stmt->bind_param($types, ...$chunk);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * @return array<int, array<int>>
     */
    private function chunkIds(array $ids, int $chunkSize = 500): array
    {
        $chunks = [];
        $buffer = [];
        foreach ($ids as $id) {
            $buffer[] = (int) $id;
            if (count($buffer) >= $chunkSize) {
                $chunks[] = $buffer;
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    private function pruneMissingRecords(
        array $existingProducts,
        array $existingSeries,
        array $existingCategories,
        array $touchedProducts,
        array $touchedSeries,
        array $touchedCategories
    ): void {
        $productsToDelete = array_diff($existingProducts, array_keys($touchedProducts));
        $this->deleteProducts($productsToDelete);

        $seriesToDelete = array_diff($existingSeries, array_keys($touchedSeries));
        $this->deleteSeries($seriesToDelete);

        $categoriesToDelete = array_diff($existingCategories, array_keys($touchedCategories));
        $this->deleteCategories($categoriesToDelete);
    }

    private function assertCsvValue(bool $condition, string $field, int $lineNumber): void
    {
        if (!$condition) {
            throw new CatalogApiException(
                'VALIDATION_ERROR',
                sprintf('Row %d: %s is required.', $lineNumber, $field),
                400,
                ['row' => $lineNumber, 'field' => $field]
            );
        }
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllCategories(): array
    {
        $result = $this->connection->query(
            'SELECT id, parent_id, name, type, display_order FROM category'
        );
        $categories = [];
        while ($row = $result->fetch_assoc()) {
            $categories[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'display_order' => (int) $row['display_order'],
            ];
        }
        $result->free();

        return $categories;
    }

    /**
     * @return array<int, string>
     */
    private function fetchAllCustomFieldKeys(string $scope = SERIES_FIELD_SCOPE_PRODUCT): array
    {
        $stmt = $this->connection->prepare(
            'SELECT field_key, MIN(sort_order) AS sort_order
             FROM series_custom_field
             WHERE field_scope = ?
             GROUP BY field_key
             ORDER BY sort_order, field_key'
        );
        $stmt->bind_param('s', $scope);
        $stmt->execute();
        $result = $stmt->get_result();
        $keys = [];
        while ($row = $result->fetch_assoc()) {
            $keys[] = (string) $row['field_key'];
        }
        $stmt->close();

        return $keys;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchProductsWithSeries(): array
    {
        $sql = 'SELECT p.id, p.series_id, p.sku, p.name, p.description, s.name AS series_name, s.display_order AS series_display_order
                FROM product p
                INNER JOIN category s ON s.id = p.series_id
                ORDER BY s.name, p.sku';
        $result = $this->connection->query($sql);
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = [
                'id' => (int) $row['id'],
                'series_id' => (int) $row['series_id'],
                'series_name' => (string) $row['series_name'],
                'series_display_order' => (int) $row['series_display_order'],
                'sku' => (string) $row['sku'],
                'name' => (string) $row['name'],
                'description' => $row['description'] !== null ? (string) $row['description'] : '',
            ];
        }
        $result->free();

        return $products;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function fetchProductCustomValues(): array
    {
        $sql = 'SELECT pcv.product_id, scf.field_key, pcv.value
                FROM product_custom_field_value pcv
                INNER JOIN series_custom_field scf ON scf.id = pcv.series_custom_field_id';
        $result = $this->connection->query($sql);
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $productId = (int) $row['product_id'];
            $fieldKey = (string) $row['field_key'];
            $map[$productId][$fieldKey] = (string) $row['value'];
        }
        $result->free();

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     */
    private function buildCategoryPath(array $categories, int $seriesId): string
    {
        if (!isset($categories[$seriesId])) {
            return '';
        }

        $path = [$categories[$seriesId]['name']];
        $currentId = $categories[$seriesId]['parent_id'] ?? null;
        while ($currentId !== null && isset($categories[$currentId])) {
            array_unshift($path, $categories[$currentId]['name']);
            $currentId = $categories[$currentId]['parent_id'];
        }

        return implode(' > ', $path);
    }

    private function assertTruncateNotRunning(): void
    {
        if ($this->isTruncateInProgress()) {
            throw new CatalogApiException(
                'TRUNCATE_IN_PROGRESS',
                'Catalog truncate in progress. Try again after it completes.',
                409
            );
        }
    }

    private function isTruncateInProgress(): bool
    {
        $stmt = $this->connection->prepare('SELECT IS_USED_LOCK(?) AS lock_owner');
        $lockKey = CATALOG_TRUNCATE_LOCK_KEY;
        $stmt->bind_param('s', $lockKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return ($row['lock_owner'] ?? null) !== null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readTruncateAudits(int $limit = 20): array
    {
        $logPath = CATALOG_TRUNCATE_AUDIT_LOG;
        if (!is_file($logPath)) {
            return [];
        }

        $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        if ($limit <= 0) {
            $limit = 20;
        }
        $lines = array_slice($lines, -$limit);
        $entries = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        usort(
            $entries,
            static function (array $a, array $b): int {
                $left = (string) ($a['timestamp'] ?? '');
                $right = (string) ($b['timestamp'] ?? '');
                return strcmp($right, $left);
            }
        );

        return $entries;
    }
}
/**
 * Orchestrates dependency wiring and request handling.
 */
final class CatalogTruncateService
{
    private bool $lockHeld = false;

    public function __construct(
        private mysqli $connection,
        private string $auditLogPath = CATALOG_TRUNCATE_AUDIT_LOG
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function truncateCatalog(array $payload): array
    {
        $reasonRaw = trim((string) ($payload['reason'] ?? ''));
        if ($reasonRaw === '') {
            throw new CatalogApiException(
                'VALIDATION_ERROR',
                'Reason is required to truncate the catalog.',
                400,
                ['reason' => 'Reason is required.']
            );
        }
        if (mb_strlen($reasonRaw) > CATALOG_TRUNCATE_REASON_MAX) {
            throw new CatalogApiException(
                'VALIDATION_ERROR',
                sprintf('Reason must be %d characters or fewer.', CATALOG_TRUNCATE_REASON_MAX),
                400,
                ['reason' => 'Reason too long.']
            );
        }

        $confirmToken = strtoupper(trim((string) ($payload['confirmToken'] ?? '')));
        if ($confirmToken !== CATALOG_TRUNCATE_CONFIRM_TOKEN) {
            throw new CatalogApiException(
                'TRUNCATE_CONFIRMATION_REQUIRED',
                'Confirmation text mismatch. Please type TRUNCATE to proceed.',
                400,
                ['confirmToken' => 'INVALID']
            );
        }

        $correlationId = trim((string) ($payload['correlationId'] ?? ''));
        if ($correlationId === '') {
            $correlationId = bin2hex(random_bytes(16));
        }

        $reason = $this->sanitizeReason($reasonRaw);
        $this->ensureAuditDirectory();
        $this->acquireLock();

        try {
            $counts = $this->collectDeletionCounts();
            $this->performTruncate();
            $timestamp = (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
            $auditEntry = [
                'event' => 'catalog_truncate',
                'id' => $correlationId,
                'reason' => $reason,
                'deleted' => $counts,
                'timestamp' => $timestamp,
            ];
            $this->appendAuditEntry($auditEntry);

            return [
                'auditId' => $correlationId,
                'deleted' => $counts,
                'timestamp' => $timestamp,
            ];
        } catch (CatalogApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new CatalogApiException(
                'TRUNCATE_ERROR',
                'Unable to truncate catalog.',
                500,
                ['error' => $exception->getMessage()]
            );
        } finally {
            $this->releaseLock();
        }
    }

    private function performTruncate(): void
    {
        $tables = [
            'product_custom_field_value',
            'series_custom_field_value',
            'product',
            'series_custom_field',
            'category',
            'seed_migration',
        ];

        $this->connection->begin_transaction();
        try {
            $this->setForeignKeyChecks(false);
            foreach ($tables as $table) {
                $this->connection->query(sprintf('TRUNCATE TABLE %s', $table));
            }
            $this->setForeignKeyChecks(true);
            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->setForeignKeyChecks(true);
            $this->connection->rollback();
            throw $exception;
        }
    }

    private function setForeignKeyChecks(bool $enabled): void
    {
        $value = $enabled ? 1 : 0;
        $this->connection->query(sprintf('SET FOREIGN_KEY_CHECKS = %d', $value));
    }

    private function countCategoriesByType(string $type): int
    {
        $stmt = $this->connection->prepare('SELECT COUNT(1) AS total FROM category WHERE type = ?');
        $stmt->bind_param('s', $type);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        return $count;
    }

    /**
     * @return array<string, int>
     */
    private function collectDeletionCounts(): array
    {
        return [
            'categories' => $this->countCategoriesByType('category'),
            'series' => $this->countCategoriesByType('series'),
            'products' => $this->countRows('product'),
            'fieldDefinitions' => $this->countRows('series_custom_field'),
            'productValues' => $this->countRows('product_custom_field_value'),
            'seriesValues' => $this->countRows('series_custom_field_value'),
        ];
    }

    private function countRows(string $table): int
    {
        $stmt = $this->connection->prepare(sprintf('SELECT COUNT(1) AS total FROM %s', $table));
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        return $count;
    }

    private function sanitizeReason(string $reason): string
    {
        $reason = preg_replace('/[\r\n]+/', ' ', $reason) ?? $reason;

        return trim($reason);
    }

    private function ensureAuditDirectory(): void
    {
        $directory = dirname($this->auditLogPath);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new CatalogApiException(
                    'TRUNCATE_ERROR',
                    'Unable to prepare audit directory.',
                    500
                );
            }
        }
    }

    private function appendAuditEntry(array $entry): void
    {
        $encoded = json_encode(
            $entry,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($encoded === false) {
            throw new CatalogApiException('TRUNCATE_ERROR', 'Failed to encode audit entry.', 500);
        }

        $result = @file_put_contents(
            $this->auditLogPath,
            $encoded . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        if ($result === false) {
            throw new CatalogApiException('TRUNCATE_ERROR', 'Failed to write truncate audit.', 500);
        }
    }

    private function acquireLock(): void
    {
        $stmt = $this->connection->prepare('SELECT GET_LOCK(?, 0) AS lock_obtained');
        $lockKey = CATALOG_TRUNCATE_LOCK_KEY;
        $stmt->bind_param('s', $lockKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ((int) ($row['lock_obtained'] ?? 0) !== 1) {
            throw new CatalogApiException(
                'TRUNCATE_IN_PROGRESS',
                'Another destructive operation is already running. Try again shortly.',
                409
            );
        }

        $this->lockHeld = true;
    }

    private function releaseLock(): void
    {
        if (!$this->lockHeld) {
            return;
        }
        $stmt = $this->connection->prepare('SELECT RELEASE_LOCK(?) AS released');
        $lockKey = CATALOG_TRUNCATE_LOCK_KEY;
        $stmt->bind_param('s', $lockKey);
        $stmt->execute();
        $stmt->close();
        $this->lockHeld = false;
    }
}

final class CatalogApplication
{
    private const RESPONSE_STREAMED = '__STREAMED__';

    private function __construct(
        private mysqli $connection,
        private HttpResponder $responder,
        private HttpRequestReader $requestReader,
        private Seeder $seeder,
        private HierarchyService $hierarchyService,
        private SeriesFieldService $seriesFieldService,
        private SeriesAttributeService $seriesAttributeService,
        private ProductService $productService,
        private CatalogCsvService $csvService,
        private CatalogTruncateService $truncateService,
        private PublicCatalogService $publicCatalogService,
        private SpecSearchService $specSearchService,
        private LatexTemplateService $latexTemplateService,
        private LatexBuildService $latexBuildService
    ) {
    }

    /**
     * Creates an application with default configuration.
     */
    public static function create(bool $performBootstrap = true): self
    {
        $factory = new DatabaseFactory(__DIR__ . '/db_config.php');
        $connection = $factory->createConnection();
        $responder = new HttpResponder();
        $requestReader = new HttpRequestReader();
        $seeder = new Seeder($connection);
        $hierarchyService = new HierarchyService($connection);
        $seriesFieldService = new SeriesFieldService($connection);
        $seriesAttributeService = new SeriesAttributeService($connection, $seriesFieldService);
        $productService = new ProductService($connection, $seriesFieldService);
        $csvService = new CatalogCsvService($connection, $hierarchyService, $seriesFieldService);
        $truncateService = new CatalogTruncateService($connection);
        $publicCatalogService = new PublicCatalogService(
            $hierarchyService,
            $seriesFieldService,
            $seriesAttributeService,
            $productService
        );
        $specSearchService = new SpecSearchService($connection);
        $latexTemplateService = new LatexTemplateService($connection);
        $pdflatexBinary = getenv(LATEX_PDFLATEX_ENV);
        $pdflatexPath = is_string($pdflatexBinary) && $pdflatexBinary !== ''
            ? $pdflatexBinary
            : LATEX_DEFAULT_PDFLATEX;
        $latexBuildService = new LatexBuildService($pdflatexPath, LATEX_PDF_STORAGE, LATEX_BUILD_WORKDIR);

        $app = new self(
            $connection,
            $responder,
            $requestReader,
            $seeder,
            $hierarchyService,
            $seriesFieldService,
            $seriesAttributeService,
            $productService,
            $csvService,
            $truncateService,
            $publicCatalogService,
            $specSearchService,
            $latexTemplateService,
            $latexBuildService
        );

        if ($performBootstrap) {
            $app->bootstrap();
        }

        return $app;
    }

    /**
     * Ensures schema and seed are in place.
     */
    public function bootstrap(): void
    {
        $this->seeder->ensureSchema();
        $this->seeder->seedInitialData();
    }

    /**
     * Handles the current HTTP request.
     */
    public function run(): void
    {
        $this->bootstrap();

        $action = isset($_GET['action']) ? (string) $_GET['action'] : '';
        if ($action === '') {
            $this->responder->sendError('ACTION_REQUIRED', 'The action query parameter is required.', 400);
            exit(0);
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        try {
            $data = $this->dispatch($action, $method);
            if ($data === self::RESPONSE_STREAMED) {
                return;
            }
            $payload = ['success' => true];
            if ($data !== null) {
                $payload['data'] = $data;
            }
            $this->responder->sendJson($payload);
        } catch (CatalogApiException $exception) {
            $details = $exception->getDetails();
            if (
                $exception->getErrorCode() === 'METHOD_NOT_ALLOWED'
                && isset($details['expected'])
                && !headers_sent()
            ) {
                header('Allow: ' . $details['expected']);
            }

            $this->responder->sendError(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getStatusCode(),
                $details
            );
        } catch (Throwable $exception) {
            error_log('Catalog API failure: ' . $exception->getMessage());
            $this->responder->sendError(
                'SERVER_ERROR',
                'Unexpected server error occurred.',
                500,
                ['error' => $exception->getMessage()]
            );
        }

        exit(0);
    }

    public function getConnection(): mysqli
    {
        return $this->connection;
    }

    public function getSeeder(): Seeder
    {
        return $this->seeder;
    }

    public function getHierarchyService(): HierarchyService
    {
        return $this->hierarchyService;
    }

    public function getSeriesFieldService(): SeriesFieldService
    {
        return $this->seriesFieldService;
    }

    public function getSeriesAttributeService(): SeriesAttributeService
    {
        return $this->seriesAttributeService;
    }

    public function getProductService(): ProductService
    {
        return $this->productService;
    }

    public function getCsvService(): CatalogCsvService
    {
        return $this->csvService;
    }

    public function getTruncateService(): CatalogTruncateService
    {
        return $this->truncateService;
    }

    public function getPublicCatalogService(): PublicCatalogService
    {
        return $this->publicCatalogService;
    }

    /**
     * Dispatches the action to the correct service.
     *
     * @return array<string, mixed>|null
     */
    private function dispatch(string $action, string $method): array|string|null
    {
        switch ($action) {
            case 'v1.ping':
                $this->requestReader->requireMethod('GET', $method);
                return ['message' => 'Catalog backend ready.'];
            case 'v1.listHierarchy':
                $this->requestReader->requireMethod('GET', $method);
                return $this->hierarchyService->listHierarchy();
            case 'v1.saveNode':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                return $this->hierarchyService->saveNode($payload);
            case 'v1.deleteNode':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                $nodeId = isset($payload['id']) ? (int) $payload['id'] : 0;
                if ($nodeId <= 0) {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Node ID is required.',
                        400,
                        ['id' => 'Node ID is required.']
                    );
                }
                $this->hierarchyService->deleteNode($nodeId);
                return null;
            case 'v1.listSeriesFields':
                $this->requestReader->requireMethod('GET', $method);
                $seriesId = isset($_GET['seriesId']) ? (int) $_GET['seriesId'] : 0;
                if ($seriesId <= 0) {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Series ID is required.',
                        400,
                        ['seriesId' => 'Series ID is required.']
                    );
                }
                $scope = isset($_GET['scope']) ? (string) $_GET['scope'] : SERIES_FIELD_SCOPE_PRODUCT;
                $fields = $this->seriesFieldService->listFields($seriesId, $scope);
                return $fields;
            case 'v1.publicCatalogSnapshot':
                $this->requestReader->requireMethod('GET', $method);
                return $this->publicCatalogService->buildSnapshot();
            case 'v1.specSearchRootCategories':
                $this->requestReader->requireMethod('GET', $method);
                return $this->specSearchService->listRootCategories();
            case 'v1.specSearchProductCategories':
                $this->requestReader->requireMethod('GET', $method);
                $rootId = isset($_GET['root_id']) ? (string) $_GET['root_id'] : '';
                if ($rootId === '') {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Root category is required.',
                        400,
                        ['root_id' => 'Root category is required.']
                    );
                }
                return $this->specSearchService->listProductCategoryGroups($rootId);
            case 'v1.specSearchFacets':
                $this->requestReader->requireMethod('POST', $method);
                $facetsPayload = $this->requestReader->readJsonBody();
                $facetsRootId = isset($facetsPayload['root_id']) ? (string) $facetsPayload['root_id'] : '';
                if ($facetsRootId === '') {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Root category is required.',
                        400,
                        ['root_id' => 'Root category is required.']
                    );
                }
                $facetCategoryIds = $this->normalizeSelectedCategoryIds($facetsPayload['category_ids'] ?? []);
                return $this->specSearchService->listFacets($facetsRootId, $facetCategoryIds);
            case 'v1.specSearchProducts':
                $this->requestReader->requireMethod('POST', $method);
                $productPayload = $this->requestReader->readJsonBody();
                $productRootId = isset($productPayload['root_id']) ? (string) $productPayload['root_id'] : '';
                if ($productRootId === '') {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Root category is required.',
                        400,
                        ['root_id' => 'Root category is required.']
                    );
                }
                $productCategoryIds = $this->normalizeSelectedCategoryIds($productPayload['category_ids'] ?? []);
                $filters = isset($productPayload['filters']) && is_array($productPayload['filters'])
                    ? $productPayload['filters']
                    : [];
                return $this->specSearchService->searchProducts($productRootId, $productCategoryIds, $filters);
            case 'v1.listLatexTemplates':
                $this->requestReader->requireMethod('GET', $method);
                return $this->latexTemplateService->listTemplates();
            case 'v1.getLatexTemplate':
                $this->requestReader->requireMethod('GET', $method);
                $templateId = $this->requireLatexTemplateId();
                return $this->latexTemplateService->getTemplate($templateId);
            case 'v1.createLatexTemplate':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                return $this->latexTemplateService->createTemplate($payload);
            case 'v1.updateLatexTemplate':
                $this->requestReader->requireMethod('PUT', $method);
                $templateId = $this->requireLatexTemplateId();
                $payload = $this->requestReader->readJsonBody();
                return $this->latexTemplateService->updateTemplate($templateId, $payload);
            case 'v1.deleteLatexTemplate':
                $this->requestReader->requireMethod('DELETE', $method);
                $templateId = $this->requireLatexTemplateId();
                $this->latexTemplateService->deleteTemplate($templateId);
                return ['deleted' => true];
            case 'v1.buildLatexTemplate':
                $this->requestReader->requireMethod('POST', $method);
                $templateId = $this->requireLatexTemplateId();
                $template = $this->latexTemplateService->getTemplate($templateId);
                $buildResult = $this->latexBuildService->build(
                    $templateId,
                    (string) ($template['latex'] ?? '')
                );
                $updated = $this->latexTemplateService->updatePdfPath(
                    $templateId,
                    (string) $buildResult['relativePath'],
                    $template['pdfPath'] ?? null
                );
                return [
                    'pdfPath' => $updated['pdfPath'],
                    'downloadUrl' => $updated['downloadUrl'],
                    'updatedAt' => $updated['updatedAt'],
                    'stdout' => $buildResult['stdout'],
                    'stderr' => $buildResult['stderr'],
                    'exitCode' => $buildResult['exitCode'],
                    'log' => $buildResult['log'],
                    'correlationId' => $buildResult['correlationId'],
                ];
            case 'v1.getSeriesAttributes':
                $this->requestReader->requireMethod('GET', $method);
                $seriesId = isset($_GET['seriesId']) ? (int) $_GET['seriesId'] : 0;
                if ($seriesId <= 0) {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Series ID is required.',
                        400,
                        ['seriesId' => 'Series ID is required.']
                    );
                }
                return $this->seriesAttributeService->getAttributes($seriesId);
            case 'v1.saveSeriesField':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                return $this->seriesFieldService->saveField($payload);
            case 'v1.saveSeriesAttributes':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                return $this->seriesAttributeService->saveAttributes($payload);
            case 'v1.deleteSeriesField':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                $fieldId = isset($payload['id']) ? (int) $payload['id'] : 0;
                if ($fieldId <= 0) {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Field ID is required.',
                        400,
                        ['id' => 'Field ID is required.']
                    );
                }
                $this->seriesFieldService->deleteField($fieldId);
                return null;
            case 'v1.listProducts':
                $this->requestReader->requireMethod('GET', $method);
                $seriesId = isset($_GET['seriesId']) ? (int) $_GET['seriesId'] : 0;
                if ($seriesId <= 0) {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Series ID is required.',
                        400,
                        ['seriesId' => 'Series ID is required.']
                    );
                }
                $result = $this->productService->listProducts($seriesId);
                return $result['products'];
            case 'v1.saveProduct':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                return $this->productService->saveProduct($payload);
            case 'v1.deleteProduct':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                $productId = isset($payload['id']) ? (int) $payload['id'] : 0;
                if ($productId <= 0) {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Product ID is required.',
                        400,
                        ['id' => 'Product ID is required.']
                    );
                }
                $this->productService->deleteProduct($productId);
                return null;
            case 'v1.truncateCatalog':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                return $this->truncateService->truncateCatalog($payload);
            case 'v1.listCsvHistory':
                $this->requestReader->requireMethod('GET', $method);
                return $this->csvService->listHistory();
            case 'v1.exportCsv':
                $this->requestReader->requireMethod('POST', $method);
                return $this->csvService->exportCatalog();
            case 'v1.importCsv':
                $this->requestReader->requireMethod('POST', $method);
                if (!isset($_FILES['file'])) {
                    throw new CatalogApiException('CSV_REQUIRED', 'CSV file upload is required.', 400);
                }
                return $this->csvService->importFromUploadedFile($_FILES['file']);
            case 'v1.restoreCsv':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                $fileId = isset($payload['id']) ? (string) $payload['id'] : '';
                if ($fileId === '') {
                    throw new CatalogApiException('CSV_NOT_FOUND', 'CSV id is required.', 404);
                }
                return $this->csvService->restoreCatalog($fileId);
            case 'v1.downloadCsv':
                $this->requestReader->requireMethod('GET', $method);
                $fileId = isset($_GET['id']) ? (string) $_GET['id'] : '';
                if ($fileId === '') {
                    throw new CatalogApiException('CSV_NOT_FOUND', 'CSV id is required.', 404);
                }
                $this->csvService->streamFile($fileId, $this->responder);
                return self::RESPONSE_STREAMED;
            case 'v1.deleteCsv':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                $fileId = isset($payload['id']) ? (string) $payload['id'] : '';
                if ($fileId === '') {
                    throw new CatalogApiException('CSV_NOT_FOUND', 'CSV id is required.', 404);
                }
                $this->csvService->deleteFile($fileId);
                return ['deleted' => true];
            default:
                throw new CatalogApiException('ACTION_NOT_FOUND', 'Unknown action requested.', 404);
        }
    }

    /**
     * Normalizes category identifiers from client payloads.
     *
     * @param mixed $raw
     *
     * @return array<int, string>
     */
    private function normalizeSelectedCategoryIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $normalized = [];
        foreach ($raw as $value) {
            $string = trim((string) $value);
            if ($string === '') {
                continue;
            }
            $normalized[$string] = true;
        }

        return array_keys($normalized);
    }

    private function requireLatexTemplateId(): int
    {
        $templateId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($templateId <= 0) {
            throw new CatalogApiException(
                'LATEX_VALIDATION_ERROR',
                'Template ID is required.',
                400,
                ['id' => 'Template ID is required.']
            );
        }

        return $templateId;
    }
}

if (!defined('CATALOG_NO_AUTO_BOOTSTRAP')) {
    CatalogApplication::create()->run();
}

