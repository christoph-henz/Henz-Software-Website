<?php

declare(strict_types=1);

namespace App\Core\Config;

final class ConfigCache
{
    public function write(string $cacheFile, array $config): bool
    {
        $payload = '<?php return ' . var_export($config, true) . ';';
        return (bool) file_put_contents($cacheFile, $payload, LOCK_EX);
    }
}
