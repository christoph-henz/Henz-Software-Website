<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\ContactFormConfigValidator;
use Throwable;

final class ContactRequestController extends BaseApiController
{
    public function __construct(
        private readonly AppointmentController $appointmentController,
        private readonly TicketController $ticketController,
        private readonly ContactFormConfigValidator $configValidator,
    ) {
    }

    public function store(Request $request): Response
    {
        $data = $this->normalizeDottedFieldKeys($request->all());
        $serviceType = strtolower(trim((string) ($data['service_type'] ?? '')));

        if ($serviceType === '') {
            return $this->fail('Validation failed', 422, [
                'service_type' => ['required'],
            ]);
        }

        $dynamicErrors = $this->configValidator->validate($data);
        if ($dynamicErrors !== []) {
            return $this->fail('Validation failed', 422, $dynamicErrors);
        }

        if ($serviceType === 'contact' && !$this->readAvailabilityRuleBool('appointments_enabled', true)) {
            return $this->fail('Terminbuchung ist derzeit deaktiviert.', 409, [
                'service_type' => ['appointments_disabled'],
            ]);
        }

        if (in_array($serviceType, ['service', 'ticket'], true) && !$this->readAvailabilityRuleBool('tickets_enabled', true)) {
            return $this->fail('Ticketsystem ist derzeit deaktiviert.', 409, [
                'service_type' => ['tickets_disabled'],
            ]);
        }

        if (in_array($serviceType, ['contact', 'service'], true)) {
            return $this->appointmentController->storeFromContactForm($data, $serviceType);
        }

        if ($serviceType === 'ticket') {
            return $this->ticketController->storeFromContactForm($data);
        }

        return $this->fail('Validation failed', 422, [
            'service_type' => ['invalid_option'],
        ]);
    }

    private function readAvailabilityRuleBool(string $ruleKey, bool $default): bool
    {
        try {
            $row = db('availability_rules')
                ->where('rule_key', $ruleKey)
                ->select(['rule_value'])
                ->first();
        } catch (Throwable) {
            return $default;
        }

        if (!is_array($row)) {
            return $default;
        }

        $raw = strtolower(trim((string) ($row['rule_value'] ?? '')));
        if ($raw === '') {
            return $default;
        }

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * PHP normalisiert POST-Feldnamen mit Punkten zu Unterstrichen.
     * Diese Methode stellt die erwarteten Dot-Keys aus dem Formularschema wieder her.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeDottedFieldKeys(array $payload): array
    {
        $config = require base_path('public/ui/_config/contact-page.php');
        $fields = is_array($config['form']['fields'] ?? null) ? $config['form']['fields'] : [];
        $paths = [];
        $this->collectFieldPaths($fields, '', $paths);

        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            if (array_key_exists($path, $payload)) {
                continue;
            }

            $phpKey = str_replace('.', '_', $path);
            if (array_key_exists($phpKey, $payload)) {
                $payload[$path] = $payload[$phpKey];
            }
        }

        return $payload;
    }

    /**
     * @param array<int, mixed> $fields
     * @param array<int, string> $paths
     */
    private function collectFieldPaths(array $fields, string $prefix, array &$paths): void
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
            $paths[] = $path;

            $choices = is_array($field['choices'] ?? null) ? $field['choices'] : [];
            foreach ($choices as $choice) {
                if (!is_array($choice)) {
                    continue;
                }

                $choiceValue = trim((string) ($choice['value'] ?? ''));
                if ($choiceValue === '') {
                    continue;
                }

                $nested = is_array($choice['fields'] ?? null) ? $choice['fields'] : [];
                if ($nested === []) {
                    continue;
                }

                $this->collectFieldPaths($nested, $path . '.' . $choiceValue, $paths);
            }
        }
    }
}
