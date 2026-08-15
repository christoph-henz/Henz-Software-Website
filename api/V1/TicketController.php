<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Core\Http\Response;
use App\Services\EmailAutomationService;
use Throwable;

final class TicketController extends BaseApiController
{
    /**
     * @param array<string, mixed> $data
     */
    public function storeFromContactForm(array $data): Response
    {
        if (!$this->readAvailabilityRuleBool('tickets_enabled', true)) {
            return $this->fail('Ticketsystem ist derzeit deaktiviert.', 409, [
                'service_type' => ['tickets_disabled'],
            ]);
        }

        $prefix = 'service_type.ticket.';

        $clientNumber = trim((string) ($data[$prefix . 'client_number'] ?? ''));
        $ticketCategory = trim((string) ($data[$prefix . 'ticket_category'] ?? ''));

        $message = $this->firstFilledString([
            (string) ($data[$prefix . 'ticket_category.technical.steps'] ?? ''),
            (string) ($data[$prefix . 'ticket_category.invoice.message'] ?? ''),
            (string) ($data[$prefix . 'ticket_category.other.message'] ?? ''),
        ]);

        $errors = [];
        if ($clientNumber === '') {
            $errors[$prefix . 'client_number'][] = 'required';
        }
        if ($ticketCategory === '') {
            $errors[$prefix . 'ticket_category'][] = 'required';
        }
        if ($message === '') {
            $errors[$prefix . 'ticket_category'][] = 'message_required';
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
        $priority = trim((string) ($data[$prefix . 'ticket_category.technical.ticket_priority'] ?? ''));

        $subject = sprintf('[Ticket:%s] %s', $ticketCategory, $message);
        $payloadJson = json_encode([
            'source' => 'contact_form',
            'service_type' => 'ticket',
            'ticket_category' => $ticketCategory,
            'payload' => $branchData,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ticketId = (int) db('tickets')->insert([
            'client_id' => $clientId,
            'ticket_type' => 'ticket',
            'category' => $ticketCategory !== '' ? $ticketCategory : 'general',
            'priority' => $priority !== '' ? $priority : null,
            'subject' => substr($subject, 0, 255),
            'message' => $message,
            'payload_json' => is_string($payloadJson) ? $payloadJson : null,
            'source' => 'contact_form',
            'status' => 'new',
        ]);

        app(EmailAutomationService::class)->dispatch('request.submitted', [
            'client_id' => $clientId,
            'form_data' => $data,
        ]);

        return $this->ok([
            'request_type' => 'ticket',
            'client_id' => $clientId,
            'ticket_id' => $ticketId,
            'ticket_category' => $ticketCategory,
        ], 201);
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
