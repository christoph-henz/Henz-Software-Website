<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\BaseApiController;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Support\PermissionBits;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class AvailabilityAdminController extends BaseApiController
{
    private const VIEW_APPOINTMENTS_BIT = 1;
    private const MANAGE_APPOINTMENTS_BIT = 2;
    private const BERLIN_TIMEZONE = 'Europe/Berlin';
    /** @var array<string, bool> */
    private array $appointmentColumnAvailability = [];
    /** @var array<string, bool> */
    private array $serviceColumnAvailability = [];

    public function index(Request $request): Response
    {
        if (!$this->canViewAvailability($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $timezone = new DateTimeZone(self::BERLIN_TIMEZONE);
        $fromRaw = trim((string) $request->query('from', ''));
        $toRaw = trim((string) $request->query('to', ''));

        $from = $this->parseDateTimeInTimezone($fromRaw, $timezone) ?? (new DateTimeImmutable('now', $timezone))->setTime(0, 0, 0);
        $to = $this->parseDateTimeInTimezone($toRaw, $timezone) ?? $from->modify('+90 days');

        if ($to <= $from) {
            return $this->fail('Validation failed', 422, [
                'range' => ['to_must_be_after_from'],
            ]);
        }

        return $this->ok([
            'rules' => $this->readRules(),
            'recurring_availability' => $this->readRecurringAvailability(),
            'blocked_times' => $this->readBlockedTimes($from, $to),
            'range' => [
                'from' => $from->format(DATE_ATOM),
                'to' => $to->format(DATE_ATOM),
            ],
            'timezone' => $timezone->getName(),
        ]);
    }

    public function updateRules(Request $request): Response
    {
        if (!$this->canManageAvailability($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $data = $request->all();
        $errors = [];

        $bufferMinutes = $this->readIntPayload($data['buffer_minutes'] ?? null, 0, 180, $errors, 'buffer_minutes');
        $maxAppointmentsPerDay = $this->readIntPayload($data['max_appointments_per_day'] ?? null, 0, 100, $errors, 'max_appointments_per_day');
        $appointmentsEnabled = $this->normalizeBool($data['appointments_enabled'] ?? true) ? 1 : 0;
        $ticketsEnabled = $this->normalizeBool($data['tickets_enabled'] ?? true) ? 1 : 0;
        $minNoticeHours = $this->readIntPayload($data['appointments_min_hours_notice'] ?? null, 0, 720, $errors, 'appointments_min_hours_notice');
        $advanceDays = $this->readIntPayload($data['appointments_advance_days'] ?? null, 1, 3650, $errors, 'appointments_advance_days');
        $dayStartHour = $this->readIntPayload($data['appointments_day_start_hour'] ?? null, 0, 23, $errors, 'appointments_day_start_hour');
        $dayEndHour = $this->readIntPayload($data['appointments_day_end_hour'] ?? null, 1, 24, $errors, 'appointments_day_end_hour');
        $cancellationNoticeHours = $this->readIntPayload($data['cancellation_hours_notice'] ?? null, 1, 720, $errors, 'cancellation_hours_notice');
        $reminderHoursBefore = $this->readIntPayload($data['reminder_hours_before'] ?? null, 1, 720, $errors, 'reminder_hours_before');

        if ($dayStartHour !== null && $dayEndHour !== null && $dayEndHour <= $dayStartHour) {
            $errors['appointments_day_end_hour'] = ['must_be_after_start_hour'];
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        $rules = [
            'appointments_enabled' => (string) $appointmentsEnabled,
            'tickets_enabled' => (string) $ticketsEnabled,
            'buffer_minutes' => (string) $bufferMinutes,
            'max_appointments_per_day' => (string) $maxAppointmentsPerDay,
            'appointments_min_hours_notice' => (string) $minNoticeHours,
            'appointments_advance_days' => (string) $advanceDays,
            'appointments_day_start_hour' => (string) $dayStartHour,
            'appointments_day_end_hour' => (string) $dayEndHour,
            'cancellation_hours_notice' => (string) $cancellationNoticeHours,
            'reminder_hours_before' => (string) $reminderHoursBefore,
        ];

        $descriptions = [
            'appointments_enabled' => 'Terminbuchung aktiviert (1) oder deaktiviert (0)',
            'tickets_enabled' => 'Ticketsystem aktiviert (1) oder deaktiviert (0)',
            'buffer_minutes' => 'Pufferzeit zwischen Terminen in Minuten',
            'max_appointments_per_day' => 'Maximale Anzahl Termine pro Tag (0 = unbegrenzt)',
            'appointments_min_hours_notice' => 'Mindestvorlaufzeit in Stunden',
            'appointments_advance_days' => 'Maximale Vorausplanung in Tagen',
            'appointments_day_start_hour' => 'Frueheste Stunde fuer Tagesansicht',
            'appointments_day_end_hour' => 'Spaeteste Stunde fuer Tagesansicht',
            'cancellation_hours_notice' => 'Stornofrist in Stunden vor Termin',
            'reminder_hours_before' => 'Erinnerung in Stunden vor Termin',
        ];

        try {
            $pdo = app(Database::class)->connection();
            $stmt = $pdo->prepare(
                'INSERT INTO availability_rules (rule_key, rule_value, description)
                 VALUES (:rule_key, :rule_value, :description)
                 ON DUPLICATE KEY UPDATE
                    rule_value = VALUES(rule_value),
                    description = VALUES(description),
                    updated_at = CURRENT_TIMESTAMP'
            );

            foreach ($rules as $key => $value) {
                $stmt->execute([
                    ':rule_key' => $key,
                    ':rule_value' => $value,
                    ':description' => $descriptions[$key] ?? null,
                ]);
            }
        } catch (Throwable) {
            return $this->fail('Availability rules table not available', 500, [
                'availability_rules' => ['table_not_available'],
            ]);
        }

        return $this->ok([
            'rules' => $this->readRules(),
        ]);
    }

    public function replaceRecurring(Request $request): Response
    {
        if (!$this->canManageAvailability($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $entries = $request->input('entries');
        if (!is_array($entries)) {
            return $this->fail('Validation failed', 422, [
                'entries' => ['required'],
            ]);
        }

        $normalized = [];
        $errors = [];
        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                $errors['entries'][] = 'invalid_entry_at_' . $index;
                continue;
            }

            $dayOfWeek = (int) ($entry['day_of_week'] ?? 0);
            $startTime = trim((string) ($entry['start_time'] ?? ''));
            $endTime = trim((string) ($entry['end_time'] ?? ''));
            $isActive = $this->normalizeBool($entry['is_active'] ?? true) ? 1 : 0;

            if ($dayOfWeek < 1 || $dayOfWeek > 7) {
                $errors['entries'][] = 'invalid_day_of_week_at_' . $index;
                continue;
            }

            if (!$this->isTimeString($startTime) || !$this->isTimeString($endTime)) {
                $errors['entries'][] = 'invalid_time_at_' . $index;
                continue;
            }

            if ($this->timeToMinutes($endTime) <= $this->timeToMinutes($startTime)) {
                $errors['entries'][] = 'end_before_start_at_' . $index;
                continue;
            }

            $normalized[] = [
                'day_of_week' => $dayOfWeek,
                'start_time' => $this->normalizeTime($startTime),
                'end_time' => $this->normalizeTime($endTime),
                'is_active' => $isActive,
            ];
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        try {
            $pdo = app(Database::class)->connection();
            $pdo->beginTransaction();

            $pdo->exec('DELETE FROM recurring_availability');

            if ($normalized !== []) {
                $stmt = $pdo->prepare(
                    'INSERT INTO recurring_availability (day_of_week, start_time, end_time, is_active, created_by_user_id)
                     VALUES (:day_of_week, :start_time, :end_time, :is_active, :created_by_user_id)'
                );

                $userId = $this->getUserId($request);
                foreach ($normalized as $entry) {
                    $stmt->execute([
                        ':day_of_week' => $entry['day_of_week'],
                        ':start_time' => $entry['start_time'],
                        ':end_time' => $entry['end_time'],
                        ':is_active' => $entry['is_active'],
                        ':created_by_user_id' => $userId > 0 ? $userId : null,
                    ]);
                }
            }

            $pdo->commit();
        } catch (Throwable) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return $this->fail('Recurring availability table not available', 500, [
                'recurring_availability' => ['table_not_available'],
            ]);
        }

        return $this->ok([
            'recurring_availability' => $this->readRecurringAvailability(),
        ]);
    }

    public function createBlockedTime(Request $request): Response
    {
        if (!$this->canManageAvailability($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $data = $request->all();
        $startsAtRaw = trim((string) ($data['starts_at'] ?? ''));
        $endsAtRaw = trim((string) ($data['ends_at'] ?? ''));
        $reason = trim((string) ($data['reason'] ?? ''));

        $timezone = new DateTimeZone(self::BERLIN_TIMEZONE);
        $startsAt = $this->parseDateTimeInTimezone($startsAtRaw, $timezone);
        $endsAt = $this->parseDateTimeInTimezone($endsAtRaw, $timezone);

        $errors = [];
        if ($startsAt === null) {
            $errors['starts_at'] = ['invalid_datetime'];
        }
        if ($endsAt === null) {
            $errors['ends_at'] = ['invalid_datetime'];
        }
        if ($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt) {
            $errors['range'] = ['ends_at_must_be_after_starts_at'];
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        try {
            if ($this->hasBookingOverlap($startsAt, $endsAt)) {
                return $this->fail('Sperrzeit kollidiert mit bestehender Buchung. Bitte Termin zuerst umbuchen oder einen anderen Zeitraum wählen.', 409, [
                    'booking' => ['overlaps_existing_booking'],
                ]);
            }
        } catch (Throwable) {
            return $this->fail('Booking validation unavailable', 500, [
                'booking' => ['validation_unavailable'],
            ]);
        }

        try {
            $id = db('blocked_times')->insert([
                'starts_at' => $startsAt->format('Y-m-d H:i:s'),
                'ends_at' => $endsAt->format('Y-m-d H:i:s'),
                'reason' => $reason !== '' ? $reason : null,
                'created_by_user_id' => $this->getUserId($request),
            ]);

            $row = db('blocked_times')->where('id', $id)->first();
        } catch (Throwable) {
            return $this->fail('Blocked times table not available', 500, [
                'blocked_times' => ['table_not_available'],
            ]);
        }

        return $this->ok([
            'blocked_time' => $this->formatBlockedTimeRow(is_array($row) ? $row : []),
        ], 201);
    }

    public function deleteBlockedTime(Request $request): Response
    {
        if (!$this->canManageAvailability($request)) {
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

        try {
            $existing = db('blocked_times')->where('id', $id)->first();
            if (!is_array($existing)) {
                return $this->fail('Blocked time not found', 404);
            }

            db('blocked_times')->where('id', $id)->delete();
        } catch (Throwable) {
            return $this->fail('Blocked times table not available', 500, [
                'blocked_times' => ['table_not_available'],
            ]);
        }

        return $this->ok([
            'deleted' => true,
            'id' => $id,
        ]);
    }

    private function readRules(): array
    {
        $rules = [
            'appointments_enabled' => 1,
            'tickets_enabled' => 1,
            'buffer_minutes' => 0,
            'max_appointments_per_day' => 0,
            'appointments_min_hours_notice' => 24,
            'appointments_advance_days' => 60,
            'appointments_day_start_hour' => 8,
            'appointments_day_end_hour' => 18,
            'cancellation_hours_notice' => 48,
            'reminder_hours_before' => 24,
        ];

        $legacyMap = [
            'min_notice_hours' => 'appointments_min_hours_notice',
            'advance_days' => 'appointments_advance_days',
            'booking_min_hours_notice' => 'appointments_min_hours_notice',
            'booking_advance_days' => 'appointments_advance_days',
        ];

        try {
            $rows = db('availability_rules')
                ->select(['rule_key', 'rule_value'])
                ->get();
        } catch (Throwable) {
            return $rules;
        }

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
            if ($raw === '' || !preg_match('/^-?\\d+$/', $raw)) {
                continue;
            }

            $rules[$key] = (int) $raw;
        }

        return $rules;
    }

    /** @return array<int, array<string, mixed>> */
    private function readRecurringAvailability(): array
    {
        try {
            $rows = db('recurring_availability')
                ->select(['id', 'day_of_week', 'start_time', 'end_time', 'is_active', 'created_by_user_id', 'created_at', 'updated_at'])
                ->orderBy('day_of_week', 'asc')
                ->orderBy('start_time', 'asc')
                ->get();
        } catch (Throwable) {
            return [];
        }

        return array_map(function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'day_of_week' => (int) ($row['day_of_week'] ?? 0),
                'start_time' => (string) ($row['start_time'] ?? ''),
                'end_time' => (string) ($row['end_time'] ?? ''),
                'is_active' => (int) ($row['is_active'] ?? 0) === 1,
                'created_by_user_id' => isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }, is_array($rows) ? $rows : []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readBlockedTimes(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        try {
            $rows = db('blocked_times')
                ->where('starts_at', $to->format('Y-m-d H:i:s'), '<')
                ->where('ends_at', $from->format('Y-m-d H:i:s'), '>')
                ->orderBy('starts_at', 'asc')
                ->get();
        } catch (Throwable) {
            return [];
        }

        return array_map(fn (array $row): array => $this->formatBlockedTimeRow($row), is_array($rows) ? $rows : []);
    }

    /** @param array<string, mixed> $row */
    private function formatBlockedTimeRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'starts_at' => (string) ($row['starts_at'] ?? ''),
            'ends_at' => (string) ($row['ends_at'] ?? ''),
            'reason' => $row['reason'] ?? null,
            'created_by_user_id' => isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function canViewAvailability(Request $request): bool
    {
        $adminUser = $this->adminUser($request);
        $roleMask = (int) ($adminUser['role_mask'] ?? 0);

        $viewBit = PermissionBits::resolve('view_appointments', self::VIEW_APPOINTMENTS_BIT);
        $manageBit = PermissionBits::resolve('manage_appointments', self::MANAGE_APPOINTMENTS_BIT);

        return (($roleMask & ($viewBit | $manageBit)) !== 0);
    }

    private function canManageAvailability(Request $request): bool
    {
        $adminUser = $this->adminUser($request);
        $roleMask = (int) ($adminUser['role_mask'] ?? 0);

        $manageBit = PermissionBits::resolve('manage_appointments', self::MANAGE_APPOINTMENTS_BIT);

        return (($roleMask & $manageBit) !== 0);
    }

    /** @return array<string, mixed> */
    private function adminUser(Request $request): array
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');

        return is_array($session[$sessionKey] ?? null) ? $session[$sessionKey] : [];
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
        if ($raw === '' || !preg_match('/^-?\\d+$/', $raw)) {
            return $default;
        }

        return (int) $raw;
    }

    /** @param array<string, mixed> $errors */
    private function readIntPayload(mixed $value, int $min, int $max, array &$errors, string $field): ?int
    {
        if (!is_numeric($value)) {
            $errors[$field] = ['invalid_integer'];
            return null;
        }

        $parsed = (int) $value;
        if ($parsed < $min || $parsed > $max) {
            $errors[$field] = ['out_of_range'];
            return null;
        }

        return $parsed;
    }

    private function parseDateTimeInTimezone(string $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $formats = [
            'Y-m-d\\TH:i:sP',
            'Y-m-d\\TH:iP',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d\\TH:i:s',
            'Y-m-d\\TH:i',
        ];

        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            if ($dt instanceof DateTimeImmutable) {
                return $dt;
            }
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function isTimeString(string $value): bool
    {
        return preg_match('/^([01]\\d|2[0-3]):([0-5]\\d)(?::([0-5]\\d))?$/', $value) === 1;
    }

    private function normalizeTime(string $value): string
    {
        if (preg_match('/^([01]\\d|2[0-3]):([0-5]\\d)$/', $value) === 1) {
            return $value . ':00';
        }

        return $value;
    }

    private function timeToMinutes(string $value): int
    {
        $parts = explode(':', $value);
        $hours = isset($parts[0]) ? (int) $parts[0] : 0;
        $minutes = isset($parts[1]) ? (int) $parts[1] : 0;

        return ($hours * 60) + $minutes;
    }

    private function hasBookingOverlap(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): bool
    {
        $windowFrom = $startsAt->modify('-1 day');
        $windowTo = $endsAt->modify('+1 day');

        $dateColumn = $this->resolveAppointmentDateColumn();
        if ($dateColumn === null) {
            return false;
        }

        $hasStatusColumn = $this->isAppointmentColumnAvailable('status');
        $hasAppointmentDurationColumn = $this->isAppointmentColumnAvailable('duration_minutes');
        $hasServiceDurationColumn = $this->isServiceColumnAvailable('duration_minutes');

        $pdo = app(Database::class)->connection();
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
            ':from' => $windowFrom->format('Y-m-d H:i:s'),
            ':to' => $windowTo->format('Y-m-d H:i:s'),
        ]);

        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return false;
        }

        $timezone = new DateTimeZone(self::BERLIN_TIMEZONE);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $bookingStartRaw = (string) ($row['scheduled_at'] ?? '');
            $bookingStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $bookingStartRaw, $timezone);
            if (!$bookingStart instanceof DateTimeImmutable) {
                continue;
            }

            $durationMinutes = $this->roundDurationToThirtyMinutes((int) ($row['duration_minutes'] ?? 0));
            if ($durationMinutes <= 0) {
                continue;
            }

            $bookingEnd = $bookingStart->modify('+' . $durationMinutes . ' minutes');
            if ($startsAt < $bookingEnd && $bookingStart < $endsAt) {
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

    private function getUserId(Request $request): ?int
    {
        $adminUser = $this->adminUser($request);
        $userId = (int) ($adminUser['id'] ?? 0);

        return $userId > 0 ? $userId : null;
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
}
