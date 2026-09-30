<?php
declare(strict_types=1);

namespace CatalogSuite\Http;

use JsonException;
use CatalogSuite\Http\CatalogApiException;

final class HttpResponder
{
    private ?string $correlationId = null;

    public function setCorrelationId(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    private function emitCorrelationHeader(): void
    {
        if ($this->correlationId && !Transport::headersSent()) {
            Transport::header('X-Correlation-ID: ' . $this->correlationId);
        }
    }

    /**
     * Emits a JSON payload.
     *
     * @param array<string, mixed> $payload
     */
    public function sendJson(array $payload, int $statusCode = 200): void
    {
        $this->emitCorrelationHeader();
        if (!Transport::headersSent()) {
            Transport::status($statusCode);
            Transport::header('Content-Type: application/json; charset=utf-8');
        }

        try {
            if ($this->correlationId) {
                $payload['correlationId'] = $payload['correlationId'] ?? $this->correlationId;
            }
            echo json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            Transport::status(500);
            $fallback = [
                'success' => false,
                'errorCode' => 'ENCODING_ERROR',
                'message' => 'Unable to encode response payload.',
                'details' => ['error' => $exception->getMessage()],
            ];
            echo json_encode($fallback);
        }
    }

    /**
     * Emits an error response.
     *
     * @param array<string, mixed> $details
     */
    public function sendError(
        string $errorCode,
        string $message,
        int $statusCode = 400,
        array $details = []
    ): void {
        $this->sendJson(
            [
                'success' => false,
                'errorCode' => $errorCode,
                'message' => $message,
                'details' => $details,
            ],
            $statusCode
        );
    }

    public function sendFile(string $filePath, string $downloadName, string $contentType = 'text/csv'): void
    {
        if (!is_file($filePath)) {
            throw new CatalogApiException('CSV_NOT_FOUND', 'CSV file not found.', 404);
        }
        $this->emitCorrelationHeader();
        if (!Transport::headersSent()) {
            Transport::header('Content-Type: ' . $contentType);
            Transport::header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
            Transport::header('Cache-Control: no-store, no-cache, must-revalidate');
        }
        $result = Transport::file($filePath);
        if ($result === false) {
            throw new CatalogApiException('CSV_READ_ERROR', 'Unable to stream CSV file.', 500);
        }
        Transport::flush();
    }
}
