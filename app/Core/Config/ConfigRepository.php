<?php

declare(strict_types=1);

namespace App\Core\Config;

use RuntimeException;

final class ConfigRepository
{
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private array $items = [];

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function load(string $configPath, ?string $cachePath = null): void
    {
        if ($cachePath !== null && is_file($cachePath)) {
            $cached = require $cachePath;
            if (is_array($cached)) {
                $this->items = $cached;
                return;
            }
        }

        if (!is_dir($configPath)) {
            throw new RuntimeException(sprintf('Config directory not found: %s', $configPath));
        }

        $items = [];
        $files = glob(rtrim($configPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [];

        foreach ($files as $file) {
            $key = basename($file, '.php');
            $data = require $file;
            if (is_array($data)) {
                $items[$key] = $data;
            }
        }

        $this->items = $items;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }
}
