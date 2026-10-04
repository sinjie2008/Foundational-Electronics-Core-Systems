<?php
declare(strict_types=1);

use CatalogSuite\Services\CatalogV1Service;
use CatalogSuite\Services\LatexService;
use CatalogSuite\Services\TypstService;
use CatalogSuite\Support\Config;

require_once __DIR__ . '/Fixtures.php';

return static function (TestSuite $t, mysqli $db): void {
    $f = Fixtures::catalog($db);
    $config = Config::get('app');
    $assertPdfText = static function (string $path, array $expected) use ($t): void {
        $binary = getenv('CATALOG_TEST_PDFTOTEXT_BIN') ?: '/usr/bin/pdftotext';
        if (!is_executable($binary)) {
            $t->skip('compiled PDF text ' . basename($path), 'Set CATALOG_TEST_PDFTOTEXT_BIN to a native pdftotext executable.');
            return;
        }
        $process = proc_open([$binary, $path, '-'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to extract compiled PDF text.');
        }
        fclose($pipes[0]);
        $text = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $t->same(0, proc_close($process));
        $t->same('', $error);
        $text = preg_replace('/\s+/u', ' ', $text);
        foreach ($expected as $value) {
            $t->truth(str_contains($text, $value), 'Compiled PDF is missing its stored runtime data: ' . $value);
        }
    };
    $typst = getenv('CATALOG_TEST_TYPST_BIN') ?: null;
    if ($typst === null && PHP_OS_FAMILY === 'Windows') {
        $typst = $config['typst']['binary'];
    }
    if (is_string($typst) && is_executable($typst)) {
        $config['typst']['binary'] = $typst;
        Config::set('app', $config);
        $t->test('real Typst compilation and registered PDF serving preserve dynamic catalog data', static function () use ($t, $db, $f, $assertPdfText): void {
            $result = (new TypstService($db))->compileTypst("= Generic runtime document\n#metadata.at(\"b_summary\")\n#for part in products [#part.sku ]", $f['seriesB']);
            $t->truth(is_file($result['path']));
            $t->same('%PDF-', substr(file_get_contents($result['path']), 0, 5));
            $assertPdfText($result['path'], ['Generic runtime document', '42', 'TEST-DISTINCT-PART']);
            $asset = Fixtures::insert($db, 'catalog_asset', ['owner_type' => 'series', 'owner_id' => $f['seriesB'], 'asset_key' => 'runtime-generated-document',
                'role' => 'runtime-pdf-role', 'storage_disk' => 'typst', 'file_path' => basename($result['path']), 'mime_type' => 'application/pdf', 'is_public' => 1, 'is_download' => 1]);
            $download = (new CatalogV1Service($db))->assetDownload($asset);
            $t->same(realpath($result['path']), $download['file']);
            $t->same([$asset], array_column((new CatalogV1Service($db))->series($f['pathB'])['documents'], 'id'));
        });
        $t->test('real Typst compilation remains available for draft series with internal metadata', static function () use ($t, $db, $f, $assertPdfText): void {
            $field = Fixtures::insert($db, 'series_custom_field', ['series_id' => $f['seriesA'], 'field_key' => 'runtime_internal_text', 'label' => 'Runtime internal text',
                'field_scope' => 'series_metadata', 'is_public_portal_hidden' => 1]);
            Fixtures::insert($db, 'series_custom_field_value', ['series_id' => $f['seriesA'], 'series_custom_field_id' => $field, 'value' => 'Internal generic text']);
            $db->query("UPDATE category SET is_published = 0 WHERE id = {$f['seriesA']}");
            try {
                $result = (new TypstService($db))->compileTypst("#metadata.at(\"runtime_internal_text\")", $f['seriesA']);
                $t->truth(is_file($result['path']));
                $assertPdfText($result['path'], ['Internal generic text']);
                $t->throws(static fn () => (new CatalogV1Service($db))->series($f['pathA']), 404);
            } finally {
                $db->query("UPDATE category SET is_published = 1 WHERE id = {$f['seriesA']}");
            }
        });
    } else {
        $t->skip('real Typst compilation', 'Set CATALOG_TEST_TYPST_BIN to an executable native Typst binary.');
    }
    $binary = getenv('CATALOG_TEST_PDFLATEX_BIN') ?: (PHP_OS_FAMILY === 'Linux' && is_executable('/usr/bin/pdflatex') ? '/usr/bin/pdflatex' : null);
    if (is_string($binary) && is_executable($binary)) {
        $t->test('real LaTeX compilation preserves internal metadata for draft catalog series', static function () use ($t, $db, $f, $binary, $assertPdfText): void {
            $config = Config::get('app');
            $config['latex']['default_binary'] = $binary;
            Config::set('app', $config);
            $field = Fixtures::insert($db, 'series_custom_field', ['series_id' => $f['seriesB'], 'field_key' => 'runtime_private_copy',
                'label' => 'Runtime private copy', 'field_scope' => 'series_metadata', 'is_public_portal_hidden' => 1]);
            Fixtures::insert($db, 'series_custom_field_value', ['series_id' => $f['seriesB'], 'series_custom_field_id' => $field, 'value' => 'Generic private copy']);
            $db->query("UPDATE category SET is_published = 0 WHERE id = {$f['seriesB']}");
            try {
                $result = (new LatexService($db))->compileLatex('\\documentclass{article}\\begin{document}runtime_private_copy\\end{document}', $f['seriesB']);
                $t->truth(is_file($result['path']));
                $t->same('%PDF-', substr(file_get_contents($result['path']), 0, 5));
                $assertPdfText($result['path'], ['Generic private copy']);
            } finally {
                $db->query("UPDATE category SET is_published = 1 WHERE id = {$f['seriesB']}");
            }
        });
    } else {
        $t->skip('real LaTeX compilation', 'Set CATALOG_TEST_PDFLATEX_BIN to an executable native pdflatex binary.');
    }
};
