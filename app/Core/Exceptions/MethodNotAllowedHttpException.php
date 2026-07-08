<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class MethodNotAllowedHttpException extends HttpException
{
    /** @param array<int, string> $allowed */
    public function __construct(array $allowed, string $message = 'Method not allowed')
    {
        parent::__construct($message, 405, ['Allow' => implode(', ', $allowed)]);
    }
}
