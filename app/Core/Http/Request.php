<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Request
{
    /** @param array<string, mixed> $server */
    public function __construct(
        private readonly array $query,
        private readonly array $request,
        private readonly array $attributes,
        private readonly array $cookies,
        private readonly array $files,
        private readonly array $server,
        private readonly string $rawBody,
    ) {
    }

    public static function capture(): self
    {
        $rawBody = file_get_contents('php://input');

        return new self(
            $_GET,
            $_POST,
            [],
            $_COOKIE,
            $_FILES,
            $_SERVER,
            $rawBody === false ? '' : $rawBody
        );
    }

    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        return '/' . ltrim((string) parse_url($uri, PHP_URL_PATH), '/');
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function queryAll(): array
    {
        return $this->query;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $payload = $this->json();
        if (array_key_exists($key, $this->request)) {
            return $this->request[$key];
        }

        if (is_array($payload) && array_key_exists($key, $payload)) {
            return $payload[$key];
        }

        return $this->attributes[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $json = $this->json();
        return array_merge($this->request, is_array($json) ? $json : [], $this->attributes);
    }

    public function header(string $name, mixed $default = null): mixed
    {
        $trimmedName = trim($name);
        if ($trimmedName === '') {
            return $default;
        }

        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $trimmedName));
        if (array_key_exists($normalized, $this->server)) {
            return $this->server[$normalized];
        }

        $redirectNormalized = 'REDIRECT_' . $normalized;
        if (array_key_exists($redirectNormalized, $this->server)) {
            return $this->server[$redirectNormalized];
        }

        $rawNormalized = strtoupper(str_replace('-', '_', $trimmedName));
        if (array_key_exists($rawNormalized, $this->server)) {
            return $this->server[$rawNormalized];
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $headerName => $headerValue) {
                    if (strcasecmp((string) $headerName, $trimmedName) === 0) {
                        return $headerValue;
                    }
                }
            }
        }

        if (strtolower($trimmedName) === 'content-type') {
            return $this->server['CONTENT_TYPE'] ?? $default;
        }

        return $default;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        $headers = [];
        foreach ($this->server as $key => $value) {
            if (!str_starts_with((string) $key, 'HTTP_')) {
                continue;
            }
            $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) $key, 5)))));
            $headers[$headerName] = (string) $value;
        }
        return $headers;
    }

    public function json(): mixed
    {
        $decoded = json_decode($this->rawBody, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    public function file(string $key): mixed
    {
        return $this->files[$key] ?? null;
    }

    public function session(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        return $_SESSION;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self(
            $this->query,
            $this->request,
            $attributes,
            $this->cookies,
            $this->files,
            $this->server,
            $this->rawBody
        );
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
