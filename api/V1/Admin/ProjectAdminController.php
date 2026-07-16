<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\BaseApiController;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Support\PermissionBits;
use DateTimeImmutable;

final class ProjectAdminController extends BaseApiController
{
    private const VIEW_PROJECTS_BIT = 256;
    private const MANAGE_PROJECTS_BIT = 512;
    private const MANAGE_ADMIN_SETTINGS_BIT = 2048;

    // ── List ─────────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        if (!$this->canManageProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $page    = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $offset  = ($page - 1) * $perPage;
        $search  = trim((string) $request->query('q', ''));

        $pdo = app(Database::class)->connection();

        $where    = 'WHERE p.deleted_at IS NULL';
        $bindings = [];

        if ($search !== '') {
            $where .= ' AND (p.name LIKE :q_name OR c.name LIKE :q_c_name OR p.description LIKE :q_description)';
            $searchLike = '%' . $search . '%';
            $bindings[':q_name'] = $searchLike;
            $bindings[':q_c_name'] = $searchLike;
            $bindings[':q_description'] = $searchLike;
        }

        $countSql = 'SELECT COUNT(*) FROM projects p';
        if ($search !== '') {
            $countSql .= ' JOIN clients c ON p.client_id = c.id';
        }
        $countSql .= ' ' . $where;

        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT p.id, p.name, p.description, p.client_id, c.name AS client_name, p.status, p.is_active, p.due_date, p.created_at
             FROM projects p
             JOIN clients c ON p.client_id = c.id
             $where
             ORDER BY p.created_at DESC
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
            'projects' => array_map(
                fn (array $row): array => $this->formatProject($row),
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
        if (!$this->canManageProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $name = trim((string) $request->input('name', ''));
        $clientId = (int) $request->input('client_id', 0);
        $description = trim((string) $request->input('description', ''));
        $status = trim((string) $request->input('status', 'pending'));
        $dueDateRaw = trim((string) $request->input('due_date', ''));
        $isActive = (int) ((bool) $request->input('is_active', true));

        $allowedStatus = ['pending', 'backlog', 'in_progress', 'review', 'completed', 'on_hold', 'cancelled'];

        $errors = [];
        if ($name === '') {
            $errors['name'][] = 'required';
        }

        if ($clientId <= 0) {
            $errors['client_id'][] = 'required';
        }

        if (!in_array($status, $allowedStatus, true)) {
            $errors['status'][] = 'invalid';
        }

        if ($dueDateRaw !== '' && !$this->isIsoDate($dueDateRaw)) {
            $errors['due_date'][] = 'invalid';
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        $pdo = app(Database::class)->connection();

        $clientExists = $pdo->prepare('SELECT id FROM clients WHERE id = :id LIMIT 1');
        $clientExists->execute([':id' => $clientId]);
        if ($clientExists->fetchColumn() === false) {
            return $this->fail('Validation failed', 422, ['client_id' => ['not_found']]);
        }

        $sessionKey = (string) config('admin.session_key', 'operations_user');
        $adminUser = $request->session()[$sessionKey] ?? [];
        $createdBy = (int) ($adminUser['id'] ?? 0);
        if ($createdBy <= 0) {
            return $this->fail('Forbidden', 403, ['auth' => ['missing_user']]);
        }

        $now = date('Y-m-d H:i:s');
        $dueDate = $dueDateRaw !== '' ? $dueDateRaw : date('Y-m-d');

        $ins = $pdo->prepare(
            'INSERT INTO projects (name, description, client_id, status, due_date, is_active, created_by, created_at, updated_at)
             VALUES (:name, :description, :client_id, :status, :due_date, :is_active, :created_by, :created_at, :updated_at)'
        );
        $ins->execute([
            ':name' => $name,
            ':description' => $description !== '' ? $description : null,
            ':client_id' => $clientId,
            ':status' => $status,
            ':due_date' => $dueDate,
            ':is_active' => $isActive,
            ':created_by' => $createdBy,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $projectId = (int) $pdo->lastInsertId();

        $row = $pdo->prepare(
            'SELECT p.id, p.name, p.description, p.client_id, c.name AS client_name, p.status, p.is_active, p.due_date, p.created_at
             FROM projects p
             JOIN clients c ON p.client_id = c.id
             WHERE p.id = :id
             LIMIT 1'
        );
        $row->execute([':id' => $projectId]);
        $projectData = $row->fetch(\PDO::FETCH_ASSOC);

        return $this->ok([
            'project' => $this->formatProject(is_array($projectData) ? $projectData : []),
        ], 201);
    }

    public function clients(Request $request): Response
    {
        if (!$this->canManageProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(200, max(1, (int) $request->query('per_page', 200)));
        $offset = ($page - 1) * $perPage;
        $search = trim((string) $request->query('q', ''));

        $pdo = app(Database::class)->connection();

        $where = '';
        $bindings = [];
        if ($search !== '') {
            $where = 'WHERE name LIKE :q';
            $bindings[':q'] = '%' . $search . '%';
        }

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM clients ' . $where);
        $countStmt->execute($bindings);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT id, name
             FROM clients
             ' . $where . '
             ORDER BY name ASC
             LIMIT :limit OFFSET :offset'
        );

        foreach ($bindings as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $clients = array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
            ];
        }, is_array($rows) ? $rows : []);

        return $this->ok([
            'clients' => $clients,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) max(1, (int) ceil($total / max(1, $perPage))),
                'q' => $search,
            ],
        ]);
    }

    public function users(Request $request): Response
    {
        if (!$this->canManageProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $pdo = app(Database::class)->connection();
        $rows = $pdo->query(
            'SELECT id, first_name, last_name, email, is_active
             FROM users
             WHERE deleted_at IS NULL
             ORDER BY last_name ASC, first_name ASC'
        );

        $users = is_object($rows)
            ? $rows->fetchAll(\PDO::FETCH_ASSOC)
            : [];

        return $this->ok([
            'users' => array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'first_name' => (string) ($row['first_name'] ?? ''),
                    'last_name' => (string) ($row['last_name'] ?? ''),
                    'email' => (string) ($row['email'] ?? ''),
                    'is_active' => (bool) ($row['is_active'] ?? false),
                ];
            }, is_array($users) ? $users : []),
        ]);
    }

    public function show(Request $request): Response
    {
        if (!$this->canViewProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $projectId = (int) $request->attribute('id', 0);
        if ($projectId <= 0) {
            return $this->fail('Validation failed', 422, ['id' => ['required']]);
        }

        $project = $this->findProjectById($projectId);
        if (!is_array($project)) {
            return $this->fail('Project not found', 404);
        }

        return $this->ok([
            'project' => $this->formatProject($project),
        ]);
    }

    public function phases(Request $request): Response
    {
        if (!$this->canViewProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $projectId = (int) $request->attribute('id', 0);
        if ($projectId <= 0 || !$this->projectExists($projectId)) {
            return $this->fail('Project not found', 404);
        }

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'SELECT pp.id, pp.project_id, pp.phase_name, pp.description, pp.status, pp.progress, pp.due_date,
                    pp.integration_tests_finished, pp.tested_by, pp.test_date, pp.test_template, pp.test_data,
                    pp.created_at, pp.updated_at,
                    u.first_name AS tested_by_first_name, u.last_name AS tested_by_last_name,
                    ft.name AS test_template_name
             FROM project_phase pp
             LEFT JOIN users u ON u.id = pp.tested_by
             LEFT JOIN form_templates ft ON ft.id = pp.test_template
               WHERE pp.project_id = :project_id AND pp.deleted_at IS NULL
               ORDER BY pp.created_at ASC'
        );
        $stmt->execute([':project_id' => $projectId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $this->ok([
            'phases' => array_map(fn (array $row): array => $this->formatPhase($row), is_array($rows) ? $rows : []),
            'test_templates' => $this->listActiveTestTemplates(),
        ]);
    }

    public function phaseTestData(Request $request): Response
    {
        if (!$this->canViewProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $projectId = (int) $request->attribute('id', 0);
        $phaseId = (int) $request->attribute('phase_id', 0);
        if ($projectId <= 0 || $phaseId <= 0 || !$this->projectExists($projectId)) {
            return $this->fail('Project or phase not found', 404);
        }

        $phase = $this->findPhaseById($projectId, $phaseId);
        if (!is_array($phase)) {
            return $this->fail('Project or phase not found', 404);
        }

        return $this->ok([
            'phase' => $this->formatPhase($phase),
            'test_data' => $phase['test_data'] ?? null,
        ]);
    }

    public function createPhaseTests(Request $request): Response
    {
        if (!$this->canManageProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $projectId = (int) $request->attribute('id', 0);
        $phaseId = (int) $request->attribute('phase_id', 0);
        if ($projectId <= 0 || $phaseId <= 0 || !$this->projectExists($projectId)) {
            return $this->fail('Project or phase not found', 404);
        }

        $phase = $this->findPhaseById($projectId, $phaseId);
        if (!is_array($phase)) {
            return $this->fail('Project or phase not found', 404);
        }

        $progress = (int) ($phase['progress'] ?? 0);
        if ($progress <= 80) {
            return $this->fail('Validation failed', 422, ['progress' => ['must_be_above_80']]);
        }

        $alreadyTested = (bool) ($phase['integration_tests_finished'] ?? false);
        if ($alreadyTested) {
            return $this->fail('Validation failed', 422, ['tests' => ['already_created']]);
        }

        $templateId = (int) $request->input('template_id', 0);
        if ($templateId <= 0) {
            return $this->fail('Validation failed', 422, ['template_id' => ['required']]);
        }

        $pdo = app(Database::class)->connection();
        $tplStmt = $pdo->prepare('SELECT id, name, template_key FROM form_templates WHERE id = :id AND is_active = 1 LIMIT 1');
        $tplStmt->execute([':id' => $templateId]);
        $template = $tplStmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($template)) {
            return $this->fail('Validation failed', 422, ['template_id' => ['not_found']]);
        }

        $sessionKey = (string) config('admin.session_key', 'operations_user');
        $adminUser = $request->session()[$sessionKey] ?? [];
        $testerId = (int) ($adminUser['id'] ?? 0);
        if ($testerId <= 0) {
            return $this->fail('Forbidden', 403, ['auth' => ['missing_user']]);
        }

        $testDate = date('Y-m-d');
        $testData = [
            'created_at' => date('c'),
            'created_by_user_id' => $testerId,
            'template_id' => (int) $template['id'],
            'template_key' => (string) ($template['template_key'] ?? ''),
            'template_name' => (string) ($template['name'] ?? ''),
            'status' => 'created',
        ];

        $update = $pdo->prepare(
            'UPDATE project_phase
             SET integration_tests_finished = 1,
                 tested_by = :tested_by,
                 test_date = :test_date,
                 test_template = :test_template,
                 test_data = :test_data,
                 updated_at = :updated_at
             WHERE id = :id AND project_id = :project_id AND deleted_at IS NULL'
        );
        $update->execute([
            ':tested_by' => $testerId,
            ':test_date' => $testDate,
            ':test_template' => (int) $template['id'],
            ':test_data' => json_encode($testData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':updated_at' => date('Y-m-d H:i:s'),
            ':id' => $phaseId,
            ':project_id' => $projectId,
        ]);

        $updated = $this->findPhaseById($projectId, $phaseId);
        return $this->ok([
            'phase' => $this->formatPhase(is_array($updated) ? $updated : []),
            'test_data_url' => '/projects/' . $projectId . '/phase/' . $phaseId . '/test-data',
        ], 201);
    }

    public function storePhase(Request $request): Response
    {
        if (!$this->canManageProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $projectId = (int) $request->attribute('id', 0);
        if ($projectId <= 0 || !$this->projectExists($projectId)) {
            return $this->fail('Project not found', 404);
        }

        $phaseName = trim((string) $request->input('phase_name', ''));
        $description = trim((string) $request->input('description', ''));
        $status = trim((string) $request->input('status', 'pending'));
        $progress = max(0, min(100, (int) $request->input('progress', 0)));
        $dueDateRaw = trim((string) $request->input('due_date', ''));

        $allowedStatus = ['pending', 'in_progress', 'review', 'completed', 'on_hold', 'cancelled'];
        $errors = [];
        if ($phaseName === '') {
            $errors['phase_name'][] = 'required';
        }
        if (!in_array($status, $allowedStatus, true)) {
            $errors['status'][] = 'invalid';
        }
        if ($dueDateRaw !== '' && !$this->isIsoDate($dueDateRaw)) {
            $errors['due_date'][] = 'invalid';
        }
        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        $dueDate = $dueDateRaw !== '' ? $dueDateRaw : date('Y-m-d');
        $pdo = app(Database::class)->connection();
        $now = date('Y-m-d H:i:s');

        $insert = $pdo->prepare(
            'INSERT INTO project_phase (project_id, phase_name, description, status, progress, due_date, created_at, updated_at)
             VALUES (:project_id, :phase_name, :description, :status, :progress, :due_date, :created_at, :updated_at)'
        );
        $insert->execute([
            ':project_id' => $projectId,
            ':phase_name' => $phaseName,
            ':description' => $description !== '' ? $description : null,
            ':status' => $status,
            ':progress' => $progress,
            ':due_date' => $dueDate,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $phaseId = (int) $pdo->lastInsertId();
        $phase = $this->findPhaseById($projectId, $phaseId);

        return $this->ok([
            'phase' => $this->formatPhase(is_array($phase) ? $phase : []),
        ], 201);
    }

    public function updatePhase(Request $request): Response
    {
        if (!$this->canManageProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $projectId = (int) $request->attribute('id', 0);
        $phaseId = (int) $request->attribute('phase_id', 0);
        if ($projectId <= 0 || $phaseId <= 0 || !$this->projectExists($projectId)) {
            return $this->fail('Project or phase not found', 404);
        }

        $phase = $this->findPhaseById($projectId, $phaseId);
        if (!is_array($phase)) {
            return $this->fail('Project or phase not found', 404);
        }

        $phaseName = trim((string) $request->input('phase_name', (string) ($phase['phase_name'] ?? '')));
        $description = trim((string) $request->input('description', (string) ($phase['description'] ?? '')));
        $status = trim((string) $request->input('status', (string) ($phase['status'] ?? 'pending')));
        $progress = max(0, min(100, (int) $request->input('progress', (int) ($phase['progress'] ?? 0))));
        $dueDateRaw = trim((string) $request->input('due_date', (string) ($phase['due_date'] ?? '')));

        $allowedStatus = ['pending', 'in_progress', 'review', 'completed', 'on_hold', 'cancelled'];
        $errors = [];
        if ($phaseName === '') {
            $errors['phase_name'][] = 'required';
        }
        if (!in_array($status, $allowedStatus, true)) {
            $errors['status'][] = 'invalid';
        }
        if ($dueDateRaw !== '' && !$this->isIsoDate($dueDateRaw)) {
            $errors['due_date'][] = 'invalid';
        }
        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        $pdo = app(Database::class)->connection();
        $pdo->prepare(
            'UPDATE project_phase
             SET phase_name = :phase_name,
                 description = :description,
                 status = :status,
                 progress = :progress,
                 due_date = :due_date,
                 updated_at = :updated_at
             WHERE id = :id AND project_id = :project_id'
        )->execute([
            ':phase_name' => $phaseName,
            ':description' => $description !== '' ? $description : null,
            ':status' => $status,
            ':progress' => $progress,
            ':due_date' => $dueDateRaw !== '' ? $dueDateRaw : date('Y-m-d'),
            ':updated_at' => date('Y-m-d H:i:s'),
            ':id' => $phaseId,
            ':project_id' => $projectId,
        ]);

        $updated = $this->findPhaseById($projectId, $phaseId);
        return $this->ok([
            'phase' => $this->formatPhase(is_array($updated) ? $updated : []),
        ]);
    }

    public function destroyPhase(Request $request): Response
    {
        if (!$this->canManageProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $projectId = (int) $request->attribute('id', 0);
        $phaseId = (int) $request->attribute('phase_id', 0);
        if ($projectId <= 0 || $phaseId <= 0 || !$this->projectExists($projectId)) {
            return $this->fail('Project or phase not found', 404);
        }

        $pdo = app(Database::class)->connection();
        $deleted = $pdo->prepare(
            'UPDATE project_phase SET deleted_at = :deleted_at, updated_at = :updated_at
             WHERE id = :id AND project_id = :project_id AND deleted_at IS NULL'
        );
        $deleted->execute([
            ':deleted_at' => date('Y-m-d H:i:s'),
            ':updated_at' => date('Y-m-d H:i:s'),
            ':id' => $phaseId,
            ':project_id' => $projectId,
        ]);

        return $this->ok(['deleted' => true, 'id' => $phaseId]);
    }

    public function members(Request $request): Response
    {
        if (!$this->canViewProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $projectId = (int) $request->attribute('id', 0);
        if ($projectId <= 0 || !$this->projectExists($projectId)) {
            return $this->fail('Project not found', 404);
        }

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'SELECT pm.id, pm.project_id, pm.user_id, pm.role, pm.created_at,
                    u.first_name, u.last_name, u.email
             FROM project_members pm
             JOIN users u ON u.id = pm.user_id
             WHERE pm.project_id = :project_id
             ORDER BY pm.created_at ASC'
        );
        $stmt->execute([':project_id' => $projectId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $this->ok([
            'members' => array_map(fn (array $row): array => $this->formatMember($row), is_array($rows) ? $rows : []),
        ]);
    }

    public function storeMember(Request $request): Response
    {
        if (!$this->canManageProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $projectId = (int) $request->attribute('id', 0);
        if ($projectId <= 0 || !$this->projectExists($projectId)) {
            return $this->fail('Project not found', 404);
        }

        $userId = (int) $request->input('user_id', 0);
        $role = trim((string) $request->input('role', 'developer'));
        $allowedRoles = ['owner', 'manager', 'developer', 'designer', 'tester'];

        $errors = [];
        if ($userId <= 0) {
            $errors['user_id'][] = 'required';
        }
        if (!in_array($role, $allowedRoles, true)) {
            $errors['role'][] = 'invalid';
        }
        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        $pdo = app(Database::class)->connection();
        $userExists = $pdo->prepare('SELECT id FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $userExists->execute([':id' => $userId]);
        if ($userExists->fetchColumn() === false) {
            return $this->fail('Validation failed', 422, ['user_id' => ['not_found']]);
        }

        $dupCheck = $pdo->prepare(
            'SELECT id
             FROM project_members
             WHERE project_id = :project_id
               AND user_id = :user_id
               AND role = :role
             LIMIT 1'
        );
        $dupCheck->execute([
            ':project_id' => $projectId,
            ':user_id' => $userId,
            ':role' => $role,
        ]);
        if ($dupCheck->fetchColumn() !== false) {
            return $this->fail('Validation failed', 422, ['role' => ['already_assigned_for_user']]);
        }

        $now = date('Y-m-d H:i:s');
        $insert = $pdo->prepare(
            'INSERT INTO project_members (project_id, user_id, role, created_at, updated_at)
             VALUES (:project_id, :user_id, :role, :created_at, :updated_at)'
        );
        $insert->execute([
            ':project_id' => $projectId,
            ':user_id' => $userId,
            ':role' => $role,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $memberId = (int) $pdo->lastInsertId();
        $member = $this->findMemberById($projectId, $memberId);

        return $this->ok([
            'member' => $this->formatMember(is_array($member) ? $member : []),
        ], 201);
    }

    public function destroyMember(Request $request): Response
    {
        if (!$this->canManageProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $projectId = (int) $request->attribute('id', 0);
        $memberId = (int) $request->attribute('member_id', 0);
        if ($projectId <= 0 || $memberId <= 0 || !$this->projectExists($projectId)) {
            return $this->fail('Project or member not found', 404);
        }

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare('DELETE FROM project_members WHERE id = :id AND project_id = :project_id');
        $stmt->execute([':id' => $memberId, ':project_id' => $projectId]);

        return $this->ok(['deleted' => true, 'id' => $memberId]);
    }

    // ── Update ────────────────────────────────────────────────────────────────
    /*
    public function update(Request $request): Response
    {
        if (!$this->canManageProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, ['id' => ['required']]);
        }

        $pdo   = app(Database::class)->connection();
        $check = $pdo->prepare('SELECT id FROM projects WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $check->execute([':id' => $id]);
        if ($check->fetchColumn() === false) {
            return $this->fail('Project not found', 404);
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
        $sessionKey = (string) config('admin.session_key', 'admin_project');
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

        $pdo->prepare('UPDATE projects SET ' . implode(', ', $fields) . ' WHERE id = :id')
            ->execute($bindings);

        $row = $pdo->prepare(
            'SELECT id, first_name, last_name, email, role_mask, is_active, last_login_at, created_at FROM projects WHERE id = :id'
        );
        $row->execute([':id' => $id]);
        $projectData = $row->fetch(\PDO::FETCH_ASSOC);

        return $this->ok(['project' => $this->formatProject(is_array($projectData) ? $projectData : [])]);
    }
    */
    // ── Soft-delete ───────────────────────────────────────────────────────────

    public function destroy(Request $request): Response
    {
        if (!$this->canManageProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, ['id' => ['required']]);
        }

        $sessionKey = (string) config('admin.session_key', 'admin_project');
        $selfId     = (int) ((($request->session())[$sessionKey] ?? [])['id'] ?? 0);
        if ($id === $selfId) {
            return $this->fail('Cannot delete your own account', 403);
        }

        $pdo   = app(Database::class)->connection();
        $check = $pdo->prepare('SELECT id FROM projects WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $check->execute([':id' => $id]);
        if ($check->fetchColumn() === false) {
            return $this->fail('Project not found', 404);
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE projects SET deleted_at = :ua, updated_at = :ua WHERE id = :id')
            ->execute([':ua' => $now, ':id' => $id]);

        return $this->ok(['deleted' => true, 'id' => $id]);
    }

    // ── Regenerate invite ─────────────────────────────────────────────────────
    /*
    public function invite(Request $request): Response
    {
        if (!$this->canManageProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, ['id' => ['required']]);
        }

        $pdo   = app(Database::class)->connection();
        $check = $pdo->prepare('SELECT id FROM projects WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $check->execute([':id' => $id]);
        if ($check->fetchColumn() === false) {
            return $this->fail('Project not found', 404);
        }

        $inviteLink = $this->generateInviteToken($pdo, $id);

        return $this->ok(['invite_link' => $inviteLink]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function generateInviteToken(\PDO $pdo, int $projectId): string
    {
        $token     = bin2hex(random_bytes(32)); // 64 hex chars
        $expiresAt = date('Y-m-d H:i:s', time() + 7200); // +2 hours

        $pdo->prepare(
            'INSERT INTO password_resets (project_id, token, expires_at) VALUES (:uid, :token, :exp)'
        )->execute([':uid' => $projectId, ':token' => $token, ':exp' => $expiresAt]);

        // Log invite link – email sending added in E-001/E-002
        error_log('Invite link for project_id=' . $projectId . ': /login?token=' . $token);

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host . '/login?token=' . $token;
    }
    */

    /** @param array<string, mixed> $row */
    private function formatProject(array $row): array
    {
        return [
            'id'            => (int) ($row['id'] ?? 0),
            'name'          => (string) ($row['name'] ?? ''),
            'description'   => (string) ($row['description'] ?? ''),
            'client_id'     => (int) ($row['client_id'] ?? 0),
            'client_name'   => (string) ($row['client_name'] ?? ''),
            'status'        => (string) ($row['status'] ?? 'pending'),
            'is_active'     => (bool) ($row['is_active'] ?? false),
            'due_date'      => $row['due_date'] ?? null,
            'created_at'    => (string) ($row['created_at'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $row */
    private function formatPhase(array $row): array
    {
        $testedByName = trim((string) (($row['tested_by_first_name'] ?? '') . ' ' . ($row['tested_by_last_name'] ?? '')));
        $decodedTestData = $row['test_data'] ?? null;
        if (is_string($decodedTestData) && $decodedTestData !== '') {
            $json = json_decode($decodedTestData, true);
            $decodedTestData = json_last_error() === JSON_ERROR_NONE ? $json : $decodedTestData;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'project_id' => (int) ($row['project_id'] ?? 0),
            'phase_name' => (string) ($row['phase_name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'status' => (string) ($row['status'] ?? 'pending'),
            'progress' => (int) ($row['progress'] ?? 0),
            'due_date' => isset($row['due_date']) ? (string) $row['due_date'] : null,
            'integration_tests_finished' => (bool) ($row['integration_tests_finished'] ?? false),
            'tested_by' => isset($row['tested_by']) ? (int) $row['tested_by'] : null,
            'tested_by_name' => $testedByName !== '' ? $testedByName : null,
            'test_date' => isset($row['test_date']) ? (string) $row['test_date'] : null,
            'test_template' => isset($row['test_template']) ? (int) $row['test_template'] : null,
            'test_template_name' => isset($row['test_template_name']) ? (string) $row['test_template_name'] : null,
            'test_data' => $decodedTestData,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $row */
    private function formatMember(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'project_id' => (int) ($row['project_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'role' => (string) ($row['role'] ?? 'developer'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'user' => [
                'first_name' => (string) ($row['first_name'] ?? ''),
                'last_name' => (string) ($row['last_name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
            ],
        ];
    }

    private function isIsoDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }

    private function canManageProjects(Request $request): bool
    {
        $sessionKey = (string) config('admin.session_key', 'operations_user');
        $adminUser = $request->session()[$sessionKey] ?? [];
        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $bit = PermissionBits::resolve('manage_projects', self::MANAGE_PROJECTS_BIT);

        return ($roleMask & $bit) !== 0;
    }

    private function canViewProjects(Request $request): bool
    {
        $sessionKey = (string) config('admin.session_key', 'operations_user');
        $adminUser = $request->session()[$sessionKey] ?? [];
        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $viewBit = PermissionBits::resolve('view_projects', self::VIEW_PROJECTS_BIT);

        if (($roleMask & $viewBit) !== 0) {
            return true;
        }

        return $this->canManageProjects($request);
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
        $sessionKey = (string) config('admin.session_key', 'operations_user');
        $adminUser = $request->session()[$sessionKey] ?? [];

        return (int) ($adminUser['role_mask'] ?? 0);
    }

    /** @return array<string, mixed>|null */
    private function findProjectById(int $projectId): ?array
    {
        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'SELECT p.id, p.name, p.description, p.client_id, c.name AS client_name, p.status, p.is_active, p.due_date, p.created_at
             FROM projects p
             JOIN clients c ON p.client_id = c.id
             WHERE p.id = :id AND p.deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([':id' => $projectId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function projectExists(int $projectId): bool
    {
        return is_array($this->findProjectById($projectId));
    }

    /** @return array<string, mixed>|null */
    private function findPhaseById(int $projectId, int $phaseId): ?array
    {
        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'SELECT pp.id, pp.project_id, pp.phase_name, pp.description, pp.status, pp.progress, pp.due_date,
                    pp.integration_tests_finished, pp.tested_by, pp.test_date, pp.test_template, pp.test_data,
                    pp.created_at, pp.updated_at,
                    u.first_name AS tested_by_first_name, u.last_name AS tested_by_last_name,
                    ft.name AS test_template_name
             FROM project_phase pp
             LEFT JOIN users u ON u.id = pp.tested_by
             LEFT JOIN form_templates ft ON ft.id = pp.test_template
             WHERE pp.id = :id AND pp.project_id = :project_id AND pp.deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([':id' => $phaseId, ':project_id' => $projectId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    private function listActiveTestTemplates(): array
    {
        $pdo = app(Database::class)->connection();
        $stmt = $pdo->query(
            'SELECT id, name, template_key
             FROM form_templates
             WHERE is_active = 1
             ORDER BY name ASC'
        );

        $rows = is_object($stmt) ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'template_key' => (string) ($row['template_key'] ?? ''),
            ];
        }, is_array($rows) ? $rows : []);
    }

    /** @return array<string, mixed>|null */
    private function findMemberById(int $projectId, int $memberId): ?array
    {
        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'SELECT pm.id, pm.project_id, pm.user_id, pm.role, pm.created_at,
                    u.first_name, u.last_name, u.email
             FROM project_members pm
             JOIN users u ON u.id = pm.user_id
             WHERE pm.id = :id AND pm.project_id = :project_id
             LIMIT 1'
        );
        $stmt->execute([':id' => $memberId, ':project_id' => $projectId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
