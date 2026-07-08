<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Logging\Logger;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $rule = $this->matchRule($request);
        if ($rule === null) {
            return $next($request);
        }

        $storePath = (string) config('rate_limits.cache_path', base_path('storage/cache/rate-limits.php'));
        $windowSeconds = max(1, (int) ($rule['window_seconds'] ?? 60));
        $maxRequests = max(1, (int) ($rule['max_requests'] ?? 10));
        $clientKey = $this->resolveClientKey($request);
        $counterKey = $this->buildCounterKey($request, $clientKey);
        $now = time();

        $store = $this->loadStore($storePath);
        $timestamps = array_values(array_filter(
            array_map('intval', (array) ($store[$counterKey] ?? [])),
            static fn (int $timestamp): bool => $timestamp > ($now - $windowSeconds)
        ));

        if (count($timestamps) >= $maxRequests) {
            $oldestTimestamp = min($timestamps);
            $retryAfter = max(1, ($oldestTimestamp + $windowSeconds) - $now);

            $this->logRateLimitHit($request, $counterKey, $maxRequests, $windowSeconds, $retryAfter);

            return Response::json([
                'success' => false,
                'error' => true,
                'message' => (string) ($rule['message'] ?? 'Too many requests'),
                'errors' => [
                    'request' => [(string) ($rule['error_code'] ?? 'rate_limited')],
                ],
            ], 429)->withHeader('Retry-After', (string) $retryAfter);
        }

        $timestamps[] = $now;
        $store[$counterKey] = $timestamps;
        $this->saveStore($storePath, $store);

        return $next($request);
    }

    private function matchRule(Request $request): ?array
    {
        $rules = (array) config('rate_limits.rules', []);
        $method = $request->method();
        $path = $request->path();

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $ruleMethods = array_map('strtoupper', array_map('trim', (array) ($rule['methods'] ?? [])));
            $rulePaths = array_map('strval', (array) ($rule['paths'] ?? []));

            if ($ruleMethods !== [] && !in_array($method, $ruleMethods, true)) {
                continue;
            }

            if ($rulePaths !== [] && !in_array($path, $rulePaths, true)) {
                continue;
            }

            return $rule;
        }

        return null;
    }

    private function resolveClientKey(Request $request): string
    {
        $forwardedFor = trim((string) $request->header('x-forwarded-for', ''));
        if ($forwardedFor !== '') {
            $parts = explode(',', $forwardedFor);
            $candidate = trim((string) ($parts[0] ?? ''));
            if ($candidate !== '') {
                return substr($candidate, 0, 45);
            }
        }

        $realIp = trim((string) $request->header('x-real-ip', ''));
        if ($realIp !== '') {
            return substr($realIp, 0, 45);
        }

        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    private function buildCounterKey(Request $request, string $clientKey): string
    {
        return implode('|', [
            $request->method(),
            $request->path(),
            $clientKey,
        ]);
    }

    /** @return array<string, array<int, int>> */
    private function loadStore(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $store = require $path;
        return is_array($store) ? $store : [];
    }

    /** @param array<string, array<int, int>> $store */
    private function saveStore(string $path, array $store): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $payload = "<?php\nreturn " . var_export($store, true) . ";\n";
        file_put_contents($path, $payload, LOCK_EX);
    }

    private function logRateLimitHit(Request $request, string $counterKey, int $maxRequests, int $windowSeconds, int $retryAfter): void
    {
        $logger = app(Logger::class);
        if (!$logger instanceof Logger) {
            return;
        }

        $logger->warning('rate_limit.exceeded', [
            'request_id' => (string) $request->attribute('request_id', 'n/a'),
            'key' => $counterKey,
            'method' => $request->method(),
            'path' => $request->path(),
            'limit' => $maxRequests,
            'window_seconds' => $windowSeconds,
            'retry_after' => $retryAfter,
        ]);
    }
}
