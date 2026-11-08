<?php
declare(strict_types=1);

namespace CatalogSuite\Support;

use CatalogSuite\Http\CatalogApiException;

/** Bounded renderer execution; inputs and outputs live in one operation's working directory. */
final class MediaCompilerProcess
{
    public static function run(array $arguments, string $directory, int $timeout = 60): array
    {
        $command = implode(' ', array_map('escapeshellarg', $arguments));
        $environment = array_merge(getenv(), ['openin_any' => 'p','openout_any' => 'p']);
        $process = proc_open($command, [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes, $directory, $environment);
        if (!is_resource($process)) {
            throw new CatalogApiException('COMPILER_UNAVAILABLE', 'The PDF renderer could not be started.', 503);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $started = microtime(true);
        $stdout = ''; $stderr = ''; $exit = -1;
        try {
            while (true) {
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                if (strlen($stdout) + strlen($stderr) > 1048576) {
                    throw new CatalogApiException('COMPILER_OUTPUT_LIMIT', 'The renderer produced too much diagnostic output.', 422);
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exit = (int) $status['exitcode'];
                    break;
                }
                if (microtime(true) - $started > $timeout) {
                    throw new CatalogApiException('COMPILER_TIMEOUT', 'The PDF renderer exceeded its time limit.', 422);
                }
                usleep(20000);
            }
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            fclose($pipes[1]); fclose($pipes[2]);
            $closed = proc_close($process);
            if ($exit < 0) {
                $exit = $closed;
            }
        }
        return ['exitCode' => $exit,'stdout' => $stdout,'stderr' => $stderr];
    }
}
