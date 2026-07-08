<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Database;

final class ClientFieldEncryptionService
{
    /** @var array<int, string> */
    private const EXCLUDED_COLUMNS = [
        'id',
        'timezone',
        'contact_preference',
        'language',
        'is_active',
        'created_at',
        'updated_at',
    ];

    /** @var array<int, string> */
    private const ENCRYPTED_COLUMNS = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'date_of_birth',
        'gender',
        'title',
        'street',
        'postal_code',
        'city',
        'country',
        'medical_notes',
        'dementia_person_surname',
        'dementia_person_name',
        'dementia_person_date_of_birth',
        'dementia_person_date_of_death',
    ];

    private ?bool $emailBlindIndexColumnAvailable = null;
    private ?bool $identityBlindIndexColumnAvailable = null;
    /** @var array<string, array{data_type:string, char_length:int|null}>|null */
    private ?array $clientColumnMeta = null;

    public function __construct(
        private readonly EncryptionService $encryption,
        private readonly Database $database,
    ) {
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    public function encryptClientData(array $data): array
    {
        $result = $data;

        foreach (self::ENCRYPTED_COLUMNS as $column) {
            if (!array_key_exists($column, $result)) {
                continue;
            }

            $value = $result[$column];
            if ($value === null) {
                continue;
            }

            $encrypted = $this->encryption->encryptSensitiveFields(['value' => $value], []);
            $cipher = $encrypted['value'] ?? null;
            if (is_array($cipher)) {
                $result[$column] = json_encode($cipher, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        if ($this->isEmailBlindIndexColumnAvailable() && array_key_exists('email', $data)) {
            $result['email_blind_index'] = $this->emailBlindIndex($data['email']);
        }

        if (
            $this->isIdentityBlindIndexColumnAvailable()
            && (
                array_key_exists('first_name', $data)
                || array_key_exists('last_name', $data)
                || array_key_exists('date_of_birth', $data)
            )
        ) {
            $result['identity_blind_index'] = $this->identityBlindIndex(
                $data['first_name'] ?? null,
                $data['last_name'] ?? null,
                $data['date_of_birth'] ?? null
            );
        }

        return $result;
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    public function decryptClientRow(array $row): array
    {
        $result = $row;

        foreach (self::ENCRYPTED_COLUMNS as $column) {
            if (!array_key_exists($column, $result)) {
                continue;
            }

            $result[$column] = $this->decryptStoredValue($result[$column]);
        }

        return $result;
    }

    /** @param array<int, array<string, mixed>> $rows
     *  @return array<int, array<string, mixed>>
     */
    public function decryptClientRows(array $rows): array
    {
        return array_map(fn (array $row): array => $this->decryptClientRow($row), $rows);
    }

    public function emailBlindIndex(mixed $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $email));
        if ($normalized === '') {
            return null;
        }

        return hash('sha256', 'email|' . $normalized . '|' . $this->blindIndexPepper());
    }

    public function identityBlindIndex(mixed $firstName, mixed $lastName, mixed $dateOfBirth): ?string
    {
        $first = $this->normalizeIdentityPart($firstName);
        $last = $this->normalizeIdentityPart($lastName);
        $dob = trim((string) ($dateOfBirth ?? ''));

        if ($first === '' || $last === '' || $dob === '') {
            return null;
        }

        return hash('sha256', 'identity|' . $first . '|' . $last . '|' . $dob . '|' . $this->blindIndexPepper());
    }

    public function isEncryptedColumn(string $column): bool
    {
        if (in_array($column, self::EXCLUDED_COLUMNS, true)) {
            return false;
        }

        return in_array($column, self::ENCRYPTED_COLUMNS, true);
    }

    public function isEmailBlindIndexColumnAvailable(): bool
    {
        if ($this->emailBlindIndexColumnAvailable !== null) {
            return $this->emailBlindIndexColumnAvailable;
        }

        $this->emailBlindIndexColumnAvailable = $this->hasClientColumn('email_blind_index');
        return $this->emailBlindIndexColumnAvailable;
    }

    public function isIdentityBlindIndexColumnAvailable(): bool
    {
        if ($this->identityBlindIndexColumnAvailable !== null) {
            return $this->identityBlindIndexColumnAvailable;
        }

        $this->identityBlindIndexColumnAvailable = $this->hasClientColumn('identity_blind_index');
        return $this->identityBlindIndexColumnAvailable;
    }

    private function decryptStoredValue(mixed $value): mixed
    {
        if (!is_string($value) || trim($value) === '') {
            return $value;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return $value;
            }

            $decrypted = $this->encryption->decryptSensitiveFields(['value' => $decoded], []);
            return $decrypted['value'] ?? $value;
        } catch (\Throwable) {
            // Backward compatibility: keep legacy plaintext values readable.
            return $value;
        }
    }

    private function hasClientColumn(string $column): bool
    {
        $meta = $this->loadClientColumnMeta();
        if ($meta !== []) {
            return isset($meta[$column]);
        }

        try {
            $pdo = $this->database->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                   AND column_name = :column_name
                 LIMIT 1'
            );
            $statement->execute([
                ':table_name' => 'clients',
                ':column_name' => $column,
            ]);

            return $statement->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function blindIndexPepper(): string
    {
        $pepper = trim((string) env('CLIENT_FIELD_HASH_PEPPER', ''));
        if ($pepper !== '') {
            return $pepper;
        }

        $fallback = trim((string) env('SESSION_RECORD_ENCRYPTION_KEY', ''));
        if ($fallback !== '') {
            return $fallback;
        }

        return 'getragen-begleiten-client-hash-pepper';
    }

    private function normalizeIdentityPart(mixed $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) ($value ?? '')) ?? ''));
    }

    /** @return array<string, array{data_type:string, char_length:int|null}> */
    private function loadClientColumnMeta(): array
    {
        if (is_array($this->clientColumnMeta)) {
            return $this->clientColumnMeta;
        }

        try {
            $pdo = $this->database->connection();
            $statement = $pdo->prepare(
                'SELECT column_name, data_type, character_maximum_length
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name'
            );
            $statement->execute([':table_name' => 'clients']);
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

            $meta = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $name = (string) ($row['column_name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $meta[$name] = [
                    'data_type' => (string) ($row['data_type'] ?? ''),
                    'char_length' => isset($row['character_maximum_length']) ? (int) $row['character_maximum_length'] : null,
                ];
            }

            $this->clientColumnMeta = $meta;
            return $meta;
        } catch (\Throwable) {
            $this->clientColumnMeta = [];
            return [];
        }
    }
}