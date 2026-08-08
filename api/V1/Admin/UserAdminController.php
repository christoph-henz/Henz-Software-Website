<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\BaseApiController;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Support\PermissionBits;

final class UserAdminController extends BaseApiController
{
    private const MANAGE_USERS_BIT = 256;
    private const MANAGE_ADMIN_SETTINGS_BIT = 2048;

    // ── List ─────────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        if (!$this->canManageUsers($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $page    = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $offset  = ($page - 1) * $perPage;
        $search  = trim((string) $request->query('q', ''));

        $pdo = app(Database::class)->connection();

        $where    = 'WHERE u.deleted_at IS NULL';
        $bindings = [];

        // Users without manage_admin_settings must not see users that have it.
        $actorMask       = $this->actorRoleMask($request);
        $adminSettingsBit = PermissionBits::resolve('manage_admin_settings', self::MANAGE_ADMIN_SETTINGS_BIT);
        if (($actorMask & $adminSettingsBit) === 0) {
            $where .= ' AND (u.role_mask & :admin_bit) = 0';
            $bindings[':admin_bit'] = $adminSettingsBit;
        }

        if ($search !== '') {
            $where .= ' AND (u.first_name LIKE :q_first OR u.last_name LIKE :q_last OR u.email LIKE :q_email)';
            $searchLike = '%' . $search . '%';
            $bindings[':q_first'] = $searchLike;
            $bindings[':q_last'] = $searchLike;
            $bindings[':q_email'] = $searchLike;
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u $where");
        $countStmt->execute($bindings);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT u.id, u.first_name, u.last_name, u.email,
                    u.role_mask, u.is_active, u.last_login_at, u.created_at
             FROM users u $where
             ORDER BY u.created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($bindings as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $this->ok([
            'users' => array_map(
                fn (array $row): array => $this->formatUser($row),
                is_array($rows) ? $rows : []
            ),
            'meta' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => (int) max(1, (int) ceil($total / $perPage)),
                'q'           => $search,
            ],
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function store(Request $request): Response
    {
        if (!$this->canManageUsers($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $firstName = trim((string) $request->input('first_name', ''));
        $lastName  = trim((string) $request->input('last_name', ''));
        $email     = strtolower(trim((string) $request->input('email', '')));
        $roleMask  = (int) $request->input('role_mask', 0);

        $errors = [];
        if ($firstName === '') {
            $errors['first_name'][] = 'required';
        }
        if ($lastName === '') {
            $errors['last_name'][] = 'required';
        }
        if ($email === '') {
            $errors['email'][] = 'required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'invalid';
        }
        if ($roleMask < 0) {
            $errors['role_mask'][] = 'invalid';
        } elseif (!$this->canAssignRoleMask($request, $roleMask)) {
            $errors['role_mask'][] = 'forbidden_bits';
        }
        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        $pdo = app(Database::class)->connection();

        $dup = $pdo->prepare('SELECT id FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1');
        $dup->execute([':email' => $email]);
        if ($dup->fetchColumn() !== false) {
            return $this->fail('Validation failed', 422, ['email' => ['already_exists']]);
        }

        $now = date('Y-m-d H:i:s');
        $ins = $pdo->prepare(
            'INSERT INTO users (first_name, last_name, email, password_hash, role_mask, is_active, created_at, updated_at)
             VALUES (:fn, :ln, :email, :ph, :rm, 0, :ca, :ua)'
        );
        $ins->execute([
            ':fn'    => $firstName,
            ':ln'    => $lastName,
            ':email' => $email,
            ':ph'    => '*LOCKED*',
            ':rm'    => $roleMask,
            ':ca'    => $now,
            ':ua'    => $now,
        ]);
        $userId = (int) $pdo->lastInsertId();

        $inviteLink = $this->generateInviteToken($pdo, $userId);

        $row = $pdo->prepare(
            'SELECT id, first_name, last_name, email, role_mask, is_active, created_at FROM users WHERE id = :id'
        );
        $row->execute([':id' => $userId]);
        $userData = $row->fetch(\PDO::FETCH_ASSOC);

        return $this->ok([
            'user'         => $this->formatUser(is_array($userData) ? $userData : []),
            'invite_link'  => $inviteLink,
        ], 201);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request): Response
    {
        if (!$this->canManageUsers($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, ['id' => ['required']]);
        }

        $pdo   = app(Database::class)->connection();
        $check = $pdo->prepare('SELECT id FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $check->execute([':id' => $id]);
        if ($check->fetchColumn() === false) {
            return $this->fail('User not found', 404);
        }

        $fields   = [];
        $bindings = [];

        $firstName = trim((string) $request->input('first_name', ''));
        if ($firstName !== '') {
            $fields[]       = 'first_name = :fn';
            $bindings[':fn'] = $firstName;
        }

        $lastName = trim((string) $request->input('last_name', ''));
        if ($lastName !== '') {
            $fields[]       = 'last_name = :ln';
            $bindings[':ln'] = $lastName;
        }

        // Prevent self-modification of role_mask
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $selfId     = (int) ((($request->session())[$sessionKey] ?? [])['id'] ?? 0);

        if ($request->input('role_mask') !== null) {
            if ($id === $selfId) {
                return $this->fail('Forbidden', 403, ['role_mask' => ['cannot_edit_own_permissions']]);
            }
            $newRoleMask = (int) $request->input('role_mask', 0);
            if ($newRoleMask < 0) {
                return $this->fail('Validation failed', 422, ['role_mask' => ['invalid']]);
            }
            if (!$this->canAssignRoleMask($request, $newRoleMask)) {
                return $this->fail('Validation failed', 422, ['role_mask' => ['forbidden_bits']]);
            }
            $fields[]       = 'role_mask = :rm';
            $bindings[':rm'] = $newRoleMask;
        }

        // Prevent self-deactivation
        if ($request->input('is_active') !== null) {
            $isActive = (int) (bool) $request->input('is_active', 1);
            if ($id === $selfId && $isActive === 0) {
                return $this->fail('Forbidden', 403, ['is_active' => ['cannot_deactivate_self']]);
            }
            if ($id !== $selfId) {
                $fields[]       = 'is_active = :ia';
                $bindings[':ia'] = $isActive;
            }
        }

        if ($fields === []) {
            return $this->fail('No fields to update', 422);
        }

        $fields[]       = 'updated_at = :ua';
        $bindings[':ua'] = date('Y-m-d H:i:s');
        $bindings[':id'] = $id;

        $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id')
            ->execute($bindings);

        $row = $pdo->prepare(
            'SELECT id, first_name, last_name, email, role_mask, is_active, last_login_at, created_at FROM users WHERE id = :id'
        );
        $row->execute([':id' => $id]);
        $userData = $row->fetch(\PDO::FETCH_ASSOC);

        return $this->ok(['user' => $this->formatUser(is_array($userData) ? $userData : [])]);
    }

    // ── Soft-delete ───────────────────────────────────────────────────────────

    public function destroy(Request $request): Response
    {
        if (!$this->canManageUsers($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, ['id' => ['required']]);
        }

        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $selfId     = (int) ((($request->session())[$sessionKey] ?? [])['id'] ?? 0);
        if ($id === $selfId) {
            return $this->fail('Cannot delete your own account', 403);
        }

        $pdo   = app(Database::class)->connection();
        $check = $pdo->prepare('SELECT id FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $check->execute([':id' => $id]);
        if ($check->fetchColumn() === false) {
            return $this->fail('User not found', 404);
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE users SET deleted_at = :ua, updated_at = :ua WHERE id = :id')
            ->execute([':ua' => $now, ':id' => $id]);

        return $this->ok(['deleted' => true, 'id' => $id]);
    }

    // ── Regenerate invite ─────────────────────────────────────────────────────

    public function invite(Request $request): Response
    {
        if (!$this->canManageUsers($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, ['id' => ['required']]);
        }

        $pdo   = app(Database::class)->connection();
        $check = $pdo->prepare('SELECT id FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $check->execute([':id' => $id]);
        if ($check->fetchColumn() === false) {
            return $this->fail('User not found', 404);
        }

        $inviteLink = $this->generateInviteToken($pdo, $id);

        return $this->ok(['invite_link' => $inviteLink]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function generateInviteToken(\PDO $pdo, int $userId): string
    {
        $token     = bin2hex(random_bytes(32)); // 64 hex chars
        $expiresAt = date('Y-m-d H:i:s', time() + 7200); // +2 hours

        $pdo->prepare(
            'INSERT INTO password_resets (user_id, token, expires_at) VALUES (:uid, :token, :exp)'
        )->execute([':uid' => $userId, ':token' => $token, ':exp' => $expiresAt]);

        // Log invite link – email sending added in E-001/E-002
        error_log('Invite link for user_id=' . $userId . ': /login?token=' . $token);

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host . '/login?token=' . $token;
    }

    /** @param array<string, mixed> $row */
    private function formatUser(array $row): array
    {
        return [
            'id'            => (int) ($row['id'] ?? 0),
            'first_name'    => (string) ($row['first_name'] ?? ''),
            'last_name'     => (string) ($row['last_name'] ?? ''),
            'email'         => (string) ($row['email'] ?? ''),
            'role_mask'     => (int) ($row['role_mask'] ?? 0),
            'is_active'     => (bool) ($row['is_active'] ?? false),
            'last_login_at' => $row['last_login_at'] ?? null,
            'created_at'    => (string) ($row['created_at'] ?? ''),
        ];
    }

    private function canManageUsers(Request $request): bool
    {
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser  = $request->session()[$sessionKey] ?? [];
        $roleMask   = (int) ($adminUser['role_mask'] ?? 0);
        $bit        = PermissionBits::resolve('manage_users', self::MANAGE_USERS_BIT);

        return ($roleMask & $bit) !== 0;
    }

    private function canAssignRoleMask(Request $request, int $targetRoleMask): bool
    {
        if ($targetRoleMask < 0) {
            return false;
        }

        $actorRoleMask = $this->actorRoleMask($request);
        if ($this->canManageAdminSettings($actorRoleMask)) {
            return true;
        }

        return ($targetRoleMask & ~$actorRoleMask) === 0;
    }

    private function canManageAdminSettings(int $roleMask): bool
    {
        $bit = PermissionBits::resolve('manage_admin_settings', self::MANAGE_ADMIN_SETTINGS_BIT);
        return ($roleMask & $bit) !== 0;
    }

    private function actorRoleMask(Request $request): int
    {
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser  = $request->session()[$sessionKey] ?? [];

        return (int) ($adminUser['role_mask'] ?? 0);
    }
}
