<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Helper for consistent JSON responses.
 */
final class Response
{
    /**
     * Send a success response envelope.
     *
     * @param array<string, mixed>|list<mixed>|null $data
     */
    public static function success($data, int $status = 200, ?string $correlationId = null): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'success' => true,
            'data' => $data,
            'correlationId' => $correlationId ?? CorrelationId::generate(),
        ]);
    }

    public static function error(string $code, string $message, int $status = 500, ?string $correlationId = null): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
                'correlationId' => $correlationId ?? CorrelationId::generate(),
            ],
        ]);
    }
}
