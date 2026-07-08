<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

final class PaymentReminderScheduler
{
    private ?bool $reminderInvoiceColumnAvailable = null;

    private const REMINDER_STAGE_1 = 1;
    private const REMINDER_STAGE_2 = 2;

    /** @var array<int, string> */
    private const STOP_BOOKING_STATUSES = ['paid', 'confirmed', 'cancelled', 'canceled', 'completed'];

    /** @var array<int, string> */
    private const INACTIVE_INVOICE_STATUSES = ['retracted', 'cancelled', 'canceled', 'void', 'voided'];

    /**
     * @return array{processed:int,sent:int,cancelled:int,failed:int,skipped:int,details:list<array<string,mixed>>}
     */
    public function run(DateTimeImmutable $now): array
    {
        $reminderHoursBefore = max(1, $this->readIntAvailabilityRule('reminder_hours_before', 24));

        $rows = db('bookings')
            ->select(['id', 'client_id', 'status', 'payment_status', 'scheduled_at', 'created_at'])
            ->get();

        if (!is_array($rows) || $rows === []) {
            return [
                'processed' => 0,
                'sent' => 0,
                'cancelled' => 0,
                'failed' => 0,
                'skipped' => 0,
                'details' => [],
            ];
        }

        $processed = 0;
        $sent = 0;
        $cancelled = 0;
        $failed = 0;
        $skipped = 0;
        $details = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $bookingId = (int) ($row['id'] ?? 0);
            $clientId = (int) ($row['client_id'] ?? 0);
            if ($bookingId <= 0 || $clientId <= 0) {
                continue;
            }

            $processed++;

            $status = strtolower(trim((string) ($row['status'] ?? 'pending')));
            $paymentStatus = strtolower(trim((string) ($row['payment_status'] ?? 'pending')));
            $scheduledAt = $this->parseDateTime((string) ($row['scheduled_at'] ?? ''));
            $createdAt = $this->parseDateTime((string) ($row['created_at'] ?? ''));

            if (!$scheduledAt instanceof DateTimeImmutable || !$createdAt instanceof DateTimeImmutable) {
                $skipped++;
                $details[] = [
                    'booking_id' => $bookingId,
                    'action' => 'skip',
                    'reason' => 'invalid_booking_timestamps',
                ];
                continue;
            }

            if ($this->shouldStopByStatus($status, $paymentStatus)) {
                $cancelledCount = $this->cancelPendingReminders($bookingId, 'stopped_due_to_booking_status');
                if ($cancelledCount > 0) {
                    $cancelled += $cancelledCount;
                } else {
                    $skipped++;
                }

                $details[] = [
                    'booking_id' => $bookingId,
                    'action' => 'stop',
                    'cancelled' => $cancelledCount,
                    'status' => $status,
                    'payment_status' => $paymentStatus,
                ];
                continue;
            }

            $activeInvoice = $this->fetchActiveInvoiceForBooking($bookingId);
            if ($activeInvoice === null) {
                $cancelledCount = $this->cancelPendingReminders($bookingId, 'no_active_invoice');
                if ($cancelledCount > 0) {
                    $cancelled += $cancelledCount;
                } else {
                    $skipped++;
                }

                $details[] = [
                    'booking_id' => $bookingId,
                    'action' => 'skip',
                    'reason' => 'no_active_invoice',
                    'cancelled' => $cancelledCount,
                ];
                continue;
            }

            $invoiceId = (int) ($activeInvoice['id'] ?? 0);
            if ($invoiceId <= 0) {
                $skipped++;
                $details[] = [
                    'booking_id' => $bookingId,
                    'action' => 'skip',
                    'reason' => 'invalid_active_invoice',
                ];
                continue;
            }

            if ($scheduledAt <= $now) {
                $cancelledCount = $this->cancelPendingReminders($bookingId, 'appointment_elapsed');
                if ($cancelledCount > 0) {
                    $cancelled += $cancelledCount;
                } else {
                    $skipped++;
                }

                $details[] = [
                    'booking_id' => $bookingId,
                    'action' => 'skip',
                    'reason' => 'appointment_elapsed',
                    'cancelled' => $cancelledCount,
                ];
                continue;
            }

            $scheduledStages = $this->determineReminderStages($createdAt, $scheduledAt, $reminderHoursBefore);
            if ($scheduledStages === []) {
                $cancelledCount = $this->cancelPendingReminders($bookingId, 'no_reminder_window');
                if ($cancelledCount > 0) {
                    $cancelled += $cancelledCount;
                } else {
                    $skipped++;
                }

                $details[] = [
                    'booking_id' => $bookingId,
                    'action' => 'skip',
                    'reason' => 'no_reminder_window',
                    'cancelled' => $cancelledCount,
                ];
                continue;
            }

            foreach ($scheduledStages as $stage) {
                $existing = $this->fetchReminderRow($bookingId, $invoiceId, $stage);
                if (is_array($existing)) {
                    $existingStatus = strtolower(trim((string) ($existing['status'] ?? 'pending')));
                    if (in_array($existingStatus, ['sent', 'failed', 'cancelled', 'canceled'], true)) {
                        $skipped++;
                        continue;
                    }
                }

                $dueAt = $this->dueAt($stage, $createdAt, $scheduledAt, $reminderHoursBefore);
                if (!$dueAt instanceof DateTimeImmutable || $dueAt > $now) {
                    $skipped++;
                    continue;
                }

                $reminderId = $existing !== null
                    ? (int) ($existing['id'] ?? 0)
                    : $this->createPendingReminder($bookingId, $invoiceId, $stage, $dueAt);

                if ($reminderId <= 0) {
                    $failed++;
                    $details[] = [
                        'booking_id' => $bookingId,
                        'stage' => $stage,
                        'action' => 'failed',
                        'reason' => 'reminder_row_create_failed',
                    ];
                    continue;
                }

                $sendResult = $this->sendReminder($stage, $bookingId, $clientId, $invoiceId);
                if ($sendResult['sent'] === true) {
                    $this->markReminderSent($reminderId, $now);
                    $sent++;
                    $details[] = [
                        'booking_id' => $bookingId,
                        'invoice_id' => $invoiceId,
                        'stage' => $stage,
                        'action' => 'sent',
                    ];
                    continue;
                }

                $this->markReminderFailed($reminderId, (string) ($sendResult['reason'] ?? 'send_failed'));
                $failed++;
                $details[] = [
                    'booking_id' => $bookingId,
                    'invoice_id' => $invoiceId,
                    'stage' => $stage,
                    'action' => 'failed',
                    'reason' => (string) ($sendResult['reason'] ?? 'send_failed'),
                ];
            }
        }

        return [
            'processed' => $processed,
            'sent' => $sent,
            'cancelled' => $cancelled,
            'failed' => $failed,
            'skipped' => $skipped,
            'details' => $details,
        ];
    }

    private function shouldStopByStatus(string $status, string $paymentStatus): bool
    {
        if ($paymentStatus === 'paid') {
            return true;
        }

        return in_array($status, self::STOP_BOOKING_STATUSES, true);
    }

    private function cancelPendingReminders(int $bookingId, string $reason): int
    {
        return db('reminders')
            ->where('booking_id', $bookingId)
            ->where('status', 'pending')
            ->update([
                'status' => 'cancelled',
                'error_message' => $reason,
            ]);
    }

    /** @return array<int, int> */
    private function determineReminderStages(
        DateTimeImmutable $createdAt,
        DateTimeImmutable $scheduledAt,
        int $reminderHoursBefore
    ): array
    {
        $distanceSeconds = $scheduledAt->getTimestamp() - $createdAt->getTimestamp();
        if ($distanceSeconds <= 0) {
            return [];
        }

        $leadSeconds = max(1, $reminderHoursBefore) * 3600;
        if ($distanceSeconds <= $leadSeconds) {
            return [];
        }

        $distanceDays = $distanceSeconds / 86400;
        if ($distanceDays > 7) {
            return [self::REMINDER_STAGE_1, self::REMINDER_STAGE_2];
        }

        return [self::REMINDER_STAGE_2];
    }

    private function dueAt(
        int $stage,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $scheduledAt,
        int $reminderHoursBefore
    ): ?DateTimeImmutable
    {
        if ($stage === self::REMINDER_STAGE_1) {
            return $createdAt
                ->setTime(11, 0, 0)
                ->modify('+5 days');
        }

        if ($stage === self::REMINDER_STAGE_2) {
            return $scheduledAt->modify('-' . max(1, $reminderHoursBefore) . ' hours');
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function fetchReminderRow(int $bookingId, int $invoiceId, int $stage): ?array
    {
        $query = db('reminders')
            ->where('reminder_time', $stage)
            ->orderBy('id', 'desc');

        if ($this->isReminderInvoiceColumnAvailable()) {
            $query->where('invoice_id', $invoiceId);
        } else {
            $query->where('booking_id', $bookingId);
        }

        return $query->first();
    }

    private function createPendingReminder(int $bookingId, int $invoiceId, int $stage, DateTimeImmutable $dueAt): int
    {
        $payload = [
            'booking_id' => $bookingId,
            'reminder_time' => $stage,
            'scheduled_for' => $dueAt->format('Y-m-d H:i:s'),
            'status' => 'pending',
            'error_message' => null,
        ];

        if ($this->isReminderInvoiceColumnAvailable()) {
            $payload['invoice_id'] = $invoiceId;
        }

        return db('reminders')->insert($payload);
    }

    /** @return array{sent: bool, reason: string} */
    private function sendReminder(int $stage, int $bookingId, int $clientId, int $invoiceId): array
    {
        $event = $stage === self::REMINDER_STAGE_1
            ? 'booking.payment_reminder_1'
            : 'booking.payment_reminder_2';

        $result = app(EmailAutomationService::class)->dispatch($event, [
            'booking_id' => $bookingId,
            'client_id' => $clientId,
            'invoice_id' => $invoiceId,
        ]);

        $sentCount = (int) ($result['sent'] ?? 0);
        if ($sentCount > 0) {
            return ['sent' => true, 'reason' => ''];
        }

        $reason = 'send_failed';
        $results = $result['results'] ?? null;
        if (is_array($results) && $results !== []) {
            $first = $results[0] ?? null;
            if (is_array($first) && isset($first['reason']) && is_string($first['reason'])) {
                $reason = $first['reason'];
            }
        }

        return ['sent' => false, 'reason' => $reason];
    }

    private function markReminderSent(int $reminderId, DateTimeImmutable $sentAt): void
    {
        db('reminders')
            ->where('id', $reminderId)
            ->update([
                'status' => 'sent',
                'sent_at' => $sentAt->format('Y-m-d H:i:s'),
                'error_message' => null,
            ]);
    }

    private function markReminderFailed(int $reminderId, string $reason): void
    {
        db('reminders')
            ->where('id', $reminderId)
            ->update([
                'status' => 'failed',
                'error_message' => mb_substr($reason, 0, 1000),
            ]);
    }

    private function parseDateTime(string $value): ?DateTimeImmutable
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $trimmed);
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }

        try {
            return new DateTimeImmutable($trimmed);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function fetchActiveInvoiceForBooking(int $bookingId): ?array
    {
        if ($bookingId <= 0) {
            return null;
        }

        $invoice = db('invoices')
            ->where('booking_id', $bookingId)
            ->orderBy('id', 'desc')
            ->first();

        if (!is_array($invoice)) {
            return null;
        }

        $status = strtolower(trim((string) ($invoice['status'] ?? 'created')));
        if (in_array($status, self::INACTIVE_INVOICE_STATUSES, true)) {
            return null;
        }

        return $invoice;
    }

    private function isReminderInvoiceColumnAvailable(): bool
    {
        if ($this->reminderInvoiceColumnAvailable !== null) {
            return $this->reminderInvoiceColumnAvailable;
        }

        try {
            $pdo = app(\App\Core\Database\Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name LIMIT 1'
            );
            $statement->execute([
                'table_name' => 'reminders',
                'column_name' => 'invoice_id',
            ]);
            $this->reminderInvoiceColumnAvailable = $statement->fetchColumn() !== false;
            return $this->reminderInvoiceColumnAvailable;
        } catch (\Throwable) {
            $this->reminderInvoiceColumnAvailable = false;
            return false;
        }
    }

    private function readIntAvailabilityRule(string $ruleKey, int $default): int
    {
        try {
            $row = db('availability_rules')
                ->where('rule_key', $ruleKey)
                ->select(['rule_value'])
                ->first();

            if (is_array($row)) {
                $raw = trim((string) ($row['rule_value'] ?? ''));
                if ($raw !== '' && is_numeric($raw)) {
                    return (int) $raw;
                }
            }
        } catch (\Throwable) {
            // Use hardcoded fallback while availability_rules is not available.
        }

        return $default;
    }
}
