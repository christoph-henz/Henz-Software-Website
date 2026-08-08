<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\ValidationHttpException;
use DateTimeImmutable;
use DateTimeZone;

final class FormRecordValidationService
{
    /**
     * @param array<string, mixed> $payload
     * @param array<int, mixed> $schema
     * @return array<string, mixed>
     */
    public function validateAndNormalizePayload(array $payload, array $schema, string $timezone, bool $requireRequiredFields): array
    {
        $fields = $this->flattenSchemaFields($schema);
        $errors = [];
        $normalized = [];

        foreach ($payload as $fieldKey => $value) {
            if (!is_string($fieldKey) || trim($fieldKey) === '') {
                continue;
            }

            if (!isset($fields[$fieldKey])) {
                $errors[$fieldKey][] = 'field is not part of schema_json';
                continue;
            }

            $fieldErrors = [];
            $normalizedValue = $this->normalizeValueForField($fields[$fieldKey], $value, $timezone, $fieldErrors);
            if ($fieldErrors !== []) {
                $errors[$fieldKey] = $fieldErrors;
                continue;
            }

            $normalized[$fieldKey] = $normalizedValue;
        }

        if ($requireRequiredFields) {
            foreach ($fields as $fieldKey => $definition) {
                $required = (bool) ($definition['required'] ?? false);
                if (!$required) {
                    continue;
                }

                if (!array_key_exists($fieldKey, $normalized) || $this->isEmptyValue($definition, $normalized[$fieldKey])) {
                    $errors[$fieldKey][] = 'required field is missing';
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationHttpException($errors);
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $schema
     * @return array<string, array<string, mixed>>
     */
    public function flattenSchemaFields(array $schema): array
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
                'required' => (bool) ($item['required'] ?? false),
                'options' => is_array($item['options'] ?? null) ? array_values($item['options']) : [],
                'min' => $item['min'] ?? null,
                'max' => $item['max'] ?? null,
                'decimals' => $item['decimals'] ?? null,
                'visibility_rules' => $item['visibility_rules'] ?? null,
            ];
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, string> $errors
     */
    private function normalizeValueForField(array $definition, mixed $value, string $timezone, array &$errors): mixed
    {
        $type = (string) ($definition['type'] ?? 'text');

        return match ($type) {
            'text' => $this->normalizeText($value, 4000, $errors),
            'textarea' => $this->normalizeText($value, 10000, $errors),
            'number' => $this->normalizeNumber($value, $definition, $errors),
            'date' => $this->normalizeDate($value, $timezone, $errors),
            'radio', 'checkbox_single', 'select' => $this->normalizeSingleOption($value, $definition, $errors),
            'checkbox_multiple' => $this->normalizeMultiOption($value, $definition, $errors),
            default => $value,
        };
    }

    /** @param array<int, string> $errors */
    private function normalizeText(mixed $value, int $maxLength, array &$errors): string
    {
        if (!is_scalar($value)) {
            $errors[] = 'must be a string';
            return '';
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            $errors[] = 'must not be empty';
            return '';
        }

        if (mb_strlen($normalized) > $maxLength) {
            $errors[] = 'must not exceed ' . $maxLength . ' characters';
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, string> $errors
     */
    private function normalizeNumber(mixed $value, array $definition, array &$errors): int|float|null
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            $errors[] = 'must be numeric';
            return null;
        }

        $raw = str_replace(',', '.', trim((string) $value));
        if ($raw === '' || !is_numeric($raw)) {
            $errors[] = 'must be numeric';
            return null;
        }

        $number = strpos($raw, '.') === false ? (int) $raw : (float) $raw;

        $min = $this->toNullableFloat($definition['min'] ?? null);
        $max = $this->toNullableFloat($definition['max'] ?? null);
        if ($min !== null && (float) $number < $min) {
            $errors[] = 'must be >= ' . $min;
        }

        if ($max !== null && (float) $number > $max) {
            $errors[] = 'must be <= ' . $max;
        }

        $decimals = $definition['decimals'] ?? null;
        if ($decimals !== null) {
            if ($decimals === false || $decimals === 0 || $decimals === '0') {
                if (floor((float) $number) !== (float) $number) {
                    $errors[] = 'must be an integer';
                }
            } elseif (is_int($decimals) || (is_string($decimals) && ctype_digit(trim($decimals)))) {
                $maxScale = (int) $decimals;
                $parts = explode('.', $raw, 2);
                $scale = isset($parts[1]) ? strlen(rtrim($parts[1], '0')) : 0;
                if ($scale > $maxScale) {
                    $errors[] = 'must have at most ' . $maxScale . ' decimal places';
                }
            }
        }

        return $number;
    }

    /** @param array<int, string> $errors */
    private function normalizeDate(mixed $value, string $timezone, array &$errors): ?int
    {
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && ctype_digit(trim($value))) {
            return (int) trim($value);
        }

        if (!is_string($value)) {
            $errors[] = 'must be a string with format dd.mm.yyyy or dd.mm.yyyy hh:mm';
            return null;
        }

        $input = trim($value);
        if ($input === '') {
            $errors[] = 'must not be empty';
            return null;
        }

        $zone = new DateTimeZone($timezone);
        $candidate = DateTimeImmutable::createFromFormat('!d.m.Y H:i', $input, $zone);
        if ($candidate === false) {
            $candidate = DateTimeImmutable::createFromFormat('!d.m.Y', $input, $zone);
        }

        if ($candidate === false) {
            $errors[] = 'invalid date format, expected dd.mm.yyyy or dd.mm.yyyy hh:mm';
            return null;
        }

        $formatCheck = $candidate->format(str_contains($input, ' ') ? 'd.m.Y H:i' : 'd.m.Y');
        if ($formatCheck !== $input) {
            $errors[] = 'invalid date value';
            return null;
        }

        return $candidate->getTimestamp();
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, string> $errors
     */
    private function normalizeSingleOption(mixed $value, array $definition, array &$errors): ?string
    {
        if (!is_scalar($value)) {
            $errors[] = 'must be a scalar option value';
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            $errors[] = 'must not be empty';
            return null;
        }

        $options = array_map(static fn (mixed $option): string => trim((string) $option), $definition['options'] ?? []);
        if ($options !== [] && !in_array($normalized, $options, true)) {
            $errors[] = 'must be one of schema options';
            return null;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, string> $errors
     * @return array<int, string>|null
     */
    private function normalizeMultiOption(mixed $value, array $definition, array &$errors): ?array
    {
        if (!is_array($value)) {
            $errors[] = 'must be an array of option values';
            return null;
        }

        $options = array_map(static fn (mixed $option): string => trim((string) $option), $definition['options'] ?? []);
        $normalized = [];

        foreach ($value as $entry) {
            $item = trim((string) $entry);
            if ($item === '') {
                continue;
            }

            if ($options !== [] && !in_array($item, $options, true)) {
                $errors[] = 'contains value outside schema options';
                return null;
            }

            $normalized[] = $item;
        }

        $normalized = array_values(array_unique($normalized));
        if ($normalized === []) {
            $errors[] = 'must contain at least one selected option';
            return null;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $definition */
    private function isEmptyValue(array $definition, mixed $value): bool
    {
        $type = (string) ($definition['type'] ?? 'text');
        if ($type === 'checkbox_multiple') {
            return !is_array($value) || $value === [];
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return $value === null;
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }
}