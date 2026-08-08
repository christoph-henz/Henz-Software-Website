<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\BaseApiController;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Exceptions\ValidationHttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Support\PermissionBits;
use App\Repositories\SessionAuditRepository;
use App\Services\FormTemplateService;
use App\Services\FormTemplatePdfService;

final class FormTemplateAdminController extends BaseApiController
{
    private const MANAGE_FORM_TEMPLATES_BIT = 16384;

    public function index(Request $request): Response
    {
        if (!$this->canManageTemplates($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        ['sort' => $sort, 'direction' => $direction] = $this->resolveListSorting($request);
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $errors = [];
        if (!in_array($sort, ['name', 'description', 'created_at'], true)) {
            $errors['sort'][] = 'sort must be one of name, description, created_at';
        }

        if (!in_array($direction, ['asc', 'desc'], true)) {
            $errors['direction'][] = 'direction must be asc or desc';
        }

        if ($page <= 0) {
            $errors['page'][] = 'page must be a positive integer';
        }

        if ($perPage <= 0 || $perPage > 100) {
            $errors['per_page'][] = 'per_page must be between 1 and 100';
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'name' => trim((string) $request->query('name', '')),
            'description' => trim((string) $request->query('description', '')),
            'is_active' => $this->readBoolQuery($request, 'active_only', false) ? 1 : '',
            'include_deleted' => $this->readBoolQuery($request, 'include_deleted', false),
        ];

        try {
            $result = app(FormTemplateService::class)->list($filters, $sort, strtoupper($direction), $page, $perPage);
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->fail($exception->getMessage(), $exception->statusCode());
        }

        return $this->ok([
            'data' => $result['data'],
            'pagination' => $result['meta'],
        ]);
    }

    public function show(Request $request): Response
    {
        if (!$this->canManageTemplates($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['id must be a positive integer'],
            ]);
        }

        try {
            $template = app(FormTemplateService::class)->getById(
                $id,
                $this->readBoolQuery($request, 'include_deleted', false)
            );
        } catch (NotFoundHttpException $exception) {
            return $this->fail($exception->getMessage(), 404);
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->fail($exception->getMessage(), $exception->statusCode());
        }

        return $this->ok([
            'template' => $template,
        ]);
    }

    public function store(Request $request): Response
    {
        if (!$this->canManageTemplates($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        try {
            $template = app(FormTemplateService::class)->createTemplate($request->all());
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->fail($exception->getMessage(), $exception->statusCode());
        }

        return $this->ok([
            'template' => $template,
        ], 201);
    }

    public function update(Request $request): Response
    {
        if (!$this->canManageTemplates($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['id must be a positive integer'],
            ]);
        }

        try {
            $template = app(FormTemplateService::class)->updateTemplate($id, $request->all());
        } catch (NotFoundHttpException $exception) {
            return $this->fail($exception->getMessage(), 404);
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->fail($exception->getMessage(), $exception->statusCode());
        }

        return $this->ok([
            'template' => $template,
        ]);
    }

    public function destroy(Request $request): Response
    {
        if (!$this->canManageTemplates($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['id must be a positive integer'],
            ]);
        }

        try {
            $deleted = app(FormTemplateService::class)->softDeleteTemplate($id);
        } catch (NotFoundHttpException $exception) {
            return $this->fail($exception->getMessage(), 404);
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->fail($exception->getMessage(), $exception->statusCode());
        }

        return $this->ok([
            'template' => $deleted,
        ]);
    }

    public function listVersions(Request $request): Response
    {
        if (!$this->canManageTemplates($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['id must be a positive integer'],
            ]);
        }

        try {
            $versions = app(FormTemplateService::class)->listVersions($id);
        } catch (NotFoundHttpException $exception) {
            return $this->fail($exception->getMessage(), 404);
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->fail($exception->getMessage(), $exception->statusCode());
        }

        return $this->ok([
            'versions' => $versions,
        ]);
    }

    public function showVersion(Request $request): Response
    {
        if (!$this->canManageTemplates($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $templateId = (int) $request->attribute('id', 0);
        $versionId = (int) $request->attribute('version_id', 0);

        $errors = [];
        if ($templateId <= 0) {
            $errors['id'][] = 'id must be a positive integer';
        }

        if ($versionId <= 0) {
            $errors['version_id'][] = 'version_id must be a positive integer';
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        try {
            $version = app(FormTemplateService::class)->getVersionById($templateId, $versionId);
        } catch (NotFoundHttpException $exception) {
            return $this->fail($exception->getMessage(), 404);
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->fail($exception->getMessage(), $exception->statusCode());
        }

        return $this->ok([
            'version' => $version,
        ]);
    }

    public function publishVersion(Request $request): Response
    {
        if (!$this->canManageTemplates($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['id must be a positive integer'],
            ]);
        }

        $payload = $request->all();
        $schema = isset($payload['schema_json']) && is_array($payload['schema_json']) ? $payload['schema_json'] : [];

        try {
            $version = app(FormTemplateService::class)->publishVersion(
                $id,
                $this->actorUserId($request),
                $schema
            );
        } catch (NotFoundHttpException $exception) {
            return $this->fail($exception->getMessage(), 404);
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->fail($exception->getMessage(), $exception->statusCode());
        }

        return $this->ok([
            'version' => $version,
        ], 201);
    }

    public function exportVersionPdf(Request $request): Response
    {
        if (!$this->canManageTemplates($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $templateId = (int) $request->attribute('id', 0);
        $versionId = (int) $request->attribute('version_id', 0);

        $errors = [];
        if ($templateId <= 0) {
            $errors['id'][] = 'id must be a positive integer';
        }

        if ($versionId <= 0) {
            $errors['version_id'][] = 'version_id must be a positive integer';
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        try {
            $template = app(FormTemplateService::class)->getById($templateId, false);
            $version = app(FormTemplateService::class)->getVersionById($templateId, $versionId);
            $pdf = app(FormTemplatePdfService::class)->renderTemplateVersionPdf($template, $version);
        } catch (NotFoundHttpException $exception) {
            return $this->fail($exception->getMessage(), 404);
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->fail($exception->getMessage(), $exception->statusCode());
        } catch (\Throwable) {
            return $this->fail('PDF export failed', 500);
        }

        $this->auditTemplateVersionPdfExport($request, $templateId, $versionId);

        return new Response((string) ($pdf['content'] ?? ''), 200, [
            'Content-Type' => (string) ($pdf['mime_type'] ?? 'application/pdf'),
            'Content-Disposition' => 'attachment; filename="' . (string) ($pdf['file_name'] ?? 'vorlage.pdf') . '"',
            'Content-Length' => (string) strlen((string) ($pdf['content'] ?? '')),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function canManageTemplates(Request $request): bool
    {
        $adminUser = $this->adminUser($request);
        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $requiredMask = PermissionBits::resolve('manage_form_templates', self::MANAGE_FORM_TEMPLATES_BIT);

        return ($roleMask & $requiredMask) !== 0;
    }

    /** @return array<string, mixed> */
    private function adminUser(Request $request): array
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');

        return is_array($session[$sessionKey] ?? null) ? $session[$sessionKey] : [];
    }

    private function actorUserId(Request $request): int
    {
        $adminUser = $this->adminUser($request);
        return (int) ($adminUser['id'] ?? 0);
    }

    /** @return array{sort: string, direction: string} */
    private function resolveListSorting(Request $request): array
    {
        $sort = strtolower(trim((string) $request->query('sort', 'name')));
        $directionQuery = $request->query('direction');
        $direction = $directionQuery === null
            ? ''
            : strtolower(trim((string) $directionQuery));

        $compactSortMap = [
            'name_asc' => ['sort' => 'name', 'direction' => 'asc'],
            'name_desc' => ['sort' => 'name', 'direction' => 'desc'],
            'description_asc' => ['sort' => 'description', 'direction' => 'asc'],
            'description_desc' => ['sort' => 'description', 'direction' => 'desc'],
            'created_asc' => ['sort' => 'created_at', 'direction' => 'asc'],
            'created_desc' => ['sort' => 'created_at', 'direction' => 'desc'],
            'created_at_asc' => ['sort' => 'created_at', 'direction' => 'asc'],
            'created_at_desc' => ['sort' => 'created_at', 'direction' => 'desc'],
        ];

        if (isset($compactSortMap[$sort])) {
            $resolved = $compactSortMap[$sort];

            return [
                'sort' => $resolved['sort'],
                'direction' => $direction !== '' ? $direction : $resolved['direction'],
            ];
        }

        return [
            'sort' => $sort,
            'direction' => $direction !== '' ? $direction : 'asc',
        ];
    }

    private function readBoolQuery(Request $request, string $key, bool $default): bool
    {
        $value = $request->query($key, $default ? '1' : '0');
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function auditTemplateVersionPdfExport(Request $request, int $templateId, int $versionId): void
    {
        $purposeCode = trim((string) $request->query('purpose_code', 'SESSION_TEMPLATE_VERSION_PDF_EXPORT'));
        if ($purposeCode === '') {
            $purposeCode = 'SESSION_TEMPLATE_VERSION_PDF_EXPORT';
        }

        try {
            app(SessionAuditRepository::class)->create([
                'actor_user_id' => $this->actorUserId($request),
                'action' => 'export',
                'resource_type' => 'session_template_version_pdf',
                'resource_id' => $templateId . ':' . $versionId,
                'field_scope' => 'template_version_pdf',
                'purpose_code' => $purposeCode,
                'ip_address' => (string) ($request->header('X-Forwarded-For', '') ?: ($_SERVER['REMOTE_ADDR'] ?? '')),
                'user_agent' => (string) $request->header('User-Agent', ''),
            ]);
        } catch (\Throwable) {
            // S-011 policy: audit failures must not block business operations.
            return;
        }
    }
}
