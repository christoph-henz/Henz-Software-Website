<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\BaseApiController;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Support\PermissionBits;
use App\Services\ClientFieldEncryptionService;
use DateTimeImmutable;
use Throwable;

final class ProjectAdminController extends BaseApiController
{
    private const VIEW_PROJECTS_BIT = 256;
    private const MANAGE_PROJECTS_BIT = 512;
    private const MANAGE_ADMIN_SETTINGS_BIT = 2048;
    private const PROJECT_TEST_ATTACHMENT_BASE_PATH = 'storage/media/secure/project-tests';

    /** @var array<string, string> */
    private const ALLOWED_TEST_ATTACHMENT_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];

    // ── List ─────────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        if (!$this->canViewProjects($request)) {
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
            "SELECT p.id, p.name, p.description, p.client_id, c.name AS client_name, p.status, p.progress, p.is_active, p.due_date, p.created_at
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
            'SELECT p.id, p.name, p.description, p.client_id, c.name AS client_name, p.status, p.progress, p.is_active, p.due_date, p.created_at
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
        $clientCrypto = app(ClientFieldEncryptionService::class);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $clients = array_map(function (array $row) use ($clientCrypto): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'name' => $this->decryptClientName((string) ($row['name'] ?? ''), $clientCrypto),
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

        $existingTestData = $this->normalizePhaseTestData($phase['test_data'] ?? null);
        if ((int) ($existingTestData['template_id'] ?? 0) > 0) {
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

        $versionStmt = $pdo->prepare(
            'SELECT id, version_no, schema_json
             FROM form_template_versions
             WHERE template_id = :template_id
             ORDER BY version_no DESC
             LIMIT 1'
        );
        $versionStmt->execute([':template_id' => (int) $template['id']]);
        $latestVersion = $versionStmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($latestVersion)) {
            return $this->fail('Validation failed', 422, ['template_id' => ['missing_published_version']]);
        }

        $schema = [];
        if (isset($latestVersion['schema_json']) && is_string($latestVersion['schema_json']) && trim($latestVersion['schema_json']) !== '') {
            $decodedSchema = json_decode((string) $latestVersion['schema_json'], true);
            if (is_array($decodedSchema)) {
                $schema = $decodedSchema;
            }
        }

        $testDate = date('Y-m-d');
        $testData = [
            'created_at' => date('c'),
            'created_by_user_id' => $testerId,
            'template_id' => (int) $template['id'],
            'template_key' => (string) ($template['template_key'] ?? ''),
            'template_name' => (string) ($template['name'] ?? ''),
            'template_version_id' => (int) ($latestVersion['id'] ?? 0),
            'template_version_no' => (int) ($latestVersion['version_no'] ?? 0),
            'schema_json' => $schema,
            'payload_json' => [],
            'attachments' => [],
            'status' => 'draft',
            'saved_at' => null,
        ];

        $update = $pdo->prepare(
            'UPDATE project_phase
             SET integration_tests_finished = 0,
                 tested_by = NULL,
                 test_date = NULL,
                 test_template = :test_template,
                 test_data = :test_data,
                 updated_at = :updated_at
             WHERE id = :id AND project_id = :project_id AND deleted_at IS NULL'
        );
        $update->execute([
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
            'test_data' => $testData,
        ], 201);
    }

    public function savePhaseTestData(Request $request): Response
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

        $testData = $this->normalizePhaseTestData($phase['test_data'] ?? null);
        if ((int) ($testData['template_id'] ?? 0) <= 0) {
            return $this->fail('Validation failed', 422, ['tests' => ['not_initialized']]);
        }

        $payload = $request->all();
        $payloadJson = is_array($payload['payload_json'] ?? null) ? $payload['payload_json'] : null;
        if ($payloadJson === null) {
            return $this->fail('Validation failed', 422, ['payload_json' => ['required_array']]);
        }

        $sessionKey = (string) config('admin.session_key', 'operations_user');
        $adminUser = $request->session()[$sessionKey] ?? [];
        $actorId = (int) ($adminUser['id'] ?? 0);

        $testData['payload_json'] = $payloadJson;
        $testData['status'] = 'completed';
        $testData['saved_at'] = date('c');
        $testData['updated_by_user_id'] = $actorId > 0 ? $actorId : null;

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'UPDATE project_phase
             SET integration_tests_finished = 1,
                 tested_by = :tested_by,
                 test_date = :test_date,
                 test_data = :test_data,
                 updated_at = :updated_at
             WHERE id = :id AND project_id = :project_id AND deleted_at IS NULL'
        );

        $stmt->execute([
            ':tested_by' => $actorId > 0 ? $actorId : null,
            ':test_date' => date('Y-m-d'),
            ':test_data' => json_encode($testData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':updated_at' => date('Y-m-d H:i:s'),
            ':id' => $phaseId,
            ':project_id' => $projectId,
        ]);

        $updated = $this->findPhaseById($projectId, $phaseId);

        return $this->ok([
            'phase' => $this->formatPhase(is_array($updated) ? $updated : []),
            'test_data' => $testData,
        ]);
    }

    public function uploadPhaseTestAttachment(Request $request): Response
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

        $testData = $this->normalizePhaseTestData($phase['test_data'] ?? null);
        if ((int) ($testData['template_id'] ?? 0) <= 0) {
            return $this->fail('Validation failed', 422, ['tests' => ['not_initialized']]);
        }

        $file = $request->file('file');
        if (!is_array($file)) {
            return $this->fail('Validation failed', 422, ['file' => ['missing']]);
        }

        $tmpPath = trim((string) ($file['tmp_name'] ?? ''));
        $originalFilename = trim((string) ($file['name'] ?? ''));
        $size = (int) ($file['size'] ?? 0);
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($errorCode !== UPLOAD_ERR_OK || $tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return $this->fail('Validation failed', 422, ['file' => ['upload_failed']]);
        }

        $maxBytes = $this->effectiveAttachmentMaxFileSizeBytes();
        if ($size <= 0 || $size > $maxBytes) {
            return $this->fail('Validation failed', 422, ['file' => ['too_large']]);
        }

        $mimeType = $this->detectAttachmentMimeType($tmpPath, (string) ($file['type'] ?? ''));
        if (!isset(self::ALLOWED_TEST_ATTACHMENT_MIME_TYPES[$mimeType])) {
            return $this->fail('Validation failed', 422, ['file' => ['invalid_type']]);
        }

        $relativeDirectory = date('Y/m');
        $targetDirectory = base_path(self::PROJECT_TEST_ATTACHMENT_BASE_PATH . '/' . $relativeDirectory);
        if (!$this->ensureDirectory($targetDirectory)) {
            return $this->fail('Attachment storage unavailable', 500, ['storage' => ['unavailable']]);
        }

        $extension = self::ALLOWED_TEST_ATTACHMENT_MIME_TYPES[$mimeType];
        $storedFilename = bin2hex(random_bytes(16)) . '.' . $extension;
        $relativePath = $relativeDirectory . '/' . $storedFilename;
        $absolutePath = base_path(self::PROJECT_TEST_ATTACHMENT_BASE_PATH . '/' . $relativePath);

        if (!move_uploaded_file($tmpPath, $absolutePath)) {
            return $this->fail('Attachment could not be persisted', 500, ['storage' => ['write_failed']]);
        }

        $sessionKey = (string) config('admin.session_key', 'operations_user');
        $adminUser = $request->session()[$sessionKey] ?? [];
        $actorId = (int) ($adminUser['id'] ?? 0);

        $attachments = is_array($testData['attachments'] ?? null) ? $testData['attachments'] : [];
        $attachment = [
            'id' => bin2hex(random_bytes(12)),
            'original_filename' => $originalFilename !== '' ? $originalFilename : $storedFilename,
            'mime_type' => $mimeType,
            'size_bytes' => $size,
            'storage_path' => $relativePath,
            'uploaded_at' => date('c'),
            'uploaded_by_user_id' => $actorId > 0 ? $actorId : null,
        ];
        $attachments[] = $attachment;

        $testData['attachments'] = $attachments;
        $testData['updated_by_user_id'] = $actorId > 0 ? $actorId : null;

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'UPDATE project_phase
             SET test_data = :test_data,
                 updated_at = :updated_at
             WHERE id = :id AND project_id = :project_id AND deleted_at IS NULL'
        );
        $stmt->execute([
            ':test_data' => json_encode($testData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':updated_at' => date('Y-m-d H:i:s'),
            ':id' => $phaseId,
            ':project_id' => $projectId,
        ]);

        $updated = $this->findPhaseById($projectId, $phaseId);

        return $this->ok([
            'attachment' => $attachment,
            'phase' => $this->formatPhase(is_array($updated) ? $updated : []),
        ], 201);
    }

    public function downloadPhaseTestAttachment(Request $request): Response
    {
        if (!$this->canViewProjects($request)) {
            return $this->fail('Forbidden', 403, ['permission' => ['insufficient_role']]);
        }

        $projectId = (int) $request->attribute('id', 0);
        $phaseId = (int) $request->attribute('phase_id', 0);
        $attachmentId = trim((string) $request->attribute('attachment_id', ''));

        if ($projectId <= 0 || $phaseId <= 0 || $attachmentId === '' || !$this->projectExists($projectId)) {
            return $this->fail('Project, phase or attachment not found', 404);
        }

        $phase = $this->findPhaseById($projectId, $phaseId);
        if (!is_array($phase)) {
            return $this->fail('Project, phase or attachment not found', 404);
        }

        $testData = $this->normalizePhaseTestData($phase['test_data'] ?? null);
        $attachments = is_array($testData['attachments'] ?? null) ? $testData['attachments'] : [];

        $selected = null;
        foreach ($attachments as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (trim((string) ($item['id'] ?? '')) !== $attachmentId) {
                continue;
            }

            $selected = $item;
            break;
        }

        if (!is_array($selected)) {
            return $this->fail('Project, phase or attachment not found', 404);
        }

        $relativePath = trim((string) ($selected['storage_path'] ?? ''));
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return $this->fail('Attachment not found', 404);
        }

        $absolutePath = base_path(self::PROJECT_TEST_ATTACHMENT_BASE_PATH . '/' . $relativePath);
        if (!is_file($absolutePath)) {
            return $this->fail('Attachment not found', 404);
        }

        $mimeType = $this->detectAttachmentMimeType($absolutePath, (string) ($selected['mime_type'] ?? 'application/octet-stream'));
        $filename = trim((string) ($selected['original_filename'] ?? 'attachment'));
        if ($filename === '') {
            $filename = 'attachment';
        }

        $content = file_get_contents($absolutePath);
        if (!is_string($content)) {
            return $this->fail('Attachment not readable', 500);
        }

        return new Response($content, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="' . addslashes($filename) . '"',
            'Cache-Control' => 'private, no-store',
        ]);
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

        $this->recalculateProjectProgress($projectId);

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

        $this->recalculateProjectProgress($projectId);

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

        $this->recalculateProjectProgress($projectId);

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

        $name = trim((string) $request->input('name', ''));
        if ($name !== '') {
            $fields[]       = 'name = :name';
            $bindings[':name'] = $name;
        }

        $description = trim((string) $request->input('description', ''));
        if ($description !== '') {
            $fields[]       = 'description = :description';
            $bindings[':description'] = $description;
        }

        if ($request->input('client_id') !== null) {
            $clientId = (int) $request->input('client_id', 0);
            if ($clientId <= 0) {
                return $this->fail('Validation failed', 422, ['client_id' => ['invalid']]);
            }
            $fields[]         = 'client_id = :client_id';
            $bindings[':client_id'] = $clientId;
        }

        $status = trim((string) $request->input('status', ''));
        if ($status !== '') {
            $allowedStatuses = ['pending', 'backlog', 'in_progress', 'review', 'completed', 'on_hold', 'cancelled'];
            if (!in_array($status, $allowedStatuses, true)) {
                return $this->fail('Validation failed', 422, ['status' => ['invalid']]);
            }
            $fields[]       = 'status = :status';
            $bindings[':status'] = $status;
        }

        $isActive = $request->input('is_active');
        if ($isActive !== null) {
            $isActive = (int) (bool) $isActive;
            $fields[]       = 'is_active = :is_active';
            $bindings[':is_active'] = $isActive;
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
            'SELECT p.id, p.name, p.description, p.client_id, c.name AS client_name, p.status, p.progress, p.is_active, p.due_date, p.created_at, p.updated_at
             FROM projects p
             LEFT JOIN clients c ON c.id = p.client_id
             WHERE p.id = :id'
        );
        $row->execute([':id' => $id]);
        $projectData = $row->fetch(\PDO::FETCH_ASSOC);

        return $this->ok(['project' => $this->formatProject(is_array($projectData) ? $projectData : [])]);
    }

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
            'progress'      => (int) ($row['progress'] ?? 0),
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

        if (is_array($decodedTestData)) {
            $decodedTestData = $this->normalizePhaseTestData($decodedTestData);
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

    /** @param mixed $raw */
    /** @return array<string, mixed> */
    private function normalizePhaseTestData(mixed $raw): array
    {
        $decoded = $raw;
        if (is_string($decoded) && trim($decoded) !== '') {
            $parsed = json_decode($decoded, true);
            if (is_array($parsed)) {
                $decoded = $parsed;
            }
        }

        if (!is_array($decoded)) {
            return [];
        }

        $attachments = is_array($decoded['attachments'] ?? null) ? $decoded['attachments'] : [];
        $normalizedAttachments = [];

        foreach ($attachments as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $normalizedAttachments[] = [
                'id' => $id,
                'original_filename' => (string) ($item['original_filename'] ?? ''),
                'mime_type' => (string) ($item['mime_type'] ?? 'application/octet-stream'),
                'size_bytes' => (int) ($item['size_bytes'] ?? 0),
                'storage_path' => (string) ($item['storage_path'] ?? ''),
                'uploaded_at' => isset($item['uploaded_at']) ? (string) $item['uploaded_at'] : null,
                'uploaded_by_user_id' => isset($item['uploaded_by_user_id']) ? (int) $item['uploaded_by_user_id'] : null,
            ];
        }

        return [
            'created_at' => isset($decoded['created_at']) ? (string) $decoded['created_at'] : null,
            'created_by_user_id' => isset($decoded['created_by_user_id']) ? (int) $decoded['created_by_user_id'] : null,
            'template_id' => (int) ($decoded['template_id'] ?? 0),
            'template_key' => (string) ($decoded['template_key'] ?? ''),
            'template_name' => (string) ($decoded['template_name'] ?? ''),
            'template_version_id' => (int) ($decoded['template_version_id'] ?? 0),
            'template_version_no' => (int) ($decoded['template_version_no'] ?? 0),
            'schema_json' => is_array($decoded['schema_json'] ?? null) ? $decoded['schema_json'] : [],
            'payload_json' => is_array($decoded['payload_json'] ?? null) ? $decoded['payload_json'] : [],
            'attachments' => $normalizedAttachments,
            'status' => (string) ($decoded['status'] ?? ''),
            'saved_at' => isset($decoded['saved_at']) ? (string) $decoded['saved_at'] : null,
            'updated_by_user_id' => isset($decoded['updated_by_user_id']) ? (int) $decoded['updated_by_user_id'] : null,
        ];
    }

    private function effectiveAttachmentMaxFileSizeBytes(): int
    {
        $defaultMb = 5;
        $maxMb = $defaultMb;

        try {
            $row = db('settings')
                ->where('`key`', 'media_max_file_size')
                ->select(['value'])
                ->first();

            $raw = (string) ($row['value'] ?? '');
            if (is_numeric($raw)) {
                $maxMb = (int) $raw;
            }
        } catch (Throwable) {
            $maxMb = $defaultMb;
        }

        $maxMb = max(1, min(5120, $maxMb));
        return $maxMb * 1024 * 1024;
    }

    private function detectAttachmentMimeType(string $filePath, string $fallback): string
    {
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($filePath);
            if (is_string($detected) && trim($detected) !== '') {
                return trim($detected);
            }
        }

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = @finfo_file($finfo, $filePath);
                @finfo_close($finfo);
                if (is_string($detected) && trim($detected) !== '') {
                    return trim($detected);
                }
            }
        }

        $candidate = trim($fallback);
        return $candidate !== '' ? $candidate : 'application/octet-stream';
    }

    private function ensureDirectory(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            return false;
        }

        return is_writable($path);
    }

    /** @return array<string, mixed>|null */
    private function findProjectById(int $projectId): ?array
    {
        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'SELECT p.id, p.name, p.description, p.client_id, c.name AS client_name, p.status, p.progress, p.is_active, p.due_date, p.created_at
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

    private function recalculateProjectProgress(int $projectId): void
    {
        if ($projectId <= 0) {
            return;
        }

        $pdo = app(Database::class)->connection();

        $avgStmt = $pdo->prepare(
            "SELECT AVG(pp.progress)
             FROM project_phase pp
             WHERE pp.project_id = :project_id
               AND pp.deleted_at IS NULL
             AND LOWER(pp.status) <> 'cancelled'"
        );
        $avgStmt->execute([':project_id' => $projectId]);

        $avg = $avgStmt->fetchColumn();
        $progress = $avg !== false && $avg !== null
            ? (int) max(0, min(100, (int) round((float) $avg)))
            : 0;

        $update = $pdo->prepare(
            'UPDATE projects
             SET progress = :progress,
                 updated_at = :updated_at
             WHERE id = :project_id AND deleted_at IS NULL'
        );
        $update->execute([
            ':progress' => $progress,
            ':updated_at' => date('Y-m-d H:i:s'),
            ':project_id' => $projectId,
        ]);
    }
}
