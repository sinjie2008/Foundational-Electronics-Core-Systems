<?php
declare(strict_types=1);

namespace CatalogSuite\Http;

/** Shares the original HTTP behavior between PHP entrypoints and a framework host. */
final class Transport
{
    /** @var array{status:int, headers:array<string, string>, file:?string}|null */
    private static ?array $response = null;
    private static ?string $requestBody = null;

    /** Read the current request body without depending on a framework's input stream. */
    public static function body(): string|false
    {
        return self::$requestBody ?? file_get_contents('php://input');
    }

    /** Framework responses can collect headers independently of native output. */
    public static function headersSent(): bool
    {
        return self::$response === null && headers_sent();
    }

    /** Emit a native header or collect it for the host response. */
    public static function header(string $header, bool $replace = true, int $status = 0): void
    {
        if (self::$response === null) {
            header($header, $replace, $status);
            return;
        }
        if ($status !== 0) {
            self::$response['status'] = $status;
        }
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            self::$response['headers'][trim($parts[0])] = trim($parts[1]);
        }
    }

    /** Read or set the HTTP status in either runtime. */
    public static function status(?int $status = null): int
    {
        if (self::$response !== null) {
            $previous = self::$response['status'];
            if ($status !== null) {
                self::$response['status'] = $status;
            }
            return $previous;
        }
        return ($status === null ? http_response_code() : http_response_code($status)) ?: 200;
    }

    /** Preserve native file streaming; let framework hosts stream the same file. */
    public static function file(string $path): int|false
    {
        if (self::$response === null) {
            return readfile($path);
        }
        $size = filesize($path);
        if ($size !== false) {
            self::$response['file'] = $path;
        }
        return $size;
    }

    /** Flush only native output; framework hosts own their response lifecycle. */
    public static function flush(): void
    {
        if (self::$response === null) {
            flush();
        }
    }

    /**
     * Run an existing controller with host request data and collect its response.
     * Request state is restored even when an action throws.
     *
     * @return array{status:int, headers:array<string,string>, file:?string, body:string}
     */
    public static function capture(
        string $body,
        array $query,
        array $post,
        array $files,
        array $server,
        callable $action
    ): array {
        $previousGlobals = [$_GET, $_POST, $_FILES, $_SERVER];
        $previousResponse = self::$response;
        $previousBody = self::$requestBody;
        $bufferLevel = ob_get_level();
        self::$response = ['status' => 200, 'headers' => [], 'file' => null];
        self::$requestBody = $body;
        [$_GET, $_POST, $_FILES, $_SERVER] = [$query, $post, $files, $server];
        ob_start();
        try {
            $action();
            return [...self::$response, 'body' => (string) ob_get_contents()];
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            [$_GET, $_POST, $_FILES, $_SERVER] = $previousGlobals;
            self::$response = $previousResponse;
            self::$requestBody = $previousBody;
        }
    }
}
