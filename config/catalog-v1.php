<?php
declare(strict_types=1);

return [
    'page_base' => getenv('CATALOG_PUBLIC_PAGE_BASE') ?: '/products',
    'api_base' => '/api/v1/catalog',
    'max_per_page' => 100,
    'default_per_page' => 25,
];
