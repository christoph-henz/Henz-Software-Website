<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use Exception;

class HttpException extends Exception
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 500,
        private readonly array $headers = []
    ) {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
