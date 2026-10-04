<?php
declare(strict_types=1);

/** Dependency-free integration assertions; all records live in a disposable database. */
final class TestSuite
{
    public int $passed = 0;
    public int $failed = 0;
    public int $skipped = 0;

    public function test(string $name, callable $test): void
    {
        try {
            $test();
            $this->passed++;
            echo "PASS {$name}\n";
        } catch (Throwable $error) {
            $this->failed++;
            $origin = $error->getTrace()[0] ?? [];
            $location = isset($origin['file'], $origin['line']) ? ' [' . basename($origin['file']) . ':' . $origin['line'] . ']' : '';
            echo "FAIL {$name}: {$error->getMessage()}{$location}\n";
        }
    }

    public function skip(string $name, string $reason): void
    {
        $this->skipped++;
        echo "SKIP {$name}: {$reason}\n";
    }

    public function same(mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException('Expected ' . json_encode($expected) . '; got ' . json_encode($actual));
        }
    }

    public function truth(bool $condition, string $message = 'Assertion failed'): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /** JSON objects are unordered; JSON arrays retain their documented display order. */
    public function equivalent(mixed $expected, mixed $actual): void
    {
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) {
                return $value;
            }
            $value = array_map($normalize, $value);
            if (!array_is_list($value)) {
                ksort($value);
            }
            return $value;
        };
        $this->same($normalize($expected), $normalize($actual));
    }

    public function throws(callable $action, int $status): void
    {
        try {
            $action();
        } catch (CatalogSuite\Http\CatalogApiException $error) {
            $this->same($status, $error->getStatusCode());
            return;
        }
        throw new RuntimeException('Expected API exception with status ' . $status);
    }
}
