<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Database;

final class EmailLogPrivacyService
{
    /** @var array<string, bool> */
    private array $columnAvailabilityCache = [];

    public function __construct(
        private readonly Database $database,
        private readonly EncryptionService $encryption,
    ) {
    }

    public function hasColumn(string $columnName): bool
    {
        if (array_key_exists($columnName, $this->columnAvailabilityCache)) {
            return $this->columnAvailabilityCache[$columnName];
        }

        try {
            $pdo = $this->database->connection();
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                   AND column_name = :column_name
                 LIMIT 1'
            );
            $stmt->execute([
                ':table_name' => 'email_logs',
                ':column_name' => $columnName,
            ]);

            $this->columnAvailabilityCache[$columnName] = $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            $this->columnAvailabilityCache[$columnName] = false;
        }

        return $this->columnAvailabilityCache[$columnName];
    }

    public function clientRefHash(int $clientId): ?string
    {
        if ($clientId <= 0) {
            return null;
        }

        return hash('sha256', 'email-log-client|' . $clientId . '|' . $this->hashPepper());
    }

    public function encryptAddress(string $address): ?string
    {
        $normalized = strtolower(trim($address));
        if ($normalized === '') {
            return null;
        }

        return $this->encryptText($normalized);
    }

    public function encryptText(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $payload = $this->encryption->encryptSensitiveFields(['value' => $value], []);
        $cipher = $payload['value'] ?? null;
        if (!is_array($cipher)) {
            return null;
        }

        return json_encode($cipher, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function decryptAddress(mixed $encryptedValue, string $fallback = ''): string
    {
        return strtolower(trim($this->decryptText($encryptedValue, $fallback)));
    }

    public function decryptText(mixed $encryptedValue, string $fallback = ''): string
    {
        if (!is_string($encryptedValue) || trim($encryptedValue) === '') {
            return $fallback;
        }

        try {
            $decoded = json_decode($encryptedValue, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return $fallback;
            }

            $decrypted = $this->encryption->decryptSensitiveFields(['value' => $decoded], []);
            $value = (string) ($decrypted['value'] ?? '');

            return $value !== '' ? $value : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    public function maskAddress(string $address): string
    {
        $normalized = strtolower(trim($address));
        if ($normalized === '' || !str_contains($normalized, '@')) {
            return '[hidden]';
        }

        [$local, $domain] = explode('@', $normalized, 2);
        $localMasked = strlen($local) <= 2
            ? str_repeat('*', max(1, strlen($local)))
            : substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 2)) . substr($local, -1);

        return $localMasked . '@' . $domain;
    }

    private function hashPepper(): string
    {
        $pepper = trim((string) env('EMAIL_LOG_HASH_PEPPER', ''));
        if ($pepper !== '') {
            return $pepper;
        }

        $fallback = trim((string) env('SESSION_RECORD_ENCRYPTION_KEY', ''));
        if ($fallback !== '') {
            return $fallback;
        }

        return 'getragen-begleiten-email-log-hash-pepper';
    }
}