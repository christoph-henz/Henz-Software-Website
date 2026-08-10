<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class AvailabilityController extends BaseApiController
{
    /** @var array<string, bool> */
    private array $appointmentColumnAvailability = [];
    /** @var array<string, bool> */
    private array $serviceColumnAvailability = [];

    public function slots(Request $request): Response
    {
        $isAdminContext = str_starts_with($request->path(), '/admin/');
        $serviceSlug = trim((string) $request->query('service_slug', ''));
        $fromRaw = trim((string) $request->query('from', ''));
        $toRaw = trim((string) $request->query('to', ''));
        $timezoneRaw = trim((string) $request->query('timezone', (string) config('app.timezone', 'UTC')));

        if ($serviceSlug === '' || $fromRaw === '' || $toRaw === '') {
            $errors = [];
            if ($serviceSlug === '') {
                $errors['service_slug'] = ['required'];
            }
            if ($fromRaw === '') {
                $errors['from'] = ['required'];
            }
            if ($toRaw === '') {
                $errors['to'] = ['required'];
            }

            return $this->fail('Validation failed', 422, [
                ...$errors,
            ]);
        }

        $timezone = $this->resolveTimezone($timezoneRaw);
        if ($timezone === null) {
            return $this->fail('Validation failed', 422, [
                'timezone' => ['invalid_timezone'],
            ]);
        }

        $from = $this->parseDateTime($fromRaw, $timezone);
        $to = $this->parseDateTime($toRaw, $timezone);

        if ($from === null || $to === null) {
            return $this->fail('Validation failed', 422, [
                'range' => ['invalid_datetime_range'],
            ]);
        }

        if ($to <= $from) {
            return $this->fail('Validation failed', 422, [
                'range' => ['to_must_be_after_from'],
            ]);
        }

        $service = $this->resolveService($serviceSlug);
        if ($service === null) {
            return $this->fail('Validation failed', 422, [
                'service_slug' => ['invalid_service'],
            ]);
        }

        $rules = $this->readAvailabilityRules();
        $minHoursNotice = $isAdminContext ? 0 : $rules['booking_min_hours_notice'];
        $advanceDays = $isAdminContext ? 3650 : $rules['booking_advance_days'];
        $bufferMinutes = $rules['buffer_minutes'];
        $maxAppointmentsPerDay = $rules['max_appointments_per_day'];
        $window = $this->readAvailabilityWindow();
        $recurringWindows = $this->readRecurringAvailabilityWindows();

        $now = new DateTimeImmutable('now', $timezone);
        $minAllowed = $now->modify('+' . $minHoursNotice . ' hours');
        $maxAllowed = $now->modify('+' . $advanceDays . ' days');

        $effectiveFrom = $from > $minAllowed ? $from : $minAllowed;
        $effectiveTo = $to < $maxAllowed ? $to : $maxAllowed;

        if ($effectiveTo <= $effectiveFrom) {
            return $this->ok([
                'service' => [
                    'id' => (int) $service['id'],
                    'slug' => (string) $service['slug'],
                    'duration_minutes' => (int) $service['duration_minutes'],
                ],
                'timezone' => $timezone->getName(),
                'range' => [
                    'from' => $from->format(DATE_ATOM),
                    'to' => $to->format(DATE_ATOM),
                ],
                'slots' => [],
            ]);
        }

        $bookedIntervals = $this->fetchBookedIntervals($effectiveFrom, $effectiveTo, $window);
        $blockedIntervals = $this->fetchBlockedIntervals($effectiveFrom, $effectiveTo, $timezone);
        $occupiedIntervals = array_merge($bookedIntervals, $blockedIntervals);
        $bookingsPerDay = $this->fetchBookingsCountByDay($effectiveFrom, $effectiveTo);
        $slotDurationMinutes = (int) $service['duration_minutes'];
        $slotStepMinutes = $window['slot_step_minutes'];

        $slots = [];
        $unavailableSlots = [];
        foreach ($this->iterateDays($effectiveFrom, $effectiveTo, $timezone) as $dayStart) {
            $dayDate = $dayStart->format('Y-m-d');
            if ($maxAppointmentsPerDay > 0 && (($bookingsPerDay[$dayDate] ?? 0) >= $maxAppointmentsPerDay)) {
                continue;
            }

            $workWindows = $this->resolveWorkWindowsForDay($dayStart, $recurringWindows, $window);
            if ($workWindows === []) {
                continue;
            }

            foreach ($workWindows as $workWindow) {
                $workStart = $workWindow['start'];
                $workEnd = $workWindow['end'];

                if ($workEnd <= $workStart) {
                    continue;
                }

                $candidate = $workStart;
                while ($candidate < $workEnd) {
                    if ($candidate < $effectiveFrom) {
                        $candidate = $candidate->modify('+' . $slotStepMinutes . ' minutes');
                        continue;
                    }

                    if ($candidate >= $effectiveTo) {
                        break;
                    }

                    $candidateEnd = $candidate->modify('+' . $slotDurationMinutes . ' minutes');
                    if ($candidateEnd > $workEnd || $candidateEnd > $effectiveTo) {
                        $candidate = $candidate->modify('+' . $slotStepMinutes . ' minutes');
                        continue;
                    }

                    $blockedReason = $this->findBlockedReasonForInterval($candidate, $candidateEnd, $blockedIntervals);
                    $isBlocked = $blockedReason !== null;
                    $isBooked = !$isBlocked && $this->overlapsWithBookings($candidate, $candidateEnd, $bookedIntervals, $bufferMinutes);

                    if (!$isBlocked && !$isBooked) {
                        $slots[] = [
                            'start' => $candidate->format(DATE_ATOM),
                            'end' => $candidateEnd->format(DATE_ATOM),
                        ];
                    } else {
                        $unavailableSlots[] = [
                            'start' => $candidate->format(DATE_ATOM),
                            'end' => $candidateEnd->format(DATE_ATOM),
                            'type' => $isBlocked ? 'blocked' : 'booked',
                            'reason' => $isBlocked ? $blockedReason : 'Belegt',
                        ];
                    }

                    $candidate = $candidate->modify('+' . $slotStepMinutes . ' minutes');
                }
            }
        }

        return $this->ok([
            'service' => [
                'id' => (int) $service['id'],
                'slug' => (string) $service['slug'],
                'duration_minutes' => (int) $service['duration_minutes'],
            ],
            'timezone' => $timezone->getName(),
            'range' => [
                'from' => $from->format(DATE_ATOM),
                'to' => $to->format(DATE_ATOM),
            ],
            'slots' => $slots,
            'unavailable_slots' => $unavailableSlots,
        ]);
    }

    public function days(Request $request): Response
    {
        $isAdminContext = str_starts_with($request->path(), '/admin/');
        $serviceSlug = trim((string) $request->query('service_slug', ''));
        $monthRaw = trim((string) $request->query('month', ''));
        $timezoneRaw = trim((string) $request->query('timezone', (string) config('app.timezone', 'UTC')));

        if ($serviceSlug === '' || $monthRaw === '') {
            $errors = [];
            if ($serviceSlug === '') {
                $errors['service_slug'] = ['required'];
            }
            if ($monthRaw === '') {
                $errors['month'] = ['required'];
            }

            return $this->fail('Validation failed', 422, [
                ...$errors,
            ]);
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $monthRaw)) {
            return $this->fail('Validation failed', 422, [
                'month' => ['invalid_month_format'],
            ]);
        }

        $timezone = $this->resolveTimezone($timezoneRaw);
        if ($timezone === null) {
            return $this->fail('Validation failed', 422, [
                'timezone' => ['invalid_timezone'],
            ]);
        }

        $service = $this->resolveService($serviceSlug);
        if ($service === null) {
            return $this->fail('Validation failed', 422, [
                'service_slug' => ['invalid_service'],
            ]);
        }

        $monthStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $monthRaw . '-01 00:00:00', $timezone);
        if (!$monthStart instanceof DateTimeImmutable) {
            return $this->fail('Validation failed', 422, [
                'month' => ['invalid_month_format'],
            ]);
        }

        $monthEnd = $monthStart->modify('first day of next month');

        $rules = $this->readAvailabilityRules();
        $minHoursNotice = $isAdminContext ? 0 : $rules['booking_min_hours_notice'];
        $advanceDays = $isAdminContext ? 3650 : $rules['booking_advance_days'];
        $bufferMinutes = $rules['buffer_minutes'];
        $maxAppointmentsPerDay = $rules['max_appointments_per_day'];
        $window = $this->readAvailabilityWindow();
        $recurringWindows = $this->readRecurringAvailabilityWindows();

        $now = new DateTimeImmutable('now', $timezone);
        $minAllowed = $now->modify('+' . $minHoursNotice . ' hours');
        $maxAllowed = $now->modify('+' . $advanceDays . ' days');

        $effectiveFrom = $monthStart > $minAllowed ? $monthStart : $minAllowed;
        $effectiveTo = $monthEnd < $maxAllowed ? $monthEnd : $maxAllowed;

        $days = [];
        $bookedIntervals = [];
        $blockedIntervals = [];
        $occupiedIntervals = [];
        $bookingsPerDay = [];
        if ($effectiveTo > $effectiveFrom) {
            $bookedIntervals = $this->fetchBookedIntervals($effectiveFrom, $effectiveTo, $window);
            $blockedIntervals = $this->fetchBlockedIntervals($effectiveFrom, $effectiveTo, $timezone);
            $occupiedIntervals = array_merge($bookedIntervals, $blockedIntervals);
            $bookingsPerDay = $this->fetchBookingsCountByDay($effectiveFrom, $effectiveTo);
        }

        $slotDurationMinutes = (int) $service['duration_minutes'];
        $slotStepMinutes = $window['slot_step_minutes'];

        foreach ($this->iterateDays($monthStart, $monthEnd, $timezone) as $dayStart) {
            $dayDate = $dayStart->format('Y-m-d');
            $workWindows = $this->resolveWorkWindowsForDay($dayStart, $recurringWindows, $window);
            if ($workWindows === []) {
                continue;
            }

            $fullDayBlockedReason = $this->findFullDayBlockedReason($workWindows, $blockedIntervals);
            if ($fullDayBlockedReason !== null) {
                $days[] = [
                    'date' => $dayDate,
                    'has_availability' => false,
                    'free_slots_count' => 0,
                    'full_day_blocked' => true,
                    'unavailable_reason' => $fullDayBlockedReason,
                ];
                continue;
            }

            if ($maxAppointmentsPerDay > 0 && (($bookingsPerDay[$dayDate] ?? 0) >= $maxAppointmentsPerDay)) {
                $days[] = [
                    'date' => $dayDate,
                    'has_availability' => false,
                    'free_slots_count' => 0,
                    'full_day_blocked' => false,
                    'unavailable_reason' => 'Tageslimit erreicht',
                ];
                continue;
            }

            $freeSlotsCount = 0;
            foreach ($workWindows as $workWindow) {
                $workStart = $workWindow['start'];
                $workEnd = $workWindow['end'];

                if ($workEnd <= $workStart) {
                    continue;
                }

                $candidate = $workStart;
                while ($candidate < $workEnd) {
                    if ($candidate < $effectiveFrom) {
                        $candidate = $candidate->modify('+' . $slotStepMinutes . ' minutes');
                        continue;
                    }

                    if ($candidate >= $effectiveTo) {
                        break;
                    }

                    $candidateEnd = $candidate->modify('+' . $slotDurationMinutes . ' minutes');
                    if ($candidateEnd > $workEnd || $candidateEnd > $effectiveTo) {
                        $candidate = $candidate->modify('+' . $slotStepMinutes . ' minutes');
                        continue;
                    }

                    if (!$this->overlapsWithBookings($candidate, $candidateEnd, $occupiedIntervals, $bufferMinutes)) {
                        $freeSlotsCount++;
                    }

                    $candidate = $candidate->modify('+' . $slotStepMinutes . ' minutes');
                }
            }

            $days[] = [
                'date' => $dayDate,
                'has_availability' => $freeSlotsCount > 0,
                'free_slots_count' => $freeSlotsCount,
                'full_day_blocked' => false,
                'unavailable_reason' => $freeSlotsCount > 0 ? null : null,
            ];
        }

        return $this->ok([
            'service' => [
                'id' => (int) $service['id'],
                'slug' => (string) $service['slug'],
                'duration_minutes' => (int) $service['duration_minutes'],
            ],
            'timezone' => $timezone->getName(),
            'month' => $monthRaw,
            'days' => $days,
        ]);
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

    private function readIntSetting(string $key, int $default): int
    {
        $row = db('settings')
            ->where('`key`', $key)
            ->select(['value'])
            ->first();

        if ($row === null) {
            return $default;
        }

        $raw = trim((string) ($row['value'] ?? ''));
        if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
            return $default;
        }

        return (int) $raw;
    }

    /** @return array{buffer_minutes:int,max_appointments_per_day:int,booking_min_hours_notice:int,booking_advance_days:int,cancellation_hours_notice:int,reminder_hours_before:int} */
    private function readAvailabilityRules(): array
    {
        $rules = [
            'buffer_minutes' => 0,
            'max_appointments_per_day' => 0,
            'booking_min_hours_notice' => 24,
            'booking_advance_days' => 60,
            'cancellation_hours_notice' => 48,
            'reminder_hours_before' => 24,
        ];

        $legacyMap = [
            'min_notice_hours' => 'booking_min_hours_notice',
            'advance_days' => 'booking_advance_days',
        ];

        try {
            $rows = db('availability_rules')->select(['rule_key', 'rule_value'])->get();
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
            if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
                continue;
            }

            $rules[$key] = (int) $raw;
        }

        return $rules;
    }

    /**
     * @return array<int, list<array{start_minutes:int,end_minutes:int}>>
     */
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
     * @return array{id:int,slug:string,duration_minutes:int}|null
     */
    private function resolveService(string $serviceSlug): ?array
    {
        $serviceSlug = trim($serviceSlug);
        if ($serviceSlug === '') {
            return null;
        }

        $hasServiceDurationColumn = $this->isServiceColumnAvailable('duration_minutes');
        $query = db('services')
            ->where('is_active', 1)
            ->select($hasServiceDurationColumn ? ['id', 'slug', 'duration_minutes'] : ['id', 'slug']);

        if (ctype_digit($serviceSlug)) {
            $query->where('id', (int) $serviceSlug);
        } else {
            $query->where('slug', $serviceSlug);
        }

        $row = $query->first();

        if (!is_array($row)) {
            return null;
        }

        $serviceId = (int) ($row['id'] ?? 0);
        $durationMinutes = $hasServiceDurationColumn
            ? (int) ($row['duration_minutes'] ?? 0)
            : 60;

        if ($serviceId <= 0 || $durationMinutes <= 0) {
            return null;
        }

        return [
            'id' => $serviceId,
            'slug' => (string) ($row['slug'] ?? $serviceSlug),
            'duration_minutes' => $durationMinutes,
        ];
    }

    private function resolveTimezone(string $timezone): ?DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (Throwable) {
            return null;
        }
    }

    private function parseDateTime(string $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $formats = [
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:iP',
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
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

    /**
     * @return array<int, array{start:DateTimeImmutable,end:DateTimeImmutable}>
     */
    private function fetchBookedIntervals(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $window
    ): array
    {
        $dateColumn = $this->resolveAppointmentDateColumn();
        if ($dateColumn === null) {
            return [];
        }

        $bufferTo = $to->modify('+12 hours');
        $bufferFrom = $from->modify('-12 hours');

        $database = app(Database::class);
        $pdo = $database->connection();

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

        $sql = 'SELECT a.' . $dateColumn . ' AS scheduled_at, ' . $durationSql . ' AS duration_minutes
                FROM appointments a
                LEFT JOIN services s ON s.id = a.service_id
                WHERE a.' . $dateColumn . ' >= :from
                  AND a.' . $dateColumn . ' < :to
                  ' . $statusSql;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'from' => $bufferFrom->format('Y-m-d H:i:s'),
            'to' => $bufferTo->format('Y-m-d H:i:s'),
        ]);

        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $intervals = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $scheduledAtRaw = (string) ($row['scheduled_at'] ?? '');
            $duration = $this->normalizeBookingDurationMinutes(
                (int) ($row['duration_minutes'] ?? 0),
                (int) ($window['slot_step_minutes'] ?? 30)
            );
            if ($scheduledAtRaw === '' || $duration <= 0) {
                continue;
            }

            $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $scheduledAtRaw, $from->getTimezone());
            if (!$start instanceof DateTimeImmutable) {
                continue;
            }

            $end = $start->modify('+' . $duration . ' minutes');

            $intervals[] = [
                'start' => $start,
                'end' => $end,
            ];
        }

        return $intervals;
    }

    /**
     * @return array<int, array{start:DateTimeImmutable,end:DateTimeImmutable,reason:string}>
     */
    private function fetchBlockedIntervals(DateTimeImmutable $from, DateTimeImmutable $to, DateTimeZone $timezone): array
    {
        $database = app(Database::class);
        $pdo = $database->connection();

        $bufferTo = $to->modify('+12 hours');
        $bufferFrom = $from->modify('-12 hours');

        try {
            $blockedTimesSql = 'SELECT starts_at, ends_at, reason
                                FROM blocked_times
                                WHERE starts_at < :to
                                  AND ends_at > :from';
            $blockedTimesStmt = $pdo->prepare($blockedTimesSql);
            $blockedTimesStmt->execute([
                'from' => $bufferFrom->format('Y-m-d H:i:s'),
                'to' => $bufferTo->format('Y-m-d H:i:s'),
            ]);

            $rows = $blockedTimesStmt->fetchAll();
            if (!is_array($rows)) {
                return [];
            }

            $intervals = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $startsAtRaw = (string) ($row['starts_at'] ?? '');
                $endsAtRaw = (string) ($row['ends_at'] ?? '');
                if ($startsAtRaw === '' || $endsAtRaw === '') {
                    continue;
                }

                $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startsAtRaw, $timezone);
                $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endsAtRaw, $timezone);
                if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable || $end <= $start) {
                    continue;
                }

                $reason = trim((string) ($row['reason'] ?? ''));
                $intervals[] = [
                    'start' => $start,
                    'end' => $end,
                    'reason' => $reason !== '' ? $reason : 'Sperrzeit',
                ];
            }

            return $intervals;
        } catch (Throwable) {
            // blocked_times table is optional before H-004 migration is applied
            return [];
        }
    }

    /** @return array<string, int> */
    private function fetchBookingsCountByDay(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
                $dateColumn = $this->resolveAppointmentDateColumn();
                if ($dateColumn === null) {
                        return [];
                }

        $database = app(Database::class);
        $pdo = $database->connection();

                $hasStatusColumn = $this->isAppointmentColumnAvailable('status');
                $statusSql = $hasStatusColumn
                        ? "AND (status IS NULL OR status NOT IN ('storno', 'declined', 'cancelled'))"
                        : '';

                $sql = 'SELECT DATE(' . $dateColumn . ') AS booking_date, COUNT(*) AS booking_count
                                FROM appointments
                                WHERE ' . $dateColumn . ' >= :from
                                    AND ' . $dateColumn . ' < :to
                                    ' . $statusSql . '
                                GROUP BY DATE(' . $dateColumn . ')';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ]);

        $rows = $stmt->fetchAll();
        $counts = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = trim((string) ($row['booking_date'] ?? ''));
            if ($date === '') {
                continue;
            }

            $counts[$date] = (int) ($row['booking_count'] ?? 0);
        }

        return $counts;
    }

    private function normalizeBookingDurationMinutes(int $durationMinutes, int $slotStepMinutes): int
    {
        if ($durationMinutes <= 0) {
            return 0;
        }

        if ($slotStepMinutes < 5) {
            $slotStepMinutes = 30;
        }

        return (int) (ceil($durationMinutes / $slotStepMinutes) * $slotStepMinutes);
    }

    private function isWithinDayWindow(DateTimeImmutable $start, DateTimeImmutable $end, array $window): bool
    {
        $dayStart = $start->setTime(0, 0, 0);
        $windowStart = $dayStart->setTime((int) $window['start_hour'], 0, 0);
        $windowEnd = $dayStart->setTime((int) $window['end_hour'], 0, 0);

        return $start >= $windowStart && $end <= $windowEnd;
    }

    /**
     * @param array<int, array{start:DateTimeImmutable,end:DateTimeImmutable}> $bookedIntervals
     */
    private function overlapsWithBookings(
        DateTimeImmutable $candidateStart,
        DateTimeImmutable $candidateEnd,
        array $bookedIntervals,
        int $bufferMinutes = 0
    ): bool {
        foreach ($bookedIntervals as $interval) {
            $intervalStart = $interval['start'];
            $intervalEnd = $interval['end'];

            if ($bufferMinutes > 0) {
                $intervalStart = $intervalStart->modify('-' . $bufferMinutes . ' minutes');
                $intervalEnd = $intervalEnd->modify('+' . $bufferMinutes . ' minutes');
            }

            if ($candidateStart < $intervalEnd && $intervalStart < $candidateEnd) {
                return true;
            }
        }

        return false;
    }

    private function findBlockedReasonForInterval(
        DateTimeImmutable $candidateStart,
        DateTimeImmutable $candidateEnd,
        array $blockedIntervals
    ): ?string {
        foreach ($blockedIntervals as $interval) {
            if (!($interval['start'] instanceof DateTimeImmutable) || !($interval['end'] instanceof DateTimeImmutable)) {
                continue;
            }

            if ($candidateStart < $interval['end'] && $interval['start'] < $candidateEnd) {
                $reason = trim((string) ($interval['reason'] ?? ''));
                return $reason !== '' ? $reason : 'Sperrzeit';
            }
        }

        return null;
    }

    private function findFullDayBlockedReason(array $workWindows, array $blockedIntervals): ?string
    {
        foreach ($blockedIntervals as $blockedInterval) {
            if (!($blockedInterval['start'] instanceof DateTimeImmutable) || !($blockedInterval['end'] instanceof DateTimeImmutable)) {
                continue;
            }

            $coversWholeDay = true;
            foreach ($workWindows as $workWindow) {
                $workStart = $workWindow['start'] ?? null;
                $workEnd = $workWindow['end'] ?? null;
                if (!($workStart instanceof DateTimeImmutable) || !($workEnd instanceof DateTimeImmutable)) {
                    $coversWholeDay = false;
                    break;
                }

                if (!($blockedInterval['start'] <= $workStart && $blockedInterval['end'] >= $workEnd)) {
                    $coversWholeDay = false;
                    break;
                }
            }

            if ($coversWholeDay) {
                $reason = trim((string) ($blockedInterval['reason'] ?? ''));
                return $reason !== '' ? $reason : 'Sperrzeit';
            }
        }

        return null;
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

    /**
     * @return iterable<int, DateTimeImmutable>
     */
    private function iterateDays(DateTimeImmutable $from, DateTimeImmutable $to, DateTimeZone $timezone): iterable
    {
        $fromDay = $from->setTime(0, 0, 0);
        $toDay = $to->setTime(0, 0, 0);

        $period = new DatePeriod(
            $fromDay,
            new DateInterval('P1D'),
            $toDay->modify('+1 day')
        );

        foreach ($period as $day) {
            if ($day instanceof DateTimeImmutable) {
                yield $day->setTimezone($timezone);
            }
        }
    }

    private function isWeekend(DateTimeImmutable $day): bool
    {
        $dayOfWeek = (int) $day->format('N');
        return $dayOfWeek >= 6;
    }

    /**
     * @param array<int, list<array{start_minutes:int,end_minutes:int}>> $recurringWindows
     * @param array{start_hour:int, end_hour:int, slot_step_minutes:int} $defaultWindow
     * @return list<array{start:DateTimeImmutable,end:DateTimeImmutable}>
     */
    private function resolveWorkWindowsForDay(
        DateTimeImmutable $dayStart,
        array $recurringWindows,
        array $defaultWindow
    ): array {
        $dayOfWeek = (int) $dayStart->format('N');

        if ($recurringWindows === []) {
            if ($this->isWeekend($dayStart)) {
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
}
