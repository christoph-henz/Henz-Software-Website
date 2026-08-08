<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

final class ContactFormConfigValidator
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, array<int, string>>
     */
    public function validate(array $payload): array
    {
        $config = require base_path('public/ui/_config/contact-page.php');
        $fields = (array) ($config['form']['fields'] ?? []);

        $activeFields = [];
        $this->collectActiveFields($fields, '', $payload, $activeFields);

        $errors = [];
        foreach ($activeFields as $path => $field) {
            $name = trim((string) ($field['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $type = strtolower(trim((string) ($field['type'] ?? 'text')));
            $value = $payload[$path] ?? null;

            $required = (bool) ($field['required'] ?? false);
            if ($required && $this->isEmpty($value)) {
                $errors[$path][] = 'required';
                continue;
            }

            if (in_array($type, ['select', 'choice'], true)) {
                $choices = is_array($field['choices'] ?? null) ? $field['choices'] : [];
                if ($this->isChoiceList($choices) && !$this->isEmpty($value)) {
                    $allowed = [];
                    foreach ($choices as $choice) {
                        if (!is_array($choice)) {
                            continue;
                        }
                        $allowed[] = (string) ($choice['value'] ?? '');
                    }

                    if (!in_array((string) $value, $allowed, true)) {
                        $errors[$path][] = 'invalid_option';
                    }
                }
            }

            foreach ($this->normalizeValidators($field['validation'] ?? null) as $validator) {
                if (!is_array($validator)) {
                    continue;
                }

                $rule = strtolower(trim((string) ($validator['rule'] ?? '')));
                if ($rule === '') {
                    continue;
                }

                if ($this->isEmpty($value) && $rule !== 'required') {
                    continue;
                }

                $candidate = (string) ($value ?? '');

                if ($rule === 'required' && $this->isEmpty($value)) {
                    $errors[$path][] = 'required';
                    continue;
                }

                if ($rule === 'email' && !filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                    $errors[$path][] = 'email';
                    continue;
                }

                if ($rule === 'minlength') {
                    $minLength = (int) ($validator['value'] ?? 0);
                    if ($this->stringLength($candidate) < $minLength) {
                        $errors[$path][] = 'min_length';
                    }
                    continue;
                }

                if ($rule === 'maxlength') {
                    $maxLength = (int) ($validator['value'] ?? 0);
                    if ($maxLength > 0 && $this->stringLength($candidate) > $maxLength) {
                        $errors[$path][] = 'max_length';
                    }
                    continue;
                }

                if ($rule === 'length') {
                    $length = (int) ($validator['value'] ?? 0);
                    if ($length > 0 && $this->stringLength($candidate) !== $length) {
                        $errors[$path][] = 'length';
                    }
                    continue;
                }

                if ($rule === 'regex') {
                    $pattern = (string) ($validator['pattern'] ?? '');
                    if ($pattern !== '' && @preg_match('/' . $pattern . '/u', $candidate) !== 1) {
                        $errors[$path][] = 'regex';
                    }
                    continue;
                }

                if ($rule === 'number' && !is_numeric($candidate)) {
                    $errors[$path][] = 'number';
                    continue;
                }

                if ($rule === 'integer' && filter_var($candidate, FILTER_VALIDATE_INT) === false) {
                    $errors[$path][] = 'integer';
                    continue;
                }

                if ($rule === 'min' && is_numeric($candidate) && (float) $candidate < (float) ($validator['value'] ?? 0)) {
                    $errors[$path][] = 'min';
                    continue;
                }

                if ($rule === 'max' && is_numeric($candidate) && (float) $candidate > (float) ($validator['value'] ?? 0)) {
                    $errors[$path][] = 'max';
                    continue;
                }

                if ($rule === 'range' && is_numeric($candidate)) {
                    $min = (float) ($validator['min'] ?? 0);
                    $max = (float) ($validator['max'] ?? 0);
                    $valueFloat = (float) $candidate;
                    if ($valueFloat < $min || $valueFloat > $max) {
                        $errors[$path][] = 'range';
                    }
                    continue;
                }

                if ($rule === 'url' && filter_var($candidate, FILTER_VALIDATE_URL) === false) {
                    $errors[$path][] = 'url';
                    continue;
                }

                if ($rule === 'date' && !$this->isValidDate($candidate)) {
                    $errors[$path][] = 'date';
                    continue;
                }

                if ($rule === 'afterdate') {
                    $limit = trim((string) ($validator['value'] ?? ''));
                    if ($this->isValidDate($candidate) && $this->isValidDate($limit)) {
                        if (new DateTimeImmutable($candidate) <= new DateTimeImmutable($limit)) {
                            $errors[$path][] = 'after_date';
                        }
                    }
                    continue;
                }

                if ($rule === 'beforedate') {
                    $limit = trim((string) ($validator['value'] ?? ''));
                    if ($this->isValidDate($candidate) && $this->isValidDate($limit)) {
                        if (new DateTimeImmutable($candidate) >= new DateTimeImmutable($limit)) {
                            $errors[$path][] = 'before_date';
                        }
                    }
                    continue;
                }

                if ($rule === 'equals') {
                    $targetField = trim((string) ($validator['field'] ?? ''));
                    if ($targetField === '') {
                        continue;
                    }

                    $targetPath = $this->resolveTargetPath($path, $targetField);
                    $targetValue = (string) ($payload[$targetPath] ?? '');
                    if ($candidate !== $targetValue) {
                        $errors[$path][] = 'equals';
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<int, mixed> $fields
     * @param array<string, mixed> $payload
     * @param array<string, array<string, mixed>> $activeFields
     */
    private function collectActiveFields(array $fields, string $prefix, array $payload, array &$activeFields): void
    {
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = trim((string) ($field['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $path = $prefix === '' ? $name : $prefix . '.' . $name;
            $activeFields[$path] = $field;

            $choices = is_array($field['choices'] ?? null) ? $field['choices'] : [];
            if (!$this->isChoiceList($choices)) {
                continue;
            }

            $selectedValue = (string) ($payload[$path] ?? '');
            if ($selectedValue === '') {
                continue;
            }

            foreach ($choices as $choice) {
                if (!is_array($choice)) {
                    continue;
                }

                $choiceValue = (string) ($choice['value'] ?? '');
                if ($choiceValue !== $selectedValue) {
                    continue;
                }

                $nestedFields = is_array($choice['fields'] ?? null)
                    ? $choice['fields']
                    : [];

                $this->collectActiveFields($nestedFields, $path . '.' . $selectedValue, $payload, $activeFields);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeValidators(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        if (array_key_exists('rule', $raw)) {
            return [$raw];
        }

        $validators = [];
        foreach ($raw as $item) {
            if (is_array($item) && array_key_exists('rule', $item)) {
                $validators[] = $item;
            }
        }

        return $validators;
    }

    /**
     * @param array<int, mixed> $choices
     */
    private function isChoiceList(array $choices): bool
    {
        if ($choices === []) {
            return false;
        }

        foreach ($choices as $choice) {
            if (!is_array($choice) || !array_key_exists('value', $choice)) {
                return false;
            }
        }

        return true;
    }

    private function resolveTargetPath(string $fieldPath, string $targetField): string
    {
        if (str_contains($targetField, '.')) {
            return $targetField;
        }

        $parts = explode('.', $fieldPath);
        array_pop($parts);
        $parts[] = $targetField;

        return implode('.', $parts);
    }

    private function isValidDate(string $value): bool
    {
        try {
            new DateTimeImmutable($value);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    private function stringLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value);
        }

        return strlen($value);
    }
}
