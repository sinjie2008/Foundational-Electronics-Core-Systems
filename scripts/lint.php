<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root,
    FilesystemIterator::SKIP_DOTS), static fn (SplFileInfo $entry): bool => !$entry->isDir()
        || !in_array($entry->getFilename(), ['.git', 'vendor', 'node_modules', 'storage'], true)));
$passed = 0;
$failed = 0;
foreach ($iterator as $entry) {
    if (!$entry->isFile() || $entry->getExtension() !== 'php') {
        continue;
    }
    $process = proc_open([PHP_BINARY, '-l', $entry->getPathname()], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start PHP syntax checker.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) === 0) {
        $passed++;
    } else {
        $failed++;
        fwrite(STDERR, $output);
    }
}
echo json_encode(['php_files_passed' => $passed, 'failed' => $failed]) . "\n";
exit($failed === 0 ? 0 : 1);
