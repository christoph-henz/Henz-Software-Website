<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class ValidationHttpException extends HttpException
{
    /** @param array<string, array<int, string>> $errors */
    public function __construct(private readonly array $errors, string $message = 'Validation failed')
    {
        parent::__construct($message, 422);
    }

    /** @return array<string, array<int, string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
