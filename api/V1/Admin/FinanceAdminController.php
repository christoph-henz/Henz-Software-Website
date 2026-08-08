<?php
declare(strict_types=1);

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\BaseApiController;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\ClientFieldEncryptionService;
use App\Services\EmailAutomationService;
use App\Services\InvoicePdfService;
use App\Core\Support\PermissionBits;
use DateTimeImmutable;
use DateTimeZone;
final class FinanceAdminController extends BaseApiController
{
    private const VIEW_PROJECT_MASK = 1;
    private const MANAGE_PROJECT_MASK = 2;

    private ?bool $invoiceTableAvailable = null;
    private ?bool $invoicePdfColumnAvailable = null;

    private function canViewProjects(Request $request): bool
    {
        $roleMask = $this->getUserRoleMask($request);
        $viewMask = PermissionBits::resolve('view_projects', self::VIEW_PROJECT_MASK);
        $manageMask = PermissionBits::resolve('manage_projects', self::MANAGE_PROJECT_MASK);

        return ($roleMask & $viewMask) !== 0 || ($roleMask & $manageMask) !== 0;
    }

    private function canManageProjects(Request $request): bool
    {
        $roleMask = $this->getUserRoleMask($request);
        $manageMask = PermissionBits::resolve('manage_projects', self::MANAGE_PROJECT_MASK);

        return ($roleMask & $manageMask) !== 0;
    }

    private function getUserRoleMask(Request $request): int
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = $session[$sessionKey] ?? null;

        if (!is_array($adminUser)) {
            return 0;
        }

        return (int) ($adminUser['role_mask'] ?? 0);
    }

    private function getUserId(Request $request): ?int
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = $session[$sessionKey] ?? null;

        if (!is_array($adminUser)) {
            return null;
        }

        $id = $adminUser['id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    public function createInvoice(Request $request): Response
    {
        if (!$this->canManageProjects($request)) {
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

        $project = $this->fetchProjectRow($id);
        if ($project === null) {
            return $this->fail('Project not found', 404);
        }

        if (!$this->canCreateInvoiceForProject($project)) {
            return $this->fail('Invoice feature not available', 409, [
                'invoice' => ['payment_automation_disabled'],
            ]);
        }

        if (!$this->isInvoiceTableAvailable()) {
            return $this->fail('Invoice feature not available', 503, [
                'invoice' => ['migration_required'],
            ]);
        }

        if ((string) ($project['status'] ?? 'pending') === 'cancelled') {
            return $this->fail('Invalid project state', 409, [
                'status' => ['invoice_not_allowed_for_cancelled_project'],
            ]);
        }

        $existingInvoice = $this->fetchLatestInvoiceSummary($id);
        $projectStatus = (string) ($project['status'] ?? 'pending');
        if ($existingInvoice !== null && $projectStatus !== 'pending') {
            return $this->fail('Invoice already exists', 409, [
                'invoice' => ['already_exists'],
            ]);
        }

        $data = $request->all();
        $includeDefaultItem = !array_key_exists('include_default_item', $data)
            || filter_var((string) $data['include_default_item'], FILTER_VALIDATE_BOOL) === true;
        $additionalItems = $this->normalizeInvoiceItemsFromRequest($data);
        $discountAmount = isset($data['discount_amount']) && is_numeric($data['discount_amount'])
            ? (float) $data['discount_amount']
            : 0.0;

        $invoiceDate = (string) ($data['invoice_date'] ?? '') !== '' ? DateTimeImmutable::createFromFormat('Y-m-d', (string) $data['invoice_date'], new DateTimeZone('UTC')) : null;
        if ($invoiceDate === null) {
            return $this->fail('Validation failed', 422, [
                'invoice_date' => ['invalid_date'],
            ]);
        }

        $dueDate = (string) ($data['due_date'] ?? '') !== '' ? (string) ($data['due_date'] ?? '') : $this->previousBusinessDay($invoiceDate);

        $currency = strtoupper(trim((string) config('mail.payment.currency', 'EUR')));
        if ($currency === '') {
            $currency = 'EUR';
        }

        $invoiceItems = [];
        if ($includeDefaultItem) {

            $invoiceItems[] = [
                'type' => 'service',
                'description' => sprintf(
                    '%s (%s)',
                    (string) ($project['service_name'] ?? 'Leistung'),
                    (string) ($project['scheduled_at'] ?? '')
                ),
                'quantity' => 1.0,
                'unit_price' => isset($project['service_price']) ? (float) $project['service_price'] : 0.0,
            ];
        }

        foreach ($additionalItems as $item) {
            $invoiceItems[] = [
                'type' => 'additional',
                'description' => (string) $item['description'],
                'quantity' => (float) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
            ];
        }

        if ($discountAmount !== 0.0) {
            $invoiceItems[] = [
                'type' => 'discount',
                'description' => 'Rabatt',
                'quantity' => 1.0,
                'unit_price' => -abs($discountAmount),
            ];
        }

        if ($invoiceItems === []) {
            return $this->fail('Validation failed', 422, [
                'invoice_items' => ['at_least_one_item_required'],
            ]);
        }

        $baseAmount = 0.0;
        $discountTotal = 0.0;
        foreach ($invoiceItems as $item) {
            $lineTotal = ((float) $item['quantity']) * ((float) $item['unit_price']);
            if ((string) ($item['type'] ?? '') === 'discount') {
                $discountTotal += abs($lineTotal);
                continue;
            }

            $baseAmount += $lineTotal;
        }
        $baseAmount = round($baseAmount, 2);
        $discountTotal = round($discountTotal, 2);
        $subTotal = round($baseAmount - $discountTotal, 2);

        if ($subTotal <= 0.0) {
            return $this->fail('Validation failed', 422, [
                'total_amount' => ['must_be_positive'],
            ]);
        }

        $userId = $this->getUserId($request);
        $invoiceId = 0;

        $database = app(Database::class);
        try {
            $invoiceId = (int) $database->transaction(function () use ($database, $id, $project, $invoiceDate, $dueDate, $currency, $invoiceItems, $baseAmount, $discountTotal, $subTotal, $userId): int {
                $alreadyExisting = $this->fetchLatestInvoiceSummary($id);
                if ($alreadyExisting !== null) {
                    db('invoices')
                        ->where('project_id', $id)
                        ->update([
                            'status' => 'retracted',
                        ]);
                }

                $nextInvoiceNumber = $this->reserveNextInvoiceNumber($database);

                $createdAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                $invoiceId = (int) db('invoices')->insert([
                    'invoice_number' => $nextInvoiceNumber,
                    'client_id' => (int) ($project['client_id'] ?? 0),
                    'project_id' => $id,
                    'currency_code' => $currency,
                    'sub_total_amount' => $baseAmount,
                    'discount_amount' => $discountTotal,
                    'total_amount' => $subTotal,
                    'status' => 'created',
                    'invoice_date' => $invoiceDate->format('Y-m-d'),
                    'due_date' => $dueDate instanceof DateTimeImmutable ? $dueDate->format('Y-m-d') : null,
                    'created_by_user_id' => $userId,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                $sortOrder = 0;
                foreach ($invoiceItems as $item) {
                    $sortOrder++;
                    $quantity = (float) ($item['quantity'] ?? 1.0);
                    $unitPrice = (float) ($item['unit_price'] ?? 0.0);
                    db('invoice_items')->insert([
                        'invoice_id' => $invoiceId,
                        'item_type' => (string) ($item['type'] ?? 'additional'),
                        'description' => (string) ($item['description'] ?? ''),
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'line_total' => round($quantity * $unitPrice, 2),
                        'sort_order' => $sortOrder,
                    ]);
                }

                return $invoiceId;
            });
        } catch (\RuntimeException $exception) {
            throw $exception;
        }

        $mailDispatch = app(EmailAutomationService::class)->dispatch('invoice.created', [
            'invoice_id' => $invoiceId,
            'project_id' => $id,
            'client_id' => (int) ($project['client_id'] ?? 0),
        ]);

        $invoiceUpdate = [];

        if ((int) ($mailDispatch['sent'] ?? 0) > 0) {
            $invoiceUpdate['status'] = 'sent';
            $invoiceUpdate['sent_at'] = date('Y-m-d H:i:s');
        }

        if ($this->isInvoicePdfColumnAvailable()) {
            try {
                $pdfMeta = app(InvoicePdfService::class)->generateForInvoice($invoiceId);
                $invoiceUpdate['pdf_path'] = (string) ($pdfMeta['relative_path'] ?? '');
                $invoiceUpdate['pdf_mime_type'] = (string) ($pdfMeta['mime_type'] ?? 'application/pdf');
                $invoiceUpdate['pdf_file_size'] = (int) ($pdfMeta['file_size'] ?? 0);
                $invoiceUpdate['pdf_sha256'] = (string) ($pdfMeta['sha256'] ?? '');
                $invoiceUpdate['pdf_generated_at'] = (string) ($pdfMeta['generated_at'] ?? date('Y-m-d H:i:s'));
            } catch (\Throwable) {
                // Keep invoice flow resilient even when PDF generation fails.
            }
        }

        if ($invoiceUpdate !== []) {
            db('invoices')->where('id', $invoiceId)->update($invoiceUpdate);
        }

        $updatedProject = $this->fetchProjectRow($id);
        $invoiceRow = $this->fetchInvoiceRow($invoiceId);

        return $this->ok([
            'project' => $this->formatProject($updatedProject ?? $project),
            'invoice' => $invoiceRow,
            'email_dispatch' => $mailDispatch,
        ], 201);
    }

    private function isPaymentAutomationEnabled(): bool
    {
        return filter_var((string) config('mail.payment.automation_enabled', false), FILTER_VALIDATE_BOOL) === true;
    }

    /** @param array<string, mixed> $project */
    private function canCreateInvoiceForProject(array $project): bool
    {
        if ($this->isPaymentAutomationEnabled()) {
            return true;
        }

        return (bool) ($project['is_package_project'] ?? false)
            && (int) ($project['package_session_no'] ?? 0) === 1;
    }

    /** @return array<string, mixed>|null */
    private function fetchInvoiceRow(int $invoiceId): ?array
    {
        if (!$this->isInvoiceTableAvailable()) {
            return null;
        }

        $row = db('invoices')
            ->where('id', $invoiceId)
            ->first();

        if (!is_array($row)) {
            return null;
        }

        $pdfPath = trim((string) ($row['pdf_path'] ?? ''));

        return [
            'id' => (int) ($row['id'] ?? 0),
            'invoice_number' => (int) ($row['invoice_number'] ?? 0),
            'client_id' => (int) ($row['client_id'] ?? 0),
            'project_id' => (int) ($row['project_id'] ?? 0),
            'currency_code' => (string) ($row['currency_code'] ?? 'EUR'),
            'sub_total_amount' => isset($row['sub_total_amount']) ? (float) $row['sub_total_amount'] : 0.0,
            'discount_amount' => isset($row['discount_amount']) ? (float) $row['discount_amount'] : 0.0,
            'total_amount' => isset($row['total_amount']) ? (float) $row['total_amount'] : 0.0,
            'status' => (string) ($row['status'] ?? 'created'),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'due_date' => (string) ($row['due_date'] ?? ''),
            'sent_at' => isset($row['sent_at']) ? (string) $row['sent_at'] : null,
            'pdf_available' => $pdfPath !== '',
            'pdf_generated_at' => isset($row['pdf_generated_at']) ? (string) $row['pdf_generated_at'] : null,
            'items' => $this->fetchInvoiceItems($invoiceId),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchInvoiceItems(int $invoiceId): array
    {
        if (!$this->isInvoiceTableAvailable()) {
            return [];
        }

        $rows = db('invoice_items')
            ->where('invoice_id', $invoiceId)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'item_type' => (string) ($row['item_type'] ?? 'additional'),
                'description' => (string) ($row['description'] ?? ''),
                'quantity' => isset($row['quantity']) ? (float) $row['quantity'] : 1.0,
                'unit_price' => isset($row['unit_price']) ? (float) $row['unit_price'] : 0.0,
                'line_total' => isset($row['line_total']) ? (float) $row['line_total'] : 0.0,
            ];
        }, is_array($rows) ? $rows : []);
    }

    private function reserveNextInvoiceNumber(Database $database): int
    {
        $pdo = $database->connection();
        $statement = $pdo->query('SELECT next_invoice_number FROM invoice_number_sequences WHERE id = 1 FOR UPDATE');
        $nextInvoiceNumber = (int) ($statement !== false ? $statement->fetchColumn() : 0);

        if ($nextInvoiceNumber <= 0) {
            $bootstrapStatement = $pdo->query('SELECT COALESCE(MAX(invoice_number), 20260000) + 1 FROM invoices');
            $nextInvoiceNumber = (int) ($bootstrapStatement !== false ? $bootstrapStatement->fetchColumn() : 20260001);
            if ($nextInvoiceNumber <= 0) {
                $nextInvoiceNumber = 20260001;
            }

            db('invoice_number_sequences')->insert([
                'id' => 1,
                'next_invoice_number' => $nextInvoiceNumber + 1,
            ]);

            return $nextInvoiceNumber;
        }

        db('invoice_number_sequences')
            ->where('id', 1)
            ->update([
                'next_invoice_number' => $nextInvoiceNumber + 1,
            ]);

        return $nextInvoiceNumber;
    }

    /** @return array<string, mixed>|null */
    private function fetchLatestInvoiceSummary(int $projectId): ?array
    {
        if (!$this->isInvoiceTableAvailable()) {
            return null;
        }

        $row = db('invoices')
            ->where('project_id', $projectId)
            ->orderBy('id', 'desc')
            ->first();

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'invoice_number' => (int) ($row['invoice_number'] ?? 0),
            'currency_code' => (string) ($row['currency_code'] ?? 'EUR'),
            'total_amount' => isset($row['total_amount']) ? (float) $row['total_amount'] : 0.0,
            'status' => (string) ($row['status'] ?? 'created'),
            'invoice_date' => isset($row['invoice_date']) ? (string) $row['invoice_date'] : null,
            'due_date' => isset($row['due_date']) ? (string) $row['due_date'] : null,
        ];
    }

    /** @return list<array{description:string,quantity:float,unit_price:float}> */
    private function normalizeInvoiceItemsFromRequest(array $data): array
    {
        $rawItems = $data['additional_items'] ?? [];
        if (!is_array($rawItems)) {
            return [];
        }

        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }

            $description = trim((string) ($rawItem['description'] ?? ''));
            $quantity = isset($rawItem['quantity']) && is_numeric($rawItem['quantity'])
                ? (float) $rawItem['quantity']
                : 1.0;
            $unitPrice = isset($rawItem['unit_price']) && is_numeric($rawItem['unit_price'])
                ? (float) $rawItem['unit_price']
                : 0.0;

            if ($description === '' || $quantity <= 0 || $unitPrice === 0.0) {
                continue;
            }

            $items[] = [
                'description' => $description,
                'quantity' => round($quantity, 2),
                'unit_price' => round($unitPrice, 2),
            ];
        }

        return $items;
    }

    private function previousBusinessDay(DateTimeImmutable $date): DateTimeImmutable
    {
        $candidate = $date->modify('-1 day');
        while (in_array((int) $candidate->format('N'), [6, 7], true)) {
            $candidate = $candidate->modify('-1 day');
        }

        return $candidate;
    }

    private function isInvoiceTableAvailable(): bool
    {
        if ($this->invoiceTableAvailable !== null) {
            return $this->invoiceTableAvailable;
        }

        try {
            $pdo = app(Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1'
            );
            $statement->execute(['table_name' => 'invoices']);
            $this->invoiceTableAvailable = $statement->fetchColumn() !== false;
            return $this->invoiceTableAvailable;
        } catch (\Throwable) {
            $this->invoiceTableAvailable = false;
            return false;
        }
    }

    private function isInvoicePdfColumnAvailable(): bool
    {
        if ($this->invoicePdfColumnAvailable !== null) {
            return $this->invoicePdfColumnAvailable;
        }

        try {
            $pdo = app(Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                   AND column_name = :column_name
                 LIMIT 1'
            );
            $statement->execute([
                'table_name' => 'invoices',
                'column_name' => 'pdf_path',
            ]);

            $this->invoicePdfColumnAvailable = $statement->fetchColumn() !== false;
            return $this->invoicePdfColumnAvailable;
        } catch (\Throwable) {
            $this->invoicePdfColumnAvailable = false;
            return false;
        }
    }
}