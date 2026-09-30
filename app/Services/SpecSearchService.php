<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Repositories\SpecSearchRepository;
use CatalogSuite\Support\Config;
use CatalogSuite\Support\Db;
use mysqli;

/**
 * Shapes catalog data for root categories, facets, and product search.
 */
final class SpecSearchService
{
    private SpecSearchRepository $catalog;
    private string $mediaStorageDir;
    private string $baseUrl;
    private string $typstPdfUrlPrefix;

    /**
     * Create the service with injectable persistence.
     */
    public function __construct(?mysqli $db = null, ?SpecSearchRepository $catalog = null)
    {
        $config = Config::get('app');
        $projectRoot = rtrim(
            (string) ($config['project_root'] ?? dirname(__DIR__, 2)),
            "/\\"
        );
        $storageConfig = (array) ($config['storage'] ?? []);
        $typstConfig = (array) ($config['typst'] ?? []);
        $this->mediaStorageDir = rtrim(
            (string) ($storageConfig['media'] ?? $projectRoot . '/storage/media'),
            "/\\"
        );
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $this->typstPdfUrlPrefix = rtrim(
            (string) ($typstConfig['pdf_url_prefix'] ?? 'storage/typst-pdfs'),
            '/'
        );
        $this->catalog = $catalog ?? new SpecSearchRepository($db ?? Db::connection());
    }

    /**
     * Fetch root categories (parent NULL, type category).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRootCategories(): array
    {
        $rows = [];
        foreach ($this->catalog->getRootCategories() as $row) {
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
        $groups = $this->catalog->getChildCategories($rootId);

        foreach ($groups as $group) {
            $categories = [];
            foreach ($this->catalog->getChildCategories((int) $group['id']) as $cat) {
                $categories[] = [
                    'id' => (int) $cat['id'],
                    'name' => $cat['name'],
                ];
            }

            if ($categories !== []) {
                $data[] = [
                    'group' => $group['name'],
                    'categories' => $categories,
                ];
            }
        }

        if ($data === []) {
            $directCategories = [];
            foreach ($groups as $row) {
                $directCategories[] = [
                    'id' => (int) $row['id'],
                    'name' => $row['name'],
                ];
            }

            if ($directCategories !== []) {
                $data[] = [
                    'group' => 'Categories',
                    'categories' => $directCategories,
                ];
            }
        }

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
        $facets = [];
        $seriesFacet = [
            'key' => 'series',
            'label' => 'Series',
            'values' => [],
        ];

        foreach ($this->catalog->getSeriesNames($ids) as $row) {
            $seriesFacet['values'][] = $row['name'];
        }
        if (!empty($seriesFacet['values'])) {
            $facets[] = $seriesFacet;
        }

        $seriesIds = [];
        foreach ($this->catalog->getSeriesIds($ids) as $row) {
            $seriesIds[] = (int) $row['id'];
        }
        if ($seriesIds === []) {
            return $facets;
        }

        $fields = [];
        foreach ($this->catalog->getProductAttributeFields($seriesIds) as $row) {
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
            $values = [];
            foreach ($this->catalog->getFacetValues($field['ids']) as $row) {
                $values[] = $row['value'];
            }
            if ($values !== []) {
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
        $rawProducts = [];
        $productIds = [];
        $seriesIds = [];
        foreach ($this->catalog->searchProducts($catIds, $filters) as $row) {
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

        $seriesImages = $this->metadataBySeries('series_product_image', $seriesIds);
        $seriesSpecs = $this->metadataBySeries('series_product_spec', $seriesIds);
        $seriesTypstEnabled = [];
        foreach ($this->catalog->getTypstEnabledSeries($seriesIds) as $row) {
            $seriesTypstEnabled[(int) $row['id']] = ((int) ($row['typst_templating_enabled'] ?? 0)) === 1;
        }

        $seriesTypstPdf = [];
        foreach ($this->catalog->getTypstPdfPaths($seriesIds) as $row) {
            $seriesId = (int) $row['series_id'];
            if (!isset($seriesTypstPdf[$seriesId])) {
                $seriesTypstPdf[$seriesId] = $row['last_pdf_path'] ?? '';
            }
        }

        foreach ($this->catalog->getProductAttributeValues($productIds) as $row) {
            $productId = (int) $row['product_id'];
            if (isset($rawProducts[$productId])) {
                $rawProducts[$productId][$row['field_key']] = $row['value'];
            }
        }

        foreach ($rawProducts as &$product) {
            $seriesId = $product['seriesId'];
            $product['seriesImage'] = $this->formatImagePath($seriesImages[$seriesId] ?? '');

            $pdfUrl = '';
            if (($seriesTypstEnabled[$seriesId] ?? false) && !empty($seriesTypstPdf[$seriesId])) {
                $pdfUrl = $this->buildTypstPdfUrl($seriesTypstPdf[$seriesId]);
            } elseif (!empty($seriesSpecs[$seriesId])) {
                $pdfUrl = $this->formatMediaPath($seriesSpecs[$seriesId]);
            }
            $product['pdfDownload'] = $pdfUrl;
        }
        unset($product);

        return array_values($rawProducts);
    }

    /**
     * Map a metadata key to its stored value by series id.
     *
     * @param list<int> $seriesIds
     * @return array<int, string>
     */
    private function metadataBySeries(string $key, array $seriesIds): array
    {
        $values = [];
        foreach ($this->catalog->getSeriesMetadataValues($seriesIds, $key) as $row) {
            $values[(int) $row['series_id']] = $row['value'] ?? '';
        }

        return $values;
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

        if (preg_match('#^(https?:)?//#i', $path) === 1 || str_starts_with($path, 'data:')) {
            return $path;
        }

        $path = str_replace('\\', '/', $path);
        if (preg_match('#/public/(.+)$#i', $path, $matches) === 1) {
            $path = $matches[1];
        }

        $relative = ltrim($path, '/');
        $storageMediaPath = $this->mediaStorageDir . '/' . $relative;
        if (is_file($storageMediaPath)) {
            return $this->baseUrl === ''
                ? '../storage/media/' . $relative
                : $this->baseUrl . '/storage/media/' . $relative;
        }
        if (str_starts_with($relative, 'storage/')) {
            return $this->baseUrl === ''
                ? '../' . $relative
                : $this->baseUrl . '/' . $relative;
        }

        return $this->baseUrl === ''
            ? '../' . $relative
            : $this->baseUrl . '/storage/media/' . $relative;
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

        return $this->typstPdfUrlPrefix . '/' . $file;
    }
}
