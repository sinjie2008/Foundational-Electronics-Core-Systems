<?php
declare(strict_types=1);

/**
 * Application configuration (paths, tokens, limits).
 * Adjust values per environment as needed.
 */
return [
    'storage' => [
        'csv' => __DIR__ . '/../storage/csv',
        'latex_build' => __DIR__ . '/../storage/latex-build',
        'latex_pdfs' => __DIR__ . '/../storage/latex-pdfs',
        'logs' => __DIR__ . '/../storage/logs',
    ],
    'truncate' => [
        'token' => 'TRUNCATE',
        'lock_key' => 'catalog_truncate_lock',
        'reason_max' => 256,
    ],
    'latex' => [
        'pdflatex_env' => 'CATALOG_PDFLATEX_BIN',
        'default_binary' => 'pdflatex',
        'pdf_url_prefix' => '/storage/latex-pdfs',
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
