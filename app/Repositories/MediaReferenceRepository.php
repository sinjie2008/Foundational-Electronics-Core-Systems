<?php
declare(strict_types=1);

namespace CatalogSuite\Repositories;

/** Reads authoritative legacy values, including references that bypass the new index. */
final class MediaReferenceRepository
{
    private array $tables;
    private array $columns = [];

    public function __construct(private MediaRepository $repository)
    {
        $tables = $repository->rows('SHOW TABLES');
        $this->tables = array_map(static fn (array $row): string => (string) array_values($row)[0], $tables);
        foreach ($repository->rows('SELECT TABLE_NAME AS tableName,COLUMN_NAME AS columnName FROM information_schema.columns WHERE table_schema=DATABASE()') as $column) {
            $this->columns[$column['tableName']][$column['columnName']] = true;
        }
    }

    public function has(string $table): bool
    {
        return in_array($table, $this->tables, true);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return isset($this->columns[$table][$column]);
    }

    public function all(): array
    {
        $r = $this->repository;
        $slots = [];
        $queries = [
            "SELECT 'series' AS owner_type,v.series_id AS owner_id,f.id AS field_id,'series_metadata' AS field_scope,v.value
             FROM series_custom_field_value v JOIN series_custom_field f ON f.id=v.series_custom_field_id WHERE f.field_type='file' AND COALESCE(v.value,'')<>''",
            "SELECT 'product' AS owner_type,v.product_id AS owner_id,f.id AS field_id,'product_attribute' AS field_scope,v.value
             FROM product_custom_field_value v JOIN series_custom_field f ON f.id=v.series_custom_field_id WHERE f.field_type='file' AND COALESCE(v.value,'')<>''",
            "SELECT 'field_default' AS owner_type,series_id AS owner_id,id AS field_id,field_scope,default_value AS value FROM series_custom_field WHERE field_type='file' AND COALESCE(default_value,'')<>''",
        ];
        foreach (['typst_variables' => 'typst_variable', 'latex_variables' => 'latex_variable'] as $table => $type) {
            if ($this->hasColumn($table, 'field_value')) {
                $queries[] = "SELECT '$type' AS owner_type,id AS owner_id,id AS field_id,'image' AS field_scope,field_value AS value FROM $table WHERE field_type IN ('image','file') AND COALESCE(field_value,'')<>''";
            }
        }
        foreach (['typst_templates' => ['typst_template','last_pdf_path'], 'latex_templates' => ['latex_template','last_pdf_path'], 'latex_template' => ['legacy_latex_template','pdf_path']] as $table => [$type,$column]) {
            if ($this->hasColumn($table, $column)) {
                $queries[] = "SELECT '$type' AS owner_type,id AS owner_id,0 AS field_id,'generated_pdf' AS field_scope,$column AS value FROM $table WHERE COALESCE($column,'')<>''";
            }
        }
        foreach ($queries as $query) {
            foreach ($r->rows($query) as $row) {
                $slots[] = [
                    'slot' => $row['owner_type'] . ':' . $row['owner_id'] . ':' . $row['field_scope'] . ':' . $row['field_id'],
                    'type' => $row['owner_type'], 'ownerId' => (int) $row['owner_id'],
                    'fieldId' => (int) $row['field_id'], 'scope' => $row['field_scope'], 'value' => $row['value'],
                ];
            }
        }
        // Static template dependencies are read without compiling or following remote addresses.
        foreach (['typst_templates' => 'typst_content', 'latex_templates' => 'latex_code', 'latex_template' => 'latex_source'] as $table => $column) {
            if (!$this->hasColumn($table, $column)) {
                continue;
            }
            foreach ($r->rows("SELECT id,$column AS source FROM $table") as $row) {
                preg_match_all('/(?:#image\s*\(\s*"([^"]+)"|\\\\includegraphics(?:\[[^\]]*\])?\{([^}]+)\})/', (string) $row['source'], $matches, PREG_SET_ORDER);
                foreach ($matches as $i => $match) {
                    $slots[] = ['slot' => 'dependency:' . $table . ':' . $row['id'] . ':' . $i, 'type' => 'template_dependency',
                        'ownerId' => (int) $row['id'], 'fieldId' => 0, 'scope' => $table, 'value' => $match[1] ?: ($match[2] ?? '')];
                }
            }
        }
        return $slots;
    }
}
