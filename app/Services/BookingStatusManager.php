<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\PermissionBits;

/**
 * BookingStatusManager
 *
 * Manages booking status transitions with validation and audit logging.
 * Implements the following state machine:
 *
 *   pending → paid (payment status change) → confirmed → completed
 *   ↑                                                        ↓
 *   └────────────────── cancelled (anytime before completed) ──┘
 *
 * Cancellation types:
 *   - early: cancelled before cutoff time (full refund possible)
 *   - late: cancelled after cutoff time (refund not guaranteed, payment may be retained)
 *
 * Reverts (admin-only):
 *   - Allowed: confirmed → paid, paid → pending, completed → confirmed
 *   - Blocked: Cannot revert from cancelled (terminal state)
 */
final class BookingStatusManager
{
    /**
     * Define allowed transitions
     *
     * @return array<string, array<string>>
     */
    public static function getAllowedTransitions(): array
    {
        return [
            'pending' => ['paid', 'cancelled'],
            'paid' => ['confirmed', 'cancelled'],
            'confirmed' => ['completed', 'cancelled', 'no_show'],
            'completed' => [],
            'cancelled' => ['no_show'],
            'no_show' => [],
        ];
    }

    /**
     * Check if a transition is allowed (forward flow, not admin revert)
     */
    public static function isTransitionAllowed(string $fromStatus, string $toStatus): bool
    {
        $allowed = self::getAllowedTransitions();

        return isset($allowed[$fromStatus]) && in_array($toStatus, $allowed[$fromStatus], true);
    }

    /**
     * Check if user can revert a booking status (admin-only permission check)
     *
     * @param int $userRoleMask Admin user's role mask with permission bits
     */
    public static function canRevertStatus(int $userRoleMask): bool
    {
        $requiredMask = PermissionBits::resolve('revert_booking_status', 4);

        return ($userRoleMask & $requiredMask) !== 0;
    }

    /**
     * Check if a revert transition is allowed (admin-only)
     * Allowed reverts:
     *   - confirmed → paid
     *   - confirmed → pending
     *   - paid → pending
     *   - completed → confirmed
     *   - completed → paid
     *   - completed → pending
     *
     * Not allowed:
     *   - Any transition FROM cancelled (terminal)
     *   - Any transition TO a status < current (except predefined reverts above)
     */
    public static function isRevertAllowed(string $fromStatus, string $toStatus): bool
    {
        // Cannot revert from cancelled (terminal state)
        if ($fromStatus === 'cancelled' || $fromStatus === 'no_show') {
            return false;
        }

        // Define allowed reverts
        $allowedReverts = [
            'confirmed' => ['paid', 'pending'],
            'paid' => ['pending'],
            'completed' => ['confirmed', 'paid', 'pending'],
        ];

        return isset($allowedReverts[$fromStatus]) && in_array($toStatus, $allowedReverts[$fromStatus], true);
    }

    /**
     * Determine cancellation type based on scheduled time and current time
     *
     * @param string $scheduledAt Booking scheduled_at in 'Y-m-d H:i:s' format
     * @param int $cancellationCutoffHours Hours before scheduled time to classify as 'late'
     */
    public static function determineCancellationType(string $scheduledAt, int $cancellationCutoffHours = 24): string
    {
        $scheduledTime = strtotime($scheduledAt);
        if ($scheduledTime === false) {
            return 'late'; // Fallback to late if invalid time
        }

        $nowTime = time();
        $hoursUntilBooking = ($scheduledTime - $nowTime) / 3600;

        return $hoursUntilBooking > $cancellationCutoffHours ? 'early' : 'late';
    }

    /**
     * Get status change description for logging
     */
    public static function getTransitionDescription(string $fromStatus, string $toStatus, ?string $revertReason = null): string
    {
        if ($revertReason) {
            return "Reverted from $fromStatus to $toStatus. Reason: $revertReason";
        }

        return "Status changed from $fromStatus to $toStatus";
    }
}
