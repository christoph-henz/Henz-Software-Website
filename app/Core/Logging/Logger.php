<?php

declare(strict_types=1);

namespace App\Core\Logging;

final class Logger
{
    public function __construct(private readonly string $path, private readonly string $minLevel = 'debug')
    {
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->isLevelAllowed($level)) {
            return;
        }

        $line = sprintf(
            "[%s] %s: %s %s%s",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context !== [] ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            PHP_EOL
        );

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }

    private function isLevelAllowed(string $level): bool
    {
        $levels = ['debug' => 100, 'info' => 200, 'warning' => 300, 'error' => 400];

        $current = $levels[strtolower($this->minLevel)] ?? 100;
        $target = $levels[strtolower($level)] ?? 100;

        return $target >= $current;
    }
}
