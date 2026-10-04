<?php
declare(strict_types=1);

/**
 * Application configuration (paths, tokens, limits).
 * Adjust values per environment as needed.
 */
$projectRoot = dirname(__DIR__);
$storageRoot = getenv('CATALOG_STORAGE_ROOT') ?: $projectRoot . '/storage';

return [
    'project_root' => $projectRoot,
    'public_root' => $projectRoot . '/public',
    'base_url' => '',
    'bootstrap_schema' => true,
    'seed_name' => 'initial_catalog_v1',
    'seed_demo' => filter_var(getenv('CATALOG_SEED_DEMO') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'storage' => [
        'csv' => $storageRoot . '/csv',
        'media' => $storageRoot . '/media',
        'latex_build' => $storageRoot . '/latex-build',
        'latex_pdfs' => $storageRoot . '/latex-pdfs',
        'typst_build' => $projectRoot . '/storage/typst-build',
        'typst_pdfs' => $projectRoot . '/public/storage/typst-pdfs',
        'typst_assets' => $projectRoot . '/public/storage/typst-assets',
        // Preserve the separate file API's historical output locations.
        'api_latex_build' => dirname($projectRoot) . '/storage/latex-build',
        'api_latex_pdfs' => dirname($projectRoot) . '/public/storage/latex-pdfs',
        'media_url_prefix' => '/storage/media',
        'logs' => $projectRoot . '/storage/logs',
    ],
    'media' => [
        'max_bytes' => 10485760,
        'allowed_mime' => ['application/pdf', 'model/gltf-binary'],
        'allowed_extensions' => ['glb'],
        'download_url' => 'catalog.php',
    ],
    'truncate' => [
        'token' => 'TRUNCATE',
        'lock_key' => 'catalog_truncate_lock',
        'reason_max' => 256,
        'audit_log' => $storageRoot . '/csv/truncate_audit.jsonl',
    ],
    'latex' => [
        'pdflatex_env' => 'CATALOG_PDFLATEX_BIN',
        'default_binary' => 'pdflatex',
        'pdf_url_prefix' => '/storage/latex-pdfs',
    ],
    'typst' => [
        'binary' => is_file($projectRoot . '/bin/typst.exe') ? $projectRoot . '/bin/typst.exe' : 'typst',
        'pdf_url_prefix' => 'storage/typst-pdfs',
    ],
    'logging' => [
        'enabled' => true,
        'path' => __DIR__ . '/../storage/logs/app.log',
        'level' => 'info', // debug|info|warn|error
        'rotation' => [
            'max_bytes' => 1048576, // 1MB size-based rotation
        ],
        'timezone' => 'UTC',
    ],
];
