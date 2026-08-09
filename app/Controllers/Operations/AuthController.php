<?php

declare(strict_types=1);

namespace App\Controllers\Operations;

use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfTokenManager;
use App\Core\Support\PermissionBits;
use App\Services\ClientFieldEncryptionService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class AuthController
{
    private const VIEW_BOOKINGS_BIT = 1;
    private const MANAGE_BOOKINGS_BIT = 2;
    private const VIEW_CLIENTS_BIT = 8;
    private const MANAGE_CLIENTS_BIT = 16;
    private const VIEW_PAYMENTS_BIT = 32;
    private const VIEW_PROJECTS_BIT = 256;
    private const MANAGE_PROJECTS_BIT = 512;

    public function loginForm(Request $request): Response
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');

        if (is_array($session[$sessionKey] ?? null) && $session[$sessionKey] !== []) {
            return Response::redirect((string) config('admin.dashboard_path', '/dashboard'));
        }

        return $this->render('admin-login-page.php', [
            'pageTitle' => 'Admin Login – Henz Software',
            'csrfToken' => app(CsrfTokenManager::class)->token(),
            'redirectTo' => $this->sanitizeRedirect((string) $request->query('redirect', '')),
            'errorMessage' => '',
            'email' => '',
        ]);
    }

    public function login(Request $request): Response
    {
        $request->session();
        $csrf = app(CsrfTokenManager::class);

        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $redirectTo = $this->sanitizeRedirect((string) $request->input('redirect', ''));

        if (!$csrf->isValid((string) $request->input('_token', ''))) {
            return $this->render('admin-login-page.php', [
                'pageTitle' => 'Admin Login – Henz Software',
                'csrfToken' => $csrf->token(),
                'redirectTo' => $redirectTo,
                'errorMessage' => 'Ungültige Sitzung. Bitte laden Sie die Seite neu.',
                'email' => $email,
            ]);
        }

        if ($email === '' || $password === '') {
            return $this->render('admin-login-page.php', [
                'pageTitle' => 'Admin Login – Henz Software',
                'csrfToken' => $csrf->token(),
                'redirectTo' => $redirectTo,
                'errorMessage' => 'Bitte E-Mail und Passwort ausfüllen.',
                'email' => $email,
            ]);
        }

        $user = db('users')
            ->where('email', $email)
            ->select(['id', 'first_name', 'last_name', 'email', 'password_hash', 'role_mask', 'is_active'])
            ->first();

        $loginErrorMessage = $this->determineLoginErrorMessage($user, $password);
        if ($loginErrorMessage !== null) {
            return $this->render('admin-login-page.php', [
                'pageTitle' => 'Admin Login – Henz Software',
                'csrfToken' => $csrf->token(),
                'redirectTo' => $redirectTo,
                'errorMessage' => $loginErrorMessage,
                'email' => $email,
            ]);
        }

        session_regenerate_id(true);

        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $_SESSION[$sessionKey] = [
            'id' => (int) ($user['id'] ?? 0),
            'first_name' => (string) ($user['first_name'] ?? ''),
            'last_name' => (string) ($user['last_name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'role_mask' => (int) ($user['role_mask'] ?? 0),
            'logged_in_at' => date(DATE_ATOM),
        ];

        return Response::redirect($redirectTo !== '' ? $redirectTo : (string) config('admin.dashboard_path', '/dashboard'), 303)
            ->withHeader('Set-Cookie', $this->buildAdminSeenCookie(false, $request));
    }

    public function dashboard(Request $request): Response
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = is_array($session[$sessionKey] ?? null) ? $session[$sessionKey] : [];

        $roleMask = (int) ($adminUser['role_mask'] ?? 0);

        $viewBookingsBit = PermissionBits::resolve('view_bookings', self::VIEW_BOOKINGS_BIT);
        $manageBookingsBit = PermissionBits::resolve('manage_bookings', self::MANAGE_BOOKINGS_BIT);
        $viewClientsBit = PermissionBits::resolve('view_clients', self::VIEW_CLIENTS_BIT);
        $manageClientsBit = PermissionBits::resolve('manage_clients', self::MANAGE_CLIENTS_BIT);
        $viewPaymentsBit = PermissionBits::resolve('view_payments', self::VIEW_PAYMENTS_BIT);
        $viewProjectsBit = max(
            PermissionBits::resolve('view_project', self::VIEW_PROJECTS_BIT),
            PermissionBits::resolve('view_projects', self::VIEW_PROJECTS_BIT)
        );
        $manageProjectsBit = PermissionBits::resolve('manage_projects', self::MANAGE_PROJECTS_BIT);
        $viewFinancesBit = max(
            PermissionBits::resolve('view_finance', 0),
            PermissionBits::resolve('view_finances', 0)
        );
        $viewUsersBit = max(
            PermissionBits::resolve('view_user', 0),
            PermissionBits::resolve('view_users', 0)
        );
        $manageUsersBit = PermissionBits::resolve('manage_users', 0);

        $canViewBookings = (($roleMask & $viewBookingsBit) !== 0) || (($roleMask & $manageBookingsBit) !== 0);
        $canManageBookings = ($roleMask & $manageBookingsBit) !== 0;
        $canViewClients = (($roleMask & $viewClientsBit) !== 0) || (($roleMask & $manageClientsBit) !== 0);
        $canViewPayments = ($roleMask & $viewPaymentsBit) !== 0;
        $canViewFinances = $viewFinancesBit > 0 && (($roleMask & $viewFinancesBit) !== 0);
        $canViewUsers = (($viewUsersBit > 0) && (($roleMask & $viewUsersBit) !== 0)) || (($manageUsersBit > 0) && (($roleMask & $manageUsersBit) !== 0));
        $canViewProjects = (($roleMask & $viewProjectsBit) !== 0) || (($roleMask & $manageProjectsBit) !== 0);
        $canViewRevenue = $canViewPayments || $canViewFinances;
        $canViewKpiSection = $canViewProjects || $canViewRevenue || $canViewUsers;
        $canViewActivitySection = $canViewProjects || $canViewRevenue || $canViewUsers;

        $dashboardConfig = require base_path('public/ui/_config/operations/admin-dashboard.php');
        $dashboardConfig['can_view_bookings'] = $canViewBookings;
        $dashboardConfig['can_view_kpi'] = $canViewKpiSection;
        $dashboardConfig['can_manage_bookings'] = $canManageBookings;

        $dashboardData = $this->buildDashboardData([
            'can_view_projects' => $canViewProjects,
            'can_view_clients' => $canViewClients,
            'can_view_bookings' => $canViewBookings,
            'can_view_payments' => $canViewPayments,
            'can_view_finances' => $canViewFinances,
            'can_view_users' => $canViewUsers,
        ]);

        return $this->render('admin-dashboard-page.php', [
            'pageTitle' => 'Adminpanel – Henz Software',
            'adminUser' => $adminUser,
            'logoutAction' => '/logout',
            'csrfToken' => app(CsrfTokenManager::class)->token(),
            'dashboardConfig' => $dashboardConfig,
            'kpiCards' => $dashboardData['kpiCards'],
            'revenueSeries' => $dashboardData['revenueSeries'],
            'revenueSummary' => $dashboardData['revenueSummary'],
            'activityItems' => $dashboardData['activityItems'],
            'projectRows' => $dashboardData['projectRows'],
            'canViewProjects' => $canViewProjects,
            'canViewKpiSection' => $canViewKpiSection,
            'canViewRevenueSection' => $canViewRevenue,
            'canViewActivitySection' => $canViewActivitySection,
        ]);
    }

    /**
     * @param array<string, bool> $permissions
     * @return array<string, mixed>
     */
    private function buildDashboardData(array $permissions): array
    {
        $timezone = new DateTimeZone((string) config('app.timezone', 'Europe/Berlin'));
        $now = new DateTimeImmutable('now', $timezone);

        $weekStart = $now->modify('monday this week')->setTime(0, 0, 0);
        $weekEnd = $weekStart->modify('+7 days');
        $prevWeekStart = $weekStart->modify('-7 days');

        $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
        $nextMonthStart = $monthStart->modify('+1 month');
        $prevMonthStart = $monthStart->modify('-1 month');

        $activeProjects = 0;
        $activeProjectsDelta = null;
        $monthRevenue = 0.0;
        $prevMonthRevenue = 0.0;
        $weekRevenue = 0.0;
        $prevWeekRevenue = 0.0;
        $revenueSeries = $this->emptyRevenueSeries();
        $activityItems = [];
        $projectRows = [];

        try {
            $pdo = app(Database::class)->connection();

            if ($permissions['can_view_projects'] ?? false) {
                $activeProjects = $this->queryInt(
                    $pdo,
                    'SELECT COUNT(*)
                     FROM projects
                     WHERE deleted_at IS NULL AND is_active = 1'
                );

                $activeProjectsLastWeek = $this->queryInt(
                    $pdo,
                    'SELECT COUNT(*)
                     FROM projects
                     WHERE deleted_at IS NULL
                       AND is_active = 1
                       AND created_at < :week_start',
                    [
                        ':week_start' => $weekStart->format('Y-m-d H:i:s'),
                    ]
                );

                $activeProjectsDelta = $activeProjects - $activeProjectsLastWeek;
                $projectRows = $this->fetchCurrentProjects($pdo);
            }

            if (($permissions['can_view_payments'] ?? false) || ($permissions['can_view_finances'] ?? false)) {
                $monthRevenue = $this->queryFloat(
                    $pdo,
                    "SELECT COALESCE(SUM(total_amount), 0)
                     FROM invoices
                     WHERE invoice_date >= :from_date
                       AND invoice_date < :to_date
                       AND status IN ('created', 'sent', 'paid')",
                    [
                        ':from_date' => $monthStart->format('Y-m-d'),
                        ':to_date' => $nextMonthStart->format('Y-m-d'),
                    ]
                );

                $prevMonthRevenue = $this->queryFloat(
                    $pdo,
                    "SELECT COALESCE(SUM(total_amount), 0)
                     FROM invoices
                     WHERE invoice_date >= :from_date
                       AND invoice_date < :to_date
                       AND status IN ('created', 'sent', 'paid')",
                    [
                        ':from_date' => $prevMonthStart->format('Y-m-d'),
                        ':to_date' => $monthStart->format('Y-m-d'),
                    ]
                );

                $weekRevenue = $this->queryFloat(
                    $pdo,
                    "SELECT COALESCE(SUM(total_amount), 0)
                     FROM invoices
                     WHERE invoice_date >= :from_date
                       AND invoice_date < :to_date
                       AND status IN ('created', 'sent', 'paid')",
                    [
                        ':from_date' => $weekStart->format('Y-m-d'),
                        ':to_date' => $weekEnd->format('Y-m-d'),
                    ]
                );

                $prevWeekRevenue = $this->queryFloat(
                    $pdo,
                    "SELECT COALESCE(SUM(total_amount), 0)
                     FROM invoices
                     WHERE invoice_date >= :from_date
                       AND invoice_date < :to_date
                       AND status IN ('created', 'sent', 'paid')",
                    [
                        ':from_date' => $prevWeekStart->format('Y-m-d'),
                        ':to_date' => $weekStart->format('Y-m-d'),
                    ]
                );

                $revenueSeries = $this->fetchRevenueSeries($pdo, $weekStart, $weekEnd);
            }

            $activityItems = $this->fetchActivityItems($pdo, $permissions, $now, $timezone);
        } catch (Throwable) {
            $activityItems = [];
        }

        $kpiCards = [];

        if ($permissions['can_view_projects'] ?? false) {
            $kpiCards[] = [
                'label' => 'Aktive Projekte',
                'value' => (string) $activeProjects,
                'delta' => $this->signedDeltaLabel($activeProjectsDelta, 'zur Vorwoche'),
                'color' => 'cyan',
                'url' => '/projects',
            ];
        }

        if ($permissions['can_view_projects'] ?? false) {
            $kpiCards[] = [
                'label' => 'Offene Tickets',
                'value' => '34',
                'delta' => '-8 diese Woche',
                'color' => 'amber',
                'url' => '/tickets',
            ];
        }

        if ($permissions['can_view_users'] ?? false) {
            $kpiCards[] = [
                'label' => 'Team-Auslastung',
                'value' => '87%',
                'delta' => '+4% diese Woche',
                'color' => 'emerald',
                'url' => '/users',
            ];
        }

        if (($permissions['can_view_payments'] ?? false) || ($permissions['can_view_finances'] ?? false)) {
            $kpiCards[] = [
                'label' => 'Monatsumsatz',
                'value' => $this->formatEur($monthRevenue),
                'delta' => $this->percentDeltaLabel($monthRevenue, $prevMonthRevenue, 'zum Vormonat'),
                'color' => 'violet',
                'url' => '/payments',
            ];
        }

        if ($kpiCards === []) {
            $kpiCards[] = [
                'label' => 'Dashboard',
                'value' => '—',
                'delta' => 'Keine Berechtigung',
                'color' => 'cyan',
                'url' => null,
            ];
        }

        return [
            'kpiCards' => $kpiCards,
            'revenueSeries' => $revenueSeries,
            'revenueSummary' => [
                'week_total_label' => (($permissions['can_view_payments'] ?? false) || ($permissions['can_view_finances'] ?? false)) ? $this->formatEur($weekRevenue) . ' gesamt' : 'Keine Berechtigung',
                'week_delta_label' => (($permissions['can_view_payments'] ?? false) || ($permissions['can_view_finances'] ?? false))
                    ? $this->percentDeltaLabel($weekRevenue, $prevWeekRevenue, '')
                    : '—',
            ],
            'activityItems' => $activityItems,
            'projectRows' => $projectRows,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchCurrentProjects(PDO $pdo): array
    {
        $clientCrypto = app(ClientFieldEncryptionService::class);

        $stmt = $pdo->prepare(
            'SELECT p.id,
                p.name,
                    c.name AS client_name,
                    p.status,
                    p.progress,
                    p.due_date
             FROM projects p
             JOIN clients c ON c.id = p.client_id
             WHERE p.deleted_at IS NULL
               AND p.is_active = 1
             ORDER BY p.updated_at DESC
             LIMIT 8'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row) use ($clientCrypto): array {
            $clientName = $this->decryptClientName((string) ($row['client_name'] ?? ''), $clientCrypto);

            return [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'client' => $clientName,
                'status' => $this->projectStatusLabel((string) ($row['status'] ?? '')),
                'progress' => (int) ($row['progress'] ?? 0),
                'due' => $this->formatDate((string) ($row['due_date'] ?? '')),
            ];
        }, is_array($rows) ? $rows : []);
    }

    /** @return array<int, array{day: string, amount: int}> */
    private function fetchRevenueSeries(PDO $pdo, DateTimeImmutable $weekStart, DateTimeImmutable $weekEnd): array
    {
        $stmt = $pdo->prepare(
            "SELECT DATE(invoice_date) AS day_key,
                    COALESCE(SUM(total_amount), 0) AS total
             FROM invoices
             WHERE invoice_date >= :from_date
               AND invoice_date < :to_date
               AND status IN ('created', 'sent', 'paid')
             GROUP BY DATE(invoice_date)"
        );
        $stmt->execute([
            ':from_date' => $weekStart->format('Y-m-d'),
            ':to_date' => $weekEnd->format('Y-m-d'),
        ]);

        $bucket = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bucket[(string) ($row['day_key'] ?? '')] = (int) round((float) ($row['total'] ?? 0));
        }

        $labels = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        $series = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->modify('+' . $i . ' days')->format('Y-m-d');
            $series[] = [
                'day' => $labels[$i],
                'amount' => (int) ($bucket[$date] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * @param array<string, bool> $permissions
     * @return array<int, array<string, string>>
     */
    private function fetchActivityItems(PDO $pdo, array $permissions, DateTimeImmutable $now, DateTimeZone $timezone): array
    {
        $clientCrypto = app(ClientFieldEncryptionService::class);
        $items = [];

        if ($permissions['can_view_projects'] ?? false) {
            $stmt = $pdo->prepare(
                'SELECT p.name AS project_name, pp.phase_name, pp.status, pp.updated_at
                 FROM project_phase pp
                 JOIN projects p ON p.id = pp.project_id
                 WHERE pp.deleted_at IS NULL
                   AND p.deleted_at IS NULL
                 ORDER BY pp.updated_at DESC
                 LIMIT 6'
            );
            $stmt->execute();

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $at = (string) ($row['updated_at'] ?? '');
                if ($at === '') {
                    continue;
                }
                $items[] = [
                    'text' => 'Projektphase "' . (string) ($row['phase_name'] ?? '') . '" in "' . (string) ($row['project_name'] ?? '') . '" wurde aktualisiert (' . (string) ($row['status'] ?? '-') . ').',
                    'happened_at' => $at,
                    'color' => 'cyan',
                ];
            }
        }

        if ($permissions['can_view_clients'] ?? false) {
            $stmt = $pdo->prepare(
                'SELECT name, updated_at
                 FROM clients
                 ORDER BY updated_at DESC
                 LIMIT 6'
            );
            $stmt->execute();

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $at = (string) ($row['updated_at'] ?? '');
                if ($at === '') {
                    continue;
                }
                $clientName = $this->decryptClientName((string) ($row['name'] ?? 'Unbekannt'), $clientCrypto);
                $items[] = [
                    'text' => 'Clientprofil von "' . $clientName . '" wurde aktualisiert.',
                    'happened_at' => $at,
                    'color' => 'amber',
                ];
            }
        }

        if ($permissions['can_view_bookings'] ?? false) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT id, status, scheduled_at, updated_at
                     FROM bookings
                     ORDER BY updated_at DESC
                     LIMIT 6'
                );
                $stmt->execute();

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $at = (string) ($row['updated_at'] ?? '');
                    if ($at === '') {
                        continue;
                    }
                    $scheduledAt = (string) ($row['scheduled_at'] ?? '');
                    $scheduledLabel = $this->formatDateTime($scheduledAt);
                    $items[] = [
                        'text' => 'Appointment #' . (string) ($row['id'] ?? '-') . ' (' . (string) ($row['status'] ?? '-') . ') am ' . $scheduledLabel . '.',
                        'happened_at' => $at,
                        'color' => 'emerald',
                    ];
                }
            } catch (Throwable) {
                // Ignore booking activity errors so other dashboard sections remain available.
            }
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string) ($b['happened_at'] ?? ''), (string) ($a['happened_at'] ?? ''));
        });

        $items = array_slice($items, 0, 8);

        return array_map(function (array $item) use ($now, $timezone): array {
            $time = (string) ($item['happened_at'] ?? '');
            $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $time, $timezone);
            $timeLabel = $date instanceof DateTimeImmutable ? $this->relativeTime($date, $now) : '-';

            return [
                'text' => (string) ($item['text'] ?? ''),
                'time' => $timeLabel,
                'color' => (string) ($item['color'] ?? 'cyan'),
            ];
        }, $items);
    }

    private function queryInt(PDO $pdo, string $sql, array $params = []): int
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function queryFloat(PDO $pdo, string $sql, array $params = []): float
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (float) ($stmt->fetchColumn() ?: 0);
    }

    /** @return array<int, array{day: string, amount: int}> */
    private function emptyRevenueSeries(): array
    {
        return [
            ['day' => 'Mo', 'amount' => 0],
            ['day' => 'Di', 'amount' => 0],
            ['day' => 'Mi', 'amount' => 0],
            ['day' => 'Do', 'amount' => 0],
            ['day' => 'Fr', 'amount' => 0],
            ['day' => 'Sa', 'amount' => 0],
            ['day' => 'So', 'amount' => 0],
        ];
    }

    private function formatEur(float $amount): string
    {
        return 'EUR ' . number_format($amount, 2, ',', '.');
    }

    private function signedDeltaLabel(?int $delta, string $suffix): string
    {
        $value = $delta ?? 0;
        $prefix = $value > 0 ? '+' : '';
        return $prefix . $value . ' ' . $suffix;
    }

    private function percentDeltaLabel(float $current, float $previous, string $suffix): string
    {
        if ($previous <= 0.0) {
            if ($current <= 0.0) {
                return '0,0% ' . trim($suffix);
            }
            return '+100,0% ' . trim($suffix);
        }

        $delta = (($current - $previous) / $previous) * 100;
        $prefix = $delta > 0 ? '+' : '';
        $label = $prefix . number_format($delta, 1, ',', '.') . '%';

        $suffix = trim($suffix);
        return $suffix !== '' ? ($label . ' ' . $suffix) : $label;
    }

    private function formatDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '-';
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $parsed instanceof DateTimeImmutable ? $parsed->format('d.m.Y') : $date;
    }

    private function formatDateTime(string $dateTime): string
    {
        $value = trim($dateTime);
        if ($value === '') {
            return '-';
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        return $parsed instanceof DateTimeImmutable ? $parsed->format('d.m.Y H:i') : $value;
    }

    private function relativeTime(DateTimeImmutable $at, DateTimeImmutable $now): string
    {
        $seconds = max(0, $now->getTimestamp() - $at->getTimestamp());
        if ($seconds < 60) {
            return 'gerade eben';
        }

        $minutes = (int) floor($seconds / 60);
        if ($minutes < 60) {
            return 'vor ' . $minutes . ' Min.';
        }

        $hours = (int) floor($minutes / 60);
        if ($hours < 24) {
            return 'vor ' . $hours . ' Std.';
        }

        $days = (int) floor($hours / 24);
        return 'vor ' . $days . ' Tag' . ($days === 1 ? '' : 'en');
    }

    private function projectStatusLabel(string $status): string
    {
        $key = strtolower(trim($status));
        return match ($key) {
            'pending' => 'Ausstehend',
            'backlog' => 'Backlog',
            'in_progress' => 'In Bearbeitung',
            'review' => 'In Pruefung',
            'completed' => 'Abgeschlossen',
            'on_hold' => 'Pausiert',
            'cancelled' => 'Abgebrochen',
            default => $key !== '' ? $status : '-',
        };
    }

    private function decryptClientName(string $value, ClientFieldEncryptionService $clientCrypto): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 'Unbekannt';
        }

        $decrypted = $clientCrypto->decryptClientRow(['name' => $trimmed]);
        $name = trim((string) ($decrypted['name'] ?? ''));

        return $name !== '' ? $name : 'Unbekannt';
    }

    public function logout(Request $request): Response
    {
        $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');

        unset($_SESSION[$sessionKey]);
        session_regenerate_id(true);

        return Response::redirect((string) config('admin.login_path', '/login'), 303)
            ->withHeader('Set-Cookie', $this->buildAdminSeenCookie(true, $request));
    }

    private function buildAdminSeenCookie(bool $clear, Request $request): string
    {
        $parts = ['gb_admin_seen=' . ($clear ? '' : '1')];
        $parts[] = 'Path=/';
        $parts[] = 'HttpOnly';
        $parts[] = 'SameSite=Lax';

        $isHttps = strtolower((string) $request->header('x-forwarded-proto', '')) === 'https'
            || (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');

        if ($isHttps) {
            $parts[] = 'Secure';
        }

        if ($clear) {
            $parts[] = 'Max-Age=0';
            $parts[] = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        }

        return implode('; ', $parts);
    }

    private function verifyPassword(string $password, ?array $user): bool
    {
        if ($user === null) {
            return false;
        }

        $storedHash = (string) ($user['password_hash'] ?? '');
        $overrideHash = trim((string) config('admin.password_hash', ''));
        $effectiveHash = $overrideHash !== '' ? $overrideHash : $storedHash;

        if ($effectiveHash === '' || str_contains($effectiveHash, 'placeholder')) {
            return false;
        }

        return password_verify($password, $effectiveHash);
    }

    private function isActiveUser(?array $user): bool
    {
        return $user !== null && (bool) ($user['is_active'] ?? false);
    }

    private function hasLoginPermission(?array $user): bool
    {
        if ($user === null) {
            return false;
        }
        $roleMask = (int) ($user['role_mask'] ?? 0);

        // Any positive permission mask may access the admin login.
        return $roleMask > 0;
    }

    private function determineLoginErrorMessage(?array $user, string $password): ?string
    {
        if ($user === null || !$this->verifyPassword($password, $user)) {
            return 'Anmeldung fehlgeschlagen. Bitte prüfen Sie E-Mail und Passwort.';
        }

        if (!$this->isActiveUser($user)) {
            return 'Ihr Benutzerkonto ist derzeit deaktiviert. Bitte wenden Sie sich an den Administrator.';
        }

        if (!$this->hasLoginPermission($user)) {
            return 'Ihr Benutzerkonto hat keine Berechtigung für den Adminbereich.';
        }

        return null;
    }

    private function sanitizeRedirect(string $redirectTo): string
    {
        $redirectTo = trim($redirectTo);

        if ($redirectTo === '') {
            return '';
        }

        if (!str_starts_with($redirectTo, '/') || str_starts_with($redirectTo, '//')) {
            return '';
        }

        if (in_array($redirectTo, ['/login', '/logout'], true)) {
            return '';
        }

        return $redirectTo;
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data = [], int $status = 200): Response
    {
        $statusCode = $status;

        extract($data, EXTR_SKIP);

        ob_start();
        require base_path('public/ui/_templates/operations/' . $template);
        $html = (string) ob_get_clean();

        return new Response($html, $statusCode, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }
}