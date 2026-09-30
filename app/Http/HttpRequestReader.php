<?php
declare(strict_types=1);

namespace CatalogSuite\Http;

use JsonException;
use CatalogSuite\Http\CatalogApiException;

final class HttpRequestReader
{
    /**
     * Ensures the request method matches expectations.
     *
     * @throws CatalogApiException When the HTTP verb is unexpected.
     */
    public function requireMethod(string $expected, string $actual): void
    {
        if (strcasecmp($expected, $actual) !== 0) {
            throw new CatalogApiException(
                'METHOD_NOT_ALLOWED',
                sprintf(
                    'Expected HTTP %s but received %s.',
                    strtoupper($expected),
                    strtoupper($actual)
                ),
                405,
                [
                    'expected' => strtoupper($expected),
                    'actual' => strtoupper($actual),
                ]
            );
        }
    }

    /**
     * Reads and decodes the JSON request body.
     *
     * @return array<string, mixed>
     *
     * @throws CatalogApiException When the payload is invalid JSON.
     */
    public function readJsonBody(): array
    {
        $raw = Transport::body();
        if ($raw === false || $raw === '') {
            return [];
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CatalogApiException(
                'INVALID_JSON',
                'Unable to parse JSON payload.',
                400,
                ['error' => $exception->getMessage()]
            );
        }

        if (!is_array($data)) {
            throw new CatalogApiException(
                'INVALID_JSON',
                'JSON payload must decode to an object.',
                400
            );
        }

        return $data;
    }

    /**
     * Reads JSON from either multipart form (metadata field) or raw body.
     *
     * @return array<string, mixed>
     *
     * @throws CatalogApiException When metadata is invalid JSON.
     */
    public function readJsonBodyOrMultipart(string $metadataField = 'metadata'): array
    {
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($contentType, 'multipart/form-data') !== false) {
            $raw = isset($_POST[$metadataField]) ? (string) $_POST[$metadataField] : '';
            if ($raw === '') {
                return [];
            }
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new CatalogApiException(
                    'INVALID_JSON',
                    'Unable to parse metadata JSON.',
                    400,
                    ['error' => $exception->getMessage()]
                );
            }
            if (!is_array($decoded)) {
                throw new CatalogApiException('INVALID_JSON', 'Unable to parse metadata JSON.', 400);
            }

            return $decoded;
        }

        return $this->readJsonBody();
    }

    /**
     * Normalizes uploaded file arrays keyed by field key.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getUploadedFiles(string $field = 'files'): array
    {
        if (!isset($_FILES[$field])) {
            return [];
        }

        $files = $_FILES[$field];
        $normalized = [];

        if (is_array($files['name'])) {
            foreach ($files['name'] as $key => $name) {
                $error = $files['error'][$key] ?? null;
                if ($error === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $normalized[$key] = [
                    'name' => $name,
                    'type' => $files['type'][$key] ?? null,
                    'tmp_name' => $files['tmp_name'][$key] ?? null,
                    'error' => $error,
                    'size' => $files['size'][$key] ?? null,
                ];
            }
        } else {
            if (($files['error'] ?? null) === UPLOAD_ERR_NO_FILE) {
                return [];
            }
            $normalized['file'] = [
                'name' => $files['name'],
                'type' => $files['type'],
                'tmp_name' => $files['tmp_name'],
                'error' => $files['error'],
                'size' => $files['size'],
            ];
        }

        return $normalized;
    }
}
