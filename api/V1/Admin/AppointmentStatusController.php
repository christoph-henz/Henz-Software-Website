<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\BaseApiController;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\ClientFieldEncryptionService;
use App\Services\BookingStatusManager;
use App\Services\EmailAutomationService;
use App\Core\Support\PermissionBits;
use DateTimeImmutable;
use DateTimeZone;

final class AppointmentStatusController extends BaseApiController
{
    private const MANAGE_BOOKINGS_MASK = 2;

    /**
     * PATCH /v1/admin/appointments/{id}/status
     *
     * Update appointment status with transition validation.
     * Supports both normal transitions and admin reverts (if permitted).
     */
    public function updateStatus(Request $request): Response
    {
        if (!$this->canManageBookings($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['required'],
            ]);
        }

        $booking = $this->fetchBooking($id);
        if ($booking === null) {
            return $this->fail('Booking not found', 404);
        }

        $data = $request->all();
        $hasStatus = array_key_exists('status', $data);
        $hasPaymentStatus = array_key_exists('payment_status', $data);

        if (!$hasStatus && !$hasPaymentStatus) {
            return $this->fail('Validation failed', 422, [
                'status' => ['required'],
                'payment_status' => ['required'],
            ]);
        }

        $currentStatus = (string) ($booking['status'] ?? 'pending');
        $currentPaymentStatus = (string) ($booking['payment_status'] ?? 'pending');
        $scheduledAt = (string) ($booking['scheduled_at'] ?? '');
        $nowString = date('Y-m-d H:i:s');
        $userId = $this->getUserId($request);
        $revertReason = isset($data['revert_reason']) ? trim((string) $data['revert_reason']) : null;

        if ($hasPaymentStatus) {
            $paymentStatus = trim((string) $data['payment_status']);
            if ($paymentStatus !== 'paid') {
                return $this->fail('Validation failed', 422, [
                    'payment_status' => ['only_paid_supported'],
                ]);
            }

            if ($currentPaymentStatus === 'paid') {
                return $this->ok([
                    'booking' => $this->formatBooking($booking),
                    'transition' => [
                        'from' => $currentStatus,
                        'to' => $currentStatus,
                        'type' => 'payment',
                        'cancellation_type' => null,
                        'payment_from' => $currentPaymentStatus,
                        'payment_to' => $currentPaymentStatus,
                    ],
                ]);
            }

            if (!in_array($currentStatus, ['pending', 'confirmed', 'no_show'], true)) {
                return $this->fail('Invalid status transition', 409, [
                    'status' => ['payment_update_allowed_only_for_pending_confirmed_or_no_show'],
                ]);
            }

            db('bookings')
                ->where('id', $id)
                ->update([
                    'payment_status' => 'paid',
                ]);

            $updatedBooking = $this->fetchBooking($id);
            $paymentMailClientId = (int) (($updatedBooking['client_id'] ?? $booking['client_id']) ?? 0);
            app(EmailAutomationService::class)->dispatch('booking.payment_received', [
                'booking_id' => $id,
                'client_id' => $paymentMailClientId,
            ]);

            return $this->ok([
                'booking' => $this->formatBooking($updatedBooking ?? $booking),
                'transition' => [
                    'from' => $currentStatus,
                    'to' => (string) (($updatedBooking['status'] ?? $currentStatus)),
                    'type' => 'payment',
                    'cancellation_type' => null,
                    'payment_from' => $currentPaymentStatus,
                    'payment_to' => 'paid',
                ],
            ]);
        }

        $newStatus = trim((string) $data['status']);
        $allowedStatuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no_show'];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            return $this->fail('Validation failed', 422, [
                'status' => ['invalid_status_value'],
            ]);
        }

        if ($currentStatus === $newStatus) {
            return $this->ok([
                'booking' => $this->formatBooking($booking),
                'transition' => [
                    'from' => $currentStatus,
                    'to' => $newStatus,
                    'type' => 'transition',
                    'cancellation_type' => null,
                ],
            ]);
        }

        $cancellationType = null;
        $transitionType = 'transition';
        $effectiveNewStatus = $newStatus;
        $updateData = [
            'status' => $newStatus,
            'status_changed_at' => $nowString,
            'status_changed_by_user_id' => $userId,
        ];

        if ($newStatus === 'confirmed') {
            if ($currentStatus !== 'pending') {
                return $this->fail('Invalid status transition', 409, [
                    'status' => ['confirmed_allowed_only_from_pending'],
                ]);
            }

            if (!$this->isFreeServiceBooking($booking)) {
                return $this->fail('Invalid status transition', 409, [
                    'status' => ['confirmed_directly_allowed_only_for_free_service'],
                ]);
            }

            $updateData['payment_status'] = 'paid';
            $updateData['cancelled_at'] = null;
            $updateData['cancellation_timing'] = null;
            $updateData['cancellation_reason'] = null;
        } elseif ($newStatus === 'completed') {
            if ($currentStatus !== 'confirmed') {
                return $this->fail('Invalid status transition', 409, [
                    'status' => ['completed_allowed_only_from_confirmed'],
                ]);
            }

            if (!$this->hasScheduledAtPassed($scheduledAt)) {
                return $this->fail('Invalid status transition', 409, [
                    'status' => ['completed_allowed_only_after_scheduled_at'],
                ]);
            }

            $updateData['cancelled_at'] = null;
            $updateData['cancellation_timing'] = null;
            $updateData['cancellation_reason'] = null;
        } elseif ($newStatus === 'cancelled') {
            if (!in_array($currentStatus, ['pending', 'confirmed'], true)) {
                return $this->fail('Invalid status transition', 409, [
                    'status' => ['cancellation_allowed_only_until_confirmed'],
                ]);
            }

            $cutoffHours = max(1, $this->readIntAvailabilityRule('cancellation_hours_notice', 48));
            $cancellationType = BookingStatusManager::determineCancellationType($scheduledAt, $cutoffHours);
            $updateData['cancellation_timing'] = $cancellationType;
            $updateData['cancelled_at'] = $nowString;
            if (array_key_exists('cancellation_reason', $data)) {
                $reason = trim((string) ($data['cancellation_reason'] ?? ''));
                $updateData['cancellation_reason'] = $reason !== '' ? $reason : null;
            }
        } elseif ($newStatus === 'no_show') {
            if (!in_array($currentStatus, ['confirmed', 'completed', 'cancelled'], true)) {
                return $this->fail('Invalid status transition', 409, [
                    'status' => ['no_show_allowed_only_from_confirmed_completed_or_cancelled'],
                ]);
            }

            $reason = trim((string) ($data['no_show_reason'] ?? ''));
            $updateData['payment_status'] = $currentPaymentStatus === 'paid' ? 'paid' : 'pending';
            $updateData['cancellation_timing'] = null;
            $updateData['cancelled_at'] = null;
            $updateData['cancellation_reason'] = $reason !== '' ? $reason : null;
        } elseif ($newStatus === 'pending') {
            if (!BookingStatusManager::canRevertStatus($this->getUserRoleMask($request))) {
                return $this->fail('Forbidden', 403, [
                    'permission' => ['revert_booking_status_required'],
                ]);
            }

            if (!in_array($currentStatus, ['confirmed', 'cancelled', 'no_show'], true)) {
                return $this->fail('Invalid status transition', 409, [
                    'status' => ['reset_allowed_only_from_confirmed_cancelled_or_no_show'],
                ]);
            }

            if ($currentStatus !== 'no_show' && $this->hasScheduledAtPassed($scheduledAt)) {
                return $this->fail('Invalid status transition', 409, [
                    'status' => ['reset_not_allowed_after_scheduled_at'],
                ]);
            }

            $transitionType = 'revert';
            if ($this->isFreeServiceBooking($booking)) {
                $updateData['status'] = 'confirmed';
                $updateData['payment_status'] = 'paid';
                $effectiveNewStatus = 'confirmed';
            } else {
                $updateData['payment_status'] = 'pending';
            }
            $updateData['cancellation_timing'] = null;
            $updateData['cancelled_at'] = null;
            $updateData['cancellation_reason'] = null;
        }

        db('bookings')
            ->where('id', $id)
            ->update($updateData);

        db('booking_status_audit_log')->insert([
            'booking_id' => $id,
            'old_status' => $currentStatus,
            'new_status' => $effectiveNewStatus,
            'changed_by_user_id' => $userId,
            'revert_reason' => $transitionType === 'revert' ? ($revertReason !== '' ? $revertReason : 'manual_reset') : null,
            'ip_address' => $this->resolveIpAddress($request),
        ]);

        $updatedBooking = $this->fetchBooking($id);
        if ($newStatus === 'cancelled') {
            app(EmailAutomationService::class)->dispatch('booking.canceled', [
                'booking_id' => $id,
                'client_id' => (int) (($updatedBooking['client_id'] ?? $booking['client_id']) ?? 0),
            ]);
        } elseif ($newStatus === 'no_show') {
            app(EmailAutomationService::class)->dispatch('booking.no_show', [
                'booking_id' => $id,
                'client_id' => (int) (($updatedBooking['client_id'] ?? $booking['client_id']) ?? 0),
            ]);
        }

        return $this->ok([
            'booking' => $this->formatBooking($updatedBooking ?? $booking),
            'transition' => [
                'from' => $currentStatus,
                'to' => $effectiveNewStatus,
                'type' => $transitionType,
                'cancellation_type' => $cancellationType,
            ],
        ]);
    }

    /** @param array<string, mixed> $booking */
    private function isFreeServiceBooking(array $booking): bool
    {
        return isset($booking['service_price']) && (float) $booking['service_price'] <= 0.0;
    }

    /**
     * GET /v1/admin/bookings/{id}/status-audit
     *
     * Retrieve status change audit log for a booking.
     */
    public function auditLog(Request $request): Response
    {
        if (!$this->canManageBookings($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['required'],
            ]);
        }

        $booking = $this->fetchBooking($id);
        if ($booking === null) {
            return $this->fail('Booking not found', 404);
        }

        $logs = db('booking_status_audit_log')
            ->where('booking_id', $id)
            ->orderBy('changed_at', 'asc')
            ->get();

        $formatted = array_map(fn (array $log): array => [
            'id' => (int) ($log['id'] ?? 0),
            'old_status' => (string) ($log['old_status'] ?? ''),
            'new_status' => (string) ($log['new_status'] ?? ''),
            'changed_by_user_id' => $log['changed_by_user_id'] ? (int) $log['changed_by_user_id'] : null,
            'changed_at' => (string) ($log['changed_at'] ?? ''),
            'revert_reason' => $log['revert_reason'] ? (string) $log['revert_reason'] : null,
            'ip_address' => (string) ($log['ip_address'] ?? ''),
        ], $logs);

        return $this->ok([
            'audit_log' => $formatted,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchBooking(int $id): ?array
    {
        return db('bookings b')
            ->join('clients c', 'c.id', '=', 'b.client_id')
            ->join('services s', 's.id', '=', 'b.service_id')
            ->select([
                'b.id',
                'b.client_id',
                'b.service_id',
                'b.scheduled_at',
                'b.status',
                'b.payment_status',
                'b.cancellation_timing',
                'b.status_changed_at',
                'b.cancelled_at',
                'b.created_at',
                'b.updated_at',
                'b.notes',
                'b.cancellation_reason',
                'b.package_purchase_id',
                'b.is_package_booking',
                'b.package_session_no',
                'b.package_session_state',
                'c.first_name',
                'c.last_name',
                'c.email',
                'c.phone',
                's.name AS service_name',
                's.slug AS service_slug',
                's.price AS service_price',
                's.duration_minutes AS service_duration_minutes',
            ])
            ->where('b.id', $id)
            ->first();
    }

    /**
     * @param array<string, mixed> $booking
     * @return array<string, mixed>
     */
    private function formatBooking(array $booking): array
    {
        $firstName = trim((string) ($booking['first_name'] ?? ''));
        $lastName = trim((string) ($booking['last_name'] ?? ''));

        $booking = app(ClientFieldEncryptionService::class)->decryptClientRow($booking);
        return [
            'id' => (int) ($booking['id'] ?? 0),
            'client_id' => (int) ($booking['client_id'] ?? 0),
            'service_id' => (int) ($booking['service_id'] ?? 0),
            'scheduled_at' => (string) ($booking['scheduled_at'] ?? ''),
            'status' => (string) ($booking['status'] ?? 'pending'),
            'payment_status' => (string) ($booking['payment_status'] ?? 'pending'),
            'notes' => $booking['notes'] ?? null,
            'cancellation_reason' => $booking['cancellation_reason'] ?? null,
            'cancellation_timing' => $booking['cancellation_timing'] ? (string) $booking['cancellation_timing'] : null,
            'package_purchase_id' => isset($booking['package_purchase_id']) && $booking['package_purchase_id'] !== null ? (int) $booking['package_purchase_id'] : null,
            'is_package_booking' => (bool) ($booking['is_package_booking'] ?? false),
            'package_session_no' => isset($booking['package_session_no']) && $booking['package_session_no'] !== null ? (int) $booking['package_session_no'] : null,
            'package_session_state' => isset($booking['package_session_state']) ? (string) $booking['package_session_state'] : null,
            'status_changed_at' => $booking['status_changed_at'] ? (string) $booking['status_changed_at'] : null,
            'cancelled_at' => $booking['cancelled_at'] ? (string) $booking['cancelled_at'] : null,
            'created_at' => (string) ($booking['created_at'] ?? ''),
            'updated_at' => (string) ($booking['updated_at'] ?? ''),
            'client' => [
                'name' => trim($firstName . ' ' . $lastName),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => (string) ($booking['email'] ?? ''),
                'phone' => (string) ($booking['phone'] ?? ''),
            ],
            'service' => [
                'name' => (string) ($booking['service_name'] ?? ''),
                'slug' => (string) ($booking['service_slug'] ?? ''),
                'price' => isset($booking['service_price']) ? (float) $booking['service_price'] : 0.0,
                'duration_minutes' => isset($booking['service_duration_minutes']) ? (int) $booking['service_duration_minutes'] : 0,
            ],
        ];
    }

    private function canManageBookings(Request $request): bool
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = $session[$sessionKey] ?? null;

        if (!is_array($adminUser)) {
            return false;
        }

        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $requiredMask = PermissionBits::resolve('manage_bookings', self::MANAGE_BOOKINGS_MASK);

        return ($roleMask & $requiredMask) !== 0;
    }

    private function getUserRoleMask(Request $request): int
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = $session[$sessionKey] ?? null;

        if (!is_array($adminUser)) {
            return 0;
        }

        return (int) ($adminUser['role_mask'] ?? 0);
    }

    private function getUserId(Request $request): ?int
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = $session[$sessionKey] ?? null;

        if (!is_array($adminUser)) {
            return null;
        }

        $id = $adminUser['id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    private function resolveIpAddress(Request $request): string
    {
        $forwardedFor = trim((string) $request->header('x-forwarded-for', ''));
        if ($forwardedFor !== '') {
            $parts = explode(',', $forwardedFor);
            $candidate = trim((string) ($parts[0] ?? ''));
            if ($candidate !== '') {
                return substr($candidate, 0, 45);
            }
        }

        $realIp = trim((string) $request->header('x-real-ip', ''));
        if ($realIp !== '') {
            return substr($realIp, 0, 45);
        }

        return '0.0.0.0';
    }

    private function readIntSetting(string $key, int $default): int
    {
        $row = db('settings')
            ->where('`key`', $key)
            ->select(['value'])
            ->first();

        if (!is_array($row)) {
            return $default;
        }

        $raw = trim((string) ($row['value'] ?? ''));
        if ($raw === '' || !is_numeric($raw)) {
            return $default;
        }

        return (int) $raw;
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

    private function hasScheduledAtPassed(string $scheduledAt): bool
    {
        $timezone = new DateTimeZone('Europe/Berlin');
        $scheduled = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $scheduledAt, $timezone);
        if (!$scheduled instanceof DateTimeImmutable) {
            return true;
        }

        $now = new DateTimeImmutable('now', $timezone);
        return $scheduled <= $now;
    }
}
