<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\BaseApiController;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\ClientFieldEncryptionService;
use App\Services\EmailAutomationService;
use App\Services\InvoicePdfService;
use App\Services\PackageBookingManager;
use App\Core\Support\PermissionBits;
use DateTimeImmutable;
use DateTimeZone;

final class BookingAdminController extends BaseApiController
{
    private const VIEW_BOOKINGS_MASK = 1;
    private const MANAGE_BOOKINGS_MASK = 2;
    private const BERLIN_TIMEZONE = 'Europe/Berlin';
    private ?bool $invoiceTableAvailable = null;
    private ?bool $invoicePdfColumnAvailable = null;
    private ?bool $clientTimezoneColumnAvailable = null;

    public function index(Request $request): Response
    {
        if (!$this->canViewBookings($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $this->autoCompleteElapsedConfirmedBookings();

        $pagination = $this->resolvePagination($request);
        $sorting = $this->resolveSorting($request);
        $statusFilter = strtolower(trim((string) $request->query('status', '')));
        $searchTerm = strtolower(trim((string) $request->query('q', '')));

        $allowedStatuses = ['pending', 'paid', 'confirmed', 'completed', 'cancelled', 'no_show'];
        if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
            return $this->fail('Validation failed', 422, [
                'status' => ['invalid_status'],
            ]);
        }

        $total = $this->countBookingRows($statusFilter, $searchTerm);
        $rows = $this->fetchBookingRows($pagination, $sorting, $statusFilter, $searchTerm);

        $bookings = array_map(fn (array $row): array => $this->formatBooking($row), $rows);

        return $this->ok([
            'bookings' => $bookings,
            'meta' => [
                'page' => $pagination['page'],
                'offset' => $pagination['offset'],
                'per_page' => $pagination['per_page'],
                'total' => $total,
                'total_pages' => (int) max(1, (int) ceil($total / max(1, $pagination['per_page']))),
                'sort' => $sorting['sort'],
                'direction' => $sorting['direction'],
                'window' => $this->readAvailabilityWindow(),
            ],
        ]);
    }

    public function summary(Request $request): Response
    {
        if (!$this->canViewBookings($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $this->autoCompleteElapsedConfirmedBookings();

        $rows = $this->fetchBookingRows(
            ['page' => 1, 'offset' => 0, 'per_page' => 5000],
            ['sort' => 'scheduled_at', 'direction' => 'asc', 'column' => 'b.scheduled_at'],
            '',
            ''
        );

        $outstandingCount = 0;
        $outstandingTotal = 0.0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $amount = $this->resolveOpenPaymentAmount($row);
            if ($amount <= 0.0) {
                continue;
            }

            $outstandingCount++;
            $outstandingTotal += $amount;
        }

        return $this->ok([
            'summary' => [
                'outstanding_count' => $outstandingCount,
                'outstanding_total' => round($outstandingTotal, 2),
            ],
        ]);
    }

    public function show(Request $request): Response
    {
        if (!$this->canViewBookings($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $this->autoCompleteElapsedConfirmedBookings();

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['required'],
            ]);
        }

        $row = $this->fetchBookingRow($id);
        if ($row === null) {
            return $this->fail('Booking not found', 404);
        }

        return $this->ok([
            'booking' => $this->formatBooking($row),
        ]);
    }

    public function meta(Request $request): Response
    {
        if (!$this->canManageBookings($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        return $this->ok([
            'services' => $this->fetchServiceOptions(),
            'packages' => $this->fetchPackageOptions(),
            'clients' => $this->fetchClientOptions(),
            'window' => $this->readAvailabilityWindow(),
        ]);
    }

    public function store(Request $request): Response
    {
        if (!$this->canManageBookings($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $data = $request->all();
        $serviceId = (int) ($data['service_id'] ?? 0);
        $requestedPackageId = (int) ($data['package_id'] ?? 0);
        $startedAtRaw = trim((string) ($data['started_at'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));

        $errors = [];
        if ($serviceId <= 0) {
            $errors['service_id'] = ['required'];
        }
        if ($startedAtRaw === '') {
            $errors['started_at'] = ['required'];
        }
        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        $timezone = $this->berlinTimezone();
        $startedAt = $this->parseDateTimeInBerlin($startedAtRaw);
        if ($startedAt === null) {
            return $this->fail('Validation failed', 422, [
                'started_at' => ['invalid_datetime'],
            ]);
        }

        $startedAt = $this->roundDateTimeToNextThirtyMinutes($startedAt, $timezone);

        $service = $this->fetchService($serviceId);
        if ($service === null) {
            return $this->fail('Validation failed', 422, [
                'service_id' => ['invalid_service'],
            ]);
        }

        $durationMinutes = (int) ($service['duration_minutes'] ?? 0);
        if ($durationMinutes <= 0) {
            return $this->fail('Validation failed', 422, [
                'service_id' => ['invalid_service_slug_duration'],
            ]);
        }

        $durationMinutes = $this->roundDurationToThirtyMinutes($durationMinutes);
        $isFreeService = (float) ($service['price'] ?? 0.0) <= 0.0;
        $initialStatus = $isFreeService ? 'confirmed' : 'pending';
        $initialPaymentStatus = $isFreeService ? 'paid' : 'pending';

        if ($this->hasSlotConflict($startedAt, $durationMinutes, null)) {
            return $this->fail('Slot conflict', 409, [
                'slot' => ['occupied_or_blocked'],
            ]);
        }

        $clientResolution = $this->resolveClientIdForManualBooking($data);
        if ($clientResolution['error'] !== null) {
            return $this->fail('Validation failed', 422, $clientResolution['error']);
        }

        $clientId = (int) ($clientResolution['client_id'] ?? 0);
        if ($clientId <= 0) {
            return $this->fail('Validation failed', 422, [
                'client' => ['invalid_client_data'],
            ]);
        }

        $selectedPackageRow = null;
        if ($requestedPackageId > 0) {
            $selectedPackageRow = db('service_packages')
                ->where('id', $requestedPackageId)
                ->where('is_active', 1)
                ->select(['id', 'service_id', 'session_count'])
                ->first();

            if (!is_array($selectedPackageRow)) {
                return $this->fail('Validation failed', 422, [
                    'package_id' => ['invalid_package'],
                ]);
            }

            if ((int) ($selectedPackageRow['service_id'] ?? 0) !== $serviceId) {
                return $this->fail('Validation failed', 422, [
                    'package_id' => ['package_service_mismatch'],
                ]);
            }
        }

        $bookingId = db('bookings')->insert([
            'client_id' => $clientId,
            'service_id' => $serviceId,
            'scheduled_at' => $startedAt->format('Y-m-d H:i:s'),
            'status' => $initialStatus,
            'payment_status' => $initialPaymentStatus,
            'notes' => $notes !== '' ? $notes : null,
        ]);

        $packageHint = null;
        $packageManager = app(PackageBookingManager::class);
        $activePurchase = $packageManager->findActivePurchaseForClientService($clientId, $serviceId);
        if ($activePurchase !== null) {
            $packageHint = $packageManager->findActivePackageHint($clientId, $serviceId);
        }

        if ($activePurchase === null && is_array($selectedPackageRow)) {
            $purchaseId = $packageManager->createPurchaseFromPackage($clientId, $selectedPackageRow);
            if ($purchaseId > 0) {
                $activePurchase = db('package_purchases')->where('id', $purchaseId)->first();
                $packageHint = $packageManager->findActivePackageHint($clientId, $serviceId);
            }
        }

        if (is_array($activePurchase)) {
            $packagePurchaseId = (int) ($activePurchase['id'] ?? 0);
            $reserved = $packageManager->reserveSession($packagePurchaseId, $bookingId, null, $this->getUserId($request));
            if ($reserved === null) {
                // Fallback when no quota is available: keep manual booking as normal service.
                db('bookings')
                    ->where('id', $bookingId)
                    ->update([
                        'package_purchase_id' => null,
                        'is_package_booking' => 0,
                        'package_session_no' => null,
                        'package_session_state' => 'none',
                    ]);
            } else {
                // Erste Paketsitzung bleibt offen bis Zahlungseingang; Folgesitzungen
                // werden als bereits durch den Paketkauf bestaetigt behandelt.
                $packageSessionNo = (int) ($reserved['session_no'] ?? 0);
                db('bookings')
                    ->where('id', $bookingId)
                    ->update([
                        'status' => $packageSessionNo > 1 ? 'confirmed' : 'pending',
                    ]);
            }
        }

        if ($isFreeService) {
            db('bookings')
                ->where('id', $bookingId)
                ->update([
                    'status' => 'confirmed',
                    'payment_status' => 'paid',
                ]);
        }

        $row = $this->fetchBookingRow($bookingId);

        return $this->ok([
            'booking' => $this->formatBooking($row ?? []),
            'duration_minutes' => $durationMinutes,
            'package_hint' => $packageHint,
        ], 201);
    }

    public function listBlocked(Request $request): Response
    {
        if (!$this->canViewBookings($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $from = trim((string) $request->query('from', ''));
        $to   = trim((string) $request->query('to', ''));

        $query = db('blocked_times')
            ->orderBy('starts_at', 'asc');

        if ($from !== '') {
            $query->where('ends_at', $from, '>');
        }

        if ($to !== '') {
            $query->where('starts_at', $to, '<');
        }

        $rows = $query->get();

        $slots = array_map(fn (array $row): array => $this->formatBlockedTimeAsSlot($row), is_array($rows) ? $rows : []);

        return $this->ok([
            'blocked_slots' => $slots,
            'count' => count($slots),
        ]);
    }

    public function deleteBlocked(Request $request): Response
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

        $row = db('blocked_times')->where('id', $id)->first();
        if ($row === null) {
            return $this->fail('Blocked time not found', 404);
        }

        db('blocked_times')->where('id', $id)->delete();

        return $this->ok([
            'deleted_id' => $id,
        ]);
    }

    public function reschedule(Request $request): Response
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

        $booking = $this->fetchBookingRow($id);
        if ($booking === null) {
            return $this->fail('Booking not found', 404);
        }

        if ((string) ($booking['status'] ?? '') === 'cancelled') {
            return $this->fail('Invalid booking state', 409, [
                'status' => ['cannot_reschedule_cancelled'],
            ]);
        }

        $data = $request->all();
        $startedAtRaw = trim((string) ($data['started_at'] ?? ''));
        if ($startedAtRaw === '') {
            return $this->fail('Validation failed', 422, [
                'started_at' => ['required'],
            ]);
        }

        $timezone = $this->berlinTimezone();
        $startedAt = $this->parseDateTimeInBerlin($startedAtRaw);
        if ($startedAt === null) {
            return $this->fail('Validation failed', 422, [
                'started_at' => ['invalid_datetime'],
            ]);
        }
        $startedAt = $this->roundDateTimeToNextThirtyMinutes($startedAt, $timezone);

        $durationMinutes = (int) ($booking['service_duration_minutes'] ?? 0);
        if ($durationMinutes <= 0) {
            $serviceSlug = (string) ($booking['service_slug'] ?? '');
            $durationMinutes = $this->deriveDurationFromServiceSlug($serviceSlug) ?? 0;
        }
        if ($durationMinutes <= 0) {
            return $this->fail('Validation failed', 422, [
                'service' => ['invalid_service_slug_duration'],
            ]);
        }

        $durationMinutes = $this->roundDurationToThirtyMinutes($durationMinutes);

        if (array_key_exists('duration_minutes', $data)) {
            $provided = (int) ($data['duration_minutes'] ?? 0);
            $provided = $this->roundDurationToThirtyMinutes($provided);
            if ($provided <= 0 || $provided !== $durationMinutes) {
                return $this->fail('Validation failed', 422, [
                    'duration_minutes' => ['must_match_service_duration'],
                ]);
            }
        }

        if ($this->hasSlotConflict($startedAt, $durationMinutes, $id)) {
            return $this->fail('Slot conflict', 409, [
                'slot' => ['occupied_or_blocked'],
            ]);
        }

        db('bookings')
            ->where('id', $id)
            ->update([
                'scheduled_at' => $startedAt->format('Y-m-d H:i:s'),
            ]);

        $updated = $this->fetchBookingRow($id);

        return $this->ok([
            'booking' => $this->formatBooking($updated ?? $booking),
            'duration_minutes' => $durationMinutes,
        ]);
    }

    public function cancel(Request $request): Response
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

        $booking = $this->fetchBookingRow($id);
        if ($booking === null) {
            return $this->fail('Booking not found', 404);
        }

        $currentStatus = (string) ($booking['status'] ?? 'pending');
        if ($currentStatus === 'cancelled') {
            return $this->ok([
                'booking' => $this->formatBooking($booking),
                'cancellation_timing' => (string) ($booking['cancellation_timing'] ?? ''),
            ]);
        }

        if (!in_array($currentStatus, ['pending', 'confirmed'], true)) {
            return $this->fail('Invalid booking state', 409, [
                'status' => ['cancellation_allowed_only_until_confirmed'],
            ]);
        }

        $data = $request->all();
        $reason = trim((string) ($data['cancellation_reason'] ?? ''));

        $timezone = $this->berlinTimezone();
        $now = new DateTimeImmutable('now', $timezone);
        $scheduledAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($booking['scheduled_at'] ?? ''), $timezone);
        if (!$scheduledAt instanceof DateTimeImmutable) {
            return $this->fail('Validation failed', 422, [
                'booking' => ['invalid_scheduled_at'],
            ]);
        }

        $secondsUntilStart = $scheduledAt->getTimestamp() - $now->getTimestamp();
        $cutoffHours = max(1, $this->readIntAvailabilityRule('cancellation_hours_notice', 48));
        $cancellationTiming = $secondsUntilStart > ($cutoffHours * 3600) ? 'early' : 'late';
        $userId = $this->getUserId($request);
        $nowString = $now->format('Y-m-d H:i:s');

        db('bookings')
            ->where('id', $id)
            ->update([
                'status' => 'cancelled',
                'cancellation_timing' => $cancellationTiming,
                'cancelled_at' => $nowString,
                'cancellation_reason' => $reason !== '' ? $reason : null,
                'status_changed_at' => $nowString,
                'status_changed_by_user_id' => $userId,
            ]);

        db('booking_status_audit_log')->insert([
            'booking_id' => $id,
            'old_status' => $currentStatus,
            'new_status' => 'cancelled',
            'changed_by_user_id' => $userId,
            'revert_reason' => null,
            'ip_address' => $this->resolveIpAddress($request),
        ]);

        $packageAction = null;
        $packagePurchaseRefunded = false;
        $packageManager = app(PackageBookingManager::class);
        if ($cancellationTiming === 'early') {
            $releaseResult = $packageManager->releaseReservedSession($id, 'early_cancellation', $userId, true);
            if (($releaseResult['released'] ?? false) === true) {
                $packageAction = 'released_and_refunded';
                $packagePurchaseRefunded = (bool) ($releaseResult['purchase_refunded'] ?? false);
            }
        } else {
            if ($packageManager->consumeReservedSession($id, 'late_cancellation_no_refund', $userId)) {
                $packageAction = 'consumed_no_refund';
            }
        }

        $updated = $this->fetchBookingRow($id);
        app(EmailAutomationService::class)->dispatch('booking.canceled', [
            'booking_id' => $id,
            'client_id' => (int) (($updated['client_id'] ?? $booking['client_id']) ?? 0),
        ]);

        return $this->ok([
            'booking' => $this->formatBooking($updated ?? $booking),
            'cancellation_timing' => $cancellationTiming,
            'package_action' => $packageAction,
            'package_purchase_refunded' => $packagePurchaseRefunded,
        ]);
    }

    private function canViewBookings(Request $request): bool
    {
        $roleMask = $this->getUserRoleMask($request);
        $viewMask = PermissionBits::resolve('view_bookings', self::VIEW_BOOKINGS_MASK);
        $manageMask = PermissionBits::resolve('manage_bookings', self::MANAGE_BOOKINGS_MASK);

        return ($roleMask & $viewMask) !== 0 || ($roleMask & $manageMask) !== 0;
    }

    private function canManageBookings(Request $request): bool
    {
        $roleMask = $this->getUserRoleMask($request);
        $manageMask = PermissionBits::resolve('manage_bookings', self::MANAGE_BOOKINGS_MASK);

        return ($roleMask & $manageMask) !== 0;
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

    /**
     * @return array{page:int, offset:int, per_page:int}
     */
    private function resolvePagination(Request $request): array
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', 20);

        if ($perPage < 1) {
            $perPage = 20;
        }
        $perPage = min($perPage, 100);

        $offsetRaw = $request->query('offset', null);
        if (is_numeric($offsetRaw)) {
            $offset = max(0, (int) $offsetRaw);
        } else {
            $offset = ($page - 1) * $perPage;
        }

        return [
            'page' => $page,
            'offset' => $offset,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array{sort:string, direction:string, column:string}
     */
    private function resolveSorting(Request $request): array
    {
        $sort = strtolower(trim((string) $request->query('sort', 'scheduled_at')));
        $direction = strtolower(trim((string) $request->query('direction', 'asc')));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';

        $allowedSorts = [
            'id' => 'b.id',
            'scheduled_at' => 'b.scheduled_at',
            'status' => 'b.status',
            'payment_status' => 'b.payment_status',
            'created_at' => 'b.created_at',
            'client_name' => "LOWER(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')))",
            'email' => 'c.email',
            'service_name' => 's.name',
        ];

        if (!isset($allowedSorts[$sort])) {
            $sort = 'scheduled_at';
        }

        return [
            'sort' => $sort,
            'direction' => $direction,
            'column' => $allowedSorts[$sort],
        ];
    }

    private function baseBookingsQuery(): \App\Core\Database\QueryBuilder
    {
        return db('bookings b')
            ->join('clients c', 'c.id', '=', 'b.client_id')
            ->join('services s', 's.id', '=', 'b.service_id')
            ->join('package_purchases pp', 'pp.id', '=', 'b.package_purchase_id', 'LEFT')
            ->select([
                'b.id',
                'b.client_id',
                'b.service_id',
                'b.scheduled_at',
                'b.status',
                'b.payment_status',
                'b.notes',
                'b.cancellation_reason',
                'b.cancellation_timing',
                'b.cancelled_at',
                'b.status_changed_at',
                'b.status_changed_by_user_id',
                'b.package_purchase_id',
                'b.is_package_booking',
                'b.package_session_no',
                'b.package_session_state',
                'b.created_at',
                'b.updated_at',
                'pp.payment_status AS package_purchase_payment_status',
                'pp.purchased_at AS package_purchase_purchased_at',
                'pp.paid_at AS package_purchase_paid_at',
                'pp.package_name_snapshot AS package_purchase_name_snapshot',
                'pp.package_slug_snapshot AS package_purchase_slug_snapshot',
                'pp.package_price_snapshot AS package_purchase_price_snapshot',
                'pp.package_session_count_snapshot AS package_purchase_session_count_snapshot',
                'c.first_name',
                'c.last_name',
                'c.email',
                'c.phone',
                's.name AS service_name',
                's.slug AS service_slug',
                's.price AS service_price',
                's.duration_minutes AS service_duration_minutes',
            ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchBookingRows(array $pagination, array $sorting, string $statusFilter, string $searchTerm): array
    {
        $database = app(Database::class);
        $pdo = $database->connection();

        $sql = $this->buildBookingSql();
        $sql .= $this->buildBookingWhereSql($statusFilter, $searchTerm);
        $sql .= ' ORDER BY ' . $sorting['column'] . ' ' . strtoupper($sorting['direction']);
        $sql .= ' LIMIT :limit OFFSET :offset';

        $stmt = $pdo->prepare($sql);
        foreach ($this->buildBookingParams($statusFilter, $searchTerm) as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', (int) $pagination['per_page'], \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) $pagination['offset'], \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    private function countBookingRows(string $statusFilter, string $searchTerm): int
    {
        $database = app(Database::class);
        $pdo = $database->connection();

        $sql = 'SELECT COUNT(*) AS aggregate FROM bookings b'
            . ' INNER JOIN clients c ON c.id = b.client_id'
            . ' INNER JOIN services s ON s.id = b.service_id';
        $sql .= $this->buildBookingWhereSql($statusFilter, $searchTerm);

        $stmt = $pdo->prepare($sql);
        foreach ($this->buildBookingParams($statusFilter, $searchTerm) as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();

        $value = $stmt->fetchColumn();
        return (int) ($value !== false ? $value : 0);
    }

    private function buildBookingSql(): string
    {
        $sql = 'SELECT '
            . 'b.id, b.client_id, b.service_id, b.scheduled_at, b.status, b.payment_status, b.notes, '
            . 'b.cancellation_reason, b.cancellation_timing, b.cancelled_at, b.status_changed_at, '
            . 'b.status_changed_by_user_id, b.package_purchase_id, b.is_package_booking, '
            . 'b.package_session_no, b.package_session_state, b.created_at, b.updated_at, ';

        if ($this->isInvoiceTableAvailable()) {
            $sql .= 'inv.id AS invoice_id, inv.invoice_number, inv.currency_code AS invoice_currency_code, '
                . 'inv.total_amount AS invoice_total_amount, inv.status AS invoice_status, '
                . 'inv.invoice_date AS invoice_date, inv.due_date AS invoice_due_date, ';
        }

        $sql .= 'pp.payment_status AS package_purchase_payment_status, pp.purchased_at AS package_purchase_purchased_at, '
            . 'pp.paid_at AS package_purchase_paid_at, pp.package_price_snapshot AS package_purchase_price_snapshot, '
            . 'c.first_name, c.last_name, c.email, c.phone, '
            . 's.name AS service_name, s.slug AS service_slug, s.price AS service_price, s.duration_minutes AS service_duration_minutes '
            . 'FROM bookings b '
            . 'INNER JOIN clients c ON c.id = b.client_id '
            . 'INNER JOIN services s ON s.id = b.service_id ';

        if ($this->isInvoiceTableAvailable()) {
            $sql .= 'LEFT JOIN invoices inv ON inv.id = (SELECT MAX(i2.id) FROM invoices i2 WHERE i2.booking_id = b.id) ';
        }

        $sql .= 'LEFT JOIN package_purchases pp ON pp.id = b.package_purchase_id';

        return $sql;
    }

    private function buildBookingWhereSql(string $statusFilter, string $searchTerm): string
    {
        $parts = [];

        if ($statusFilter !== '') {
            $parts[] = 'b.status = :status_filter';
        }

        if ($searchTerm !== '') {
            $parts[] = '(' . implode(' OR ', [
                "LOWER(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) LIKE :search_name",
                'LOWER(c.email) LIKE :search_email',
                'LOWER(s.slug) LIKE :search_slug',
                'LOWER(s.name) LIKE :search_service',
            ]) . ')';
        }

        return $parts !== [] ? ' WHERE ' . implode(' AND ', $parts) : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBookingParams(string $statusFilter, string $searchTerm): array
    {
        $params = [];

        if ($statusFilter !== '') {
            $params['status_filter'] = $statusFilter;
        }

        if ($searchTerm !== '') {
            $searchValue = '%' . $searchTerm . '%';
            $params['search_name'] = $searchValue;
            $params['search_email'] = $searchValue;
            $params['search_slug'] = $searchValue;
            $params['search_service'] = $searchValue;
        }

        return $params;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchBookingRow(int $id): ?array
    {
        return $this->baseBookingsQuery()
            ->where('b.id', $id)
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchService(int $serviceId): ?array
    {
        $row = db('services')
            ->where('id', $serviceId)
            ->where('is_active', 1)
            ->select(['id', 'slug', 'duration_minutes', 'price'])
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatBooking(array $row): array
    {
        $row = app(ClientFieldEncryptionService::class)->decryptClientRow($row);

        $firstName = trim((string) ($row['first_name'] ?? ''));
        $lastName = trim((string) ($row['last_name'] ?? ''));
        $clientName = trim($firstName . ' ' . $lastName);
        $invoice = null;

        if (isset($row['invoice_id']) && (int) ($row['invoice_id'] ?? 0) > 0) {
            $invoice = [
                'id' => (int) ($row['invoice_id'] ?? 0),
                'invoice_number' => (int) ($row['invoice_number'] ?? 0),
                'currency_code' => (string) ($row['invoice_currency_code'] ?? 'EUR'),
                'total_amount' => isset($row['invoice_total_amount']) ? (float) $row['invoice_total_amount'] : 0.0,
                'status' => (string) ($row['invoice_status'] ?? 'created'),
                'invoice_date' => isset($row['invoice_date']) ? (string) $row['invoice_date'] : null,
                'due_date' => isset($row['invoice_due_date']) ? (string) $row['invoice_due_date'] : null,
            ];
        } elseif ((int) ($row['id'] ?? 0) > 0) {
            $invoice = $this->fetchLatestInvoiceSummary((int) $row['id']);
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'client_id' => (int) ($row['client_id'] ?? 0),
            'service_id' => (int) ($row['service_id'] ?? 0),
            'scheduled_at' => (string) ($row['scheduled_at'] ?? ''),
            'status' => (string) ($row['status'] ?? 'pending'),
            'payment_status' => (string) ($row['payment_status'] ?? 'pending'),
            'notes' => $row['notes'] ?? null,
            'cancellation_reason' => $row['cancellation_reason'] ?? null,
            'cancellation_timing' => $row['cancellation_timing'] ?? null,
            'cancelled_at' => $row['cancelled_at'] ?? null,
            'status_changed_at' => $row['status_changed_at'] ?? null,
            'status_changed_by_user_id' => $row['status_changed_by_user_id'] !== null ? (int) $row['status_changed_by_user_id'] : null,
            'package_purchase_id' => isset($row['package_purchase_id']) && $row['package_purchase_id'] !== null ? (int) $row['package_purchase_id'] : null,
            'is_package_booking' => (bool) ($row['is_package_booking'] ?? false),
            'package_session_no' => isset($row['package_session_no']) && $row['package_session_no'] !== null ? (int) $row['package_session_no'] : null,
            'package_session_state' => isset($row['package_session_state']) ? (string) $row['package_session_state'] : null,
            'package_purchase' => isset($row['package_purchase_purchased_at']) || isset($row['package_purchase_payment_status'])
                ? [
                    'id' => isset($row['package_purchase_id']) && $row['package_purchase_id'] !== null ? (int) $row['package_purchase_id'] : null,
                    'name' => isset($row['package_purchase_name_snapshot']) ? (string) $row['package_purchase_name_snapshot'] : null,
                    'slug' => isset($row['package_purchase_slug_snapshot']) ? (string) $row['package_purchase_slug_snapshot'] : null,
                    'payment_status' => isset($row['package_purchase_payment_status']) ? (string) $row['package_purchase_payment_status'] : null,
                    'purchased_at' => isset($row['package_purchase_purchased_at']) ? (string) $row['package_purchase_purchased_at'] : null,
                    'paid_at' => isset($row['package_purchase_paid_at']) ? (string) $row['package_purchase_paid_at'] : null,
                    'price' => isset($row['package_purchase_price_snapshot']) ? (float) $row['package_purchase_price_snapshot'] : 0.0,
                    'session_count' => isset($row['package_purchase_session_count_snapshot']) ? (int) $row['package_purchase_session_count_snapshot'] : null,
                ]
                : null,
            'invoice' => $invoice,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'client' => [
                'name' => $clientName,
                'email' => (string) ($row['email'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
            ],
            'service' => [
                'name' => (string) ($row['service_name'] ?? ''),
                'slug' => (string) ($row['service_slug'] ?? ''),
                'price' => isset($row['service_price']) ? (float) $row['service_price'] : 0.0,
                'duration_minutes' => isset($row['service_duration_minutes']) ? (int) $row['service_duration_minutes'] : 0,
            ],
        ];
    }

    private function resolveOpenPaymentAmount(array $row): float
    {
        $bookingStatus = strtolower(trim((string) ($row['status'] ?? '')));
        if ($bookingStatus === 'confirmed') {
            return 0.0;
        }

        $cancellationTiming = strtolower(trim((string) ($row['cancellation_timing'] ?? '')));
        if ($bookingStatus === 'cancelled' && $cancellationTiming === 'early') {
            return 0.0;
        }

        $invoiceStatus = strtolower(trim((string) ($row['invoice_status'] ?? '')));
        $invoiceTotal = isset($row['invoice_total_amount']) ? (float) $row['invoice_total_amount'] : 0.0;

        if ($invoiceTotal > 0.0 && $invoiceStatus !== '' && !in_array($invoiceStatus, ['paid', 'retracted', 'cancelled'], true)) {
            return $invoiceTotal;
        }

        $paymentStatus = strtolower(trim((string) ($row['payment_status'] ?? 'pending')));
        if ($paymentStatus === 'paid' || $paymentStatus === 'refunded') {
            return 0.0;
        }

        if ((bool) ($row['is_package_booking'] ?? false) && isset($row['package_purchase_price_snapshot'])) {
            return (float) $row['package_purchase_price_snapshot'];
        }

        return isset($row['service_price']) ? (float) $row['service_price'] : 0.0;
    }

    public function createInvoice(Request $request): Response
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

        $booking = $this->fetchBookingRow($id);
        if ($booking === null) {
            return $this->fail('Booking not found', 404);
        }

        if (!$this->canCreateInvoiceForBooking($booking)) {
            return $this->fail('Invoice feature not available', 409, [
                'invoice' => ['payment_automation_disabled'],
            ]);
        }

        if (!$this->isInvoiceTableAvailable()) {
            return $this->fail('Invoice feature not available', 503, [
                'invoice' => ['migration_required'],
            ]);
        }

        if ((string) ($booking['status'] ?? 'pending') === 'cancelled') {
            return $this->fail('Invalid booking state', 409, [
                'status' => ['invoice_not_allowed_for_cancelled_booking'],
            ]);
        }

        $existingInvoice = $this->fetchLatestInvoiceSummary($id);
        $bookingStatus = (string) ($booking['status'] ?? 'pending');
        if ($existingInvoice !== null && $bookingStatus !== 'pending') {
            return $this->fail('Invoice already exists', 409, [
                'invoice' => ['already_exists'],
            ]);
        }

        $data = $request->all();
        $includeDefaultItem = !array_key_exists('include_default_item', $data)
            || filter_var((string) $data['include_default_item'], FILTER_VALIDATE_BOOL) === true;
        $additionalItems = $this->normalizeInvoiceItemsFromRequest($data);
        $discountAmount = isset($data['discount_amount']) && is_numeric($data['discount_amount'])
            ? (float) $data['discount_amount']
            : 0.0;

        $invoiceDate = $this->parseInvoiceDate((string) ($data['invoice_date'] ?? ''));
        if ($invoiceDate === null) {
            return $this->fail('Validation failed', 422, [
                'invoice_date' => ['invalid_date'],
            ]);
        }

        $scheduledAt = $this->parseDateTimeInBerlin((string) ($booking['scheduled_at'] ?? ''));
        if ($scheduledAt === null) {
            return $this->fail('Invalid booking state', 409, [
                'scheduled_at' => ['invalid_booking_datetime'],
            ]);
        }

        $appointmentDate = $scheduledAt->setTime(0, 0, 0);
        $latestAllowedDueDate = $this->previousBusinessDay($appointmentDate);
        $maxDueDaysByAppointment = (int) $invoiceDate->diff($latestAllowedDueDate)->format('%r%a');
        $forceNoDueDate = filter_var((string) ($data['no_due_date'] ?? false), FILTER_VALIDATE_BOOL) === true;
        $cannotSetDueDate = $maxDueDaysByAppointment < 1;

        $defaultDueDays = (int) config('mail.payment.default_due_days', 7);
        $dueDaysWasProvided = isset($data['due_days']) && is_numeric($data['due_days']);
        $dueDate = null;
        if (!$cannotSetDueDate && !$forceNoDueDate) {
            $dueDays = $dueDaysWasProvided
                ? (int) $data['due_days']
                : max(1, min($defaultDueDays, $maxDueDaysByAppointment));

            if ($dueDaysWasProvided && $dueDays > $maxDueDaysByAppointment) {
                return $this->fail('Validation failed', 422, [
                    'due_days' => ['must_not_exceed_previous_business_day_before_appointment'],
                ]);
            }

            $dueDays = max(1, min($dueDays, min(90, $maxDueDaysByAppointment)));
            $dueDate = $invoiceDate->modify('+' . $dueDays . ' days');
        }

        $currency = strtoupper(trim((string) config('mail.payment.currency', 'EUR')));
        if ($currency === '') {
            $currency = 'EUR';
        }

        $invoiceItems = [];
        if ($includeDefaultItem) {
            $isPackageFirstSession = (bool) ($booking['is_package_booking'] ?? false)
                && (int) ($booking['package_session_no'] ?? 0) === 1;

            if ($isPackageFirstSession) {
                $packagePurchaseId = (int) ($booking['package_purchase_id'] ?? 0);
                $packagePurchase = $packagePurchaseId > 0
                    ? db('package_purchases')
                        ->where('id', $packagePurchaseId)
                        ->select(['package_name_snapshot', 'package_session_count_snapshot', 'package_price_snapshot'])
                        ->first()
                    : null;

                $packageName = is_array($packagePurchase) ? $this->resolvePackageDisplayName($packagePurchase) : 'Paket';
                $packageSessionCount = is_array($packagePurchase) ? (int) ($packagePurchase['package_session_count_snapshot'] ?? 0) : 0;
                $packagePrice = is_array($packagePurchase) && isset($packagePurchase['package_price_snapshot'])
                    ? (float) $packagePurchase['package_price_snapshot']
                    : 0.0;

                $description = 'Paket: ' . $packageName;
                $serviceName = trim((string) ($booking['service_name'] ?? ''));
                if ($serviceName !== '') {
                    $description .= ' (' . $serviceName . ')';
                }

                $invoiceItems[] = [
                    'type' => 'package',
                    'description' => $description,
                    'quantity' => 1.0,
                    'unit_price' => $packagePrice,
                ];
            } else {
                $invoiceItems[] = [
                    'type' => 'service',
                    'description' => sprintf(
                        '%s (%s)',
                        (string) ($booking['service_name'] ?? 'Leistung'),
                        (string) ($booking['scheduled_at'] ?? '')
                    ),
                    'quantity' => 1.0,
                    'unit_price' => isset($booking['service_price']) ? (float) $booking['service_price'] : 0.0,
                ];
            }
        }

        foreach ($additionalItems as $item) {
            $invoiceItems[] = [
                'type' => 'additional',
                'description' => (string) $item['description'],
                'quantity' => (float) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
            ];
        }

        if ($discountAmount !== 0.0) {
            $invoiceItems[] = [
                'type' => 'discount',
                'description' => 'Rabatt',
                'quantity' => 1.0,
                'unit_price' => -abs($discountAmount),
            ];
        }

        if ($invoiceItems === []) {
            return $this->fail('Validation failed', 422, [
                'invoice_items' => ['at_least_one_item_required'],
            ]);
        }

        $baseAmount = 0.0;
        $discountTotal = 0.0;
        foreach ($invoiceItems as $item) {
            $lineTotal = ((float) $item['quantity']) * ((float) $item['unit_price']);
            if ((string) ($item['type'] ?? '') === 'discount') {
                $discountTotal += abs($lineTotal);
                continue;
            }

            $baseAmount += $lineTotal;
        }
        $baseAmount = round($baseAmount, 2);
        $discountTotal = round($discountTotal, 2);
        $subTotal = round($baseAmount - $discountTotal, 2);

        if ($subTotal <= 0.0) {
            return $this->fail('Validation failed', 422, [
                'total_amount' => ['must_be_positive'],
            ]);
        }

        $userId = $this->getUserId($request);
        $invoiceId = 0;

        $database = app(Database::class);
        try {
            $invoiceId = (int) $database->transaction(function () use (
                $database,
                $id,
                $booking,
                $invoiceDate,
                $dueDate,
                $currency,
                $invoiceItems,
                $baseAmount,
                $discountTotal,
                $subTotal,
                $userId
            ): int {
                $alreadyExisting = $this->fetchLatestInvoiceSummary($id);
                if ($alreadyExisting !== null) {
                    db('invoices')
                        ->where('booking_id', $id)
                        ->update([
                            'status' => 'retracted',
                        ]);
                }

                $nextInvoiceNumber = $this->reserveNextInvoiceNumber($database);

                $createdAt = (new DateTimeImmutable('now', $this->berlinTimezone()))->format('Y-m-d H:i:s');
                $invoiceId = (int) db('invoices')->insert([
                    'invoice_number' => $nextInvoiceNumber,
                    'client_id' => (int) ($booking['client_id'] ?? 0),
                    'booking_id' => $id,
                    'currency_code' => $currency,
                    'sub_total_amount' => $baseAmount,
                    'discount_amount' => $discountTotal,
                    'total_amount' => $subTotal,
                    'status' => 'created',
                    'invoice_date' => $invoiceDate->format('Y-m-d'),
                    'due_date' => $dueDate instanceof DateTimeImmutable ? $dueDate->format('Y-m-d') : null,
                    'created_by_user_id' => $userId,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                $sortOrder = 0;
                foreach ($invoiceItems as $item) {
                    $sortOrder++;
                    $quantity = (float) ($item['quantity'] ?? 1.0);
                    $unitPrice = (float) ($item['unit_price'] ?? 0.0);
                    db('invoice_items')->insert([
                        'invoice_id' => $invoiceId,
                        'item_type' => (string) ($item['type'] ?? 'additional'),
                        'description' => (string) ($item['description'] ?? ''),
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'line_total' => round($quantity * $unitPrice, 2),
                        'sort_order' => $sortOrder,
                    ]);
                }

                return $invoiceId;
            });
        } catch (\RuntimeException $exception) {
            throw $exception;
        }

        $mailDispatch = app(EmailAutomationService::class)->dispatch('invoice.created', [
            'invoice_id' => $invoiceId,
            'booking_id' => $id,
            'client_id' => (int) ($booking['client_id'] ?? 0),
        ]);

        $invoiceUpdate = [];

        if ((int) ($mailDispatch['sent'] ?? 0) > 0) {
            $invoiceUpdate['status'] = 'sent';
            $invoiceUpdate['sent_at'] = date('Y-m-d H:i:s');
        }

        if ($this->isInvoicePdfColumnAvailable()) {
            try {
                $pdfMeta = app(InvoicePdfService::class)->generateForInvoice($invoiceId);
                $invoiceUpdate['pdf_path'] = (string) ($pdfMeta['relative_path'] ?? '');
                $invoiceUpdate['pdf_mime_type'] = (string) ($pdfMeta['mime_type'] ?? 'application/pdf');
                $invoiceUpdate['pdf_file_size'] = (int) ($pdfMeta['file_size'] ?? 0);
                $invoiceUpdate['pdf_sha256'] = (string) ($pdfMeta['sha256'] ?? '');
                $invoiceUpdate['pdf_generated_at'] = (string) ($pdfMeta['generated_at'] ?? date('Y-m-d H:i:s'));
            } catch (\Throwable) {
                // Keep invoice flow resilient even when PDF generation fails.
            }
        }

        if ($invoiceUpdate !== []) {
            db('invoices')->where('id', $invoiceId)->update($invoiceUpdate);
        }

        $updatedBooking = $this->fetchBookingRow($id);
        $invoiceRow = $this->fetchInvoiceRow($invoiceId);

        return $this->ok([
            'booking' => $this->formatBooking($updatedBooking ?? $booking),
            'invoice' => $invoiceRow,
            'email_dispatch' => $mailDispatch,
        ], 201);
    }

    private function isPaymentAutomationEnabled(): bool
    {
        return filter_var((string) config('mail.payment.automation_enabled', false), FILTER_VALIDATE_BOOL) === true;
    }

    private function resolvePackageDisplayName(array $packagePurchase): string
    {
        $name = trim((string) ($packagePurchase['package_name_snapshot'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $slug = trim((string) ($packagePurchase['package_slug_snapshot'] ?? ''));
        if ($slug !== '') {
            $label = str_replace(['-', '_'], ' ', strtolower($slug));
            $label = preg_replace('/\s+/', ' ', $label) ?? $label;
            $label = trim($label);
            if ($label !== '') {
                return ucwords($label);
            }
        }

        return 'Paket';
    }

    /** @param array<string, mixed> $booking */
    private function canCreateInvoiceForBooking(array $booking): bool
    {
        if ($this->isPaymentAutomationEnabled()) {
            return true;
        }

        return (bool) ($booking['is_package_booking'] ?? false)
            && (int) ($booking['package_session_no'] ?? 0) === 1;
    }

    /** @return array<string, mixed>|null */
    private function fetchInvoiceRow(int $invoiceId): ?array
    {
        if (!$this->isInvoiceTableAvailable()) {
            return null;
        }

        $row = db('invoices')
            ->where('id', $invoiceId)
            ->first();

        if (!is_array($row)) {
            return null;
        }

        $pdfPath = trim((string) ($row['pdf_path'] ?? ''));

        return [
            'id' => (int) ($row['id'] ?? 0),
            'invoice_number' => (int) ($row['invoice_number'] ?? 0),
            'client_id' => (int) ($row['client_id'] ?? 0),
            'booking_id' => (int) ($row['booking_id'] ?? 0),
            'currency_code' => (string) ($row['currency_code'] ?? 'EUR'),
            'sub_total_amount' => isset($row['sub_total_amount']) ? (float) $row['sub_total_amount'] : 0.0,
            'discount_amount' => isset($row['discount_amount']) ? (float) $row['discount_amount'] : 0.0,
            'total_amount' => isset($row['total_amount']) ? (float) $row['total_amount'] : 0.0,
            'status' => (string) ($row['status'] ?? 'created'),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'due_date' => (string) ($row['due_date'] ?? ''),
            'sent_at' => isset($row['sent_at']) ? (string) $row['sent_at'] : null,
            'pdf_available' => $pdfPath !== '',
            'pdf_generated_at' => isset($row['pdf_generated_at']) ? (string) $row['pdf_generated_at'] : null,
            'items' => $this->fetchInvoiceItems($invoiceId),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchInvoiceItems(int $invoiceId): array
    {
        if (!$this->isInvoiceTableAvailable()) {
            return [];
        }

        $rows = db('invoice_items')
            ->where('invoice_id', $invoiceId)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'item_type' => (string) ($row['item_type'] ?? 'additional'),
                'description' => (string) ($row['description'] ?? ''),
                'quantity' => isset($row['quantity']) ? (float) $row['quantity'] : 1.0,
                'unit_price' => isset($row['unit_price']) ? (float) $row['unit_price'] : 0.0,
                'line_total' => isset($row['line_total']) ? (float) $row['line_total'] : 0.0,
            ];
        }, is_array($rows) ? $rows : []);
    }

    private function reserveNextInvoiceNumber(Database $database): int
    {
        $pdo = $database->connection();
        $statement = $pdo->query('SELECT next_invoice_number FROM invoice_number_sequences WHERE id = 1 FOR UPDATE');
        $nextInvoiceNumber = (int) ($statement !== false ? $statement->fetchColumn() : 0);

        if ($nextInvoiceNumber <= 0) {
            $bootstrapStatement = $pdo->query('SELECT COALESCE(MAX(invoice_number), 20260000) + 1 FROM invoices');
            $nextInvoiceNumber = (int) ($bootstrapStatement !== false ? $bootstrapStatement->fetchColumn() : 20260001);
            if ($nextInvoiceNumber <= 0) {
                $nextInvoiceNumber = 20260001;
            }

            db('invoice_number_sequences')->insert([
                'id' => 1,
                'next_invoice_number' => $nextInvoiceNumber + 1,
            ]);

            return $nextInvoiceNumber;
        }

        db('invoice_number_sequences')
            ->where('id', 1)
            ->update([
                'next_invoice_number' => $nextInvoiceNumber + 1,
            ]);

        return $nextInvoiceNumber;
    }

    /** @return array<string, mixed>|null */
    private function fetchLatestInvoiceSummary(int $bookingId): ?array
    {
        if (!$this->isInvoiceTableAvailable()) {
            return null;
        }

        $row = db('invoices')
            ->where('booking_id', $bookingId)
            ->orderBy('id', 'desc')
            ->first();

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'invoice_number' => (int) ($row['invoice_number'] ?? 0),
            'currency_code' => (string) ($row['currency_code'] ?? 'EUR'),
            'total_amount' => isset($row['total_amount']) ? (float) $row['total_amount'] : 0.0,
            'status' => (string) ($row['status'] ?? 'created'),
            'invoice_date' => isset($row['invoice_date']) ? (string) $row['invoice_date'] : null,
            'due_date' => isset($row['due_date']) ? (string) $row['due_date'] : null,
        ];
    }

    /** @return list<array{description:string,quantity:float,unit_price:float}> */
    private function normalizeInvoiceItemsFromRequest(array $data): array
    {
        $rawItems = $data['additional_items'] ?? [];
        if (!is_array($rawItems)) {
            return [];
        }

        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }

            $description = trim((string) ($rawItem['description'] ?? ''));
            $quantity = isset($rawItem['quantity']) && is_numeric($rawItem['quantity'])
                ? (float) $rawItem['quantity']
                : 1.0;
            $unitPrice = isset($rawItem['unit_price']) && is_numeric($rawItem['unit_price'])
                ? (float) $rawItem['unit_price']
                : 0.0;

            if ($description === '' || $quantity <= 0 || $unitPrice === 0.0) {
                continue;
            }

            $items[] = [
                'description' => $description,
                'quantity' => round($quantity, 2),
                'unit_price' => round($unitPrice, 2),
            ];
        }

        return $items;
    }

    private function parseInvoiceDate(string $value): ?DateTimeImmutable
    {
        $trimmed = trim($value);
        $timezone = $this->berlinTimezone();
        if ($trimmed === '') {
            return new DateTimeImmutable('today', $timezone);
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $trimmed, $timezone);
        return $parsed instanceof DateTimeImmutable ? $parsed : null;
    }

    private function previousBusinessDay(DateTimeImmutable $date): DateTimeImmutable
    {
        $candidate = $date->modify('-1 day');
        while (in_array((int) $candidate->format('N'), [6, 7], true)) {
            $candidate = $candidate->modify('-1 day');
        }

        return $candidate;
    }

    private function isInvoiceTableAvailable(): bool
    {
        if ($this->invoiceTableAvailable !== null) {
            return $this->invoiceTableAvailable;
        }

        try {
            $pdo = app(Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1'
            );
            $statement->execute(['table_name' => 'invoices']);
            $this->invoiceTableAvailable = $statement->fetchColumn() !== false;
            return $this->invoiceTableAvailable;
        } catch (\Throwable) {
            $this->invoiceTableAvailable = false;
            return false;
        }
    }

    /** @return array{start_hour:int, end_hour:int, slot_step_minutes:int} */
    private function readAvailabilityWindow(): array
    {
        $startHour = 8;
        $endHour = 18;
        $slotStepMinutes = $this->readIntSetting('booking_slot_interval_minutes', 30);

        if ($startHour < 0 || $startHour > 23) {
            $startHour = 8;
        }

        if ($endHour < 1 || $endHour > 24) {
            $endHour = 18;
        }

        if ($endHour <= $startHour) {
            $endHour = min(24, $startHour + 8);
        }

        if ($slotStepMinutes < 5) {
            $slotStepMinutes = 30;
        }

        return [
            'start_hour' => $startHour,
            'end_hour' => $endHour,
            'slot_step_minutes' => $slotStepMinutes,
        ];
    }

    private function isInvoicePdfColumnAvailable(): bool
    {
        if ($this->invoicePdfColumnAvailable !== null) {
            return $this->invoicePdfColumnAvailable;
        }

        try {
            $pdo = app(Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                   AND column_name = :column_name
                 LIMIT 1'
            );
            $statement->execute([
                'table_name' => 'invoices',
                'column_name' => 'pdf_path',
            ]);

            $this->invoicePdfColumnAvailable = $statement->fetchColumn() !== false;
            return $this->invoicePdfColumnAvailable;
        } catch (\Throwable) {
            $this->invoicePdfColumnAvailable = false;
            return false;
        }
    }

    private function isClientTimezoneColumnAvailable(): bool
    {
        if ($this->clientTimezoneColumnAvailable !== null) {
            return $this->clientTimezoneColumnAvailable;
        }

        try {
            $pdo = app(Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                   AND column_name = :column_name
                 LIMIT 1'
            );
            $statement->execute([
                'table_name' => 'clients',
                'column_name' => 'timezone',
            ]);

            $this->clientTimezoneColumnAvailable = $statement->fetchColumn() !== false;
            return $this->clientTimezoneColumnAvailable;
        } catch (\Throwable) {
            $this->clientTimezoneColumnAvailable = false;
            return false;
        }
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

        $value = trim((string) ($row['value'] ?? ''));
        if ($value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
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

    /**
     * @param array<string, mixed> $data
     * @return array{client_id:int|null,error:array<string,array<int,string>>|null}
     */
    private function resolveClientIdForManualBooking(array $data): array
    {
        $clientCrypto = app(ClientFieldEncryptionService::class);
        $clientIdRaw = $data['client_id'] ?? null;
        $hasClientId = is_numeric($clientIdRaw) && (int) $clientIdRaw > 0;

        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phone = trim((string) ($data['phone'] ?? ''));
        $dateOfBirth = $this->normalizeDateToYmd(trim((string) ($data['date_of_birth'] ?? $data['dob'] ?? '')));
        $hasNewClientFields = $firstName !== '' || $lastName !== '' || $email !== '' || $phone !== '' || $dateOfBirth !== null;

        if ($hasClientId && $hasNewClientFields) {
            return [
                'client_id' => null,
                'error' => [
                    'client' => ['provide_either_client_id_or_new_client_fields'],
                ],
            ];
        }

        if (!$hasClientId && !$hasNewClientFields) {
            return [
                'client_id' => null,
                'error' => [
                    'client' => ['required'],
                ],
            ];
        }

        if ($hasClientId) {
            $clientId = (int) $clientIdRaw;
            $clientRow = db('clients')
                ->where('id', $clientId)
                ->select(['id'])
                ->first();

            if (!is_array($clientRow)) {
                return [
                    'client_id' => null,
                    'error' => [
                        'client_id' => ['invalid_client'],
                    ],
                ];
            }

            return [
                'client_id' => $clientId,
                'error' => null,
            ];
        }

        $errors = [];
        if ($firstName === '') {
            $errors['first_name'] = ['required'];
        }
        if ($lastName === '') {
            $errors['last_name'] = ['required'];
        }
        if ($email === '') {
            $errors['email'] = ['required'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = ['email'];
        }
        if ($dateOfBirth === null) {
            $errors['date_of_birth'] = ['required'];
        }

        if ($errors !== []) {
            return [
                'client_id' => null,
                'error' => $errors,
            ];
        }

        $debugMode = (bool) config('app.debug', false);
        if (!$debugMode) {
            $existing = null;
            $identityIndex = $clientCrypto->identityBlindIndex($firstName, $lastName, (string) $dateOfBirth);

            if ($identityIndex !== null && $clientCrypto->isIdentityBlindIndexColumnAvailable()) {
                $candidates = db('clients')
                    ->where('identity_blind_index', $identityIndex)
                    ->select(['id', 'first_name', 'last_name', 'date_of_birth'])
                    ->get();

                foreach ($clientCrypto->decryptClientRows(is_array($candidates) ? $candidates : []) as $candidate) {
                    $candidateFirst = strtolower(trim((string) ($candidate['first_name'] ?? '')));
                    $candidateLast = strtolower(trim((string) ($candidate['last_name'] ?? '')));
                    $candidateDob = trim((string) ($candidate['date_of_birth'] ?? ''));

                    if (
                        $candidateFirst === strtolower($firstName)
                        && $candidateLast === strtolower($lastName)
                        && $candidateDob === (string) $dateOfBirth
                    ) {
                        $existing = ['id' => (int) ($candidate['id'] ?? 0)];
                        break;
                    }
                }
            } else {
                $existing = db('clients')
                    ->where('first_name', $firstName)
                    ->where('last_name', $lastName)
                    ->where('date_of_birth', (string) $dateOfBirth)
                    ->select(['id'])
                    ->first();
            }

            if (is_array($existing) && (int) ($existing['id'] ?? 0) > 0) {
                return [
                    'client_id' => (int) $existing['id'],
                    'error' => null,
                ];
            }
        }

        $clientInsert = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'date_of_birth' => $dateOfBirth,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
        ];
        if ($this->isClientTimezoneColumnAvailable()) {
            $clientInsert['timezone'] = self::BERLIN_TIMEZONE;
        }

        $clientInsert = $clientCrypto->encryptClientData($clientInsert);

        $clientId = db('clients')->insert($clientInsert);

        return [
            'client_id' => $clientId,
            'error' => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchServiceOptions(): array
    {
        $rows = db('services')
            ->select(['id', 'name', 'slug', 'duration_minutes', 'price', 'display_order', 'is_active'])
            ->orderBy('display_order', 'asc')
            ->get();

        return array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'duration_minutes' => (int) ($row['duration_minutes'] ?? 0),
                'price' => isset($row['price']) ? (float) $row['price'] : 0.0,
                'is_active' => (bool) ($row['is_active'] ?? false),
            ];
        }, is_array($rows) ? $rows : []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchPackageOptions(): array
    {
        $rows = db('service_packages')
            ->join('services s', 's.id', '=', 'service_packages.service_id')
            ->where('service_packages.is_active', 1)
            ->where('s.is_active', 1)
            ->select(['id', 'name', 'slug', 'service_id', 'session_count', 'price', 'display_order'])
            ->orderBy('display_order', 'asc')
            ->get();

        return array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'service_id' => (int) ($row['service_id'] ?? 0),
                'session_count' => (int) ($row['session_count'] ?? 0),
                'price' => isset($row['price']) ? (float) $row['price'] : 0.0,
            ];
        }, is_array($rows) ? $rows : []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchClientOptions(): array
    {
        $crypto = app(ClientFieldEncryptionService::class);

        $rows = db('clients')
            ->select(['id', 'first_name', 'last_name', 'email', 'phone'])
            ->orderBy('last_name', 'asc')
            ->orderBy('first_name', 'asc')
            ->get();

        $decryptedRows = $crypto->decryptClientRows(is_array($rows) ? $rows : []);

        usort($decryptedRows, static function (array $a, array $b): int {
            $aLast = strtolower(trim((string) ($a['last_name'] ?? '')));
            $bLast = strtolower(trim((string) ($b['last_name'] ?? '')));
            if ($aLast === $bLast) {
                $aFirst = strtolower(trim((string) ($a['first_name'] ?? '')));
                $bFirst = strtolower(trim((string) ($b['first_name'] ?? '')));
                if ($aFirst === $bFirst) {
                    return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
                }

                return $aFirst <=> $bFirst;
            }

            return $aLast <=> $bLast;
        });

        return array_map(static function (array $row): array {
            $firstName = trim((string) ($row['first_name'] ?? ''));
            $lastName = trim((string) ($row['last_name'] ?? ''));
            return [
                'id' => (int) ($row['id'] ?? 0),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => trim($firstName . ' ' . $lastName),
                'email' => (string) ($row['email'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
            ];
        }, $decryptedRows);
    }

    private function hasSlotConflict(DateTimeImmutable $startedAt, int $durationMinutes, ?int $excludeBookingId): bool
    {
        $candidateEnd = $startedAt->modify('+' . $durationMinutes . ' minutes');
        $from = $startedAt->modify('-2 days');
        $to = $candidateEnd->modify('+2 days');

        $database = app(Database::class);
        $pdo = $database->connection();

                $sqlBookings = 'SELECT b.id, b.scheduled_at, s.duration_minutes
            FROM bookings b
            INNER JOIN services s ON s.id = b.service_id
            WHERE b.status <> :cancelled
              AND b.scheduled_at >= :from
              AND b.scheduled_at < :to';

        $params = [
            'cancelled' => 'cancelled',
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];

        if ($excludeBookingId !== null && $excludeBookingId > 0) {
            $sqlBookings .= ' AND b.id <> :exclude_id';
            $params['exclude_id'] = $excludeBookingId;
        }

        $stmt = $pdo->prepare($sqlBookings);
        $stmt->execute($params);
        $bookingRows = $stmt->fetchAll();

        if (is_array($bookingRows)) {
            $timezone = $this->berlinTimezone();
            foreach ($bookingRows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $scheduledAtRaw = (string) ($row['scheduled_at'] ?? '');
                $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $scheduledAtRaw, $timezone);
                if (!$start instanceof DateTimeImmutable) {
                    continue;
                }

                $duration = (int) ($row['duration_minutes'] ?? 0);
                if ($duration <= 0) {
                    continue;
                }

                $duration = $this->roundDurationToThirtyMinutes($duration);
                $end = $start->modify('+' . $duration . ' minutes');

                if ($startedAt < $end && $start < $candidateEnd) {
                    return true;
                }
            }
        }

                $sqlBlocks = 'SELECT starts_at, ends_at
                        FROM blocked_times
                        WHERE starts_at < :to
                            AND ends_at > :from';

        $stmt = $pdo->prepare($sqlBlocks);
        $stmt->execute([
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ]);

        $blockRows = $stmt->fetchAll();
        if (!is_array($blockRows)) {
            return false;
        }

        $timezone = $this->berlinTimezone();
        foreach ($blockRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $blockStartRaw = (string) ($row['starts_at'] ?? '');
            $blockEndRaw = (string) ($row['ends_at'] ?? '');
            $blockStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $blockStartRaw, $timezone);
            $blockEnd = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $blockEndRaw, $timezone);
            if (!$blockStart instanceof DateTimeImmutable) {
                continue;
            }
            if (!$blockEnd instanceof DateTimeImmutable || $blockEnd <= $blockStart) {
                continue;
            }

            if ($startedAt < $blockEnd && $blockStart < $candidateEnd) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private function formatBlockedTimeAsSlot(array $row): array
    {
        $timezone = $this->berlinTimezone();
        $startsAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($row['starts_at'] ?? ''), $timezone);
        $endsAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($row['ends_at'] ?? ''), $timezone);

        $durationMinutes = 0;
        if ($startsAt instanceof DateTimeImmutable && $endsAt instanceof DateTimeImmutable && $endsAt > $startsAt) {
            $durationMinutes = max(0, (int) round(($endsAt->getTimestamp() - $startsAt->getTimestamp()) / 60));
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'started_at' => (string) ($row['starts_at'] ?? ''),
            'ends_at' => (string) ($row['ends_at'] ?? ''),
            'duration_minutes' => $durationMinutes,
            'reason' => $row['reason'] ?? null,
            'created_by_user_id' => isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    private function autoCompleteElapsedConfirmedBookings(): void
    {
        $now = (new DateTimeImmutable('now', $this->berlinTimezone()))->format('Y-m-d H:i:s');

        $rows = db('bookings')
            ->where('status', 'confirmed')
            ->where('scheduled_at', $now, '<')
            ->select(['id'])
            ->get();

        if (!is_array($rows) || $rows === []) {
            return;
        }

        db('bookings')
            ->where('status', 'confirmed')
            ->where('scheduled_at', $now, '<')
            ->update([
                'status' => 'completed',
                'status_changed_at' => $now,
                'status_changed_by_user_id' => null,
            ]);

        foreach ($rows as $row) {
            $bookingId = (int) ($row['id'] ?? 0);
            if ($bookingId <= 0) {
                continue;
            }

            db('booking_status_audit_log')->insert([
                'booking_id' => $bookingId,
                'old_status' => 'confirmed',
                'new_status' => 'completed',
                'changed_by_user_id' => null,
                'revert_reason' => 'auto_elapsed_completion',
                'ip_address' => null,
            ]);
        }
    }

    private function roundDurationToThirtyMinutes(int $minutes): int
    {
        if ($minutes <= 0) {
            return 0;
        }

        return (int) (ceil($minutes / 30) * 30);
    }

    private function deriveDurationFromServiceSlug(string $slug): ?int
    {
        if (!preg_match_all('/(\d+)/', $slug, $matches)) {
            return null;
        }

        $values = $matches[1] ?? [];
        if (!is_array($values) || $values === []) {
            return null;
        }

        $raw = (int) end($values);
        if ($raw <= 0) {
            return null;
        }

        return $raw;
    }

    private function parseDateTimeInBerlin(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timezone = $this->berlinTimezone();
        $formats = [
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:iP',
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
        ];

        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            if ($dt instanceof DateTimeImmutable) {
                return $dt;
            }
        }

        return null;
    }

    private function normalizeDateToYmd(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $formats = ['Y-m-d', 'Y/m/d', 'd.m.Y', 'd-m-Y'];
        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $value, $this->berlinTimezone());
            if ($dt instanceof DateTimeImmutable) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }

    private function roundDateTimeToNextThirtyMinutes(DateTimeImmutable $dateTime, DateTimeZone $timezone): DateTimeImmutable
    {
        $roundedTimestamp = (int) ceil($dateTime->getTimestamp() / 1800) * 1800;
        return (new DateTimeImmutable('@' . $roundedTimestamp))->setTimezone($timezone);
    }

    private function berlinTimezone(): DateTimeZone
    {
        return new DateTimeZone(self::BERLIN_TIMEZONE);
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
}
