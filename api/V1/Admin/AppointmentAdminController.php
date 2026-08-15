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
use App\Core\Support\PermissionBits;
use DateTimeImmutable;
use DateTimeZone;

final class AppointmentAdminController extends BaseApiController
{
    private const VIEW_APPOINTMENT_MASK = 1;
    private const MANAGE_APPOINTMENT_MASK = 2;
    private const BERLIN_TIMEZONE = 'Europe/Berlin';
    private ?bool $invoiceTableAvailable = null;
    private ?bool $invoicePdfColumnAvailable = null;
    private ?bool $invoiceAppointmentLinkAvailable = null;
    private ?bool $clientTimezoneColumnAvailable = null;
    private ?bool $packagePurchaseTableAvailable = null;
    private ?bool $appointmentStatusAuditTableAvailable = null;
    private ?bool $bookingStatusAuditTableAvailable = null;
    /** @var array<string, bool> */
    private array $appointmentColumnAvailability = [];

    public function index(Request $request): Response
    {
        if (!$this->canViewAppointments($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $this->autoCompleteElapsedConfirmedAppointments();

        $pagination = $this->resolvePagination($request);
        $sorting = $this->resolveSorting($request);
        $statusFilter = strtolower(trim((string) $request->query('status', '')));
        $searchTerm = strtolower(trim((string) $request->query('q', '')));

        $allowedStatuses = ['pending', 'accepted', 'declined', 'completed', 'storno', 'no_show'];
        if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
            return $this->fail('Validation failed', 422, [
                'status' => ['invalid_status'],
            ]);
        }

        $total = $this->countAppointmentRows($statusFilter, $searchTerm);
        $rows = $this->fetchAppointmentRows($pagination, $sorting, $statusFilter, $searchTerm);

        $appointments = array_map(fn (array $row): array => $this->formatAppointment($row), $rows);

        return $this->ok([
            'appointments' => $appointments,
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
        if (!$this->canViewAppointments($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $this->autoCompleteElapsedConfirmedAppointments();

        $rows = $this->fetchAppointmentRows(
            ['page' => 1, 'offset' => 0, 'per_page' => 5000],
            ['sort' => 'scheduled_at', 'direction' => 'asc', 'column' => 'b.appointment_date'],
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
        if (!$this->canViewAppointments($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $this->autoCompleteElapsedConfirmedAppointments();

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['required'],
            ]);
        }

        $row = $this->fetchAppointmentRow($id);
        if ($row === null) {
            return $this->fail('Appointment not found', 404);
        }

        return $this->ok([
            'appointment' => $this->formatAppointment($row),
        ]);
    }

    public function meta(Request $request): Response
    {
        if (!$this->canManageAppointments($request)) {
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
        if (!$this->canManageAppointments($request)) {
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

        $durationMinutes = (int) ($data['duration_minutes'] ?? 60);
        $durationMinutes = $this->roundDurationToThirtyMinutes($durationMinutes);
        if ($durationMinutes <= 0) {
            return $this->fail('Validation failed', 422, [
                'duration_minutes' => ['invalid'],
            ]);
        }
        $isFreeService = (float) ($service['price_min'] ?? 0.0) <= 0.0;
        $initialStatus = $isFreeService ? 'accepted' : 'pending';
        $initialPaymentStatus = $isFreeService ? 'paid' : 'pending';

        if ($this->hasSlotConflict($startedAt, $durationMinutes, null)) {
            return $this->fail('Slot conflict', 409, [
                'slot' => ['occupied_or_blocked'],
            ]);
        }

        $clientResolution = $this->resolveClientIdForManualAppointment($data);
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

        $insert = [
            'client_id' => $clientId,
            'service_id' => $serviceId,
            'appointment_date' => $startedAt->format('Y-m-d H:i:s'),
            'duration_minutes' => $durationMinutes,
            'status' => $initialStatus,
            'notes' => $notes !== '' ? $notes : null,
        ];

        if ($this->isAppointmentColumnAvailable('payment_status')) {
            $insert['payment_status'] = $initialPaymentStatus;
        }

        $appointmentId = db('appointments')->insert($insert);

        if ($isFreeService) {
            $freeUpdate = [
                'status' => 'accepted',
            ];
            if ($this->isAppointmentColumnAvailable('payment_status')) {
                $freeUpdate['payment_status'] = 'paid';
            }

            db('appointments')
                ->where('id', $appointmentId)
                ->update($freeUpdate);
        }

        $row = $this->fetchAppointmentRow($appointmentId);

        return $this->ok([
            'appointment' => $this->formatAppointment($row ?? []),
            'duration_minutes' => $durationMinutes,
        ], 201);
    }

    public function listBlocked(Request $request): Response
    {
        if (!$this->canViewAppointments($request)) {
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
        if (!$this->canManageAppointments($request)) {
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
        if (!$this->canManageAppointments($request)) {
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

        $appointment = $this->fetchAppointmentRow($id);
        if ($appointment === null) {
            return $this->fail('Appointment not found', 404);
        }

        $currentStatus = strtolower(trim((string) ($appointment['status'] ?? 'pending')));
        if ($currentStatus !== 'accepted') {
            return $this->fail('Invalid appointment state', 409, [
                'status' => ['reschedule_allowed_only_for_accepted'],
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

        $durationMinutes = (int) ($appointment['duration_minutes'] ?? 0);
        if ($durationMinutes <= 0) {
            return $this->fail('Validation failed', 422, [
                'duration_minutes' => ['invalid'],
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

        $updates = [
            'appointment_date' => $startedAt->format('Y-m-d H:i:s'),
        ];
        if (array_key_exists('duration_minutes', $data)) {
            $updates['duration_minutes'] = $durationMinutes;
        }

        db('appointments')
            ->where('id', $id)
            ->update($updates);

        $updated = $this->fetchAppointmentRow($id);

        $mailContext = $this->resolveAppointmentMailContext($updated ?? $appointment);
        app(EmailAutomationService::class)->dispatch('appointment.reschedule', [
            'appointment_id' => $id,
            'client_id' => $mailContext['client_id'],
            'recipient_email' => $mailContext['recipient_email'],
            'client_first_name' => $mailContext['client_first_name'],
        ]);

        return $this->ok([
            'appointment' => $this->formatAppointment($updated ?? $appointment),
            'duration_minutes' => $durationMinutes,
        ]);
    }

    public function cancel(Request $request): Response
    {
        if (!$this->canStornoAppointments($request)) {
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

        $appointment = $this->fetchAppointmentRow($id);
        if ($appointment === null) {
            return $this->fail('Appointment not found', 404);
        }

        $currentStatus = (string) ($appointment['status'] ?? 'pending');
        if ($currentStatus === 'storno') {
            return $this->ok([
                'appointment' => $this->formatAppointment($appointment),
                'cancellation_timing' => (string) ($appointment['cancellation_timing'] ?? ''),
            ]);
        }

        if ($currentStatus !== 'accepted') {
            return $this->fail('Invalid appointment state', 409, [
                'status' => ['cancellation_allowed_only_for_accepted'],
            ]);
        }

        $data = $request->all();
        $reason = trim((string) ($data['cancellation_reason'] ?? ''));

        $timezone = $this->berlinTimezone();
        $now = new DateTimeImmutable('now', $timezone);
        $scheduledAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($appointment['scheduled_at'] ?? ''), $timezone);
        if (!$scheduledAt instanceof DateTimeImmutable) {
            return $this->fail('Validation failed', 422, [
                'appointment' => ['invalid_scheduled_at'],
            ]);
        }

        $secondsUntilStart = $scheduledAt->getTimestamp() - $now->getTimestamp();
        $cutoffHours = max(1, $this->readIntAvailabilityRule('cancellation_hours_notice', 48));
        $cancellationTiming = $secondsUntilStart > ($cutoffHours * 3600) ? 'early' : 'late';
        $userId = $this->getUserId($request);
        $nowString = $now->format('Y-m-d H:i:s');

        $cancelUpdate = [
            'status' => 'storno',
        ];
        if ($this->isAppointmentColumnAvailable('cancellation_timing')) {
            $cancelUpdate['cancellation_timing'] = $cancellationTiming;
        }
        if ($this->isAppointmentColumnAvailable('cancelled_at')) {
            $cancelUpdate['cancelled_at'] = $nowString;
        }
        if ($this->isAppointmentColumnAvailable('cancellation_reason')) {
            $cancelUpdate['cancellation_reason'] = $reason !== '' ? $reason : null;
        }
        if ($this->isAppointmentColumnAvailable('status_changed_at')) {
            $cancelUpdate['status_changed_at'] = $nowString;
        }
        if ($this->isAppointmentColumnAvailable('status_changed_by_user_id')) {
            $cancelUpdate['status_changed_by_user_id'] = $userId;
        }

        db('appointments')
            ->where('id', $id)
            ->update($cancelUpdate);

        $this->insertStatusAuditLog(
            $id,
            $currentStatus,
            'storno',
            $userId,
            null,
            $this->resolveIpAddress($request)
        );

        $packageAction = null;
        $packagePurchaseRefunded = false;
        $packageManagerClass = 'App\\Services\\PackageAppointmentManager';
        if (class_exists($packageManagerClass)) {
            $packageManager = app($packageManagerClass);
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
        }

        $updated = $this->fetchAppointmentRow($id);
        $mailContext = $this->resolveAppointmentMailContext($updated ?? $appointment);
        app(EmailAutomationService::class)->dispatch('appointment.storno', [
            'appointment_id' => $id,
            'client_id' => $mailContext['client_id'],
            'recipient_email' => $mailContext['recipient_email'],
            'client_first_name' => $mailContext['client_first_name'],
        ]);

        return $this->ok([
            'appointment' => $this->formatAppointment($updated ?? $appointment),
            'cancellation_timing' => $cancellationTiming,
            'package_action' => $packageAction,
            'package_purchase_refunded' => $packagePurchaseRefunded,
        ]);
    }

    public function update(Request $request): Response
    {
        if (!$this->canManageAppointments($request)) {
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

        $appointment = $this->fetchAppointmentRow($id);
        if ($appointment === null) {
            return $this->fail('Appointment not found', 404);
        }

        $data = $request->all();
        $updates = [];
        $errors = [];

        if (array_key_exists('client_id', $data)) {
            $clientId = (int) ($data['client_id'] ?? 0);
            if ($clientId <= 0) {
                $errors['client_id'] = ['required'];
            } else {
                $clientRow = db('clients')->where('id', $clientId)->select(['id'])->first();
                if (!is_array($clientRow)) {
                    $errors['client_id'] = ['invalid_client'];
                } else {
                    $updates['client_id'] = $clientId;
                }
            }
        }

        if (array_key_exists('service_id', $data)) {
            $serviceId = (int) ($data['service_id'] ?? 0);
            if ($serviceId <= 0) {
                $errors['service_id'] = ['required'];
            } else {
                $service = $this->fetchService($serviceId);
                if ($service === null) {
                    $errors['service_id'] = ['invalid_service'];
                } else {
                    $updates['service_id'] = $serviceId;
                }
            }
        }

        if (array_key_exists('duration_minutes', $data)) {
            $durationMinutes = $this->roundDurationToThirtyMinutes((int) ($data['duration_minutes'] ?? 0));
            if ($durationMinutes <= 0) {
                $errors['duration_minutes'] = ['invalid'];
            } else {
                $updates['duration_minutes'] = $durationMinutes;
            }
        }

        if (array_key_exists('scheduled_at', $data)) {
            $scheduledAtRaw = trim((string) ($data['scheduled_at'] ?? ''));
            if ($scheduledAtRaw === '') {
                $errors['scheduled_at'] = ['required'];
            } else {
                $timezone = $this->berlinTimezone();
                $scheduledAt = $this->parseDateTimeInBerlin($scheduledAtRaw);
                if ($scheduledAt === null) {
                    $errors['scheduled_at'] = ['invalid_datetime'];
                } else {
                    $scheduledAt = $this->roundDateTimeToNextThirtyMinutes($scheduledAt, $timezone);
                    $durationMinutes = isset($updates['duration_minutes'])
                        ? (int) $updates['duration_minutes']
                        : (int) ($appointment['duration_minutes'] ?? 0);
                    $durationMinutes = $this->roundDurationToThirtyMinutes($durationMinutes);
                    if ($durationMinutes <= 0) {
                        $errors['duration_minutes'] = ['invalid'];
                    } elseif ($this->hasSlotConflict($scheduledAt, $durationMinutes, $id)) {
                        return $this->fail('Slot conflict', 409, [
                            'slot' => ['occupied_or_blocked'],
                        ]);
                    } else {
                        $updates['appointment_date'] = $scheduledAt->format('Y-m-d H:i:s');
                    }
                }
            }
        }

        if (array_key_exists('notes', $data)) {
            $notes = trim((string) ($data['notes'] ?? ''));
            $updates['notes'] = $notes !== '' ? $notes : null;
        }

        if (array_key_exists('status', $data)) {
            $status = strtolower(trim((string) ($data['status'] ?? '')));
            $allowedStatuses = ['pending', 'accepted', 'declined', 'completed', 'storno', 'no_show'];

            if ($status === '' || !in_array($status, $allowedStatuses, true)) {
                $errors['status'] = ['invalid_status'];
            } else {
                $currentStatus = strtolower(trim((string) ($appointment['status'] ?? 'pending')));
                if ($status === 'no_show' && $currentStatus !== 'completed') {
                    $errors['status'] = ['no_show_allowed_only_from_completed'];
                }
                if ($status !== $currentStatus) {
                    $updates['status'] = $status;
                }
            }
        }

        if (($updates['status'] ?? null) === 'accepted') {
            $currentClientId = isset($updates['client_id'])
                ? (int) $updates['client_id']
                : (int) ($appointment['client_id'] ?? 0);

            if ($currentClientId <= 0 && $this->isContactFormAppointment($appointment)) {
                $clientCreation = $this->resolveClientIdForAcceptedContactAppointment($appointment);
                if ($clientCreation['error'] !== null) {
                    $errors = array_merge($errors, $clientCreation['error']);
                } elseif ((int) ($clientCreation['client_id'] ?? 0) > 0) {
                    $updates['client_id'] = (int) $clientCreation['client_id'];
                }
            }
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        if ($updates === []) {
            return $this->ok([
                'appointment' => $this->formatAppointment($appointment),
            ]);
        }

        $userId = $this->getUserId($request);
        $updates['updated_by'] = $userId;

        if (isset($updates['status'])) {
            if ($this->isAppointmentColumnAvailable('status_changed_at')) {
                $updates['status_changed_at'] = (new DateTimeImmutable('now', $this->berlinTimezone()))->format('Y-m-d H:i:s');
            }
            if ($this->isAppointmentColumnAvailable('status_changed_by_user_id')) {
                $updates['status_changed_by_user_id'] = $userId;
            }
        }

        db('appointments')
            ->where('id', $id)
            ->update($updates);

        if (isset($updates['status'])) {
            $this->insertStatusAuditLog(
                $id,
                (string) ($appointment['status'] ?? 'pending'),
                (string) $updates['status'],
                $userId,
                null,
                $this->resolveIpAddress($request)
            );
        }

        $updated = $this->fetchAppointmentRow($id);

        $mailRecipientEmail = strtolower(trim((string) (
            $updated['email']
            ?? $updated['prospect_email']
            ?? $appointment['email']
            ?? $appointment['prospect_email']
            ?? ''
        )));
        $mailFirstName = trim((string) (
            $updated['name']
            ?? $updated['prospect_name']
            ?? $appointment['name']
            ?? $appointment['prospect_name']
            ?? ''
        ));

        $emailDispatch = null;
        if (isset($updates['status'])) {
            $event = null;
            $newStatus = strtolower((string) $updates['status']);
            if ($newStatus === 'accepted') {
                $event = 'appointment.accepted';
            } elseif ($newStatus === 'declined') {
                $event = 'appointment.rejected';
            } elseif ($newStatus === 'no_show') {
                $event = 'appointment.no_show';
            }

            if ($event !== null) {
                try {
                    $emailDispatch = app(EmailAutomationService::class)->dispatch($event, [
                        'appointment_id' => $id,
                        'client_id' => (int) (($updated['client_id'] ?? $appointment['client_id']) ?? 0),
                        'recipient_email' => $mailRecipientEmail,
                        'client_first_name' => $mailFirstName,
                    ]);
                } catch (\Throwable $e) {
                    $emailDispatch = [
                        'status' => 'error',
                        'reason' => 'dispatch_exception',
                    ];

                    error_log('[email-automation] appointment status dispatch exception ' . json_encode([
                        'appointment_id' => $id,
                        'event' => $event,
                        'message' => $e->getMessage(),
                    ], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
                }

                error_log('[email-automation] appointment status dispatch ' . json_encode([
                    'appointment_id' => $id,
                    'from_status' => (string) ($appointment['status'] ?? 'pending'),
                    'to_status' => (string) $updates['status'],
                    'event' => $event,
                    'result' => $emailDispatch,
                ], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
            }
        }

        return $this->ok([
            'appointment' => $this->formatAppointment($updated ?? $appointment),
            'email_dispatch' => $emailDispatch,
        ]);
    }

    public function destroy(Request $request): Response
    {
        if (!$this->canManageAppointments($request)) {
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

        $appointment = $this->fetchAppointmentRow($id);
        if ($appointment === null) {
            return $this->fail('Appointment not found', 404);
        }

        db('appointments')->where('id', $id)->delete();

        return $this->ok([
            'deleted_id' => $id,
        ]);
    }

    private function canViewAppointments(Request $request): bool
    {
        $roleMask = $this->getUserRoleMask($request);
        $viewMask = PermissionBits::resolve('view_appointments', self::VIEW_APPOINTMENT_MASK);
        $manageMask = PermissionBits::resolve('manage_appointments', self::MANAGE_APPOINTMENT_MASK);

        return ($roleMask & $viewMask) !== 0 || ($roleMask & $manageMask) !== 0;
    }

    private function canManageAppointments(Request $request): bool
    {
        $roleMask = $this->getUserRoleMask($request);
        $manageMask = PermissionBits::resolve('manage_appointments', self::MANAGE_APPOINTMENT_MASK);

        return ($roleMask & $manageMask) !== 0;
    }

    private function canStornoAppointments(Request $request): bool
    {
        $roleMask = $this->getUserRoleMask($request);
        $stornoMask = PermissionBits::resolve('storno_appointment', 4);

        return ($roleMask & $stornoMask) !== 0;
    }

    /**
     * @param array<string, mixed> $appointment
     * @return array{client_id:int, recipient_email:string, client_first_name:string}
     */
    private function resolveAppointmentMailContext(array $appointment): array
    {
        $recipientEmail = strtolower(trim((string) (
            $appointment['email']
            ?? $appointment['prospect_email']
            ?? ''
        )));

        $clientFirstName = trim((string) (
            $appointment['first_name']
            ?? $appointment['name']
            ?? $appointment['prospect_name']
            ?? ''
        ));

        return [
            'client_id' => (int) ($appointment['client_id'] ?? 0),
            'recipient_email' => $recipientEmail,
            'client_first_name' => $clientFirstName,
        ];
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
            'scheduled_at' => 'b.appointment_date',
            'status' => $this->isAppointmentColumnAvailable('status') ? 'b.status' : 'b.id',
            'payment_status' => $this->isAppointmentColumnAvailable('payment_status') ? 'b.payment_status' : 'b.id',
            'created_at' => $this->isAppointmentColumnAvailable('created_at') ? 'b.created_at' : 'b.id',
            'client_name' => "LOWER(COALESCE(c.name, ''))",
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

    private function baseAppointmentsQuery(): \App\Core\Database\QueryBuilder
    {
        $query = db('appointments b')
            ->join('clients c', 'c.id', '=', 'b.client_id', 'LEFT')
            ->join('services s', 's.id', '=', 'b.service_id');

        $select = [
                'b.id',
                'b.client_id',
                'b.service_id',
                'b.appointment_date AS scheduled_at',
                'b.duration_minutes',
                'c.name',
                'c.email',
                'c.phone',
                's.name AS service_name',
                's.slug AS service_slug',
                's.price_min AS service_price',
                'b.duration_minutes AS appointment_duration_minutes',
        ];

        $optionalAppointmentColumns = [
            'status',
            'payment_status',
            'prospect_name',
            'prospect_email',
            'origin',
            'notes',
            'cancellation_reason',
            'cancellation_timing',
            'cancelled_at',
            'status_changed_at',
            'status_changed_by_user_id',
            'package_purchase_id',
            'is_package_appointment',
            'package_session_no',
            'package_session_state',
            'created_at',
            'updated_at',
        ];
        foreach ($optionalAppointmentColumns as $column) {
            if ($this->isAppointmentColumnAvailable($column)) {
                $select[] = 'b.' . $column;
            } else {
                $select[] = 'NULL AS ' . $column;
            }
        }

        if ($this->isPackagePurchaseTableAvailable()) {
            $query = $query->join('package_purchases pp', 'pp.id', '=', 'b.package_purchase_id', 'LEFT');
            $select[] = 'pp.payment_status AS package_purchase_payment_status';
            $select[] = 'pp.purchased_at AS package_purchase_purchased_at';
            $select[] = 'pp.paid_at AS package_purchase_paid_at';
            $select[] = 'pp.package_name_snapshot AS package_purchase_name_snapshot';
            $select[] = 'pp.package_slug_snapshot AS package_purchase_slug_snapshot';
            $select[] = 'pp.package_price_snapshot AS package_purchase_price_snapshot';
            $select[] = 'pp.package_session_count_snapshot AS package_purchase_session_count_snapshot';
        } else {
            $select[] = 'NULL AS package_purchase_payment_status';
            $select[] = 'NULL AS package_purchase_purchased_at';
            $select[] = 'NULL AS package_purchase_paid_at';
            $select[] = 'NULL AS package_purchase_name_snapshot';
            $select[] = 'NULL AS package_purchase_slug_snapshot';
            $select[] = 'NULL AS package_purchase_price_snapshot';
            $select[] = 'NULL AS package_purchase_session_count_snapshot';
        }

        return $query->select($select);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAppointmentRows(array $pagination, array $sorting, string $statusFilter, string $searchTerm): array
    {
        $database = app(Database::class);
        $pdo = $database->connection();

        $sql = $this->buildAppointmentSql();
        $sql .= $this->buildAppointmentWhereSql($statusFilter, $searchTerm);
        $sql .= ' ORDER BY ' . $sorting['column'] . ' ' . strtoupper($sorting['direction']);
        $sql .= ' LIMIT :limit OFFSET :offset';

        $stmt = $pdo->prepare($sql);
        foreach ($this->buildAppointmentParams($statusFilter, $searchTerm) as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', (int) $pagination['per_page'], \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) $pagination['offset'], \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    private function countAppointmentRows(string $statusFilter, string $searchTerm): int
    {
        $database = app(Database::class);
        $pdo = $database->connection();

        $sql = 'SELECT COUNT(*) AS aggregate FROM appointments b'
            . ' LEFT JOIN clients c ON c.id = b.client_id'
            . ' INNER JOIN services s ON s.id = b.service_id';
        $sql .= $this->buildAppointmentWhereSql($statusFilter, $searchTerm);

        $stmt = $pdo->prepare($sql);
        foreach ($this->buildAppointmentParams($statusFilter, $searchTerm) as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();

        $value = $stmt->fetchColumn();
        return (int) ($value !== false ? $value : 0);
    }

    private function buildAppointmentSql(): string
    {
        $sql = 'SELECT '
            . 'b.id, b.client_id, b.service_id, b.appointment_date AS scheduled_at, b.duration_minutes, ';

        $optionalAppointmentColumns = [
            'status',
            'payment_status',
            'prospect_name',
            'prospect_email',
            'origin',
            'notes',
            'cancellation_reason',
            'cancellation_timing',
            'cancelled_at',
            'status_changed_at',
            'status_changed_by_user_id',
            'package_purchase_id',
            'is_package_appointment',
            'package_session_no',
            'package_session_state',
            'created_at',
            'updated_at',
        ];

        foreach ($optionalAppointmentColumns as $column) {
            if ($this->isAppointmentColumnAvailable($column)) {
                $sql .= 'b.' . $column . ', ';
            } else {
                $sql .= 'NULL AS ' . $column . ', ';
            }
        }

        if ($this->isInvoiceTableAvailable() && $this->isInvoiceAppointmentLinkAvailable()) {
            $sql .= 'inv.id AS invoice_id, inv.invoice_number, inv.currency_code AS invoice_currency_code, '
                . 'inv.total_amount AS invoice_total_amount, inv.status AS invoice_status, '
                . 'inv.invoice_date AS invoice_date, inv.due_date AS invoice_due_date, ';
        }

        if ($this->isPackagePurchaseTableAvailable()) {
            $sql .= 'pp.payment_status AS package_purchase_payment_status, pp.purchased_at AS package_purchase_purchased_at, '
                . 'pp.paid_at AS package_purchase_paid_at, pp.package_price_snapshot AS package_purchase_price_snapshot, ';
        } else {
            $sql .= 'NULL AS package_purchase_payment_status, NULL AS package_purchase_purchased_at, '
                . 'NULL AS package_purchase_paid_at, NULL AS package_purchase_price_snapshot, ';
        }

        $sql .= 'c.name, c.email, c.phone, '
            . 's.name AS service_name, s.slug AS service_slug, s.price_min AS service_price, b.duration_minutes AS appointment_duration_minutes '
            . 'FROM appointments b '
            . 'LEFT JOIN clients c ON c.id = b.client_id '
            . 'INNER JOIN services s ON s.id = b.service_id ';

        if ($this->isInvoiceTableAvailable() && $this->isInvoiceAppointmentLinkAvailable()) {
            $sql .= 'LEFT JOIN invoices inv ON inv.id = (SELECT MAX(i2.id) FROM invoices i2 WHERE i2.appointment_id = b.id) ';
        }

        if ($this->isPackagePurchaseTableAvailable()) {
            $sql .= 'LEFT JOIN package_purchases pp ON pp.id = b.package_purchase_id';
        }

        return $sql;
    }

    private function isPackagePurchaseTableAvailable(): bool
    {
        if ($this->packagePurchaseTableAvailable !== null) {
            return $this->packagePurchaseTableAvailable;
        }

        try {
            $pdo = app(Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1'
            );
            $statement->execute(['table_name' => 'package_purchases']);
            $this->packagePurchaseTableAvailable = $statement->fetchColumn() !== false;
            return $this->packagePurchaseTableAvailable;
        } catch (\Throwable) {
            $this->packagePurchaseTableAvailable = false;
            return false;
        }
    }

    private function buildAppointmentWhereSql(string $statusFilter, string $searchTerm): string
    {
        $parts = [];

        if ($statusFilter !== '' && $this->isAppointmentColumnAvailable('status')) {
            $parts[] = 'b.status = :status_filter';
        }

        if ($searchTerm !== '') {
            $parts[] = '(' . implode(' OR ', [
                "LOWER(COALESCE(c.name, '')) LIKE :search_name",
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
    private function buildAppointmentParams(string $statusFilter, string $searchTerm): array
    {
        $params = [];

        if ($statusFilter !== '' && $this->isAppointmentColumnAvailable('status')) {
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
    private function fetchAppointmentRow(int $id): ?array
    {
        return $this->baseAppointmentsQuery()
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
            ->select(['id', 'slug', 'price_min'])
            ->first();

        return is_array($row) ? $row : null;
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

    private function isInvoiceAppointmentLinkAvailable(): bool
    {
        if ($this->invoiceAppointmentLinkAvailable !== null) {
            return $this->invoiceAppointmentLinkAvailable;
        }

        if (!$this->isInvoiceTableAvailable()) {
            $this->invoiceAppointmentLinkAvailable = false;
            return false;
        }

        try {
            $pdo = app(Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name LIMIT 1'
            );
            $statement->execute([
                'table_name' => 'invoices',
                'column_name' => 'appointment_id',
            ]);
            $this->invoiceAppointmentLinkAvailable = $statement->fetchColumn() !== false;
            return $this->invoiceAppointmentLinkAvailable;
        } catch (\Throwable) {
            $this->invoiceAppointmentLinkAvailable = false;
            return false;
        }
    }

    /** @return array<string, mixed>|null */
    private function fetchLatestInvoiceSummary(int $appointmentId): ?array
    {
        if (!$this->isInvoiceTableAvailable() || !$this->isInvoiceAppointmentLinkAvailable()) {
            return null;
        }

        $row = db('invoices')
            ->where('appointment_id', $appointmentId)
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

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatAppointment(array $row): array
    {
        $row = app(ClientFieldEncryptionService::class)->decryptClientRow($row);

        $clientName = trim((string) ($row['name'] ?? ''));
        $prospectName = trim((string) ($row['prospect_name'] ?? ''));
        $displayName = $clientName !== '' ? $clientName : $prospectName;

        $clientEmail = trim((string) ($row['email'] ?? ''));
        $prospectEmail = trim((string) ($row['prospect_email'] ?? ''));
        $displayEmail = $clientEmail !== '' ? $clientEmail : $prospectEmail;

        $clientPhone = trim((string) ($row['phone'] ?? ''));
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
            'duration_minutes' => isset($row['duration_minutes']) ? (int) $row['duration_minutes'] : 0,
            'status' => (string) ($row['status'] ?? 'pending'),
            'payment_status' => (string) ($row['payment_status'] ?? 'pending'),
            'notes' => $this->formatReadableNotes($row['notes'] ?? null),
            'cancellation_reason' => $row['cancellation_reason'] ?? null,
            'cancellation_timing' => $row['cancellation_timing'] ?? null,
            'cancelled_at' => $row['cancelled_at'] ?? null,
            'status_changed_at' => $row['status_changed_at'] ?? null,
            'status_changed_by_user_id' => $row['status_changed_by_user_id'] !== null ? (int) $row['status_changed_by_user_id'] : null,
            'package_purchase_id' => isset($row['package_purchase_id']) && $row['package_purchase_id'] !== null ? (int) $row['package_purchase_id'] : null,
            'is_package_appointment' => (bool) ($row['is_package_appointment'] ?? false),
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
                'name' => $displayName,
                'email' => $displayEmail,
                'phone' => $clientPhone,
            ],
            'service' => [
                'name' => (string) ($row['service_name'] ?? ''),
                'slug' => (string) ($row['service_slug'] ?? ''),
                'price' => isset($row['service_price']) ? (float) $row['service_price'] : 0.0,
                'duration_minutes' => isset($row['appointment_duration_minutes']) ? (int) $row['appointment_duration_minutes'] : 0,
            ],
        ];
    }

    private function formatReadableNotes(mixed $rawNotes): ?string
    {
        if ($rawNotes === null) {
            return null;
        }

        $notes = trim((string) $rawNotes);
        if ($notes === '') {
            return null;
        }

        $decoded = json_decode($notes, true);
        if (!is_array($decoded)) {
            return $notes;
        }

        $preferredMessage = $this->findPreferredMessage($decoded);
        if ($preferredMessage !== null) {
            return $preferredMessage;
        }

        return 'Kontaktformular-Anfrage';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function findPreferredMessage(array $payload): ?string
    {
        $preferredKeys = ['message', 'update_details', 'steps', 'details', 'note', 'notes'];

        foreach ($preferredKeys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if (is_string($value)) {
                $value = trim($value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach ($payload as $value) {
            if (!is_array($value)) {
                continue;
            }

            $nestedMessage = $this->findPreferredMessage($value);
            if ($nestedMessage !== null) {
                return $nestedMessage;
            }
        }

        return null;
    }

    private function resolveOpenPaymentAmount(array $row): float
    {
        $appointmentStatus = strtolower(trim((string) ($row['status'] ?? '')));
        if ($appointmentStatus === 'accepted') {
            return 0.0;
        }

        $cancellationTiming = strtolower(trim((string) ($row['cancellation_timing'] ?? '')));
        if ($appointmentStatus === 'storno' && $cancellationTiming === 'early') {
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

        if ((bool) ($row['is_package_appointment'] ?? false) && isset($row['package_purchase_price_snapshot'])) {
            return (float) $row['package_purchase_price_snapshot'];
        }

        return isset($row['service_price']) ? (float) $row['service_price'] : 0.0;
    }

    /** @return array{start_hour:int, end_hour:int, slot_step_minutes:int} */
    private function readAvailabilityWindow(): array
    {
        $recurringWindow = $this->readRecurringWindow();

        $startHour = $recurringWindow['start_hour']
            ?? $this->readIntAvailabilityRule('appointments_day_start_hour', 8);
        $endHour = $recurringWindow['end_hour']
            ?? $this->readIntAvailabilityRule('appointments_day_end_hour', 18);
        $slotStepMinutes = $this->readIntSetting('appointment_slot_interval_minutes', 30);

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

    /** @return array{start_hour:int|null,end_hour:int|null} */
    private function readRecurringWindow(): array
    {
        try {
            $rows = db('recurring_availability')
                ->where('is_active', 1)
                ->select(['start_time', 'end_time'])
                ->get();
        } catch (\Throwable) {
            return [
                'start_hour' => null,
                'end_hour' => null,
            ];
        }

        if (!is_array($rows) || $rows === []) {
            return [
                'start_hour' => null,
                'end_hour' => null,
            ];
        }

        $minStartMinutes = null;
        $maxEndMinutes = null;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $startTime = trim((string) ($row['start_time'] ?? ''));
            $endTime = trim((string) ($row['end_time'] ?? ''));

            if (!preg_match('/^(\d{2}):(\d{2})/', $startTime, $startMatch)) {
                continue;
            }
            if (!preg_match('/^(\d{2}):(\d{2})/', $endTime, $endMatch)) {
                continue;
            }

            $startMinutes = (((int) $startMatch[1]) * 60) + (int) $startMatch[2];
            $endMinutes = (((int) $endMatch[1]) * 60) + (int) $endMatch[2];

            if ($endMinutes <= $startMinutes) {
                continue;
            }

            if ($minStartMinutes === null || $startMinutes < $minStartMinutes) {
                $minStartMinutes = $startMinutes;
            }

            if ($maxEndMinutes === null || $endMinutes > $maxEndMinutes) {
                $maxEndMinutes = $endMinutes;
            }
        }

        if ($minStartMinutes === null || $maxEndMinutes === null) {
            return [
                'start_hour' => null,
                'end_hour' => null,
            ];
        }

        return [
            'start_hour' => (int) floor($minStartMinutes / 60),
            'end_hour' => (int) ceil($maxEndMinutes / 60),
        ];
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
    private function resolveClientIdForManualAppointment(array $data): array
    {
        $clientCrypto = app(ClientFieldEncryptionService::class);
        $clientIdRaw = $data['client_id'] ?? null;
        $hasClientId = is_numeric($clientIdRaw) && (int) $clientIdRaw > 0;

        $name = trim((string) ($data['name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phone = trim((string) ($data['phone'] ?? ''));
        $hasNewClientFields = $name !== '' || $email !== '';

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
        if ($name === '') {
            $errors['name'] = ['required'];
        }
        if ($email === '') {
            $errors['email'] = ['required'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = ['email'];
        }
        if ($errors !== []) {
            return [
                'client_id' => null,
                'error' => $errors,
            ];
        }

        $debugMode = (bool) config('app.debug', false);
        if (!$debugMode) {
            $existing = db('clients')
                ->where('name', $name)
                ->where('email', $email)
                ->where('phone', $phone !== '' ? $phone : null)
                ->select(['id'])
                ->first();

            if (is_array($existing) && (int) ($existing['id'] ?? 0) > 0) {
                return [
                    'client_id' => (int) $existing['id'],
                    'error' => null,
                ];
            }
        }

        $clientInsert = [
            'name' => $name,
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
     * @param array<string, mixed> $appointment
     * @return array{client_id:int|null,error:array<string,array<int,string>>|null}
     */
    private function resolveClientIdForAcceptedContactAppointment(array $appointment): array
    {
        $name = trim((string) ($appointment['prospect_name'] ?? ''));
        $email = strtolower(trim((string) ($appointment['prospect_email'] ?? '')));
        $phone = null;

        $notesPayload = $this->decodeNotesPayload($appointment['notes'] ?? null);
        if ($email === '') {
            $email = strtolower(trim((string) ($notesPayload['prospect']['email'] ?? '')));
        }

        if ($name === '') {
            $name = trim((string) ($notesPayload['prospect']['name'] ?? ''));
            if ($name === '') {
                $firstName = trim((string) ($notesPayload['prospect']['first_name'] ?? ''));
                $lastName = trim((string) ($notesPayload['prospect']['last_name'] ?? ''));
                $name = trim($firstName . ' ' . $lastName);
            }
        }

        $phoneCandidate = trim((string) ($notesPayload['prospect']['phone'] ?? ''));
        if ($phoneCandidate !== '') {
            $phone = $phoneCandidate;
        }

        $errors = [];
        if ($name === '') {
            $errors['client'] = ['missing_prospect_name'];
        }
        if ($email === '') {
            $errors['client'] = ['missing_prospect_email'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['client'] = ['invalid_prospect_email'];
        }

        if ($errors !== []) {
            return [
                'client_id' => null,
                'error' => $errors,
            ];
        }

        $clientCrypto = app(ClientFieldEncryptionService::class);
        $existingClientId = $this->findClientIdByEmail($email, $clientCrypto);
        if ($existingClientId !== null) {
            return [
                'client_id' => $existingClientId,
                'error' => null,
            ];
        }

        $clientInsert = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'address' => null,
        ];
        if ($this->isClientTimezoneColumnAvailable()) {
            $clientInsert['timezone'] = self::BERLIN_TIMEZONE;
        }

        $clientInsert = $clientCrypto->encryptClientData($clientInsert);
        $clientId = (int) db('clients')->insert($clientInsert);

        return [
            'client_id' => $clientId > 0 ? $clientId : null,
            'error' => $clientId > 0 ? null : ['client' => ['create_failed']],
        ];
    }

    /**
     * @param array<string, mixed> $appointment
     */
    private function isContactFormAppointment(array $appointment): bool
    {
        $origin = strtolower(trim((string) ($appointment['origin'] ?? '')));
        if ($origin === 'contact_form') {
            return true;
        }

        $notesPayload = $this->decodeNotesPayload($appointment['notes'] ?? null);
        return strtolower(trim((string) ($notesPayload['service_type'] ?? ''))) === 'contact';
    }

    private function findClientIdByEmail(string $email, ClientFieldEncryptionService $clientCrypto): ?int
    {
        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '') {
            return null;
        }

        if ($clientCrypto->isEmailBlindIndexColumnAvailable()) {
            $blindIndex = $clientCrypto->emailBlindIndex($normalizedEmail);
            if ($blindIndex !== null) {
                $row = db('clients')
                    ->where('email_blind_index', $blindIndex)
                    ->select(['id'])
                    ->first();

                if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                    return (int) $row['id'];
                }
            }
        }

        $rows = db('clients')
            ->select(['id', 'email'])
            ->get();

        foreach ($clientCrypto->decryptClientRows(is_array($rows) ? $rows : []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowEmail = strtolower(trim((string) ($row['email'] ?? '')));
            if ($rowEmail === $normalizedEmail) {
                $id = (int) ($row['id'] ?? 0);
                return $id > 0 ? $id : null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeNotesPayload(mixed $rawNotes): array
    {
        if (!is_string($rawNotes) || trim($rawNotes) === '') {
            return [];
        }

        $decoded = json_decode($rawNotes, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchServiceOptions(): array
    {
        $rows = db('services')
            ->select(['id', 'name', 'slug', 'price_min AS price', 'sort_order AS display_order', 'is_active'])
            ->orderBy('sort_order', 'asc')
            ->get();

        return array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
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
        try {
            $rows = db('service_packages')
                ->join('services s', 's.id', '=', 'service_packages.service_id')
                ->where('service_packages.is_active', 1)
                ->where('s.is_active', 1)
                ->select(['id', 'name', 'slug', 'service_id', 'session_count', 'price', 'display_order'])
                ->orderBy('display_order', 'asc')
                ->get();
        } catch (\Throwable) {
            return [];
        }

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
            ->select(['id', 'name', 'email', 'phone'])
            ->orderBy('name', 'asc')
            ->get();

        $decryptedRows = $crypto->decryptClientRows(is_array($rows) ? $rows : []);

        usort($decryptedRows, static function (array $a, array $b): int {
            $aName = strtolower(trim((string) ($a['name'] ?? '')));
            $bName = strtolower(trim((string) ($b['name'] ?? '')));
            if ($aName === $bName) {
                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            }

            return $aName <=> $bName;
        });

        return array_map(static function (array $row): array {
            $name = trim((string) ($row['name'] ?? ''));
            return [
                'id' => (int) ($row['id'] ?? 0),
                'name' => $name,
                'email' => (string) ($row['email'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
            ];
        }, $decryptedRows);
    }

    private function hasSlotConflict(DateTimeImmutable $startedAt, int $durationMinutes, ?int $excludeAppointmentId): bool
    {
        $candidateEnd = $startedAt->modify('+' . $durationMinutes . ' minutes');
        $from = $startedAt->modify('-2 days');
        $to = $candidateEnd->modify('+2 days');

        $database = app(Database::class);
        $pdo = $database->connection();

                                $sqlAppointments = 'SELECT b.id, b.appointment_date AS scheduled_at, b.duration_minutes
            FROM appointments b
            WHERE b.status NOT IN (:storno, :cancelled, :declined)
                            AND b.appointment_date >= :from
                            AND b.appointment_date < :to';

        $params = [
            'storno' => 'storno',
            'cancelled' => 'cancelled',
            'declined' => 'declined',
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];

        if ($excludeAppointmentId !== null && $excludeAppointmentId > 0) {
            $sqlAppointments .= ' AND b.id <> :exclude_id';
            $params['exclude_id'] = $excludeAppointmentId;
        }

        $stmt = $pdo->prepare($sqlAppointments);
        $stmt->execute($params);
        $appointmentRows = $stmt->fetchAll();

        if (is_array($appointmentRows)) {
            $timezone = $this->berlinTimezone();
            foreach ($appointmentRows as $row) {
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

    private function autoCompleteElapsedConfirmedAppointments(): void
    {
        $now = (new DateTimeImmutable('now', $this->berlinTimezone()))->format('Y-m-d H:i:s');

        $rows = db('appointments')
            ->where('status', 'accepted')
            ->where('appointment_date', $now, '<')
            ->select(['id'])
            ->get();

        if (!is_array($rows) || $rows === []) {
            return;
        }

        $completeUpdate = [
            'status' => 'completed',
        ];
        if ($this->isAppointmentColumnAvailable('status_changed_at')) {
            $completeUpdate['status_changed_at'] = $now;
        }
        if ($this->isAppointmentColumnAvailable('status_changed_by_user_id')) {
            $completeUpdate['status_changed_by_user_id'] = null;
        }

        db('appointments')
            ->where('status', 'accepted')
            ->where('appointment_date', $now, '<')
            ->update($completeUpdate);

        foreach ($rows as $row) {
            $appointmentId = (int) ($row['id'] ?? 0);
            if ($appointmentId <= 0) {
                continue;
            }

            $this->insertStatusAuditLog(
                $appointmentId,
                'accepted',
                'completed',
                null,
                'auto_elapsed_completion',
                null
            );
        }
    }

    private function insertStatusAuditLog(
        int $appointmentId,
        string $oldStatus,
        string $newStatus,
        ?int $changedByUserId,
        ?string $revertReason,
        ?string $ipAddress
    ): void {
        if ($appointmentId <= 0) {
            return;
        }

        try {
            if ($this->isAppointmentStatusAuditTableAvailable()) {
                db('appointment_status_audit_log')->insert([
                    'appointment_id' => $appointmentId,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'changed_by_user_id' => $changedByUserId,
                    'revert_reason' => $revertReason,
                    'ip_address' => $ipAddress,
                ]);
                return;
            }

            if ($this->isBookingStatusAuditTableAvailable()) {
                db('booking_status_audit_log')->insert([
                    'booking_id' => $appointmentId,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'changed_by_user_id' => $changedByUserId,
                    'revert_reason' => $revertReason,
                    'ip_address' => $ipAddress,
                ]);
                return;
            }
        } catch (\Throwable $e) {
            error_log('[appointments] status audit insert failed ' . json_encode([
                'appointment_id' => $appointmentId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }

        error_log('[appointments] status audit table missing; skipping audit insert');
    }

    private function isAppointmentStatusAuditTableAvailable(): bool
    {
        if ($this->appointmentStatusAuditTableAvailable !== null) {
            return $this->appointmentStatusAuditTableAvailable;
        }

        $this->appointmentStatusAuditTableAvailable = $this->isTableAvailable('appointment_status_audit_log');

        return $this->appointmentStatusAuditTableAvailable;
    }

    private function isBookingStatusAuditTableAvailable(): bool
    {
        if ($this->bookingStatusAuditTableAvailable !== null) {
            return $this->bookingStatusAuditTableAvailable;
        }

        $this->bookingStatusAuditTableAvailable = $this->isTableAvailable('booking_status_audit_log');

        return $this->bookingStatusAuditTableAvailable;
    }

    private function isTableAvailable(string $tableName): bool
    {
        try {
            $pdo = app(Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1'
            );
            $statement->execute(['table_name' => $tableName]);

            return (bool) $statement->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[appointments] table availability check failed ' . json_encode([
                'table' => $tableName,
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));

            return false;
        }
    }

    private function roundDurationToThirtyMinutes(int $minutes): int
    {
        if ($minutes <= 0) {
            return 0;
        }

        return (int) (ceil($minutes / 30) * 30);
    }

    private function isAppointmentColumnAvailable(string $column): bool
    {
        if (array_key_exists($column, $this->appointmentColumnAvailability)) {
            return $this->appointmentColumnAvailability[$column];
        }

        try {
            $pdo = app(Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name LIMIT 1'
            );
            $statement->execute([
                'table_name' => 'appointments',
                'column_name' => $column,
            ]);
            $exists = $statement->fetchColumn() !== false;
            $this->appointmentColumnAvailability[$column] = $exists;
            return $exists;
        } catch (\Throwable) {
            $this->appointmentColumnAvailability[$column] = false;
            return false;
        }
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
