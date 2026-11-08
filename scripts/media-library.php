<?php
declare(strict_types=1);

// Explicit administrator operation; never included by HTTP endpoints or the asset build.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

use CatalogSuite\Repositories\MediaRepository;
use CatalogSuite\Services\MediaIndexService;
use CatalogSuite\Support\Db;
use CatalogSuite\Support\MediaSchema;
use CatalogSuite\Support\MediaStorageRegistry;

$command = $argv[1] ?? '';
if (!in_array($command, ['status','install','index'], true)) {
    fwrite(STDERR, "Usage: php scripts/media-library.php status|install|index [--confirm-additive-schema]\n");
    exit(2);
}
if ($command === 'install' && !in_array('--confirm-additive-schema', $argv, true)) {
    fwrite(STDERR, "Installation adds six media tables. Back up and verify CATALOG_DB_DATABASE, then pass --confirm-additive-schema.\n");
    exit(2);
}
try {
    $db = Db::connection();
    if ($command === 'install') {
        MediaSchema::install($db);
        echo "Media Library schema installed. Existing Catalog tables and files were not changed.\n";
    } elseif ($command === 'status') {
        echo MediaSchema::isInstalled($db) ? "Media schema is installed.\n" : "Media schema is not installed.\n";
    } else {
        MediaSchema::requireInstalled($db);
        $index = new MediaIndexService(new MediaRepository($db), new MediaStorageRegistry());
        $cursor = 0;
        $phase = 'disk';
        do {
            $result = $index->scan(null, $cursor, 100, $phase);
            echo MediaRepository::json($result) . "\n";
            $cursor = $result['nextCursor'];
            $phase = $result['nextPhase'];
        } while ($phase !== null);
    }
} catch (Throwable) {
    fwrite(STDERR, "Media operation failed. Verify database access, installation and the configured managed storage roots.\n");
    exit(1);
}
