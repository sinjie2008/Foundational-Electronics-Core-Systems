<?php
declare(strict_types=1);

namespace CatalogSuite\Http;

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
        Transport::status($status);
        Transport::header('Content-Type: application/json; charset=utf-8');
        $cid = $correlationId ?? CorrelationId::generate();
        Transport::header('X-Correlation-ID: ' . $cid);

        echo json_encode([
            'success' => true,
            'data' => $data,
            'correlationId' => $cid,
        ]);
    }

    public static function error(string $code, string $message, int $status = 500, ?string $correlationId = null): void
    {
        Transport::status($status);
        Transport::header('Content-Type: application/json; charset=utf-8');
        $cid = $correlationId ?? CorrelationId::generate();
        Transport::header('X-Correlation-ID: ' . $cid);

        echo json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
                'correlationId' => $cid,
            ],
        ]);
    }
}
