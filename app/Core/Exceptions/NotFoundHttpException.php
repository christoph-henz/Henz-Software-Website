<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class NotFoundHttpException extends HttpException
{
    public function __construct(string $message = 'Resource not found')
    {
        parent::__construct($message, 404);
    }
}
