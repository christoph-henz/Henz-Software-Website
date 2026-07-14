<?php

declare(strict_types=1);

namespace App\Controllers\Operations;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfTokenManager;
use App\Core\Support\PermissionBits;

final class AuthController
{
    public function loginForm(Request $request): Response
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');

        if (is_array($session[$sessionKey] ?? null) && $session[$sessionKey] !== []) {
            return Response::redirect((string) config('admin.dashboard_path', '/dashboard'));
        }

        return $this->render('admin-login-page.php', [
            'pageTitle' => 'Admin Login – Getragen Begleiten',
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
                'pageTitle' => 'Admin Login – Getragen Begleiten',
                'csrfToken' => $csrf->token(),
                'redirectTo' => $redirectTo,
                'errorMessage' => 'Ungültige Sitzung. Bitte laden Sie die Seite neu.',
                'email' => $email,
            ], 422);
        }

        if ($email === '' || $password === '') {
            return $this->render('admin-login-page.php', [
                'pageTitle' => 'Admin Login – Getragen Begleiten',
                'csrfToken' => $csrf->token(),
                'redirectTo' => $redirectTo,
                'errorMessage' => 'Bitte E-Mail und Passwort ausfüllen.',
                'email' => $email,
            ], 422);
        }

        $user = db('users')
            ->where('email', $email)
            ->select(['id', 'first_name', 'last_name', 'email', 'password_hash', 'role_mask', 'is_active'])
            ->first();

        $loginErrorMessage = $this->determineLoginErrorMessage($user, $password);
        if ($loginErrorMessage !== null) {
            return $this->render('admin-login-page.php', [
                'pageTitle' => 'Admin Login – Getragen Begleiten',
                'csrfToken' => $csrf->token(),
                'redirectTo' => $redirectTo,
                'errorMessage' => $loginErrorMessage,
                'email' => $email,
            ], 422);
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

        return $this->render('admin-dashboard-page.php', [
            'pageTitle' => 'Adminpanel – Getragen Begleiten',
            'adminUser' => $adminUser,
            'logoutAction' => '/logout',
            'csrfToken' => app(CsrfTokenManager::class)->token(),
        ]);
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