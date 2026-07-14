<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfTokenManager;

final class InviteController
{
    // ── Show password-set form ────────────────────────────────────────────────

    public function showForm(Request $request): Response
    {
        $token = trim((string) $request->query('token', ''));
        $row   = $this->findValidToken($token);

        if ($row === null) {
            return $this->renderError(
                422,
                'Ungültiger Einladungslink',
                'Dieser Einladungslink ist abgelaufen oder wurde bereits verwendet.'
            );
        }

        $user = $this->findUser((int) $row['user_id']);
        if ($user === null) {
            return $this->renderError(422, 'Benutzer nicht gefunden', 'Der zugehörige Benutzer existiert nicht mehr.');
        }

        return $this->render('invite-accept-page.php', [
            'pageTitle'    => 'Passwort festlegen – Getragen Begleiten',
            'csrfToken'    => app(CsrfTokenManager::class)->token(),
            'inviteToken'  => $token,
            'email'        => (string) ($user['email'] ?? ''),
            'errorMessage' => '',
        ]);
    }

    // ── Process form submission ───────────────────────────────────────────────

    public function submit(Request $request): Response
    {
        $token = trim((string) $request->query('token', ''));
        $csrf  = app(CsrfTokenManager::class);

        if (!$csrf->isValid((string) $request->input('_token', ''))) {
            return $this->render('invite-accept-page.php', [
                'pageTitle'    => 'Passwort festlegen – Getragen Begleiten',
                'csrfToken'    => $csrf->token(),
                'inviteToken'  => $token,
                'email'        => '',
                'errorMessage' => 'Ungültige Sitzung. Bitte laden Sie die Seite neu.',
            ], 422);
        }

        $row = $this->findValidToken($token);
        if ($row === null) {
            return $this->renderError(
                422,
                'Ungültiger Einladungslink',
                'Dieser Einladungslink ist abgelaufen oder wurde bereits verwendet.'
            );
        }

        $user = $this->findUser((int) $row['user_id']);
        if ($user === null) {
            return $this->renderError(422, 'Benutzer nicht gefunden', 'Der zugehörige Benutzer existiert nicht mehr.');
        }

        $password = (string) $request->input('password', '');
        $confirm  = (string) $request->input('password_confirm', '');

        $errors = [];
        if (strlen($password) < 8) {
            $errors[] = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Die Passwörter stimmen nicht überein.';
        }

        if ($errors !== []) {
            return $this->render('invite-accept-page.php', [
                'pageTitle'    => 'Passwort festlegen – Getragen Begleiten',
                'csrfToken'    => $csrf->token(),
                'inviteToken'  => $token,
                'email'        => (string) ($user['email'] ?? ''),
                'errorMessage' => implode(' ', $errors),
            ], 422);
        }

        $pdo    = app(Database::class)->connection();
        $userId = (int) $row['user_id'];
        $now    = date('Y-m-d H:i:s');

        // Set password and activate user
        $pdo->prepare('UPDATE users SET password_hash = :ph, is_active = 1, updated_at = :ua WHERE id = :id')
            ->execute([
                ':ph' => password_hash($password, PASSWORD_BCRYPT),
                ':ua' => $now,
                ':id' => $userId,
            ]);

        // Mark token as used
        $pdo->prepare('UPDATE password_resets SET used_at = :ua WHERE id = :id')
            ->execute([':ua' => $now, ':id' => (int) $row['id']]);

        return Response::redirect('/login', 303);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function findValidToken(string $token): ?array
    {
        if ($token === '' || strlen($token) > 64) {
            return null;
        }

        $pdo  = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'SELECT id, user_id FROM password_resets
             WHERE token = :token AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) && $row !== [] ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function findUser(int $userId): ?array
    {
        $pdo  = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'SELECT id, email, first_name, last_name FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) && $row !== [] ? $row : null;
    }

    private function renderError(int $code, string $title, string $message): Response
    {
        $hints = [];
        ob_start();
        require base_path('public/ui/_templates/error-page.php');
        $html = (string) ob_get_clean();

        return new Response($html, $code, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data = [], int $status = 200): Response
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require base_path('public/ui/_templates/operations/' . $template);
        $html = (string) ob_get_clean();

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
