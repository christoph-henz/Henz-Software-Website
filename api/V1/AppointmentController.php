<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Core\Database\Database;
use App\Core\Http\Response;
use App\Services\EmailAutomationService;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class AppointmentController extends BaseApiController
{
    /** @var array<string, bool> */
    private array $appointmentColumnAvailability = [];
    /** @var array<string, bool> */
    private array $serviceColumnAvailability = [];

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

        if ($serviceType === 'contact' && !$this->readAvailabilityRuleBool('appointments_enabled', true)) {
            return $this->fail('Terminbuchung ist derzeit deaktiviert.', 409, [
                'service_type' => ['appointments_disabled'],
            ]);
        }

        if ($serviceType === 'service' && !$this->readAvailabilityRuleBool('tickets_enabled', true)) {
            return $this->fail('Ticketsystem ist derzeit deaktiviert.', 409, [
                'service_type' => ['tickets_disabled'],
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

        $serviceDurationMinutes = max(30, (int) ($service['duration_minutes'] ?? 60));
        $availabilityValidation = $this->validateRequestedSlot($startsAt, $serviceDurationMinutes, $prefix);
        if ($availabilityValidation !== null) {
            return $this->fail(
                $availabilityValidation['status'] === 409
                    ? 'Der gewählte Termin ist nicht mehr verfügbar.'
                    : 'Validation failed',
                $availabilityValidation['status'],
                $availabilityValidation['errors']
            );
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
            'duration_minutes' => $serviceDurationMinutes,
            'status' => 'pending',
            'notes' => is_string($notesJson) ? $notesJson : $message,
            'prospect_name' => $prospectName !== '' ? $prospectName : null,
            'prospect_email' => $email !== '' ? $email : null,
            'origin' => 'contact_form',
        ]);

        app(EmailAutomationService::class)->dispatch('request.submitted', [
            'recipient_email' => $email,
            'client_first_name' => $firstName,
            'client_last_name' => $lastName,
            'form_data' => $data,
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

        $hasServiceDurationColumn = $this->isServiceColumnAvailable('duration_minutes');
        $columns = $hasServiceDurationColumn
            ? ['id', 'slug', 'duration_minutes']
            : ['id', 'slug'];

        if (ctype_digit($serviceValue)) {
            $service = db('services')
                ->where('id', (int) $serviceValue)
                ->where('is_active', true)
                ->select($columns)
                ->first();

            if (!is_array($service)) {
                return null;
            }

            if (!$hasServiceDurationColumn) {
                $service['duration_minutes'] = 60;
            }

            return $service;
        }

        $service = db('services')
            ->where('slug', $serviceValue)
            ->where('is_active', true)
            ->select($columns)
            ->first();

        if (!is_array($service)) {
            return null;
        }

        if (!$hasServiceDurationColumn) {
            $service['duration_minutes'] = 60;
        }

        return $service;
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

        app(EmailAutomationService::class)->dispatch('request.submitted', [
            'client_id' => $clientId,
            'form_data' => $data,
        ]);

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

    /**
     * @param string $prefix
     * @return array{status:int, errors:array<string, array<int, string>>}|null
     */
    private function validateRequestedSlot(DateTimeImmutable $startsAt, int $durationMinutes, string $prefix): ?array
    {
        $timezone = new DateTimeZone((string) config('app.timezone', 'Europe/Berlin'));
        $now = new DateTimeImmutable('now', $timezone);

        $rules = $this->readAvailabilityRules();
        $window = $this->readAvailabilityWindow();
        $recurringWindows = $this->readRecurringAvailabilityWindows();

        $minAllowed = $now->modify('+' . max(0, (int) ($rules['booking_min_hours_notice'] ?? 24)) . ' hours');
        $maxAllowed = $now->modify('+' . max(1, (int) ($rules['booking_advance_days'] ?? 60)) . ' days');
        if ($startsAt < $minAllowed) {
            return [
                'status' => 422,
                'errors' => [
                    $prefix . 'appointment_date' => ['min_notice'],
                ],
            ];
        }

        if ($startsAt > $maxAllowed) {
            return [
                'status' => 422,
                'errors' => [
                    $prefix . 'appointment_date' => ['max_advance'],
                ],
            ];
        }

        $slotStepMinutes = (int) ($window['slot_step_minutes'] ?? 30);
        $minutesSinceStart = ((int) $startsAt->format('G') * 60) + (int) $startsAt->format('i');
        if (($minutesSinceStart % $slotStepMinutes) !== 0) {
            return [
                'status' => 422,
                'errors' => [
                    $prefix . 'appointment_time' => ['invalid_slot_interval'],
                ],
            ];
        }

        $candidateEnd = $startsAt->modify('+' . max(30, $durationMinutes) . ' minutes');
        $dayStart = $startsAt->setTime(0, 0, 0);
        $workWindows = $this->resolveWorkWindowsForDay($dayStart, $recurringWindows, $window);
        $isWithinWorkWindow = false;
        foreach ($workWindows as $workWindow) {
            if ($startsAt >= $workWindow['start'] && $candidateEnd <= $workWindow['end']) {
                $isWithinWorkWindow = true;
                break;
            }
        }

        if (!$isWithinWorkWindow) {
            return [
                'status' => 422,
                'errors' => [
                    $prefix . 'appointment_time' => ['outside_working_hours'],
                ],
            ];
        }

        $maxAppointmentsPerDay = (int) ($rules['max_appointments_per_day'] ?? 0);
        if ($maxAppointmentsPerDay > 0) {
            $dayEnd = $dayStart->modify('+1 day');
            $bookingCount = $this->countAppointmentsInRange($dayStart, $dayEnd);
            if ($bookingCount >= $maxAppointmentsPerDay) {
                return [
                    'status' => 409,
                    'errors' => [
                        $prefix . 'appointment_time' => ['termin_not_available'],
                    ],
                ];
            }
        }

        if ($this->hasOccupiedIntervalOverlap($startsAt, $candidateEnd, (int) ($rules['buffer_minutes'] ?? 0), $slotStepMinutes)) {
            return [
                'status' => 409,
                'errors' => [
                    $prefix . 'appointment_time' => ['termin_not_available'],
                ],
            ];
        }

        return null;
    }

    /** @return array{buffer_minutes:int,max_appointments_per_day:int,booking_min_hours_notice:int,booking_advance_days:int} */
    private function readAvailabilityRules(): array
    {
        $rules = [
            'buffer_minutes' => 0,
            'max_appointments_per_day' => 0,
            'booking_min_hours_notice' => 24,
            'booking_advance_days' => 60,
        ];

        try {
            $rows = db('availability_rules')->select(['rule_key', 'rule_value'])->get();
        } catch (Throwable) {
            return $rules;
        }

        $legacyMap = [
            'appointments_min_hours_notice' => 'booking_min_hours_notice',
            'appointments_advance_days' => 'booking_advance_days',
            'min_notice_hours' => 'booking_min_hours_notice',
            'advance_days' => 'booking_advance_days',
        ];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = trim((string) ($row['rule_key'] ?? ''));
            if (isset($legacyMap[$key])) {
                $key = $legacyMap[$key];
            }
            if (!array_key_exists($key, $rules)) {
                continue;
            }

            $raw = trim((string) ($row['rule_value'] ?? ''));
            if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
                continue;
            }

            $rules[$key] = (int) $raw;
        }

        return $rules;
    }

    /** @return array{start_hour:int,end_hour:int,slot_step_minutes:int} */
    private function readAvailabilityWindow(): array
    {
        $slotStepMinutes = (int) $this->readIntSetting('booking_slot_interval_minutes', 30);
        if ($slotStepMinutes < 5) {
            $slotStepMinutes = 30;
        }

        return [
            'start_hour' => 8,
            'end_hour' => 18,
            'slot_step_minutes' => $slotStepMinutes,
        ];
    }

    /** @return array<int, list<array{start_minutes:int,end_minutes:int}>> */
    private function readRecurringAvailabilityWindows(): array
    {
        try {
            $rows = db('recurring_availability')
                ->where('is_active', 1)
                ->orderBy('day_of_week', 'asc')
                ->orderBy('start_time', 'asc')
                ->get();
        } catch (Throwable) {
            return [];
        }

        $windowsByDay = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $dayOfWeek = (int) ($row['day_of_week'] ?? 0);
            if ($dayOfWeek < 1 || $dayOfWeek > 7) {
                continue;
            }

            $startMinutes = $this->timeStringToMinutes((string) ($row['start_time'] ?? ''));
            $endMinutes = $this->timeStringToMinutes((string) ($row['end_time'] ?? ''));
            if ($startMinutes === null || $endMinutes === null || $endMinutes <= $startMinutes) {
                continue;
            }

            $windowsByDay[$dayOfWeek][] = [
                'start_minutes' => $startMinutes,
                'end_minutes' => $endMinutes,
            ];
        }

        return $windowsByDay;
    }

    /**
     * @param array<int, list<array{start_minutes:int,end_minutes:int}>> $recurringWindows
     * @param array{start_hour:int,end_hour:int,slot_step_minutes:int} $defaultWindow
     * @return list<array{start:DateTimeImmutable,end:DateTimeImmutable}>
     */
    private function resolveWorkWindowsForDay(DateTimeImmutable $dayStart, array $recurringWindows, array $defaultWindow): array
    {
        $dayOfWeek = (int) $dayStart->format('N');

        if ($recurringWindows === []) {
            if ($dayOfWeek >= 6) {
                return [];
            }

            return [[
                'start' => $dayStart->setTime($defaultWindow['start_hour'], 0, 0),
                'end' => $dayStart->setTime($defaultWindow['end_hour'], 0, 0),
            ]];
        }

        $windows = [];
        foreach ($recurringWindows[$dayOfWeek] ?? [] as $window) {
            $startMinutes = $window['start_minutes'];
            $endMinutes = $window['end_minutes'];
            $windows[] = [
                'start' => $dayStart->setTime((int) floor($startMinutes / 60), $startMinutes % 60, 0),
                'end' => $dayStart->setTime((int) floor($endMinutes / 60), $endMinutes % 60, 0),
            ];
        }

        return $windows;
    }

    private function hasOccupiedIntervalOverlap(DateTimeImmutable $from, DateTimeImmutable $to, int $bufferMinutes, int $slotStepMinutes): bool
    {
        $intervals = $this->fetchOccupiedIntervals(
            $from->modify('-12 hours'),
            $to->modify('+12 hours'),
            $slotStepMinutes,
            $from->getTimezone()
        );

        foreach ($intervals as $interval) {
            $intervalStart = $interval['start'];
            $intervalEnd = $interval['end'];
            if ($bufferMinutes > 0) {
                $intervalStart = $intervalStart->modify('-' . $bufferMinutes . ' minutes');
                $intervalEnd = $intervalEnd->modify('+' . $bufferMinutes . ' minutes');
            }

            if ($from < $intervalEnd && $intervalStart < $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{start:DateTimeImmutable,end:DateTimeImmutable}>
     */
    private function fetchOccupiedIntervals(DateTimeImmutable $from, DateTimeImmutable $to, int $slotStepMinutes, DateTimeZone $timezone): array
    {
        $dateColumn = $this->resolveAppointmentDateColumn();
        if ($dateColumn === null) {
            return [];
        }

        $pdo = app(Database::class)->connection();
        $hasStatusColumn = $this->isAppointmentColumnAvailable('status');
        $hasAppointmentDurationColumn = $this->isAppointmentColumnAvailable('duration_minutes');
        $hasServiceDurationColumn = $this->isServiceColumnAvailable('duration_minutes');

        if ($hasAppointmentDurationColumn && $hasServiceDurationColumn) {
            $durationSql = 'COALESCE(NULLIF(a.duration_minutes, 0), s.duration_minutes, 60)';
        } elseif ($hasAppointmentDurationColumn) {
            $durationSql = 'COALESCE(NULLIF(a.duration_minutes, 0), 60)';
        } elseif ($hasServiceDurationColumn) {
            $durationSql = 'COALESCE(s.duration_minutes, 60)';
        } else {
            $durationSql = '60';
        }
        $statusSql = $hasStatusColumn
            ? "AND (a.status IS NULL OR a.status NOT IN ('storno', 'declined', 'cancelled'))"
            : '';

        $stmt = $pdo->prepare(
            'SELECT a.' . $dateColumn . ' AS scheduled_at, ' . $durationSql . ' AS duration_minutes
             FROM appointments a
             LEFT JOIN services s ON s.id = a.service_id
             WHERE a.' . $dateColumn . ' >= :from
               AND a.' . $dateColumn . ' < :to
               ' . $statusSql
        );
        $stmt->execute([
            ':from' => $from->format('Y-m-d H:i:s'),
            ':to' => $to->format('Y-m-d H:i:s'),
        ]);

        $rows = $stmt->fetchAll();
        $intervals = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $startRaw = (string) ($row['scheduled_at'] ?? '');
            $duration = $this->normalizeDurationMinutes((int) ($row['duration_minutes'] ?? 0), $slotStepMinutes);
            if ($startRaw === '' || $duration <= 0) {
                continue;
            }

            $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startRaw, $timezone);
            if (!$start instanceof DateTimeImmutable) {
                continue;
            }

            $intervals[] = [
                'start' => $start,
                'end' => $start->modify('+' . $duration . ' minutes'),
            ];
        }

        try {
            $blockedStmt = $pdo->prepare(
                'SELECT starts_at, ends_at
                 FROM blocked_times
                 WHERE starts_at < :to
                   AND ends_at > :from'
            );
            $blockedStmt->execute([
                ':from' => $from->format('Y-m-d H:i:s'),
                ':to' => $to->format('Y-m-d H:i:s'),
            ]);

            $blockedRows = $blockedStmt->fetchAll();
            foreach (is_array($blockedRows) ? $blockedRows : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $startRaw = (string) ($row['starts_at'] ?? '');
                $endRaw = (string) ($row['ends_at'] ?? '');
                if ($startRaw === '' || $endRaw === '') {
                    continue;
                }

                $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startRaw, $timezone);
                $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endRaw, $timezone);
                if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable || $end <= $start) {
                    continue;
                }

                $intervals[] = [
                    'start' => $start,
                    'end' => $end,
                ];
            }
        } catch (Throwable) {
            // blocked_times may not exist in partially migrated environments.
        }

        return $intervals;
    }

    private function countAppointmentsInRange(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $dateColumn = $this->resolveAppointmentDateColumn();
        if ($dateColumn === null) {
            return 0;
        }

        $pdo = app(Database::class)->connection();
        $hasStatusColumn = $this->isAppointmentColumnAvailable('status');
        $statusSql = $hasStatusColumn
            ? "AND (status IS NULL OR status NOT IN ('storno', 'declined', 'cancelled'))"
            : '';

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM appointments
             WHERE ' . $dateColumn . ' >= :from
               AND ' . $dateColumn . ' < :to
               ' . $statusSql
        );
        $stmt->execute([
            ':from' => $from->format('Y-m-d H:i:s'),
            ':to' => $to->format('Y-m-d H:i:s'),
        ]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function resolveAppointmentDateColumn(): ?string
    {
        if ($this->isAppointmentColumnAvailable('scheduled_at')) {
            return 'scheduled_at';
        }

        if ($this->isAppointmentColumnAvailable('appointment_date')) {
            return 'appointment_date';
        }

        return null;
    }

    private function isAppointmentColumnAvailable(string $column): bool
    {
        if (array_key_exists($column, $this->appointmentColumnAvailability)) {
            return $this->appointmentColumnAvailability[$column];
        }

        try {
            $pdo = app(Database::class)->connection();
            $stmt = $pdo->prepare(
                'SELECT 1
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                   AND column_name = :column_name
                 LIMIT 1'
            );
            $stmt->execute([
                ':table_name' => 'appointments',
                ':column_name' => $column,
            ]);

            $exists = $stmt->fetchColumn() !== false;
            $this->appointmentColumnAvailability[$column] = $exists;
            return $exists;
        } catch (Throwable) {
            $this->appointmentColumnAvailability[$column] = false;
            return false;
        }
    }

    private function isServiceColumnAvailable(string $column): bool
    {
        if (array_key_exists($column, $this->serviceColumnAvailability)) {
            return $this->serviceColumnAvailability[$column];
        }

        try {
            $pdo = app(Database::class)->connection();
            $stmt = $pdo->prepare(
                'SELECT 1
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                   AND column_name = :column_name
                 LIMIT 1'
            );
            $stmt->execute([
                ':table_name' => 'services',
                ':column_name' => $column,
            ]);

            $exists = $stmt->fetchColumn() !== false;
            $this->serviceColumnAvailability[$column] = $exists;
            return $exists;
        } catch (Throwable) {
            $this->serviceColumnAvailability[$column] = false;
            return false;
        }
    }

    private function readIntSetting(string $key, int $default): int
    {
        try {
            $row = db('settings')->where('`key`', $key)->select(['value'])->first();
        } catch (Throwable) {
            return $default;
        }

        if (!is_array($row)) {
            return $default;
        }

        $raw = trim((string) ($row['value'] ?? ''));
        if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
            return $default;
        }

        return (int) $raw;
    }

    private function timeStringToMinutes(string $time): ?int
    {
        $time = trim($time);
        if ($time === '') {
            return null;
        }

        if (!preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $time, $matches)) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
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

    private function normalizeDurationMinutes(int $durationMinutes, int $slotStepMinutes): int
    {
        if ($durationMinutes <= 0) {
            return 0;
        }

        if ($slotStepMinutes < 5) {
            $slotStepMinutes = 30;
        }

        return (int) (ceil($durationMinutes / $slotStepMinutes) * $slotStepMinutes);
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
