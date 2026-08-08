<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Core\Http\Response;
use DateTimeImmutable;
use DateTimeZone;

final class AppointmentController extends BaseApiController
{
    /**
     * @param array<string, mixed> $data
     */
    public function storeFromContactForm(array $data, string $serviceType): Response
    {
        $serviceType = strtolower(trim($serviceType));
        if (!in_array($serviceType, ['contact', 'service'], true)) {
            return $this->fail('Validation failed', 422, [
                'service_type' => ['invalid_option'],
            ]);
        }

        return $serviceType === 'contact'
            ? $this->storeContactInquiry($data)
            : $this->storeServiceInquiry($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function storeContactInquiry(array $data): Response
    {
        $prefix = 'service_type.contact.';

        $firstName = trim((string) ($data[$prefix . 'firstname'] ?? ''));
        $lastName = trim((string) ($data[$prefix . 'lastname'] ?? ''));
        $email = strtolower(trim((string) ($data[$prefix . 'email'] ?? '')));
        $serviceValue = trim((string) ($data[$prefix . 'service'] ?? ''));
        $appointmentDate = trim((string) ($data[$prefix . 'appointment_date'] ?? ''));
        $appointmentTime = trim((string) ($data[$prefix . 'appointment_time'] ?? ''));
        $message = trim((string) ($data[$prefix . 'message'] ?? ''));

        $errors = [];
        if ($firstName === '') {
            $errors[$prefix . 'firstname'][] = 'required';
        }
        if ($lastName === '') {
            $errors[$prefix . 'lastname'][] = 'required';
        }
        if ($email === '') {
            $errors[$prefix . 'email'][] = 'required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[$prefix . 'email'][] = 'email';
        }
        if ($serviceValue === '') {
            $errors[$prefix . 'service'][] = 'required';
        }
        if ($appointmentDate === '') {
            $errors[$prefix . 'appointment_date'][] = 'required';
        }
        if ($appointmentTime === '') {
            $errors[$prefix . 'appointment_time'][] = 'required';
        }
        if ($message === '') {
            $errors[$prefix . 'message'][] = 'required';
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        $service = $this->resolveActiveServiceFromFormValue($serviceValue);

        if (!is_array($service) || (int) ($service['id'] ?? 0) <= 0) {
            return $this->fail('Validation failed', 422, [
                $prefix . 'service' => ['invalid_service'],
            ]);
        }

        $startsAt = $this->parseLocalDateTime($appointmentDate, $appointmentTime);
        if ($startsAt === null) {
            return $this->fail('Validation failed', 422, [
                $prefix . 'appointment_date' => ['invalid_datetime'],
                $prefix . 'appointment_time' => ['invalid_datetime'],
            ]);
        }

        $prospectName = trim($firstName . ' ' . $lastName);
        $notesPayload = [
            'source' => 'contact_form',
            'service_type' => 'contact',
            'message' => $message,
            'prospect' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => $prospectName,
                'email' => $email,
            ],
        ];

        $notesJson = json_encode($notesPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $appointmentId = (int) db('appointments')->insert([
            'client_id' => null,
            'service_id' => (int) ($service['id'] ?? 0),
            'appointment_date' => $startsAt->format('Y-m-d H:i:s'),
            'duration_minutes' => max(30, (int) ($service['duration_minutes'] ?? 60)),
            'status' => 'pending',
            'notes' => is_string($notesJson) ? $notesJson : $message,
            'prospect_name' => $prospectName !== '' ? $prospectName : null,
            'prospect_email' => $email !== '' ? $email : null,
            'origin' => 'contact_form',
        ]);

        return $this->ok([
            'request_type' => 'contact',
            'appointment_id' => $appointmentId,
            'service_slug' => (string) ($service['slug'] ?? ''),
            'appointment_created' => true,
            'client_created' => false,
        ], 201);
    }

    private function resolveActiveServiceFromFormValue(string $serviceValue): ?array
    {
        $serviceValue = trim($serviceValue);
        if ($serviceValue === '') {
            return null;
        }

        if (ctype_digit($serviceValue)) {
            $service = db('services')
                ->where('id', (int) $serviceValue)
                ->where('is_active', true)
                ->select(['id', 'slug', 'duration_minutes'])
                ->first();

            return is_array($service) ? $service : null;
        }

        $service = db('services')
            ->where('slug', $serviceValue)
            ->where('is_active', true)
            ->select(['id', 'slug', 'duration_minutes'])
            ->first();

        return is_array($service) ? $service : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function storeServiceInquiry(array $data): Response
    {
        $prefix = 'service_type.service.';

        $clientNumber = trim((string) ($data[$prefix . 'client_number'] ?? ''));
        $serviceAction = trim((string) ($data[$prefix . 'service_action'] ?? ''));
        $message = $this->firstFilledString([
            (string) ($data[$prefix . 'service_action.update.update_details'] ?? ''),
            (string) ($data[$prefix . 'service_action.cancel.message'] ?? ''),
            (string) ($data[$prefix . 'service_action.other.message'] ?? ''),
        ]);

        $errors = [];
        if ($clientNumber === '') {
            $errors[$prefix . 'client_number'][] = 'required';
        }
        if ($serviceAction === '') {
            $errors[$prefix . 'service_action'][] = 'required';
        }
        if ($message === '') {
            $errors[$prefix . 'service_action'][] = 'details_required';
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        $clientId = $this->resolveClientIdByNumber($clientNumber);
        if ($clientId === null) {
            return $this->fail('Validation failed', 422, [
                $prefix . 'client_number' => ['invalid_client_number'],
            ]);
        }

        $branchData = $this->extractBranch($data, $prefix);
        $priority = trim((string) ($data[$prefix . 'ticket_priority'] ?? ''));
        $ticketId = $this->createTicket(
            $clientId,
            'service',
            $serviceAction,
            $message,
            $branchData,
            $priority !== '' ? $priority : null
        );

        return $this->ok([
            'request_type' => 'service',
            'client_id' => $clientId,
            'ticket_id' => $ticketId,
            'service_action' => $serviceAction,
        ], 201);
    }

    private function parseLocalDateTime(string $date, string $time): ?DateTimeImmutable
    {
        $raw = trim($date) . ' ' . trim($time);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $raw)) {
            return null;
        }

        $timezone = new DateTimeZone((string) config('app.timezone', 'Europe/Berlin'));
        $dateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i', $raw, $timezone);
        if (!$dateTime instanceof DateTimeImmutable) {
            return null;
        }

        return $dateTime;
    }

    private function resolveClientIdByNumber(string $clientNumber): ?int
    {
        $numeric = preg_replace('/\D+/', '', $clientNumber) ?? '';
        $id = (int) $numeric;
        if ($id <= 0) {
            return null;
        }

        $row = db('clients')
            ->where('id', $id)
            ->select(['id'])
            ->first();

        return is_array($row) && (int) ($row['id'] ?? 0) > 0
            ? (int) $row['id']
            : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createTicket(
        int $clientId,
        string $ticketType,
        string $category,
        string $message,
        array $payload,
        ?string $priority = null
    ): int {
        $normalizedCategory = trim($category) !== '' ? trim($category) : 'general';
        $normalizedMessage = trim($message) !== '' ? trim($message) : 'Kontaktanfrage';
        $subject = sprintf('[%s] %s', strtoupper($normalizedCategory), $normalizedMessage);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (int) db('tickets')->insert([
            'client_id' => $clientId,
            'ticket_type' => $ticketType,
            'category' => $normalizedCategory,
            'priority' => $priority,
            'subject' => substr($subject, 0, 255),
            'message' => $normalizedMessage,
            'payload_json' => is_string($payloadJson) ? $payloadJson : null,
            'source' => 'contact_form',
            'status' => 'new',
        ]);
    }

    /**
     * @param array<int, string> $values
     */
    private function firstFilledString(array $values): string
    {
        foreach ($values as $value) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function extractBranch(array $data, string $prefix): array
    {
        $branch = [];

        foreach ($data as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, $prefix)) {
                continue;
            }

            $branchKey = substr($key, strlen($prefix));
            if ($branchKey === '') {
                continue;
            }

            $branch[$branchKey] = $value;
        }

        return $branch;
    }
}
