<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\HttpException;

final class EncryptionService
{
    private const ALGORITHM = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    public function __construct()
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, mixed> $schema
     * @return array<string, mixed>
     */
    public function encryptSensitiveFields(array $payload, array $schema): array
    {
        $result = $payload;

        foreach ($payload as $fieldKey => $value) {
            if (!is_string($fieldKey)) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            $encrypted = $this->encryptValue($value);
            if ($encrypted !== null) {
                $result[$fieldKey] = $encrypted;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, mixed> $schema
     * @return array<string, mixed>
     */
    public function decryptSensitiveFields(array $payload, array $schema): array
    {
        $result = $payload;

        foreach ($payload as $fieldKey => $value) {
            if (!is_string($fieldKey)) {
                continue;
            }

            if (!$this->isCipherEnvelope($value)) {
                continue;
            }

            $decrypted = $this->decryptValue($value);
            if ($decrypted !== null) {
                $result[$fieldKey] = $decrypted;
            }
        }

        return $result;
    }

    /**
     * Encrypt a scalar value.
     * @return array<string, string>|null
     */
    private function encryptValue(mixed $value): ?array
    {
        $key = $this->getEncryptionKey();
        if ($key === null) {
            throw new HttpException('Encryption key not configured', 500, 'ENCRYPTION_KEY_INVALID');
        }

        $format = 'string';
        if (is_string($value)) {
            $plaintext = $value;
        } else {
            $plaintext = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $format = 'json';
        }

        $iv = openssl_random_pseudo_bytes(self::IV_LENGTH, $strong);
        if (!$strong) {
            throw new HttpException('Failed to generate random IV', 500, 'ENCRYPTION_IV_GENERATION_FAILED');
        }

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::ALGORITHM,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new HttpException('Encryption failed', 500, 'ENCRYPTION_FAILED');
        }

        $keyVersion = (int) env('SESSION_RECORD_ENCRYPTION_KEY_VERSION', 1);

        return [
            'kv' => (string) $keyVersion,
            'fmt' => $format,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ct' => base64_encode($ciphertext),
        ];
    }

    /**
     * Decrypt a ciphered value object.
     */
    private function decryptValue(array $value): mixed
    {
        $kv = (int) ($value['kv'] ?? 0);
        $ivB64 = (string) ($value['iv'] ?? '');
        $tagB64 = (string) ($value['tag'] ?? '');
        $ctB64 = (string) ($value['ct'] ?? '');

        if ($ivB64 === '' || $tagB64 === '' || $ctB64 === '') {
            throw new HttpException('Invalid cipher format', 500, 'ENCRYPTION_INVALID_FORMAT');
        }

        $key = $this->getEncryptionKey($kv);
        if ($key === null) {
            throw new HttpException('Decryption key not available for version ' . $kv, 500, 'ENCRYPTION_KEY_INVALID');
        }

        $iv = base64_decode($ivB64, true);
        $tag = base64_decode($tagB64, true);
        $ciphertext = base64_decode($ctB64, true);

        if ($iv === false || $tag === false || $ciphertext === false) {
            throw new HttpException('Invalid base64 encoding in cipher', 500, 'ENCRYPTION_INVALID_BASE64');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::ALGORITHM,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new HttpException('Decryption failed or tag validation failed', 500, 'ENCRYPTION_DECRYPTION_FAILED');
        }

        $format = (string) ($value['fmt'] ?? 'string');
        if ($format !== 'json') {
            return $plaintext;
        }

        try {
            return json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new HttpException('Decryption payload JSON parse failed', 500, 'ENCRYPTION_DECRYPTED_JSON_INVALID');
        }
    }

    private function isCipherEnvelope(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        $kv = $value['kv'] ?? null;
        $iv = $value['iv'] ?? null;
        $tag = $value['tag'] ?? null;
        $ct = $value['ct'] ?? null;

        return is_scalar($kv)
            && is_string($iv)
            && is_string($tag)
            && is_string($ct)
            && $iv !== ''
            && $tag !== ''
            && $ct !== '';
    }

    private function getEncryptionKey(int $version = 0): ?string
    {
        if ($version === 0) {
            $version = (int) env('SESSION_RECORD_ENCRYPTION_KEY_VERSION', 1);
        }

        $keyEnv = 'SESSION_RECORD_ENCRYPTION_KEY';
        if ($version !== 1) {
            $keyEnv = 'SESSION_RECORD_ENCRYPTION_KEY_V' . $version;
        }

        $keyB64 = env($keyEnv, '');
        // Backward-compatible fallback: records with kv>1 can still decrypt with
        // the base key when no version-specific key is configured.
        if ($keyB64 === '' && $version !== 1) {
            $keyB64 = env('SESSION_RECORD_ENCRYPTION_KEY', '');
        }
        if ($keyB64 === '') {
            return null;
        }

        $key = base64_decode($keyB64, true);
        if ($key === false || strlen($key) !== 32) {
            return null;
        }

        return $key;
    }

    /**
     * @param array<int, mixed> $schema
     * @return array<string, array<string, mixed>>
     */
    private function flattenSchemaFields(array $schema): array
    {
        $fields = [];

        foreach ($schema as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = strtolower(trim((string) ($item['type'] ?? '')));
            if ($type === 'section') {
                $children = $item['items'] ?? null;
                if (is_array($children)) {
                    $fields = array_merge($fields, $this->flattenSchemaFields($children));
                }
                continue;
            }

            if ($type === 'letterhead') {
                continue;
            }

            $fieldKey = trim((string) ($item['field_key'] ?? ''));
            if ($fieldKey === '' || $type === '') {
                continue;
            }

            $fields[$fieldKey] = [
                'field_key' => $fieldKey,
                'type' => $type,
                'security_class' => (string) ($item['security_class'] ?? 'A'),
            ];
        }

        return $fields;
    }
}
