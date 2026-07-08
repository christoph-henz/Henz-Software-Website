<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\BaseApiController;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\ClientFieldEncryptionService;
use App\Services\EmailAutomationService;
use App\Services\PackageBookingManager;
use App\Support\PermissionBits;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class RequestAdminController extends BaseApiController
{
    private const BERLIN_TIMEZONE = 'Europe/Berlin';

    public function index(Request $request): Response
    {
        if (!$this->canViewRequests($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $pagination = $this->resolvePagination($request);
        $sorting = $this->resolveSorting($request);
        $search = trim((string) $request->query('q', ''));
        $showHidden = $this->readShowHiddenFlag($request);

        $database = app(Database::class);
        $pdo = $database->connection();
        [$whereSql, $bindings] = $this->buildIndexWhere($search, $showHidden);

        $countSql = 'SELECT COUNT(*) AS aggregate
            FROM client_requests cr
            INNER JOIN clients c ON c.id = cr.client_id
            LEFT JOIN bookings b ON b.id = cr.booking_id
            LEFT JOIN services s ON s.slug = cr.service_slug
            LEFT JOIN service_packages sp ON sp.id = cr.package_id'
            . $whereSql;

        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = 'SELECT
                cr.id,
                cr.client_id,
                cr.booking_id,
                cr.package_id,
                cr.service_slug,
                cr.message,
                cr.desired_at,
                cr.status,
                cr.created_at,
                c.first_name,
                c.last_name,
                c.email,
                c.phone,
                s.name AS service_name,
                sp.slug AS package_slug,
                sp.name AS package_name,
                sp.session_count AS package_session_count
            FROM client_requests cr
            INNER JOIN clients c ON c.id = cr.client_id
            LEFT JOIN bookings b ON b.id = cr.booking_id
            LEFT JOIN services s ON s.slug = cr.service_slug
            LEFT JOIN service_packages sp ON sp.id = cr.package_id'
            . $whereSql
            . ' ORDER BY ' . $sorting['column'] . ' ' . strtoupper($sorting['direction'])
            . ' LIMIT :limit OFFSET :offset';

        $stmt = $pdo->prepare($sql);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $pagination['per_page'], \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $pagination['offset'], \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        $requests = array_map(
            fn (array $row): array => $this->formatRequest($row),
            is_array($rows) ? $rows : []
        );

        return $this->ok([
            'requests' => $requests,
            'meta' => [
                'page' => $pagination['page'],
                'offset' => $pagination['offset'],
                'per_page' => $pagination['per_page'],
                'total' => $total,
                'total_pages' => (int) max(1, (int) ceil($total / max(1, $pagination['per_page']))),
                'sort' => $sorting['sort'],
                'direction' => $sorting['direction'],
                'q' => $search,
                'show_hidden' => $showHidden,
            ],
        ]);
    }

    public function show(Request $request): Response
    {
        if (!$this->canViewRequests($request)) {
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

        $row = $this->fetchRequestRow($id);
        if ($row === null) {
            return $this->fail('Request not found', 404);
        }

        $showHidden = $this->readShowHiddenFlag($request);
        if (!$showHidden && $this->isCompletedBookingRequest($row)) {
            return $this->fail('Request not found', 404);
        }

        return $this->ok([
            'request' => $this->formatRequest($row),
        ]);
    }

    public function update(Request $request): Response
    {
        if (!$this->canEditRequests($request)) {
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

        $row = $this->fetchRequestRow($id);
        if ($row === null) {
            return $this->fail('Request not found', 404);
        }

        $data = $request->all();
        if (!array_key_exists('status', $data)) {
            return $this->fail('Validation failed', 422, [
                'status' => ['required'],
            ]);
        }

        $newStatus = strtolower(trim((string) $data['status']));
        if (!in_array($newStatus, ['accepted', 'rejected'], true)) {
            return $this->fail('Validation failed', 422, [
                'status' => ['invalid_status'],
            ]);
        }

        if ((string) ($row['status'] ?? '') === $newStatus) {
            return $this->ok([
                'request' => $this->formatRequest($row),
                'package_hint' => null,
            ]);
        }

        $desiredAtOverride = null;
        if (array_key_exists('desired_at', $data) && trim((string) $data['desired_at']) !== '') {
            $parsedDesiredAt = $this->parseDesiredAt((string) $data['desired_at']);
            if ($parsedDesiredAt === null) {
                return $this->fail('Validation failed', 422, [
                    'desired_at' => ['invalid_datetime'],
                ]);
            }
            $desiredAtOverride = $parsedDesiredAt;
        }

        $respondedByUserId = $this->resolveSessionUserId($request);
        $respondedAt = (new DateTimeImmutable('now', new DateTimeZone(self::BERLIN_TIMEZONE)))->format('Y-m-d H:i:s');

        if ($newStatus === 'accepted' && !$this->canManageBookings($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['manage_bookings_required_for_accept'],
            ]);
        }

        try {
            $database = app(Database::class);
            $result = $database->transaction(function () use ($row, $id, $newStatus, $desiredAtOverride, $respondedByUserId, $respondedAt): array {
                $bookingId = $this->resolveBookingIdForRequest($row);
                $effectiveDesiredAt = $desiredAtOverride ?? trim((string) ($row['desired_at'] ?? ''));
                $packageHint = null;

                if ($newStatus === 'accepted') {
                    $bookingResult = $this->ensureBookingForAcceptedRequest($row, $bookingId, $effectiveDesiredAt, $respondedByUserId, $id);
                    $bookingId = $bookingResult['booking_id'];
                    $packageHint = $bookingResult['package_hint'];
                    if ($bookingId === null || $bookingId <= 0) {
                        throw new RuntimeException('accept_requires_bookable_slot');
                    }
                }

                $nextBookingId = $newStatus === 'rejected' ? null : $bookingId;

                db('client_requests')
                    ->where('id', $id)
                    ->update([
                        'status' => $newStatus,
                        'booking_id' => $nextBookingId,
                        'desired_at' => $effectiveDesiredAt !== '' ? $effectiveDesiredAt : null,
                        'responded_by_user_id' => $respondedByUserId,
                        'responded_at' => $respondedAt,
                    ]);

                if ($newStatus === 'rejected' && $bookingId !== null) {
                    db('bookings')
                        ->where('id', $bookingId)
                        ->update([
                            'status' => 'cancelled',
                            'cancellation_reason' => 'request_rejected',
                            'cancelled_at' => (new DateTimeImmutable('now', $this->berlinTimezone()))->format('Y-m-d H:i:s'),
                        ]);
                }

                return [
                    'booking_id' => $nextBookingId,
                    'package_hint' => $packageHint,
                ];
            });
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'accept_requires_bookable_slot') {
                throw $exception;
            }

            return $this->fail('Cannot accept request for requested slot', 409, [
                'slot' => ['occupied_or_blocked'],
                'request' => [
                    'service_slug' => (string) ($row['service_slug'] ?? ''),
                    'desired_at' => $desiredAtOverride ?? (string) ($row['desired_at'] ?? ''),
                ],
            ]);
        }

        $updatedRow = $this->fetchRequestRow($id);

        if (is_array($updatedRow)) {
            $updatedRow['booking_id'] = $result['booking_id'] ?? ($updatedRow['booking_id'] ?? null);
        }

        $paymentAutomationEnabled = filter_var(
            (string) config('mail.payment.automation_enabled', false),
            FILTER_VALIDATE_BOOL
        ) === true;

        if (!($newStatus === 'accepted' && $paymentAutomationEnabled)) {
            app(EmailAutomationService::class)->dispatch('request.' . $newStatus, [
                'request_id' => $id,
                'booking_id' => (int) ($result['booking_id'] ?? 0),
                'client_id' => (int) ($row['client_id'] ?? 0),
            ]);
        }

        return $this->ok([
            'request' => $this->formatRequest($updatedRow ?? $row),
            'package_hint' => $result['package_hint'] ?? null,
        ]);
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
        $sort = strtolower(trim((string) $request->query('sort', 'created_at')));
        $direction = strtolower(trim((string) $request->query('direction', 'desc')));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';

        $allowedSorts = [
            'created_at' => 'cr.created_at',
            'client' => 'c.last_name',
            'email' => 'c.email',
            'desired_at' => 'cr.desired_at',
            'service_slug' => 'cr.service_slug',
            'service_name' => 's.name',
        ];

        if (!isset($allowedSorts[$sort])) {
            $sort = 'created_at';
        }

        return [
            'sort' => $sort,
            'direction' => $direction,
            'column' => $allowedSorts[$sort],
        ];
    }

    /**
     * @return array{0:string, 1:array<string, mixed>}
     */
    private function buildIndexWhere(string $search, bool $showHidden): array
    {
        $bindings = [];
        $where = '';

        if (!$showHidden) {
            $bindings[':completed_status'] = 'completed';
            $bindings[':no_show_status'] = 'no_show';
            $where = ' WHERE (
                    cr.booking_id IS NULL
                    OR (
                        COALESCE(b.status, "") <> :completed_status
                        AND COALESCE(b.status, "") <> :no_show_status
                    )
                )';
        }

        if ($search === '') {
            return [$where, $bindings];
        }

        $bindings[':q'] = '%' . $search . '%';
        $where .= ($where === '' ? ' WHERE (' : ' AND (')
            . '
                CONCAT_WS(" ", c.first_name, c.last_name) LIKE :q
                OR c.email LIKE :q
                OR cr.service_slug LIKE :q
                OR COALESCE(s.name, "") LIKE :q
            )';

        return [$where, $bindings];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRequestRow(int $id): ?array
    {
        return $this->baseRequestQuery()
            ->where('cr.id', $id)
            ->first();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatRequest(array $row): array
    {
        $row = app(ClientFieldEncryptionService::class)->decryptClientRow($row);

        $firstName = trim((string) ($row['first_name'] ?? ''));
        $lastName = trim((string) ($row['last_name'] ?? ''));
        $clientName = trim($firstName . ' ' . $lastName);
        $bookingId = $this->resolveBookingIdForRequest($row);
        $serviceSlug = (string) ($row['service_slug'] ?? '');
        $serviceName = trim((string) ($row['service_name'] ?? ''));
        $packageId = (int) ($row['package_id'] ?? 0);
        if ($serviceName === '') {
            $serviceName = $serviceSlug;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'booking_id' => $bookingId,
            'service_slug' => $serviceSlug,
            'service' => [
                'slug' => $serviceSlug,
                'name' => $serviceName,
            ],
            'package' => [
                'id' => $packageId > 0 ? $packageId : null,
                'slug' => (string) ($row['package_slug'] ?? ''),
                'name' => (string) ($row['package_name'] ?? ''),
                'session_count' => isset($row['package_session_count']) ? (int) $row['package_session_count'] : null,
            ],
            'message' => (string) ($row['message'] ?? ''),
            'desired_at' => $this->formatDateTime($row['desired_at'] ?? null),
            'status' => (string) ($row['status'] ?? ''),
            'created_at' => $this->formatDateTime($row['created_at'] ?? null),
            'client' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => $clientName !== '' ? $clientName : 'Unknown',
                'email' => (string) ($row['email'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
            ],
        ];
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function parseDesiredAt(string $value): ?string
    {
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        $timezone = $this->berlinTimezone();
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, $timezone);
        if (!$date instanceof DateTimeImmutable) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw, $timezone);
        }
        if (!$date instanceof DateTimeImmutable) {
            $timestamp = strtotime($raw);
            if ($timestamp !== false) {
                $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
            }
        }

        if (!$date instanceof DateTimeImmutable) {
            return null;
        }

        return $date->format('Y-m-d H:i:s');
    }

    private function resolveBookingIdForRequest(array $requestRow): ?int
    {
        $bookingId = isset($requestRow['booking_id']) ? (int) $requestRow['booking_id'] : 0;
        return $bookingId > 0 ? $bookingId : null;
    }

    /**
     * @param array<string, mixed> $requestRow
     */
    private function isCompletedBookingRequest(array $requestRow): bool
    {
        $status = strtolower(trim((string) ($requestRow['booking_status'] ?? '')));
        return in_array($status, ['completed', 'no_show'], true);
    }

    private function readShowHiddenFlag(Request $request): bool
    {
        $raw = strtolower(trim((string) $request->query('show_hidden', '0')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $requestRow
     * @return array{booking_id:int|null,package_hint:array<string, mixed>|null}
     */
    private function ensureBookingForAcceptedRequest(
        array $requestRow,
        ?int $existingBookingId,
        string $desiredAtRaw,
        ?int $respondedByUserId,
        int $requestId
    ): array {
        $clientId = (int) ($requestRow['client_id'] ?? 0);
        $serviceSlug = trim((string) ($requestRow['service_slug'] ?? ''));
        $requestPackageId = (int) ($requestRow['package_id'] ?? 0);
        if ($clientId <= 0 || $serviceSlug === '' || $desiredAtRaw === '') {
            return [
                'booking_id' => null,
                'package_hint' => null,
            ];
        }

        $serviceRow = db('services')
            ->where('slug', $serviceSlug)
            ->where('is_active', 1)
            ->select(['id', 'slug', 'duration_minutes', 'price'])
            ->first();

        if (!is_array($serviceRow)) {
            return [
                'booking_id' => null,
                'package_hint' => null,
            ];
        }

        $serviceId = (int) ($serviceRow['id'] ?? 0);
        if ($serviceId <= 0) {
            return [
                'booking_id' => null,
                'package_hint' => null,
            ];
        }

        $duration = (int) ($serviceRow['duration_minutes'] ?? 0);
        if ($duration <= 0) {
            return [
                'booking_id' => null,
                'package_hint' => null,
            ];
        }

        $servicePrice = (float) ($serviceRow['price'] ?? 0.0);
        $isFreeService = $servicePrice <= 0.0;
        $initialStatus = $isFreeService ? 'confirmed' : 'pending';
        $initialPaymentStatus = $isFreeService ? 'paid' : 'pending';

        $scheduledAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $desiredAtRaw, $this->berlinTimezone());
        if (!$scheduledAt instanceof DateTimeImmutable) {
            return [
                'booking_id' => null,
                'package_hint' => null,
            ];
        }

        $scheduledAt = $this->roundDateTimeToNextThirtyMinutes($scheduledAt, $this->berlinTimezone());
        $duration = $this->roundDurationToThirtyMinutes($duration);
        $ignoreBookingId = $existingBookingId !== null && $existingBookingId > 0 ? $existingBookingId : null;

        if ($this->hasSlotConflict($scheduledAt, $duration, $ignoreBookingId)) {
            return [
                'booking_id' => null,
                'package_hint' => null,
            ];
        }

        $packageManager = app(PackageBookingManager::class);
        $activePurchase = $packageManager->findActivePurchaseForClientService($clientId, $serviceId);
        $packageHint = $activePurchase !== null
            ? $packageManager->findActivePackageHint($clientId, $serviceId)
            : null;

        if ($activePurchase === null && $requestPackageId > 0) {
            $requestedPackage = db('service_packages')
                ->where('id', $requestPackageId)
                ->where('service_id', $serviceId)
                ->where('is_active', 1)
                ->select(['id', 'service_id', 'session_count'])
                ->first();

            if (is_array($requestedPackage)) {
                $purchaseId = $packageManager->createPurchaseFromPackage($clientId, $requestedPackage);
                if ($purchaseId > 0) {
                    $activePurchase = db('package_purchases')->where('id', $purchaseId)->first();
                    $packageHint = $packageManager->findActivePackageHint($clientId, $serviceId);
                }
            }
        }

        $bookingId = $existingBookingId;
        if ($bookingId !== null && $bookingId > 0) {
            db('bookings')
                ->where('id', $bookingId)
                ->update([
                    'service_id' => $serviceId,
                    'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                    'status' => $initialStatus,
                    'payment_status' => $initialPaymentStatus,
                ]);
        } else {
            $bookingId = db('bookings')->insert([
                'client_id' => $clientId,
                'service_id' => $serviceId,
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                'status' => $initialStatus,
                'payment_status' => $initialPaymentStatus,
                'notes' => 'Auto-created from accepted request',
            ]);
        }

        if ($bookingId === null || $bookingId <= 0) {
            return [
                'booking_id' => null,
                'package_hint' => $packageHint,
            ];
        }

        $existingBookingRow = db('bookings')
            ->where('id', $bookingId)
            ->select(['package_session_state', 'package_session_no'])
            ->first();
        $hasReservedSession = is_array($existingBookingRow)
            && (string) ($existingBookingRow['package_session_state'] ?? 'none') === 'reserved';
        $packageSessionNo = $hasReservedSession ? (int) ($existingBookingRow['package_session_no'] ?? 0) : 0;
        $isPackageBooking = $hasReservedSession;

        if (is_array($activePurchase) && !$hasReservedSession) {
            $packagePurchaseId = (int) ($activePurchase['id'] ?? 0);
            $reserved = $packageManager->reserveSession($packagePurchaseId, $bookingId, $requestId, $respondedByUserId);
            if ($reserved === null) {
                // Fallback: if no package quota remains, keep it as a normal service booking.
                db('bookings')
                    ->where('id', $bookingId)
                    ->update([
                        'status' => 'pending',
                        'payment_status' => 'pending',
                        'package_purchase_id' => null,
                        'is_package_booking' => 0,
                        'package_session_no' => null,
                        'package_session_state' => 'none',
                    ]);
            } else {
                $isPackageBooking = true;
                $packageSessionNo = (int) ($reserved['session_no'] ?? 0);
            }
        }

        if ($isPackageBooking) {
            db('bookings')
                ->where('id', $bookingId)
                ->update([
                    'status' => $packageSessionNo > 1 ? 'confirmed' : 'pending',
                ]);
        }

        if ($isFreeService) {
            db('bookings')
                ->where('id', $bookingId)
                ->update([
                    'status' => 'confirmed',
                    'payment_status' => 'paid',
                ]);
        }

        return [
            'booking_id' => $bookingId,
            'package_hint' => $packageHint,
        ];
    }

    private function hasSlotConflict(DateTimeImmutable $startedAt, int $durationMinutes, ?int $ignoreBookingId = null): bool
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

        if ($ignoreBookingId !== null && $ignoreBookingId > 0) {
            $sqlBookings .= ' AND b.id <> :ignore_id';
            $params['ignore_id'] = $ignoreBookingId;
        }

        $stmt = $pdo->prepare($sqlBookings);
        $stmt->execute($params);

        $bookingRows = $stmt->fetchAll();
        if (is_array($bookingRows)) {
            foreach ($bookingRows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $startRaw = (string) ($row['scheduled_at'] ?? '');
                $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startRaw, $this->berlinTimezone());
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

        foreach ($blockRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $blockStartRaw = (string) ($row['starts_at'] ?? '');
            $blockEndRaw = (string) ($row['ends_at'] ?? '');
            $blockStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $blockStartRaw, $this->berlinTimezone());
            $blockEnd = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $blockEndRaw, $this->berlinTimezone());
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

    private function roundDurationToThirtyMinutes(int $minutes): int
    {
        if ($minutes <= 0) {
            return 0;
        }

        return (int) (ceil($minutes / 30) * 30);
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

    private function resolveSessionUserId(Request $request): ?int
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

    private function canEditRequests(Request $request): bool
    {
        $adminUser = $this->resolveAdminUserFromSession($request);
        if (!is_array($adminUser)) {
            return false;
        }

        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $requiredMask = PermissionBits::resolve('manage_clients', 16);

        return ($roleMask & $requiredMask) !== 0;
    }

    private function canViewRequests(Request $request): bool
    {
        $adminUser = $this->resolveAdminUserFromSession($request);
        if (!is_array($adminUser)) {
            return false;
        }

        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $viewMask = PermissionBits::resolve('view_clients', 8);
        $manageMask = PermissionBits::resolve('manage_clients', 16);

        return ($roleMask & ($viewMask | $manageMask)) !== 0;
    }

    private function canManageBookings(Request $request): bool
    {
        $adminUser = $this->resolveAdminUserFromSession($request);
        if (!is_array($adminUser)) {
            return false;
        }

        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $requiredMask = PermissionBits::resolve('manage_bookings', 2);

        return ($roleMask & $requiredMask) !== 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveAdminUserFromSession(Request $request): ?array
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = $session[$sessionKey] ?? null;

        if (!is_array($adminUser)) {
            return null;
        }

        return $adminUser;
    }

    private function baseRequestQuery(): \App\Core\Database\QueryBuilder
    {
        return db('client_requests cr')
            ->join('clients c', 'c.id', '=', 'cr.client_id')
            ->join('bookings b', 'b.id', '=', 'cr.booking_id', 'LEFT')
            ->join('services s', 's.slug', '=', 'cr.service_slug', 'LEFT')
            ->join('service_packages sp', 'sp.id', '=', 'cr.package_id', 'LEFT')
            ->select([
                'cr.id',
                'cr.client_id',
                'cr.booking_id',
                'cr.package_id',
                'cr.service_slug',
                'cr.message',
                'cr.desired_at',
                'cr.status',
                'cr.created_at',
                'b.status AS booking_status',
                'c.first_name',
                'c.last_name',
                'c.email',
                'c.phone',
                's.name AS service_name',
                'sp.slug AS package_slug',
                'sp.name AS package_name',
                'sp.session_count AS package_session_count',
            ]);
    }
}