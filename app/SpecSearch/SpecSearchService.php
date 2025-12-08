<?php
declare(strict_types=1);

namespace App\SpecSearch;

use App\Support\Db;
use mysqli;

/**
 * Spec-search oriented queries (root categories, facets, product listing).
 */
final class SpecSearchService
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Db::connection();
    }

    /**
     * Fetch root categories (parent NULL, type category).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRootCategories(): array
    {
        $result = $this->db->query(
            "SELECT id, name FROM category WHERE parent_id IS NULL AND type = 'category' ORDER BY display_order ASC, name ASC"
        );

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
            ];
        }

        return $rows;
    }

    /**
     * Fetch product categories grouped under a root category.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProductCategories(int $rootId): array
    {
        $data = [];

        $stmtGroups = $this->db->prepare(
            "SELECT id, name FROM category WHERE parent_id = ? AND type = 'category' ORDER BY display_order ASC"
        );
        $stmtGroups->bind_param('i', $rootId);
        $stmtGroups->execute();
        $groupsResult = $stmtGroups->get_result();

        $stmtCategories = $this->db->prepare(
            "SELECT id, name FROM category WHERE parent_id = ? AND type = 'category' ORDER BY display_order ASC"
        );

        while ($group = $groupsResult->fetch_assoc()) {
            $groupId = (int) $group['id'];
            $groupName = $group['name'];

            $stmtCategories->bind_param('i', $groupId);
            $stmtCategories->execute();
            $catsResult = $stmtCategories->get_result();

            $categories = [];
            while ($cat = $catsResult->fetch_assoc()) {
                $categories[] = [
                    'id' => (int) $cat['id'],
                    'name' => $cat['name'],
                ];
            }

            if (!empty($categories)) {
                $data[] = [
                    'group' => $groupName,
                    'categories' => $categories,
                ];
            }
        }

        if (empty($data)) {
            $stmtGroups->execute();
            $fallbackGroups = $stmtGroups->get_result();
            $directCategories = [];
            while ($row = $fallbackGroups->fetch_assoc()) {
                $directCategories[] = [
                    'id' => (int) $row['id'],
                    'name' => $row['name'],
                ];
            }

            if (!empty($directCategories)) {
                $data[] = [
                    'group' => 'Categories',
                    'categories' => $directCategories,
                ];
            }
        }

        $stmtCategories->close();
        $stmtGroups->close();

        return $data;
    }

    /**
     * Build facet definitions for given category IDs (series + custom fields).
     *
     * @param int[] $categoryIds
     * @return array<int, array<string, mixed>>
     */
    public function getFacets(array $categoryIds): array
    {
        if (empty($categoryIds)) {
            return [];
        }

        $ids = array_map('intval', $categoryIds);
        $idsStr = implode(',', $ids);

        $facets = [];
        $seriesFacet = [
            'key' => 'series',
            'label' => 'Series',
            'values' => [],
        ];

        $resultSeries = $this->db->query(
            "SELECT name FROM category WHERE parent_id IN ({$idsStr}) AND type = 'series' ORDER BY name ASC"
        );
        while ($row = $resultSeries->fetch_assoc()) {
            $seriesFacet['values'][] = $row['name'];
        }

        if (!empty($seriesFacet['values'])) {
            $facets[] = $seriesFacet;
        }

        $resultSeriesIds = $this->db->query(
            "SELECT id FROM category WHERE parent_id IN ({$idsStr}) AND type = 'series'"
        );
        $seriesIds = [];
        while ($row = $resultSeriesIds->fetch_assoc()) {
            $seriesIds[] = (int) $row['id'];
        }

        if (empty($seriesIds)) {
            return $facets;
        }

        $seriesIdsStr = implode(',', $seriesIds);
        $resultFields = $this->db->query(
            "SELECT field_key, label, id, sort_order FROM series_custom_field 
                  WHERE series_id IN ({$seriesIdsStr}) AND field_scope = 'product_attribute' 
                  ORDER BY sort_order ASC"
        );

        /** @var array<string, array{key:string,label:string,ids:int[]}> $fields */
        $fields = [];
        while ($row = $resultFields->fetch_assoc()) {
            $fieldKey = $row['field_key'];
            if (!isset($fields[$fieldKey])) {
                $fields[$fieldKey] = [
                    'key' => $fieldKey,
                    'label' => $row['label'],
                    'ids' => [],
                ];
            }
            $fields[$fieldKey]['ids'][] = (int) $row['id'];
        }

        foreach ($fields as $key => $field) {
            $fieldIdsStr = implode(',', $field['ids']);
            $resultValues = $this->db->query(
                "SELECT DISTINCT value FROM product_custom_field_value 
                      WHERE series_custom_field_id IN ({$fieldIdsStr}) 
                      AND value IS NOT NULL AND value != '' 
                      ORDER BY value ASC"
            );

            $values = [];
            while ($row = $resultValues->fetch_assoc()) {
                $values[] = $row['value'];
            }

            if (!empty($values)) {
                $facets[] = [
                    'key' => $key,
                    'label' => $field['label'],
                    'values' => $values,
                ];
            }
        }

        return $facets;
    }

    /**
     * Return products and dynamic attributes for selected categories and filters.
     *
     * @param int[] $categoryIds
     * @param array<string, array<int, string>> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getProducts(array $categoryIds, array $filters): array
    {
        if (empty($categoryIds)) {
            return [];
        }

        $catIds = array_map('intval', $categoryIds);
        $catIdsStr = implode(',', $catIds);

        $sql = "SELECT p.id, p.sku, p.name, s.name as series_name, s.id as series_id, c.name as category_name, c.id as category_id
                FROM product p
                JOIN category s ON p.series_id = s.id
                JOIN category c ON s.parent_id = c.id
                WHERE s.parent_id IN ({$catIdsStr}) AND s.type = 'series'";

        if (isset($filters['series']) && !empty($filters['series'])) {
            $seriesNames = array_map(function ($val): string {
                return "'" . $this->db->real_escape_string($val) . "'";
            }, $filters['series']);
            $seriesNamesStr = implode(',', $seriesNames);
            $sql .= " AND s.name IN ({$seriesNamesStr})";
        }

        foreach ($filters as $key => $values) {
            if ($key === 'series' || empty($values)) {
                continue;
            }

            $escapedValues = array_map(function ($val): string {
                return "'" . $this->db->real_escape_string($val) . "'";
            }, $values);
            $valuesStr = implode(',', $escapedValues);
            $fieldKeyEscaped = $this->db->real_escape_string($key);

            $sql .= " AND EXISTS (
                SELECT 1 FROM product_custom_field_value pcfv
                JOIN series_custom_field scf ON pcfv.series_custom_field_id = scf.id
                WHERE pcfv.product_id = p.id
                AND scf.field_key = '{$fieldKeyEscaped}'
                AND pcfv.value IN ({$valuesStr})
            )";
        }

        $sql .= ' LIMIT 500';

        $result = $this->db->query($sql);

        $rawProducts = [];
        $productIds = [];
        $seriesIds = [];
        while ($row = $result->fetch_assoc()) {
            $seriesId = (int) $row['series_id'];
            $rawProducts[$row['id']] = [
                'id' => (int) $row['id'],
                'sku' => $row['sku'],
                'name' => $row['name'],
                'series' => $row['series_name'],
                'seriesId' => $seriesId,
                'category' => $row['category_name'],
                'categoryId' => (int) $row['category_id'],
            ];
            if (!in_array($seriesId, $seriesIds, true)) {
                $seriesIds[] = $seriesId;
            }
            $productIds[] = (int) $row['id'];
        }

        $seriesImages = [];
        $seriesSpecs = [];
        $seriesTypstPdf = [];
        $seriesTypstEnabled = [];
        if (!empty($seriesIds)) {
            $seriesIdsStr = implode(',', $seriesIds);

            // Series metadata: product image
            $resultSeriesImages = $this->db->query(
                "SELECT scf.series_id, scfv.value 
                     FROM series_custom_field scf
                     LEFT JOIN series_custom_field_value scfv ON scf.id = scfv.series_custom_field_id AND scfv.series_id = scf.series_id
                     WHERE scf.series_id IN ({$seriesIdsStr})
                     AND scf.field_scope = 'series_metadata'
                     AND scf.field_key = 'series_product_image'"
            );
            while ($row = $resultSeriesImages->fetch_assoc()) {
                $seriesImages[(int) $row['series_id']] = $row['value'] ?? '';
            }

            // Series metadata: product spec PDF
            $resultSeriesSpecs = $this->db->query(
                "SELECT scf.series_id, scfv.value 
                     FROM series_custom_field scf
                     LEFT JOIN series_custom_field_value scfv ON scf.id = scfv.series_custom_field_id AND scfv.series_id = scf.series_id
                     WHERE scf.series_id IN ({$seriesIdsStr})
                     AND scf.field_scope = 'series_metadata'
                     AND scf.field_key = 'series_product_spec'"
            );
            while ($row = $resultSeriesSpecs->fetch_assoc()) {
                $seriesSpecs[(int) $row['series_id']] = $row['value'] ?? '';
            }

            // Typst templating flag
            $resultTypstFlag = $this->db->query(
                "SELECT id, typst_templating_enabled FROM category WHERE id IN ({$seriesIdsStr})"
            );
            while ($row = $resultTypstFlag->fetch_assoc()) {
                $seriesTypstEnabled[(int) $row['id']] = ((int) ($row['typst_templating_enabled'] ?? 0)) === 1;
            }

            // Latest Typst PDF per series (if any)
            $resultTypstPdf = $this->db->query(
                "SELECT series_id, last_pdf_path, updated_at, id 
                     FROM typst_templates 
                     WHERE series_id IN ({$seriesIdsStr}) 
                       AND last_pdf_path IS NOT NULL 
                     ORDER BY updated_at DESC, id DESC"
            );
            while ($row = $resultTypstPdf->fetch_assoc()) {
                $sid = (int) $row['series_id'];
                if (!isset($seriesTypstPdf[$sid])) {
                    $seriesTypstPdf[$sid] = $row['last_pdf_path'] ?? '';
                }
            }
        }

        if (!empty($productIds)) {
            $productIdsStr = implode(',', $productIds);
            $resultAttrs = $this->db->query(
                "SELECT pcfv.product_id, scf.field_key, pcfv.value 
                         FROM product_custom_field_value pcfv
                         JOIN series_custom_field scf ON pcfv.series_custom_field_id = scf.id
                         WHERE pcfv.product_id IN ({$productIdsStr})
                         AND scf.field_scope = 'product_attribute'"
            );
            while ($row = $resultAttrs->fetch_assoc()) {
                $pid = (int) $row['product_id'];
                if (isset($rawProducts[$pid])) {
                    $rawProducts[$pid][$row['field_key']] = $row['value'];
                }
            }
        }

        foreach ($rawProducts as &$product) {
            $sid = $product['seriesId'];
            $product['seriesImage'] = $this->formatImagePath($seriesImages[$sid] ?? '');

            $pdfUrl = '';
            if (($seriesTypstEnabled[$sid] ?? false) && !empty($seriesTypstPdf[$sid])) {
                $pdfUrl = $this->buildTypstPdfUrl($seriesTypstPdf[$sid]);
            } elseif (!empty($seriesSpecs[$sid])) {
                $pdfUrl = $this->formatMediaPath($seriesSpecs[$sid]);
            }
            $product['pdfDownload'] = $pdfUrl;
        }
        unset($product);

        return array_values($rawProducts);
    }

    /**
     * Normalize stored image paths to a web-safe URL so the UI can render without broken links.
     */
    private function formatImagePath(?string $value): string
    {
        return $this->formatMediaPath($value);
    }

    /**
     * Normalize stored media paths (images/spec PDFs) to web-safe relative URLs.
     */
    private function formatMediaPath(?string $value): string
    {
        $path = trim((string) $value);
        if ($path === '') {
            return '';
        }

        // Allow already web-ready values.
        if (preg_match('#^(https?:)?//#i', $path) === 1 || str_starts_with($path, 'data:')) {
            return $path;
        }

        // Normalize separators and strip any local public/ prefix.
        $path = str_replace('\\', '/', $path);
        if (preg_match('#/public/(.+)$#i', $path, $matches) === 1) {
            $path = $matches[1];
        }

        $relative = ltrim($path, '/');
        // Prefer storage/media prefix when file exists there.
        $storageMediaPath = dirname(__DIR__, 2) . '/storage/media/' . $relative;
        if (is_file($storageMediaPath)) {
            return '../storage/media/' . $relative;
        }

        // Otherwise, fall back to storage prefix if already present or simple absolute path under web root.
        if (str_starts_with($relative, 'storage/')) {
            return '../' . $relative;
        }

        return '../' . $relative;
    }

    /**
     * Build a Typst PDF URL from a stored path.
     */
    private function buildTypstPdfUrl(?string $path): string
    {
        if (empty($path)) {
            return '';
        }
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }
        $file = basename($path);
        if ($file === '') {
            return '';
        }
        // Served from public/storage/typst-pdfs; relative to spec-search page under /public.
        return 'storage/typst-pdfs/' . $file;
    }
}
