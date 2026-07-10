<?php

declare(strict_types=1);

use App\Core\Container\Container;
use App\Core\Config\ConfigRepository;
use App\Core\Database\Database;

if (!function_exists('app')) {
    /**
     * Retrieve the application container instance or a specific service
     *
     * @template T
     * @param class-string<T>|null $abstract
     * @return ($abstract is null ? Container : T)
     */
    function app(?string $abstract = null): mixed
    {
        global $_app_container;

        if ($abstract === null) {
            return $_app_container ?? new Container();
        }

        return ($_app_container ?? new Container())->get($abstract);
    }
}

if (!function_exists('db')) {
    /**
     * Get a database connection and return a query builder for the specified table
     *
     * 1 or null => DB1 (henz_software_main)
     * 2 => DB2 (henz_software_logging)
     *
     * @param int|string|array<int, int|string>|null $database
     */
    function db(string $table, int|string|array|null $database = 1): \App\Core\Database\QueryBuilder|\App\Core\Database\MultiConnectionQueryBuilder
    {
        $normalizeConnection = static function (int|string|null $connection): string {
            return match ($connection) {
                null, 1, '1', 'db1', 'main', 'production', 'mysql', 'henz_software_main' => 'henz_software_main',
                2, '2', 'db2', 'log', 'logging', 'henz_software_logging' => 'henz_software_logging',
                default => (string) $connection,
            };
        };

        if (is_array($database)) {
            $connections = [];
            foreach ($database as $connection) {
                $connections[] = $normalizeConnection(is_int($connection) || is_string($connection) ? $connection : null);
            }

            return app(Database::class)->table($table, $connections);
        }

        return app(Database::class)->table($table, $normalizeConnection($database));
    }
}

if (!function_exists('config')) {
    /**
     * Get application configuration value with dot notation
     */
    function config(string $key, mixed $default = null): mixed
    {
        return ConfigRepository::instance()->get($key, $default);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = dirname(__DIR__, 3);
        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }
}

if (!function_exists('admin_flash')) {
    /**
     * Set a session flash notification for the next admin page render.
     *
     * The admin-notification partial reads and clears this value on the
     * following request (survives one redirect). Call this before Response::redirect().
     *
     * @param 'error'|'warning'|'success'|'info' $type
     */
    function admin_flash(string $type, string $message): void
    {
        $allowed = ['error', 'warning', 'success', 'info'];

        if (!in_array($type, $allowed, true)) {
            $type = 'info';
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['admin_flash'] = ['type' => $type, 'message' => $message];
        }
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return ConfigRepository::instance()->get($key, $default);
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}
