<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Repositories\ProductSeriesSummaryRepository;
use mysqli;

/** Aggregates public product engineering data for one or more catalog categories. */
final class ProductSeriesSummaryService
{
    private const FIELD_METADATA = [
        'acf.length' => ['Length', 'mm', 'numeric'],
        'acf.width' => ['Width', 'mm', 'numeric'],
        'acf.height' => ['Height', 'mm', 'numeric'],
        'acf.impedance' => ['Impedance', 'Ω', 'numeric'],
        'acf.dcr' => ['DCR', 'Ω', 'numeric'],
        'acf.dcr_typ' => ['Typical DCR', 'Ω', 'numeric'],
        'acf.irms' => ['Current', 'mA', 'numeric'],
        'acf.isat' => ['Saturation Current', 'mA', 'numeric'],
        'acf.inductance' => ['Inductance', 'µH', 'numeric'],
        'acf.power' => ['Power', 'W', 'numeric'],
        'acf.insertion_loss' => ['Insertion Loss', 'dB', 'numeric'],
        'acf.terminal_pins' => ['Terminal Pins', 'pins', 'numeric'],
        'acf.module_length' => ['Module Length', 'mm', 'numeric'],
        'acf.module_width' => ['Module Width', 'mm', 'numeric'],
        'acf.qi_standard' => ['Qi Standard', '', 'categorical'],
    ];

    private const DIMENSION_KEYS = ['acf.length', 'acf.width', 'acf.height'];
    // These manufacturer tables specify min(Irms, Isat) under Rated Current.
    private const VERIFIED_RATED_CURRENT_SERIES = [
        'general-products/emc-components/chip-array-ferrite-bead/a4k-series',
        'automotive-products/automotive-emc-components/automotive-chip-array-ferrite-bead/aq4k-series',
    ];

    private ProductSeriesSummaryRepository $repository;

    public function __construct(mysqli $db)
    {
        $this->repository = new ProductSeriesSummaryRepository($db);
    }

    /**
     * Summarize category groups while allowing stable pagination by Series ID.
     * The supplied groups must contain all sibling Series for their categories,
     * even when selectedIds limits which rows are returned.
     *
     * @param list<array{category: ?array, series: list<array>}> $groups
     * @param list<int> $selectedIds
     * @return list<array{category: ?array, columns: list<array>, series: list<array>}>
     */
    public function summarize(array $groups, array $selectedIds): array
    {
        $selected = array_fill_keys(array_map('intval', $selectedIds), true);
        $allSeries = [];
        foreach ($groups as $group) {
            foreach ($group['series'] ?? [] as $series) {
                $id = (int) ($series['id'] ?? 0);
                if ($id > 0) {
                    $allSeries[$id] = $series;
                }
            }
        }

        if ($allSeries === []) {
            return [];
        }

        $ids = array_keys($allSeries);
        $keys = array_keys(self::FIELD_METADATA);
        $fieldsBySeries = $this->repository->publicFields($ids, $keys);
        $counts = $this->repository->partCounts($ids);
        $valuesBySeries = $this->aggregateValues(
            $fieldsBySeries,
            $this->repository->productFieldValues($ids, $keys, $this->hasDefaults($fieldsBySeries))
        );
        $applicableKeysByGroup = [];
        foreach ($groups as $groupIndex => $group) {
            $applicable = [];
            foreach ($group['series'] ?? [] as $series) {
                $seriesId = (int) ($series['id'] ?? 0);
                foreach ($this->applicableFieldKeys($fieldsBySeries[$seriesId] ?? [], $valuesBySeries[$seriesId] ?? []) as $key) {
                    $applicable[$key] = true;
                }
            }
            $applicableKeysByGroup[$groupIndex] = $applicable;
        }

        $output = [];
        foreach ($groups as $groupIndex => $group) {
            $groupSeries = [];
            foreach ($group['series'] ?? [] as $series) {
                $id = (int) ($series['id'] ?? 0);
                if (!isset($selected[$id]) || !isset($allSeries[$id])) {
                    continue;
                }
                $groupSeries[] = $this->summarizeSeries(
                    $series,
                    $group['category'] ?? null,
                    $fieldsBySeries[$id] ?? [],
                    $valuesBySeries[$id] ?? [],
                    $applicableKeysByGroup[$groupIndex],
                    $counts[$id] ?? 0
                );
            }
            if ($groupSeries === []) {
                continue;
            }

            $output[] = [
                'category' => $group['category'] ?? null,
                'columns' => $this->columns(
                    $applicableKeysByGroup[$groupIndex],
                    $group['category'] ?? null,
                    $group['series'],
                    $fieldsBySeries
                ),
                'series' => $groupSeries,
            ];
        }

        return $output;
    }

    private function hasDefaults(array $fieldsBySeries): bool
    {
        foreach ($fieldsBySeries as $fields) {
            foreach ($fields as $field) {
                if (trim((string) $field['default_value']) !== '') {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<int, array<string, array<string, mixed>>> */
    private function aggregateValues(array $fieldsBySeries, iterable $rows): array
    {
        $numeric = [];
        $categorical = [];
        $labels = [];
        foreach ($fieldsBySeries as $seriesId => $fields) {
            foreach ($fields as $field) {
                $labels[$seriesId][(string) $field['field_key']] = $this->labelUnit((string) $field['label']);
            }
        }
        foreach ($rows as $row) {
            $seriesId = (int) $row['series_id'];
            $key = (string) $row['field_key'];
            if (!isset(self::FIELD_METADATA[$key])) {
                continue;
            }
            $raw = $row['effective_value'];
            if ($raw === null || trim((string) $raw) === '') {
                continue;
            }
            [, , $type] = self::FIELD_METADATA[$key];
            if ($type === 'categorical') {
                $categorical[$seriesId][$key][trim((string) $raw)] = true;
                continue;
            }
            $value = $this->parseMeasurement($key, (string) $raw, $labels[$seriesId][$key] ?? null);
            if ($value === null) {
                $numeric[$seriesId][$key]['invalid'] = ($numeric[$seriesId][$key]['invalid'] ?? 0) + 1;
                continue;
            }
            $aggregate = &$numeric[$seriesId][$key];
            $aggregate['min'] = isset($aggregate['min']) ? min($aggregate['min'], $value) : $value;
            $aggregate['max'] = isset($aggregate['max']) ? max($aggregate['max'], $value) : $value;
            $aggregate['validCount'] = ($aggregate['validCount'] ?? 0) + 1;
            unset($aggregate);
        }

        $result = [];
        foreach ($fieldsBySeries as $seriesId => $fields) {
            foreach ($fields as $field) {
                $key = (string) $field['field_key'];
                if (!isset(self::FIELD_METADATA[$key])) {
                    continue;
                }
                if (self::FIELD_METADATA[$key][2] === 'categorical') {
                    $values = array_map('strval', array_keys($categorical[$seriesId][$key] ?? []));
                    sort($values, SORT_NATURAL | SORT_FLAG_CASE);
                    $result[$seriesId][$key] = ['values' => $values];
                } else {
                    $result[$seriesId][$key] = $numeric[$seriesId][$key] ?? [];
                }
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function applicableFieldKeys(array $fields, array $values): array
    {
        $keys = [];
        foreach ($fields as $field) {
            $key = (string) $field['field_key'];
            if (!isset(self::FIELD_METADATA[$key])) {
                continue;
            }
            $aggregate = $values[$key] ?? [];
            $hasData = ($aggregate['validCount'] ?? 0) > 0
                || ($aggregate['values'] ?? []) !== []
                || ($aggregate['invalid'] ?? 0) > 0;
            $hasDefault = trim((string) ($field['default_value'] ?? '')) !== '';
            if ($hasData || $hasDefault || $field['is_required']) {
                $keys[$key] = true;
            }
        }
        return array_keys($keys);
    }

    private function summarizeSeries(
        array $series,
        ?array $category,
        array $fields,
        array $aggregates,
        array $groupApplicable,
        int $partCount
    ): array {
        $fieldMap = [];
        foreach ($fields as $field) {
            $fieldMap[(string) $field['field_key']] = $field;
        }

        $specifications = [];
        foreach (array_keys($groupApplicable) as $key) {
            $field = $fieldMap[$key] ?? null;
            if ($field === null || !isset(self::FIELD_METADATA[$key])) {
                $specifications[$key] = $this->emptySpecification($key, 'not_applicable', $series);
                continue;
            }
            $aggregate = $aggregates[$key] ?? [];
            $specifications[$key] = $this->formatSpecification($key, $field, $aggregate, $series);
        }

        $dimensions = [];
        foreach (self::DIMENSION_KEYS as $key) {
            $field = $fieldMap[$key] ?? null;
            $dimension = $field === null || !isset($groupApplicable[$key])
                ? ['min' => null, 'max' => null, 'unit' => 'mm', 'status' => 'not_applicable']
                : $this->rangeValue($aggregates[$key] ?? [], 'mm');
            $dimension['display'] = $dimension['status'] === 'available'
                ? $this->formatRange($dimension['min'], $dimension['max']) . ' mm'
                : '';
            $dimensions[substr($key, 4)] = $dimension;
        }
        $presentDimensions = array_filter($dimensions, static fn (array $value): bool => $value['status'] === 'available');
        $dimensionParts = [];
        foreach ($dimensions as $dimension) {
            $dimensionParts[] = $dimension['status'] === 'available'
                ? $this->formatRange($dimension['min'], $dimension['max'])
                : '—';
        }
        $dimensions['display'] = $presentDimensions === []
            ? ''
            : implode(' × ', $dimensionParts) . ' mm';
        $dimensions['status'] = $presentDimensions === []
            ? $this->dimensionMissingStatus($dimensions)
            : 'available';
        $dimensions['unit'] = 'mm';

        return [
            'series' => array_merge($series, ['parentPath' => $category['path'] ?? null]),
            'partCount' => $partCount,
            'dimensions' => $dimensions,
            'specifications' => (object) $specifications,
        ];
    }

    private function dimensionMissingStatus(array $dimensions): string
    {
        $statuses = array_column($dimensions, 'status');
        if (in_array('invalid', $statuses, true)) {
            return 'invalid';
        }
        return in_array('missing', $statuses, true) ? 'missing' : 'not_applicable';
    }

    private function formatSpecification(string $key, array $field, array $aggregate, array $series): array
    {
        [$defaultLabel, $unit, $type] = self::FIELD_METADATA[$key];
        $label = $this->specificationLabel($key, $defaultLabel, $series, (string) $field['label']);
        if ($type === 'categorical') {
            $values = $aggregate['values'] ?? [];
            $status = $values !== [] ? 'available' : (($aggregate['invalid'] ?? 0) > 0 ? 'invalid' : 'missing');
            return [
                'label' => $label ?: (string) $field['label'],
                'values' => $values,
                'display' => implode(', ', $values),
                'status' => $status,
                'invalidCount' => (int) ($aggregate['invalid'] ?? 0),
            ];
        }

        return array_merge(
            ['label' => $label ?: (string) $field['label']],
            $this->rangeValue($aggregate, $unit)
        );
    }

    private function rangeValue(array $aggregate, string $unit): array
    {
        if (($aggregate['validCount'] ?? 0) === 0) {
            return [
                'min' => null,
                'max' => null,
                'unit' => $unit,
                'display' => '',
                'status' => ($aggregate['invalid'] ?? 0) > 0 ? 'invalid' : 'missing',
                'invalidCount' => (int) ($aggregate['invalid'] ?? 0),
            ];
        }

        $minimum = $this->normalizedNumber((float) $aggregate['min']);
        $maximum = $this->normalizedNumber((float) $aggregate['max']);
        $display = $this->formatRange($minimum, $maximum);
        if ($unit !== '') {
            $display .= ' ' . $unit;
        }

        return [
            'min' => $minimum,
            'max' => $maximum,
            'unit' => $unit,
            'display' => $display,
            'status' => 'available',
            'invalidCount' => (int) ($aggregate['invalid'] ?? 0),
        ];
    }

    private function emptySpecification(string $key, string $status, array $series): array
    {
        [$label, $unit, $type] = self::FIELD_METADATA[$key];
        $label = $this->specificationLabel($key, $label, $series);
        if ($type === 'categorical') {
            return ['label' => $label, 'values' => [], 'display' => '', 'status' => $status, 'invalidCount' => 0];
        }
        return ['label' => $label, 'min' => null, 'max' => null, 'unit' => $unit, 'display' => '', 'status' => $status, 'invalidCount' => 0];
    }

    private function specificationLabel(string $key, string $label, array $series, string $definitionLabel = ''): string
    {
        if ($key !== 'acf.irms') {
            return $label;
        }
        if (in_array($series['path'], self::VERIFIED_RATED_CURRENT_SERIES, true)) {
            return 'Rated Current';
        }
        if (!str_starts_with(strtolower($definitionLabel), 'acf ')) {
            $rms = preg_match('/\b(?:Irms|RMS Current)\b/i', $definitionLabel) === 1;
            $dc = preg_match('/\b(?:IDC|DC Current)\b/i', $definitionLabel) === 1;
            if ($rms !== $dc) {
                return $rms ? 'RMS Current' : 'DC Current';
            }
            if (!$rms && preg_match('/\bRated Current\b/i', $definitionLabel) === 1) {
                return 'Rated Current';
            }
        }
        return $label;
    }

    /** @return list<array{key: string, label: string, type: string, unit?: string}> */
    private function columns(array $applicable, ?array $category, array $groupSeries, array $fieldsBySeries): array
    {
        $keys = array_keys($applicable);
        $technical = $this->preferredColumns($keys, $category, $groupSeries[0]['path']);
        $columns = [
            ['key' => 'media.series_product_image', 'label' => 'Product', 'type' => 'image'],
            ['key' => 'series', 'label' => 'Series', 'type' => 'series'],
            ['key' => 'dimensions', 'label' => 'Dimension', 'type' => 'dimension'],
        ];
        foreach ($technical as $key) {
            [$label, $unit] = self::FIELD_METADATA[$key];
            if ($key === 'acf.irms') {
                $labels = [];
                foreach ($groupSeries as $series) {
                    $definitions = array_column($fieldsBySeries[$series['id']] ?? [], 'label', 'field_key');
                    $labels[$this->specificationLabel($key, $label, $series, $definitions[$key] ?? '')] = true;
                }
                if (count($labels) === 1) {
                    $label = array_key_first($labels);
                }
            }
            if (in_array($key, ['acf.impedance', 'acf.inductance', 'acf.dcr', 'acf.irms', 'acf.isat', 'acf.power'], true)) {
                $label .= ' Range';
            }
            $type = self::FIELD_METADATA[$key][2] === 'categorical' ? 'category' : 'range';
            $column = ['key' => $key, 'label' => $label, 'type' => $type];
            $columns[] = $unit === '' ? $column : $column + ['unit' => $unit];
        }
        $columns[] = ['key' => 'media.series_product_spec', 'label' => 'Specification', 'type' => 'download'];
        return $columns;
    }

    /** @return list<string> */
    private function preferredColumns(array $keys, ?array $category, string $seriesPath): array
    {
        $path = strtolower((string) ($category['path'] ?? $seriesPath));
        $wireless = str_contains($path, 'wireless-power-transfer') || str_contains($path, 'wireless-power');
        $transformer = str_contains($path, 'transformer');
        if ($wireless) {
            $priority = ['acf.power', 'acf.dcr', 'acf.irms', 'acf.inductance', 'acf.isat'];
        } elseif ($transformer) {
            $priority = ['acf.inductance', 'acf.power', 'acf.insertion_loss', 'acf.terminal_pins'];
        } elseif (in_array('acf.impedance', $keys, true)) {
            $priority = ['acf.impedance', 'acf.dcr', 'acf.irms', 'acf.isat'];
        } else {
            $priority = ['acf.inductance', 'acf.dcr', 'acf.irms', 'acf.isat', 'acf.power'];
        }

        $selected = [];
        foreach ($priority as $key) {
            if (in_array($key, $keys, true) && !in_array($key, $selected, true)) {
                $selected[] = $key;
            }
            if (count($selected) === 3) {
                break;
            }
        }
        if ($transformer && in_array('acf.terminal_pins', $keys, true)
            && !in_array('acf.terminal_pins', $selected, true)) {
            if (count($selected) === 3) {
                $selected[2] = 'acf.terminal_pins';
            } else {
                $selected[] = 'acf.terminal_pins';
            }
        }
        if (count($selected) < 3 && in_array('acf.qi_standard', $keys, true)) {
            $selected[] = 'acf.qi_standard';
        }

        return $selected;
    }

    private function formatRange(float $minimum, float $maximum): string
    {
        $minimumText = $this->formatNumber($minimum);
        return $minimum === $maximum ? $minimumText : $minimumText . '–' . $this->formatNumber($maximum);
    }

    private function formatNumber(float $value): string
    {
        $normalized = $this->normalizedNumber($value);
        return sprintf('%.12g', $normalized);
    }

    private function normalizedNumber(float $value): float
    {
        return (float) sprintf('%.12g', $value);
    }

    private function parseMeasurement(string $key, string $raw, ?array $labelUnit): ?float
    {
        if ($labelUnit !== null && $labelUnit['status'] !== 'recognized') {
            return null;
        }
        $expectedDimension = $this->unitDimension($key);
        if ($labelUnit !== null && $labelUnit['dimension'] !== $expectedDimension) {
            return null;
        }

        if (preg_match('/^\s*([-+]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?)\s*(.*?)\s*$/u', $raw, $matches) !== 1) {
            return null;
        }
        $number = (float) $matches[1];
        if (!is_finite($number)) {
            return null;
        }
        $suffix = trim($matches[2]);
        if ($suffix !== '') {
            $unit = $this->normalizeUnit($suffix);
            if ($unit === null || $unit['dimension'] !== $expectedDimension) {
                return null;
            }
            $number *= $unit['factor'];
        } elseif ($labelUnit !== null) {
            $number *= $labelUnit['factor'];
        } elseif ($key === 'acf.dcr' || $key === 'acf.dcr_typ') {
            // DCR source data usually uses mΩ. The explicitly mixed dcr_typ field must identify its unit.
            if ($key === 'acf.dcr_typ') {
                return null;
            }
            $number /= 1000;
        }
        return is_finite($number) ? $number : null;
    }

    /** @return array{status: string, dimension?: string, factor?: float}|null */
    private function labelUnit(string $label): ?array
    {
        $explicit = null;
        if (preg_match('/(?:\(([^()]*)\)|\[([^\[\]]*)\])\s*$/u', trim($label), $matches) === 1) {
            $explicit = $matches[1] !== '' ? trim($matches[1]) : trim($matches[2]);
        } elseif (preg_match('/\s([^\s]+)\s*$/u', trim($label), $matches) === 1) {
            if ($this->normalizeUnit($matches[1]) !== null) {
                $explicit = $matches[1];
            }
        }
        if ($explicit === null) {
            return null;
        }
        $unit = $this->normalizeUnit($explicit);
        return $unit === null
            ? ['status' => 'unknown']
            : ['status' => 'recognized', 'dimension' => $unit['dimension'], 'factor' => $unit['factor']];
    }

    private function unitDimension(string $key): string
    {
        return match ($key) {
            'acf.length', 'acf.width', 'acf.height', 'acf.module_length', 'acf.module_width' => 'length',
            'acf.impedance', 'acf.dcr', 'acf.dcr_typ' => 'resistance',
            'acf.irms', 'acf.isat' => 'current',
            'acf.inductance' => 'inductance',
            'acf.power' => 'power',
            'acf.insertion_loss' => 'decibel',
            'acf.terminal_pins' => 'count',
            default => 'unknown',
        };
    }

    /** @return array{dimension: string, factor: float}|null */
    private function normalizeUnit(string $unit): ?array
    {
        $unit = trim(str_replace(['μ', 'µ', 'Ω'], ['u', 'u', 'Ω'], $unit));
        $unit = preg_replace('/\s+/u', '', $unit) ?? $unit;
        $symbols = [
            'mm' => ['length', 1.0], 'cm' => ['length', 10.0], 'um' => ['length', 0.001],
            'in' => ['length', 25.4],
            'Ω' => ['resistance', 1.0], 'mΩ' => ['resistance', 0.001], 'kΩ' => ['resistance', 1000.0],
            'A' => ['current', 1000.0], 'mA' => ['current', 1.0],
            'H' => ['inductance', 1000000.0], 'mH' => ['inductance', 1000.0],
            'uH' => ['inductance', 1.0], 'nH' => ['inductance', 0.001],
            'W' => ['power', 1.0], 'mW' => ['power', 0.001], 'kW' => ['power', 1000.0],
            'dB' => ['decibel', 1.0],
        ];
        if (isset($symbols[$unit])) {
            return ['dimension' => $symbols[$unit][0], 'factor' => $symbols[$unit][1]];
        }

        $words = [
            'ohm' => ['resistance', 1.0], 'ohms' => ['resistance', 1.0],
            'Ohm' => ['resistance', 1.0], 'Ohms' => ['resistance', 1.0],
            'milliohm' => ['resistance', 0.001], 'milliohms' => ['resistance', 0.001],
            'Milliohm' => ['resistance', 0.001], 'Milliohms' => ['resistance', 0.001],
            'kilohm' => ['resistance', 1000.0], 'kilohms' => ['resistance', 1000.0],
            'Kilohm' => ['resistance', 1000.0], 'Kilohms' => ['resistance', 1000.0],
            'millimeter' => ['length', 1.0], 'millimeters' => ['length', 1.0],
            'Millimeter' => ['length', 1.0], 'Millimeters' => ['length', 1.0],
            'centimeter' => ['length', 10.0], 'centimeters' => ['length', 10.0],
            'Centimeter' => ['length', 10.0], 'Centimeters' => ['length', 10.0],
            'micrometer' => ['length', 0.001], 'micrometers' => ['length', 0.001],
            'Micrometer' => ['length', 0.001], 'Micrometers' => ['length', 0.001],
            'inch' => ['length', 25.4], 'inches' => ['length', 25.4],
            'Inch' => ['length', 25.4], 'Inches' => ['length', 25.4],
            'amp' => ['current', 1000.0], 'amps' => ['current', 1000.0],
            'Amp' => ['current', 1000.0], 'Amps' => ['current', 1000.0],
            'milliamp' => ['current', 1.0], 'milliamps' => ['current', 1.0],
            'Milliamp' => ['current', 1.0], 'Milliamps' => ['current', 1.0],
            'pin' => ['count', 1.0], 'pins' => ['count', 1.0], 'count' => ['count', 1.0],
            'Pin' => ['count', 1.0], 'Pins' => ['count', 1.0], 'Count' => ['count', 1.0],
        ];
        if (!isset($words[$unit])) {
            return null;
        }
        return ['dimension' => $words[$unit][0], 'factor' => $words[$unit][1]];
    }
}
