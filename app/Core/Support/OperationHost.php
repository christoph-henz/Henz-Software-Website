<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Http\Request;

final class OperationHost
{
    public static function isOperationHost(Request $request): bool
    {
        if (!self::shouldEnforceSubdomain()) {
            return true;
        }

        $subdomain = self::adminSubdomainLabel();
        if ($subdomain === '') {
            return true;
        }

        $host = self::stripPort(self::requestHost($request));
        if ($host === '') {
            return false;
        }

        return $host === $subdomain || str_starts_with($host, $subdomain . '.');
    }

    public static function buildOperationUrl(Request $request, string $path): string
    {
        $scheme = self::requestScheme($request);
        $hostWithPort = self::withAdminSubdomain(self::requestHost($request));

        if ($hostWithPort === '') {
            $hostWithPort = self::adminSubdomainLabel();
        }

        $normalizedPath = '/' . ltrim($path, '/');
        return $scheme . '://' . $hostWithPort . $normalizedPath;
    }

    /**
     * Builds a path that keeps the current route and query string.
     */
    public static function currentPathWithQuery(Request $request): string
    {
        $path = $request->path();
        $query = $request->queryAll();

        if ($query === []) {
            return $path;
        }

        $qs = http_build_query($query);
        return $qs !== '' ? ($path . '?' . $qs) : $path;
    }

    public static function shouldEnforceSubdomain(): bool
    {
        return filter_var((string) config('operations.enforce_subdomain', true), FILTER_VALIDATE_BOOL) === true;
    }

    private static function adminSubdomainLabel(): string
    {
        return strtolower(trim((string) config('operations.subdomain', 'operations')));
    }

    private static function requestHost(Request $request): string
    {
        $forwardedHost = trim((string) $request->header('x-forwarded-host', ''));
        if ($forwardedHost !== '') {
            $parts = array_map('trim', explode(',', $forwardedHost));
            return strtolower((string) ($parts[0] ?? ''));
        }

        $host = trim((string) $request->header('host', ''));
        return strtolower($host);
    }

    private static function requestScheme(Request $request): string
    {
        $forwardedProto = strtolower(trim((string) $request->header('x-forwarded-proto', '')));
        if ($forwardedProto === 'https' || $forwardedProto === 'http') {
            return $forwardedProto;
        }

        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return 'https';
        }

        return 'http';
    }

    private static function withAdminSubdomain(string $hostWithPort): string
    {
        $raw = trim(strtolower($hostWithPort));
        $subdomain = self::adminSubdomainLabel();
        if ($raw === '' || $subdomain === '') {
            return $raw;
        }

        [$host, $port] = self::splitHostAndPort($raw);
        if ($host === '') {
            return $raw;
        }

        if ($host === $subdomain || str_starts_with($host, $subdomain . '.')) {
            return $port !== '' ? ($host . ':' . $port) : $host;
        }

        $target = $subdomain . '.' . $host;
        return $port !== '' ? ($target . ':' . $port) : $target;
    }

    /** @return array{0: string, 1: string} */
    private static function splitHostAndPort(string $hostWithPort): array
    {
        $host = trim($hostWithPort);
        if ($host === '') {
            return ['', ''];
        }

        // Keep IPv6 literals untouched (no subdomain rewrite for IPv6 hosts).
        if (str_contains($host, '[') || substr_count($host, ':') > 1) {
            return [$host, ''];
        }

        $lastColon = strrpos($host, ':');
        if ($lastColon === false) {
            return [$host, ''];
        }

        $port = substr($host, $lastColon + 1);
        if ($port === '' || !ctype_digit($port)) {
            return [$host, ''];
        }

        return [substr($host, 0, $lastColon), $port];
    }

    private static function stripPort(string $hostWithPort): string
    {
        [$host] = self::splitHostAndPort($hostWithPort);
        return $host;
    }
}
