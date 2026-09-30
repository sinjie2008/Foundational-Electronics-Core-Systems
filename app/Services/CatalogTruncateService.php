<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Support\Config;

use mysqli;
use CatalogSuite\Repositories\CatalogTruncateRepository;
use Throwable;
use CatalogSuite\Http\CatalogApiException;

final class CatalogTruncateService
{
    private bool $lockHeld = false;
    private string $auditLogPath;
    private CatalogTruncateRepository $repository;

    public function __construct(
        private mysqli $connection,
        ?string $auditLogPath = null
    ) {
        $this->auditLogPath = $auditLogPath ?? Config::get('app')['truncate']['audit_log'];
        $this->repository = new CatalogTruncateRepository($connection);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function truncateCatalog(array $payload): array
    {
        $reasonRaw = trim((string) ($payload['reason'] ?? ''));
        if ($reasonRaw === '') {
            throw new CatalogApiException(
                'VALIDATION_ERROR',
                'Reason is required to truncate the catalog.',
                400,
                ['reason' => 'Reason is required.']
            );
        }
        if (mb_strlen($reasonRaw) > (int) Config::get('app')['truncate']['reason_max']) {
            throw new CatalogApiException(
                'VALIDATION_ERROR',
                sprintf('Reason must be %d characters or fewer.', (int) Config::get('app')['truncate']['reason_max']),
                400,
                ['reason' => 'Reason too long.']
            );
        }

        $confirmToken = strtoupper(trim((string) ($payload['confirmToken'] ?? '')));
        if ($confirmToken !== Config::get('app')['truncate']['token']) {
            throw new CatalogApiException(
                'TRUNCATE_CONFIRMATION_REQUIRED',
                'Confirmation text mismatch. Please type TRUNCATE to proceed.',
                400,
                ['confirmToken' => 'INVALID']
            );
        }

        $correlationId = trim((string) ($payload['correlationId'] ?? ''));
        if ($correlationId === '') {
            $correlationId = bin2hex(random_bytes(16));
        }

        $reason = $this->sanitizeReason($reasonRaw);
        $this->ensureAuditDirectory();
        $this->acquireLock();

        try {
            $counts = $this->collectDeletionCounts();
            $this->performTruncate();
            $timestamp = (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
            $auditEntry = [
                'event' => 'catalog_truncate',
                'id' => $correlationId,
                'reason' => $reason,
                'deleted' => $counts,
                'timestamp' => $timestamp,
            ];
            $this->appendAuditEntry($auditEntry);

            return [
                'auditId' => $correlationId,
                'deleted' => $counts,
                'timestamp' => $timestamp,
            ];
        } catch (CatalogApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new CatalogApiException(
                'TRUNCATE_ERROR',
                'Unable to truncate catalog.',
                500,
                ['error' => $exception->getMessage()]
            );
        } finally {
            $this->releaseLock();
        }
    }

    private function performTruncate(): void
    {
        $this->connection->begin_transaction();
        try {
            $this->setForeignKeyChecks(false);
            $this->repository->truncateCatalogTables();
            $this->setForeignKeyChecks(true);
            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->setForeignKeyChecks(true);
            $this->connection->rollback();
            throw $exception;
        }
    }

    private function setForeignKeyChecks(bool $enabled): void
    {
        $this->repository->setForeignKeyChecks($enabled);
    }

    private function countCategoriesByType(string $type): int
    {
        return $this->repository->countCategoriesByType($type);
    }

    /**
     * @return array<string, int>
     */
    private function collectDeletionCounts(): array
    {
        return [
            'categories' => $this->countCategoriesByType('category'),
            'series' => $this->countCategoriesByType('series'),
            'products' => $this->repository->countProducts(),
            'fieldDefinitions' => $this->repository->countFieldDefinitions(),
            'productValues' => $this->repository->countProductValues(),
            'seriesValues' => $this->repository->countSeriesValues(),
        ];
    }

    private function sanitizeReason(string $reason): string
    {
        $reason = preg_replace('/[\r\n]+/', ' ', $reason) ?? $reason;

        return trim($reason);
    }

    private function ensureAuditDirectory(): void
    {
        $directory = dirname($this->auditLogPath);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new CatalogApiException(
                    'TRUNCATE_ERROR',
                    'Unable to prepare audit directory.',
                    500
                );
            }
        }
    }

    private function appendAuditEntry(array $entry): void
    {
        $encoded = json_encode(
            $entry,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($encoded === false) {
            throw new CatalogApiException('TRUNCATE_ERROR', 'Failed to encode audit entry.', 500);
        }

        $result = @file_put_contents(
            $this->auditLogPath,
            $encoded . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        if ($result === false) {
            throw new CatalogApiException('TRUNCATE_ERROR', 'Failed to write truncate audit.', 500);
        }
    }

    private function acquireLock(): void
    {
        if (!$this->repository->acquireTruncateLock()) {
            throw new CatalogApiException(
                'TRUNCATE_IN_PROGRESS',
                'Another destructive operation is already running. Try again shortly.',
                409
            );
        }

        $this->lockHeld = true;
    }

    private function releaseLock(): void
    {
        if (!$this->lockHeld) {
            return;
        }
        $this->repository->releaseTruncateLock();
        $this->lockHeld = false;
    }
}
