<?php

declare(strict_types=1);

namespace App\Core\Config;

final class EnvLoader
{
    public function load(string $filePath): void
    {
        if (!is_file($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"");

            if ($name === '') {
                continue;
            }

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}
