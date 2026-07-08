<?php

declare(strict_types=1);

namespace App\Core\Security;

final class CsrfTokenManager
{
    private const SESSION_KEY = '_csrf_token';

    public function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::SESSION_KEY];
    }

    public function isValid(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $stored = $_SESSION[self::SESSION_KEY] ?? '';
        return is_string($token) && $stored !== '' && hash_equals($stored, $token);
    }
}
