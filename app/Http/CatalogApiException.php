<?php
declare(strict_types=1);

namespace CatalogSuite\Http;

use Exception;

class CatalogApiException extends Exception
{
    private int $statusCode;

    private string $errorCode;

    /** @var array<string, mixed> */
    private array $details;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $errorCode,
        string $message,
        int $statusCode = 400,
        array $details = []
    ) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->details = $details;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
