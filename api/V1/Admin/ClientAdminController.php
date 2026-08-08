<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\BaseApiController;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Logging\Logger;
use App\Services\ClientFieldEncryptionService;
use App\Services\EmailLogPrivacyService;
use App\Services\InvoicePdfService;
use App\Core\Support\PermissionBits;
use DateTimeImmutable;

final class ClientAdminController extends BaseApiController
{
    private const VIEW_CLIENTS_BIT = 32768;
    private const MANAGE_CLIENTS_BIT = 65536;
    private ?bool $invoicePdfColumnAvailable = null;
    private ?bool $clientTimezoneColumnAvailable = null;
    private ?bool $emailLogClientRefHashColumnAvailable = null;
    private ?bool $emailLogRecipientEncryptedColumnAvailable = null;
    private ?bool $emailLogSenderColumnAvailable = null;
    private ?bool $emailLogSenderEncryptedColumnAvailable = null;
    private ?bool $invoiceTableAvailable = null;
    private ?bool $ticketsTableAvailable = null;
    private ?bool $ticketProtocolsTableAvailable = null;
    /** @var array<string, true>|null */
    private ?array $invoiceColumnSet = null;

    public function index(Request $request): Response
    {
        if (!$this->canViewClients($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $offset = ($page - 1) * $perPage;

        $sort = strtolower(trim((string) $request->query('sort', 'name')));
        $direction = strtolower(trim((string) $request->query('direction', 'asc')));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';

        $sortMap = [
            'name',
            'email',
            'phone',
            'address',
            'created_at',
        ];
        if (!in_array($sort, $sortMap, true)) {
            $sort = 'name';
        }

        $search = trim((string) $request->query('q', ''));

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->query('SELECT id, name, email, phone, address, created_at FROM clients');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $rows = app(ClientFieldEncryptionService::class)->decryptClientRows(is_array($rows) ? $rows : []);

        if ($search !== '') {
            $needle = strtolower($search);
            $rows = array_values(array_filter($rows, static function (array $row) use ($needle): bool {
                $name = strtolower(trim((string) ($row['name'] ?? '')));
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                $phone = strtolower(trim((string) ($row['phone'] ?? '')));
                return str_contains($name, $needle) || str_contains($email, $needle) || str_contains($phone, $needle);
            }));
        }

        usort($rows, static function (array $a, array $b) use ($sort, $direction): int {
            $aValue = strtolower(trim((string) ($a[$sort] ?? '')));
            $bValue = strtolower(trim((string) ($b[$sort] ?? '')));
            $cmp = $aValue <=> $bValue;
            if ($cmp === 0) {
                $cmp = ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            }

            return $direction === 'asc' ? $cmp : -$cmp;
        });

        $total = count($rows);
        $rows = array_slice($rows, $offset, $perPage);

        return $this->ok([
            'clients' => array_map(
                fn(array $row): array => $this->formatClientListItem($row),
                is_array($rows) ? $rows : []
            ),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) max(1, (int) ceil($total / max(1, $perPage))),
                'sort' => $sort,
                'direction' => $direction,
                'q' => $search,
            ],
        ]);
    }

    public function show(Request $request): Response
    {
        if (!$this->canViewClients($request)) {
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

        $row = $this->fetchClient($id);
        if ($row === null) {
            return $this->fail('Client not found', 404);
        }

        return $this->ok([
            'client' => $this->formatClientDetail($row),
        ]);
    }

    public function store(Request $request): Response
    {
        if (!$this->canManageClients($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $payload = $request->all();
        $errors = [];

        $name = trim((string) ($payload['name'] ?? $payload['name'] ?? ''));
        if ($name === '') {
            $errors['name'][] = 'required';
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ($email === '') {
            $errors['email'][] = 'required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'invalid_email';
        } elseif ($this->isEmailAlreadyUsed($email)) {
            $errors['email'][] = 'already_exists';
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        $phone = trim((string) ($payload['phone'] ?? ''));
        $address = trim((string) ($payload['address'] ?? ''));
        $now = date('Y-m-d H:i:s');

        $columns = [
            'name',
            'email',
            'phone',
            'address',
            'created_at',
            'updated_at',
        ];
        $placeholders = [
            ':name',
            ':email',
            ':phone',
            ':address',
            ':created_at',
            ':updated_at',
        ];
        $bindings = [
            ':name' => $name,
            ':email' => $email,
            ':phone' => $phone !== '' ? $phone : null,
            ':address' => $address !== '' ? $address : null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ];

        $storagePayload = [];
        foreach ($bindings as $key => $value) {
            $storagePayload[ltrim((string) $key, ':')] = $value;
        }
        $storagePayload = app(ClientFieldEncryptionService::class)->encryptClientData($storagePayload);
        foreach ($storagePayload as $column => $value) {
            if (!in_array($column, $columns, true)) {
                $columns[] = $column;
                $placeholders[] = ':' . $column;
            }
            $bindings[':' . $column] = $value;
        }

        try {
            $pdo = app(Database::class)->connection();
            $insertSql = 'INSERT INTO clients (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $stmt = $pdo->prepare($insertSql);
            var_dump($insertSql, $bindings); // Debugging line to check the SQL and bindings
            $stmt->execute($bindings);

            $newId = (int) $pdo->lastInsertId();

            if ($newId <= 0) {
                return $this->fail('Client could not be created', 500, [
                    'client' => ['creation_failed'],
                ]);
            }

            $created = $this->fetchClient($newId);

            if ($created === null) {
                return $this->fail('Client created but could not be loaded', 500, [
                    'client' => ['reload_failed'],
                ]);
            }

            return $this->ok([
                'client' => $this->formatClientDetail($created),
            ]);
        } catch (\Throwable $e) {

            if ($this->isDuplicateEmailConstraintViolation($e)) {
                return $this->fail('Validation failed', 422, [
                    'email' => ['already_exists'],
                ]);
            }

            if ($e instanceof \PDOException) {
                return $this->fail('Database error', 500, [
                    'database' => ['insert_failed'],
                ]);
            }

            return $this->fail('Unexpected error', 500, [
                'server' => ['unexpected_error'],
            ]);
        }
    }

    public function validateEmail(Request $request): Response
    {
        if (!$this->canManageClients($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $email = strtolower(trim((string) $request->query('email', '')));
        if ($email === '') {
            return $this->fail('Validation failed', 422, [
                'email' => ['required'],
            ]);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Validation failed', 422, [
                'email' => ['invalid_email'],
            ]);
        }

        return $this->ok([
            'email' => $email,
            'available' => !$this->isEmailAlreadyUsed($email),
        ]);
    }

    public function update(Request $request): Response
    {
        if (!$this->canManageClients($request)) {
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

        $existing = $this->fetchClient($id);
        if ($existing === null) {
            return $this->fail('Client not found', 404);
        }

        $payload = $request->all();
        $errors = [];
        $fields = [];
        $bindings = [':id' => $id];

        if (array_key_exists('name', $payload)) {
            $name = trim((string) ($payload['name'] ?? ''));
            if ($name === '') {
                $errors['name'][] = 'required';
            } else {
                $fields[] = 'name = :name';
                $bindings[':name'] = $name;
            }
        }

        // Backward compatibility for old payloads still sending first/last name.
        if (
            !array_key_exists('name', $payload)
            && (
                array_key_exists('first_name', $payload)
                || array_key_exists('firstname', $payload)
                || array_key_exists('last_name', $payload)
                || array_key_exists('lastname', $payload)
            )
        ) {
            $firstName = trim((string) ($payload['first_name'] ?? $payload['firstname'] ?? ''));
            $lastName = trim((string) ($payload['last_name'] ?? $payload['lastname'] ?? ''));
            $composedName = trim($firstName . ' ' . $lastName);

            if ($composedName === '') {
                $errors['name'][] = 'required';
            } else {
                $fields[] = 'name = :name';
                $bindings[':name'] = $composedName;
            }
        }

        if (array_key_exists('date_of_birth', $payload) || array_key_exists('dob', $payload)) {
            $dobRaw = trim((string) ($payload['date_of_birth'] ?? $payload['dob'] ?? ''));
            $dob = $this->normalizeDate($dobRaw);
            if ($dob === null) {
                $errors['date_of_birth'][] = 'invalid_date';
            } else {
                $fields[] = 'date_of_birth = :date_of_birth';
                $bindings[':date_of_birth'] = $dob;
            }
        }

        if (array_key_exists('email', $payload)) {
            $email = strtolower(trim((string) ($payload['email'] ?? '')));
            if ($email === '') {
                $errors['email'][] = 'required';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'][] = 'invalid_email';
            } else {
                if ($this->isEmailAlreadyUsed($email, $id)) {
                    $errors['email'][] = 'already_exists';
                } else {
                    $fields[] = 'email = :email';
                    $bindings[':email'] = $email;
                }
            }
        }

        if (array_key_exists('phone', $payload)) {
            $phone = trim((string) ($payload['phone'] ?? ''));
            $fields[] = 'phone = :phone';
            $bindings[':phone'] = $phone !== '' ? $phone : null;
        }

        if (array_key_exists('address', $payload)) {
            $addressRaw = (string) ($payload['address'] ?? '');
            $addressNormalized = str_replace(["\r\n", "\r"], "\n", $addressRaw);
            $fields[] = 'address = :address';
            $bindings[':address'] = trim($addressNormalized) !== '' ? $addressNormalized : null;
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        if ($fields === []) {
            return $this->fail('Validation failed', 422, [
                'payload' => ['no_updatable_fields'],
            ]);
        }

        $fields[] = 'updated_at = :updated_at';
        $bindings[':updated_at'] = date('Y-m-d H:i:s');

        $storagePayload = [];
        foreach ($bindings as $key => $value) {
            $column = ltrim((string) $key, ':');
            if ($column === 'id') {
                continue;
            }
            $storagePayload[$column] = $value;
        }
        $storagePayload = app(ClientFieldEncryptionService::class)->encryptClientData($storagePayload);
        foreach ($storagePayload as $column => $value) {
            if ($column === 'id') {
                continue;
            }
            $fieldExpression = $column . ' = :' . $column;
            if (!in_array($fieldExpression, $fields, true)) {
                $fields[] = $fieldExpression;
            }
            $bindings[':' . $column] = $value;
        }

        $pdo = app(Database::class)->connection();
        $updateSql = 'UPDATE clients SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute($bindings);

        $updated = $this->fetchClient($id);

        return $this->ok([
            'client' => $this->formatClientDetail($updated ?? $existing),
        ]);
    }

    public function invoices(Request $request): Response
    {
        if (!$this->canViewClients($request)) {
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

        $client = $this->fetchClient($id);
        if ($client === null) {
            return $this->fail('Client not found', 404);
        }

        $pdo = app(Database::class)->connection();
        $pdfColumnsAvailable = $this->isInvoicePdfColumnAvailable();
        $pdfSelect = $pdfColumnsAvailable
            ? 'inv.pdf_path, inv.pdf_generated_at'
            : 'NULL AS pdf_path, NULL AS pdf_generated_at';

        $stmt = $pdo->prepare(
            'SELECT
        p.id AS project_id,
        p.name AS project_name,
        p.is_active AS project_is_active,
        p.status AS project_status,
        p.created_at AS project_created_at,

        inv.id AS invoice_id,
        inv.invoice_number,
        inv.status AS invoice_status,
        inv.total_amount,
        inv.currency_code,
        inv.invoice_date,
        inv.due_date,
        inv.sent_at,
        ' . $pdfSelect . '

        FROM projects p

        LEFT JOIN invoices inv
            ON inv.project_id = p.id

        WHERE p.client_id = :client_id

        ORDER BY
            p.created_at DESC,
            inv.invoice_date DESC,
            inv.invoice_number DESC'
        );

        $stmt->execute([
            ':client_id' => $id,
        ]);

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $contractsStmt = $pdo->prepare(
            'SELECT c.id, c.project_id, c.start_date, c.end_date
             FROM contracts c
             INNER JOIN projects p ON p.id = c.project_id
             WHERE p.client_id = :client_id
             ORDER BY c.id DESC'
        );
        $contractsStmt->execute([':client_id' => $id]);
        $contractRows = $contractsStmt->fetchAll(\PDO::FETCH_ASSOC);

        $pdfBasePath = str_starts_with($request->path(), '/v1/')
            ? '/v1/admin/clients'
            : '/clients/data';

        return $this->ok([
            'projects' => $this->formatProjectsWithInvoices(
                is_array($rows) ? $rows : [],
                $id,
                is_array($contractRows) ? $contractRows : [],
                $pdfBasePath
            ),
        ]);
    }

    public function createInvoice(Request $request): Response
    {
        if (!$this->canManageClients($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        if (!$this->isInvoiceTableAvailable()) {
            return $this->fail('Invoice feature not available', 503, [
                'invoice' => ['migration_required'],
            ]);
        }

        $clientId = (int) $request->attribute('id', 0);
        if ($clientId <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['required'],
            ]);
        }

        $client = $this->fetchClient($clientId);
        if ($client === null) {
            return $this->fail('Client not found', 404);
        }

        $payload = $request->all();
        $projectId = (int) ($payload['project_id'] ?? 0);
        $contractId = isset($payload['contract_id']) && $payload['contract_id'] !== '' ? (int) $payload['contract_id'] : null;
        $invoiceDateRaw = trim((string) ($payload['invoice_date'] ?? ''));
        $dueDateRaw = trim((string) ($payload['due_date'] ?? ''));
        $discountAmount = isset($payload['discount_amount']) && is_numeric($payload['discount_amount'])
            ? abs((float) $payload['discount_amount'])
            : 0.0;

        $errors = [];
        if ($projectId <= 0) {
            $errors['project_id'][] = 'required';
        }

        $invoiceDate = DateTimeImmutable::createFromFormat('Y-m-d', $invoiceDateRaw) ?: null;
        if ($invoiceDateRaw === '' || !$invoiceDate instanceof DateTimeImmutable) {
            $errors['invoice_date'][] = 'invalid_date';
        }

        $dueDate = null;
        if ($dueDateRaw !== '') {
            $dueDate = DateTimeImmutable::createFromFormat('Y-m-d', $dueDateRaw) ?: null;
            if (!$dueDate instanceof DateTimeImmutable) {
                $errors['due_date'][] = 'invalid_date';
            }
        }

        $items = $this->normalizeManualInvoiceItems($payload['items'] ?? []);
        if ($items === []) {
            $errors['items'][] = 'at_least_one_item_required';
        }

        $projectRow = $projectId > 0 ? $this->fetchProjectForClient($clientId, $projectId) : null;
        if ($projectId > 0 && $projectRow === null) {
            $errors['project_id'][] = 'not_found';
        }

        $contractRow = null;
        if ($contractId !== null && $contractId > 0) {
            $contractRow = $this->fetchContractForProject($contractId, $projectId);
            if ($contractRow === null) {
                $errors['contract_id'][] = 'invalid_for_project';
            }
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        $baseAmount = 0.0;
        foreach ($items as $item) {
            $baseAmount += ((float) $item['quantity']) * ((float) $item['unit_price']);
        }
        $baseAmount = round($baseAmount, 2);
        $discountAmount = round(min($discountAmount, $baseAmount), 2);
        $subTotal = round($baseAmount - $discountAmount, 2);

        if ($subTotal <= 0.0) {
            return $this->fail('Validation failed', 422, [
                'total_amount' => ['must_be_positive'],
            ]);
        }

        $currency = strtoupper(trim((string) config('mail.payment.currency', 'EUR')));
        if ($currency === '') {
            $currency = 'EUR';
        }

        $invoiceId = (int) app(Database::class)->transaction(function () use ($clientId, $projectId, $contractId, $invoiceDate, $dueDate, $currency, $baseAmount, $discountAmount, $subTotal, $items, $request): int {
            $columns = $this->invoiceColumnSet();
            $nextInvoiceNumber = $this->reserveNextInvoiceNumber();

            $data = [
                'invoice_number' => $nextInvoiceNumber,
                'client_id' => $clientId,
                'currency_code' => $currency,
                'sub_total_amount' => $baseAmount,
                'discount_amount' => $discountAmount,
                'total_amount' => $subTotal,
                'status' => 'created',
                'invoice_date' => $invoiceDate instanceof DateTimeImmutable ? $invoiceDate->format('Y-m-d') : date('Y-m-d'),
                'due_date' => $dueDate instanceof DateTimeImmutable ? $dueDate->format('Y-m-d') : ($invoiceDate instanceof DateTimeImmutable ? $invoiceDate->format('Y-m-d') : date('Y-m-d')),
                'created_by_user_id' => $this->actorId($request),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if (isset($columns['project_id'])) {
                $data['project_id'] = $projectId;
            }
            if (isset($columns['contract_id'])) {
                $data['contract_id'] = $contractId;
            }

            $insertData = [];
            foreach ($data as $column => $value) {
                if (isset($columns[$column])) {
                    $insertData[$column] = $value;
                }
            }

            $invoiceId = (int) db('invoices')->insert($insertData);

            $sortOrder = 0;
            foreach ($items as $item) {
                $sortOrder++;
                $quantity = (float) $item['quantity'];
                $unitPrice = (float) $item['unit_price'];
                db('invoice_items')->insert([
                    'invoice_id' => $invoiceId,
                    'item_type' => 'additional',
                    'description' => (string) $item['description'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => round($quantity * $unitPrice, 2),
                    'sort_order' => $sortOrder,
                ]);
            }

            if ($discountAmount > 0) {
                db('invoice_items')->insert([
                    'invoice_id' => $invoiceId,
                    'item_type' => 'discount',
                    'description' => 'Rabatt',
                    'quantity' => 1,
                    'unit_price' => -$discountAmount,
                    'line_total' => -$discountAmount,
                    'sort_order' => $sortOrder + 1,
                ]);
            }

            return $invoiceId;
        });

        $pdfMeta = null;
        try {
            $pdfMeta = app(InvoicePdfService::class)->generateForInvoice($invoiceId);
            if ($this->isInvoicePdfColumnAvailable()) {
                db('invoices')
                    ->where('id', $invoiceId)
                    ->update([
                        'pdf_path' => (string) ($pdfMeta['relative_path'] ?? ''),
                        'pdf_mime_type' => (string) ($pdfMeta['mime_type'] ?? 'application/pdf'),
                        'pdf_file_size' => (int) ($pdfMeta['file_size'] ?? 0),
                        'pdf_sha256' => (string) ($pdfMeta['sha256'] ?? ''),
                        'pdf_generated_at' => (string) ($pdfMeta['generated_at'] ?? date('Y-m-d H:i:s')),
                    ]);
            }
        } catch (\Throwable $e) {
            $logger = app(Logger::class);
            if ($logger instanceof Logger) {
                $logger->warning('invoice.pdf_generation_failed_on_create', [
                    'invoice_id' => $invoiceId,
                    'message' => $e->getMessage(),
                ]);
            }

            // Invoice creation should remain successful even if PDF generation fails.
        }

        $invoice = db('invoices')->where('id', $invoiceId)->first();

        return $this->ok([
            'invoice' => is_array($invoice) ? $invoice : ['id' => $invoiceId],
            'pdf_export' => [
                'generated' => is_array($pdfMeta),
                'relative_path' => is_array($pdfMeta) ? (string) ($pdfMeta['relative_path'] ?? '') : null,
            ],
        ], 201);
    }

    public function history(Request $request): Response
    {
        if (!$this->canViewClients($request)) {
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

        $client = $this->fetchClient($id);
        if ($client === null) {
            return $this->fail('Client not found', 404);
        }

        $history = $this->fetchHistoryEvents($id);

        usort($history, static function (array $a, array $b): int {
            return strcmp((string) ($b['happened_at'] ?? ''), (string) ($a['happened_at'] ?? ''));
        });

        return $this->ok([
            'history' => $history,
        ]);
    }

    public function appointments(Request $request): Response
    {
        if (!$this->canViewClients($request)) {
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

        $client = $this->fetchClient($id);
        if ($client === null) {
            return $this->fail('Client not found', 404);
        }

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'SELECT
                a.id,
                a.appointment_date,
                a.duration_minutes,
                a.status,
                a.notes,
                a.origin,
                a.created_at,
                a.updated_at,
                s.name AS service_name
             FROM appointments a
             LEFT JOIN services s ON s.id = a.service_id
             WHERE a.client_id = :client_id
             ORDER BY a.appointment_date DESC, a.id DESC'
        );
        $stmt->execute([':client_id' => $id]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $appointments = array_map(static function (array $row): array {
            $appointmentId = (int) ($row['id'] ?? 0);

            return [
                'id' => $appointmentId,
                'service_name' => trim((string) ($row['service_name'] ?? '')),
                'appointment_date' => (string) ($row['appointment_date'] ?? ''),
                'duration_minutes' => (int) ($row['duration_minutes'] ?? 0),
                'status' => (string) ($row['status'] ?? 'pending'),
                'notes' => (string) ($row['notes'] ?? ''),
                'origin' => (string) ($row['origin'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'appointment_url' => $appointmentId > 0 ? '/appointments/' . $appointmentId : null,
            ];
        }, is_array($rows) ? $rows : []);

        return $this->ok([
            'appointments' => $appointments,
        ]);
    }

    public function ticketsIndex(Request $request): Response
    {
        if (!$this->canViewClients($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $offset = ($page - 1) * $perPage;

        $sort = strtolower(trim((string) $request->query('sort', 'created_at')));
        $direction = strtolower(trim((string) $request->query('direction', 'desc')));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';

        $sortMap = [
            'id',
            'ticket_type',
            'category',
            'priority',
            'status',
            'subject',
            'created_at',
            'updated_at',
            'client_name',
        ];
        if (!in_array($sort, $sortMap, true)) {
            $sort = 'created_at';
        }

        $search = strtolower(trim((string) $request->query('q', '')));

        if (!$this->isTicketsTableAvailable()) {
            return $this->ok([
                'tickets' => [],
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'total_pages' => 1,
                    'sort' => $sort,
                    'direction' => $direction,
                    'q' => trim((string) $request->query('q', '')),
                ],
            ]);
        }

        $ticketRows = db('tickets')
            ->select([
                'id',
                'client_id',
                'ticket_type',
                'category',
                'priority',
                'subject',
                'message',
                'source',
                'status',
                'assigned_user_id',
                'created_at',
                'updated_at',
            ])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $pdo = app(Database::class)->connection();
        $clientStmt = $pdo->query('SELECT id, name, email FROM clients');
        $clientRows = $clientStmt !== false ? $clientStmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $clientRows = app(ClientFieldEncryptionService::class)->decryptClientRows(is_array($clientRows) ? $clientRows : []);

        $clientMap = [];
        foreach ($clientRows as $clientRow) {
            if (!is_array($clientRow)) {
                continue;
            }

            $clientId = (int) ($clientRow['id'] ?? 0);
            if ($clientId <= 0) {
                continue;
            }

            $clientMap[$clientId] = [
                'name' => trim((string) ($clientRow['name'] ?? '')),
                'email' => trim((string) ($clientRow['email'] ?? '')),
            ];
        }

        $rows = [];
        foreach ((array) $ticketRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $clientId = (int) ($row['client_id'] ?? 0);
            $client = $clientMap[$clientId] ?? ['name' => '', 'email' => ''];

            $item = [
                'id' => (int) ($row['id'] ?? 0),
                'client_id' => $clientId > 0 ? $clientId : null,
                'client_name' => (string) ($client['name'] ?? ''),
                'client_email' => (string) ($client['email'] ?? ''),
                'ticket_type' => (string) ($row['ticket_type'] ?? ''),
                'category' => (string) ($row['category'] ?? ''),
                'priority' => $row['priority'] !== null ? (string) $row['priority'] : null,
                'subject' => (string) ($row['subject'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
                'source' => (string) ($row['source'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'assigned_user_id' => $row['assigned_user_id'] !== null ? (int) $row['assigned_user_id'] : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];

            if ($search !== '') {
                $haystack = strtolower(trim(implode(' ', [
                    (string) ($item['client_name'] ?? ''),
                    (string) ($item['client_email'] ?? ''),
                    (string) ($item['ticket_type'] ?? ''),
                    (string) ($item['category'] ?? ''),
                    (string) ($item['priority'] ?? ''),
                    (string) ($item['status'] ?? ''),
                    (string) ($item['subject'] ?? ''),
                    (string) ($item['message'] ?? ''),
                ])));
                if (!str_contains($haystack, $search)) {
                    continue;
                }
            }

            $rows[] = $item;
        }

        usort($rows, static function (array $a, array $b) use ($sort, $direction): int {
            $aValue = strtolower(trim((string) ($a[$sort] ?? '')));
            $bValue = strtolower(trim((string) ($b[$sort] ?? '')));
            $cmp = $aValue <=> $bValue;
            if ($cmp === 0) {
                $cmp = ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            }

            return $direction === 'asc' ? $cmp : -$cmp;
        });

        $total = count($rows);
        $rows = array_slice($rows, $offset, $perPage);

        return $this->ok([
            'tickets' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) max(1, (int) ceil($total / max(1, $perPage))),
                'sort' => $sort,
                'direction' => $direction,
                'q' => trim((string) $request->query('q', '')),
            ],
        ]);
    }

    public function tickets(Request $request): Response
    {
        if (!$this->canViewClients($request)) {
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

        $client = $this->fetchClient($id);
        if ($client === null) {
            return $this->fail('Client not found', 404);
        }

        if (!$this->isTicketsTableAvailable()) {
            return $this->ok([
                'tickets' => [],
            ]);
        }

        $rows = db('tickets')
            ->where('client_id', $id)
            ->select([
                'id',
                'ticket_type',
                'category',
                'priority',
                'subject',
                'message',
                'source',
                'status',
                'assigned_user_id',
                'created_at',
                'updated_at',
            ])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $tickets = array_map(
            static fn(array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'ticket_type' => (string) ($row['ticket_type'] ?? ''),
                'category' => (string) ($row['category'] ?? ''),
                'priority' => $row['priority'] !== null ? (string) $row['priority'] : null,
                'subject' => (string) ($row['subject'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
                'source' => (string) ($row['source'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'assigned_user_id' => $row['assigned_user_id'] !== null ? (int) $row['assigned_user_id'] : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ],
            is_array($rows) ? $rows : []
        );

        return $this->ok([
            'tickets' => $tickets,
        ]);
    }

    public function ticketDetail(Request $request): Response
    {
        if (!$this->canViewClients($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $ticketId = (int) $request->attribute('ticket_id', 0);
        if ($ticketId <= 0) {
            return $this->fail('Validation failed', 422, [
                'ticket_id' => ['required'],
            ]);
        }

        if (!$this->isTicketsTableAvailable()) {
            return $this->fail('Ticket not found', 404);
        }

        $row = db('tickets')
            ->where('id', $ticketId)
            ->select([
                'id',
                'client_id',
                'ticket_type',
                'category',
                'priority',
                'subject',
                'message',
                'source',
                'status',
                'assigned_user_id',
                'created_at',
                'updated_at',
            ])
            ->first();

        if (!is_array($row)) {
            return $this->fail('Ticket not found', 404);
        }

        $clientId = (int) ($row['client_id'] ?? 0);
        $client = $clientId > 0 ? $this->fetchClient($clientId) : null;

        return $this->ok([
            'ticket' => $this->formatTicketRow($row, $client),
            'protocols' => $this->fetchTicketProtocols($ticketId),
            'status_options' => $this->ticketStatusOptions(),
            'priority_options' => $this->ticketPriorityOptions(),
        ]);
    }

    public function updateTicket(Request $request): Response
    {
        if (!$this->canManageClients($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $ticketId = (int) $request->attribute('ticket_id', 0);
        if ($ticketId <= 0) {
            return $this->fail('Validation failed', 422, [
                'ticket_id' => ['required'],
            ]);
        }

        if (!$this->isTicketsTableAvailable()) {
            return $this->fail('Ticket not found', 404);
        }

        $row = db('tickets')->where('id', $ticketId)->first();
        if (!is_array($row)) {
            return $this->fail('Ticket not found', 404);
        }

        $payload = $request->all();
        $statusProvided = array_key_exists('status', $payload);
        $priorityProvided = array_key_exists('priority', $payload);
        $protocolNote = trim((string) ($payload['protocol_note'] ?? ''));

        $updates = [];
        $errors = [];
        $oldStatus = (string) ($row['status'] ?? 'new');
        $oldPriority = $row['priority'] !== null ? (string) $row['priority'] : null;
        $newStatus = $oldStatus;
        $newPriority = $oldPriority;

        if ($statusProvided) {
            $newStatus = strtolower(trim((string) ($payload['status'] ?? '')));
            if (!in_array($newStatus, $this->ticketStatusOptions(), true)) {
                $errors['status'][] = 'invalid_status';
            } elseif ($newStatus !== $oldStatus) {
                $updates['status'] = $newStatus;
            }
        }

        if ($priorityProvided) {
            $rawPriority = strtolower(trim((string) ($payload['priority'] ?? '')));
            if ($rawPriority === '') {
                $newPriority = null;
            } elseif (!in_array($rawPriority, $this->ticketPriorityOptions(), true)) {
                $errors['priority'][] = 'invalid_priority';
            } else {
                $newPriority = $rawPriority;
            }

            if ($newPriority !== $oldPriority) {
                $updates['priority'] = $newPriority;
            }
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        if ($updates === [] && $protocolNote === '') {
            return $this->fail('Validation failed', 422, [
                'ticket' => ['nothing_to_update'],
            ]);
        }

        if ($updates !== []) {
            db('tickets')
                ->where('id', $ticketId)
                ->update($updates);
        }

        $actorId = $this->actorId($request);

        if ($protocolNote !== '') {
            $this->createTicketProtocolEntry(
                $ticketId,
                'note',
                $protocolNote,
                null,
                null,
                null,
                null,
                $actorId
            );
        }

        if ($oldStatus !== $newStatus) {
            $this->createTicketProtocolEntry(
                $ticketId,
                'status_change',
                sprintf('Status geändert: %s -> %s', $oldStatus, $newStatus),
                $oldStatus,
                $newStatus,
                null,
                null,
                $actorId
            );
        }

        if ($oldPriority !== $newPriority) {
            $this->createTicketProtocolEntry(
                $ticketId,
                'priority_change',
                sprintf(
                    'Priorität geändert: %s -> %s',
                    $oldPriority ?? '-',
                    $newPriority ?? '-'
                ),
                null,
                null,
                $oldPriority,
                $newPriority,
                $actorId
            );
        }

        $updated = db('tickets')->where('id', $ticketId)->first();
        $clientId = (int) (($updated['client_id'] ?? $row['client_id'] ?? 0));
        $client = $clientId > 0 ? $this->fetchClient($clientId) : null;

        return $this->ok([
            'ticket' => is_array($updated)
                ? $this->formatTicketRow($updated, $client)
                : $this->formatTicketRow($row, $client),
            'protocols' => $this->fetchTicketProtocols($ticketId),
        ]);
    }

    public function createTicketProtocol(Request $request): Response
    {
        if (!$this->canManageClients($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $ticketId = (int) $request->attribute('ticket_id', 0);
        if ($ticketId <= 0) {
            return $this->fail('Validation failed', 422, [
                'ticket_id' => ['required'],
            ]);
        }

        if (!$this->isTicketsTableAvailable()) {
            return $this->fail('Ticket not found', 404);
        }

        $ticket = db('tickets')->where('id', $ticketId)->select(['id'])->first();
        if (!is_array($ticket)) {
            return $this->fail('Ticket not found', 404);
        }

        $payload = $request->all();
        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            return $this->fail('Validation failed', 422, [
                'message' => ['required'],
            ]);
        }

        $this->createTicketProtocolEntry(
            $ticketId,
            'note',
            $message,
            null,
            null,
            null,
            null,
            $this->actorId($request)
        );

        return $this->ok([
            'protocols' => $this->fetchTicketProtocols($ticketId),
        ], 201);
    }

    public function invoicePdf(Request $request): Response
    {
        if (!$this->canViewClients($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $clientId = (int) $request->attribute('id', 0);
        $invoiceId = (int) $request->attribute('invoice_id', 0);

        if ($clientId <= 0 || $invoiceId <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['required'],
                'invoice_id' => ['required'],
            ]);
        }

        $pdfColumnsAvailable = $this->isInvoicePdfColumnAvailable();

        $invoice = db('invoices')
            ->where('id', $invoiceId)
            ->where('client_id', $clientId)
            ->first();

        if (!is_array($invoice)) {
            return $this->fail('Invoice not found', 404);
        }

        $relativePath = trim((string) ($invoice['pdf_path'] ?? ''));
        $absolutePath = $this->resolveInvoicePdfAbsolutePath($relativePath);

        if ($relativePath === '' || !is_file($absolutePath)) {
            try {
                $pdfMeta = app(InvoicePdfService::class)->generateForInvoice($invoiceId);
                $relativePath = (string) ($pdfMeta['relative_path'] ?? '');
                if ($relativePath === '') {
                    return $this->fail('Invoice PDF not found', 404, [
                        'invoice' => ['pdf_not_available'],
                    ]);
                }

                if ($pdfColumnsAvailable) {
                    db('invoices')
                        ->where('id', $invoiceId)
                        ->update([
                            'pdf_path' => $relativePath,
                            'pdf_mime_type' => (string) ($pdfMeta['mime_type'] ?? 'application/pdf'),
                            'pdf_file_size' => (int) ($pdfMeta['file_size'] ?? 0),
                            'pdf_sha256' => (string) ($pdfMeta['sha256'] ?? ''),
                            'pdf_generated_at' => (string) ($pdfMeta['generated_at'] ?? date('Y-m-d H:i:s')),
                        ]);
                }

                $absolutePath = $this->resolveInvoicePdfAbsolutePath($relativePath);
            } catch (\Throwable) {
                return $this->fail('Invoice PDF not found', 404, [
                    'invoice' => ['pdf_missing_on_disk'],
                ]);
            }
        }

        $body = file_get_contents($absolutePath);
        if ($body === false) {
            return $this->fail('Invoice PDF could not be read', 500);
        }

        $invoiceNumber = (int) ($invoice['invoice_number'] ?? $invoiceId);
        $fileName = 'rechnung-' . $invoiceNumber . '.pdf';
        $mimeType = trim((string) ($invoice['pdf_mime_type'] ?? 'application/pdf'));
        $disposition = strtolower(trim((string) $request->query('disposition', 'inline')));
        $dispositionType = in_array($disposition, ['attachment', 'download'], true) ? 'attachment' : 'inline';
        if ($mimeType === '') {
            $mimeType = 'application/pdf';
        }

        return new Response((string) $body, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $dispositionType . '; filename="' . $fileName . '"',
            'Content-Length' => (string) filesize($absolutePath),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function resolveInvoicePdfAbsolutePath(string $relativePath): string
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '') {
            return '';
        }

        $normalized = ltrim($relativePath, '/');
        $candidates = [
            base_path('storage/media/' . $normalized),
            base_path('storage/media/invoices/' . $normalized),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0];
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

    private function canViewClients(Request $request): bool
    {
        $viewBit = PermissionBits::resolve('view_clients', self::VIEW_CLIENTS_BIT);
        $manageBit = PermissionBits::resolve('manage_clients', self::MANAGE_CLIENTS_BIT);
        $roleMask = $this->actorRoleMask($request);

        return (($roleMask & $viewBit) !== 0) || (($roleMask & $manageBit) !== 0);
    }

    private function canManageClients(Request $request): bool
    {
        $bit = PermissionBits::resolve('manage_clients', self::MANAGE_CLIENTS_BIT);
        return ($this->actorRoleMask($request) & $bit) !== 0;
    }

    private function actorRoleMask(Request $request): int
    {
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = $request->session()[$sessionKey] ?? [];

        return (int) ($adminUser['role_mask'] ?? 0);
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }

    private function isEmailAlreadyUsed(string $email, ?int $excludeId = null): bool
    {
        $pdo = app(Database::class)->connection();
        $crypto = app(ClientFieldEncryptionService::class);

        if ($crypto->isEmailBlindIndexColumnAvailable()) {
            $emailIndex = $crypto->emailBlindIndex($email);
            if ($emailIndex !== null) {
                $sql = 'SELECT id, email FROM clients WHERE email_blind_index = :email_blind_index';
                $params = [':email_blind_index' => $emailIndex];
                if ($excludeId !== null) {
                    $sql .= ' AND id <> :id';
                    $params[':id'] = $excludeId;
                }
                $sql .= ' LIMIT 10';

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($crypto->decryptClientRows(is_array($rows) ? $rows : []) as $row) {
                    if (strtolower(trim((string) ($row['email'] ?? ''))) === strtolower(trim($email))) {
                        return true;
                    }
                }
            }

            return false;
        }

        $sql = 'SELECT id, email FROM clients';
        $params = [];
        if ($excludeId !== null) {
            $sql .= ' WHERE id <> :id';
            $params[':id'] = $excludeId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($crypto->decryptClientRows(is_array($rows) ? $rows : []) as $row) {
            if (strtolower(trim((string) ($row['email'] ?? ''))) === strtolower(trim($email))) {
                return true;
            }
        }

        return false;
    }

    private function isDuplicateEmailConstraintViolation(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'duplicate') && str_contains($message, 'email')) {
            return true;
        }

        if (str_contains($message, 'integrity constraint violation') && str_contains($message, 'email')) {
            return true;
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function fetchClient(int $id): ?array
    {
        $pdo = app(Database::class)->connection();

        $selectColumns = [
            'id',
            'name',
            'email',
            'phone',
            'address',
            'created_at',
            'updated_at',
        ];

        $stmt = $pdo->prepare(
            'SELECT ' . implode(', ', $selectColumns) . '
             FROM clients
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return app(ClientFieldEncryptionService::class)->decryptClientRow($row);
    }

    /** @param array<string, mixed> $row */
    private function formatClientListItem(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $row */
    private function formatClientDetail(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * 
     * +----------------------------------------------------------+     
     * |         Contracts for a given client ID.                 |
     * +----------------------------------------------------------+
     * 
     */

    /** @param array<string, mixed> $row */
    private function formatPackageRow(array $row): array
    {
        $total = (int) ($row['total_sessions'] ?? 0);
        $consumed = (int) ($row['consumed_sessions'] ?? 0);
        $remaining = (int) ($row['remaining_sessions'] ?? 0);
        $sessionCountSnapshot = max(1, (int) ($row['package_session_count_snapshot'] ?? $total));
        $packagePriceSnapshot = isset($row['package_price_snapshot']) ? (float) $row['package_price_snapshot'] : 0.0;
        $servicePriceSnapshot = isset($row['service_price_snapshot']) ? (float) $row['service_price_snapshot'] : 0.0;
        $computedSavings = max(0.0, ($servicePriceSnapshot * $sessionCountSnapshot) - $packagePriceSnapshot);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'package_id' => (int) ($row['package_id'] ?? 0),
            'package_name' => trim((string) ($row['package_name_snapshot'] ?? $row['package_name'] ?? '')),
            'package_slug' => (string) ($row['package_slug_snapshot'] ?? $row['package_slug'] ?? ''),
            'service_name' => trim((string) ($row['service_name_snapshot'] ?? $row['service_name'] ?? '')),
            'service_slug' => (string) ($row['service_slug_snapshot'] ?? ''),
            'session_count' => $sessionCountSnapshot,
            'package_price' => $packagePriceSnapshot,
            'service_price' => $servicePriceSnapshot,
            'computed_savings' => $computedSavings,
            'total_sessions' => $total,
            'used_sessions' => max(0, $consumed),
            'remaining_sessions' => max(0, $remaining),
            'payment_status' => (string) ($row['payment_status'] ?? ''),
            'purchased_at' => (string) ($row['purchased_at'] ?? ''),
            'expires_at' => isset($row['expires_at']) ? (string) $row['expires_at'] : null,
        ];
    }

    /**
     * 
     * +----------------------------------------------------------+     
     * | Contract        |
     * +----------------------------------------------------------+
     * 
     */

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $contracts
     * @return array<int, array<string, mixed>>
     */
    private function formatProjectsWithInvoices(array $rows, int $clientId, array $contracts = [], string $pdfBasePath = '/clients/data'): array
    {
        $projects = [];

        foreach ($rows as $row) {
            $projectId = (int) ($row['project_id'] ?? 0);

            if (!isset($projects[$projectId])) {
                $projects[$projectId] = [
                    'id' => $projectId,
                    'name' => (string) ($row['project_name'] ?? ''),
                    'is_active' => (int) ($row['project_is_active'] ?? 0) === 1,
                    'status' => (string) ($row['project_status'] ?? ''),
                    'created_at' => (string) ($row['project_created_at'] ?? ''),
                    'contracts' => [],
                    'invoices' => [],
                ];
            }

            $invoiceId = (int) ($row['invoice_id'] ?? 0);

            if ($invoiceId > 0) {
                $pdfPath = trim((string) ($row['pdf_path'] ?? ''));
                $pdfAvailable = $pdfPath !== '';
                $pdfUrlBase = rtrim($pdfBasePath, '/');
                $pdfViewUrl = $pdfUrlBase . '/' . $clientId . '/invoices/' . $invoiceId . '/pdf';

                $projects[$projectId]['invoices'][] = [
                    'id' => $invoiceId,
                    'invoice_number' => (int) ($row['invoice_number'] ?? 0),
                    'status' => (string) ($row['invoice_status'] ?? 'created'),
                    'total_amount' => isset($row['total_amount'])
                        ? (float) $row['total_amount']
                        : null,
                    'currency_code' => (string) ($row['currency_code'] ?? 'EUR'),
                    'invoice_date' => $row['invoice_date'] ?? null,
                    'due_date' => $row['due_date'] ?? null,
                    'sent_at' => $row['sent_at'] ?? null,
                    'pdf_available' => $pdfAvailable,
                    'pdf_generated_at' => $row['pdf_generated_at'] ?? null,
                    'pdf_url' => $pdfViewUrl,
                    'pdf_download_url' => $pdfViewUrl . '?disposition=attachment',
                ];
            }
        }

        foreach ($contracts as $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectId = (int) ($row['project_id'] ?? 0);
            if ($projectId <= 0 || !isset($projects[$projectId])) {
                continue;
            }

            $projects[$projectId]['contracts'][] = [
                'id' => (int) ($row['id'] ?? 0),
                'start_date' => isset($row['start_date']) ? (string) $row['start_date'] : null,
                'end_date' => isset($row['end_date']) ? (string) $row['end_date'] : null,
            ];
        }

        return array_values($projects);
    }

    /** @return array<string, true> */
    private function invoiceColumnSet(): array
    {
        if (is_array($this->invoiceColumnSet)) {
            return $this->invoiceColumnSet;
        }

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->query('SHOW COLUMNS FROM invoices');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        $this->invoiceColumnSet = [];
        foreach ($rows as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $this->invoiceColumnSet[$field] = true;
            }
        }

        return $this->invoiceColumnSet;
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

    private function isTicketsTableAvailable(): bool
    {
        if ($this->ticketsTableAvailable !== null) {
            return $this->ticketsTableAvailable;
        }

        try {
            $pdo = app(Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1'
            );
            $statement->execute(['table_name' => 'tickets']);
            $this->ticketsTableAvailable = $statement->fetchColumn() !== false;
            return $this->ticketsTableAvailable;
        } catch (\Throwable) {
            $this->ticketsTableAvailable = false;
            return false;
        }
    }

    private function isTicketProtocolsTableAvailable(): bool
    {
        if ($this->ticketProtocolsTableAvailable !== null) {
            return $this->ticketProtocolsTableAvailable;
        }

        try {
            $pdo = app(Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1'
            );
            $statement->execute(['table_name' => 'ticket_protocols']);
            $this->ticketProtocolsTableAvailable = $statement->fetchColumn() !== false;
            return $this->ticketProtocolsTableAvailable;
        } catch (\Throwable) {
            $this->ticketProtocolsTableAvailable = false;
            return false;
        }
    }

    /** @return array<int, string> */
    private function ticketStatusOptions(): array
    {
        return ['new', 'open', 'in_progress', 'resolved', 'closed'];
    }

    /** @return array<int, string> */
    private function ticketPriorityOptions(): array
    {
        return ['low', 'medium', 'high', 'critical'];
    }

    /**
     * @param array<string, mixed> $ticketRow
     * @param array<string, mixed>|null $client
     * @return array<string, mixed>
     */
    private function formatTicketRow(array $ticketRow, ?array $client = null): array
    {
        return [
            'id' => (int) ($ticketRow['id'] ?? 0),
            'client_id' => isset($ticketRow['client_id']) && $ticketRow['client_id'] !== null
                ? (int) $ticketRow['client_id']
                : null,
            'client_name' => is_array($client) ? (string) ($client['name'] ?? '') : '',
            'client_email' => is_array($client) ? (string) ($client['email'] ?? '') : '',
            'ticket_type' => (string) ($ticketRow['ticket_type'] ?? ''),
            'category' => (string) ($ticketRow['category'] ?? ''),
            'priority' => $ticketRow['priority'] !== null ? (string) $ticketRow['priority'] : null,
            'subject' => (string) ($ticketRow['subject'] ?? ''),
            'message' => (string) ($ticketRow['message'] ?? ''),
            'source' => (string) ($ticketRow['source'] ?? ''),
            'status' => (string) ($ticketRow['status'] ?? ''),
            'assigned_user_id' => $ticketRow['assigned_user_id'] !== null ? (int) $ticketRow['assigned_user_id'] : null,
            'created_at' => (string) ($ticketRow['created_at'] ?? ''),
            'updated_at' => (string) ($ticketRow['updated_at'] ?? ''),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchTicketProtocols(int $ticketId): array
    {
        if ($ticketId <= 0 || !$this->isTicketProtocolsTableAvailable()) {
            return [];
        }

        $rows = db('ticket_protocols')
            ->where('ticket_id', $ticketId)
            ->select([
                'id',
                'ticket_id',
                'protocol_type',
                'message',
                'old_status',
                'new_status',
                'old_priority',
                'new_priority',
                'created_by_user_id',
                'created_at',
            ])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return array_map(
            static fn(array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'ticket_id' => (int) ($row['ticket_id'] ?? 0),
                'protocol_type' => (string) ($row['protocol_type'] ?? 'note'),
                'message' => (string) ($row['message'] ?? ''),
                'old_status' => $row['old_status'] !== null ? (string) $row['old_status'] : null,
                'new_status' => $row['new_status'] !== null ? (string) $row['new_status'] : null,
                'old_priority' => $row['old_priority'] !== null ? (string) $row['old_priority'] : null,
                'new_priority' => $row['new_priority'] !== null ? (string) $row['new_priority'] : null,
                'created_by_user_id' => $row['created_by_user_id'] !== null ? (int) $row['created_by_user_id'] : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
            ],
            is_array($rows) ? $rows : []
        );
    }

    private function createTicketProtocolEntry(
        int $ticketId,
        string $type,
        string $message,
        ?string $oldStatus,
        ?string $newStatus,
        ?string $oldPriority,
        ?string $newPriority,
        ?int $actorId
    ): void {
        if ($ticketId <= 0 || !$this->isTicketProtocolsTableAvailable()) {
            return;
        }

        db('ticket_protocols')->insert([
            'ticket_id' => $ticketId,
            'protocol_type' => $type,
            'message' => $message,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'old_priority' => $oldPriority,
            'new_priority' => $newPriority,
            'created_by_user_id' => $actorId,
        ]);
    }

    private function reserveNextInvoiceNumber(): int
    {
        $pdo = app(Database::class)->connection();
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

    private function actorId(Request $request): ?int
    {
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = $request->session()[$sessionKey] ?? [];

        if (!is_array($adminUser)) {
            return null;
        }

        $id = $adminUser['id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    /** @return array<int, array{description: string, quantity: float, unit_price: float}> */
    private function normalizeManualInvoiceItems(mixed $rawItems): array
    {
        if (!is_array($rawItems)) {
            return [];
        }

        $items = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $description = trim((string) ($item['description'] ?? ''));
            $quantity = isset($item['quantity']) && is_numeric($item['quantity']) ? (float) $item['quantity'] : 0.0;
            $unitPrice = isset($item['unit_price']) && is_numeric($item['unit_price']) ? (float) $item['unit_price'] : 0.0;

            if ($description === '' || $quantity <= 0.0) {
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

    /** @return array<string, mixed>|null */
    private function fetchProjectForClient(int $clientId, int $projectId): ?array
    {
        $row = db('projects')
            ->where('id', $projectId)
            ->where('client_id', $clientId)
            ->first();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function fetchContractForProject(int $contractId, int $projectId): ?array
    {
        $row = db('contracts')
            ->where('id', $contractId)
            ->where('project_id', $projectId)
            ->first();

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row */
    private function formatConsentRow(array $row): array
    {
        $bookingId = isset($row['booking_id']) ? (int) $row['booking_id'] : 0;
        $requestId = isset($row['client_request_id']) ? (int) $row['client_request_id'] : 0;
        $contextType = $bookingId > 0 ? 'booking' : 'request';
        $contextId = $bookingId > 0 ? $bookingId : $requestId;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'consent_key' => (string) ($row['consent_key'] ?? ''),
            'accepted' => ((int) ($row['accepted'] ?? 0)) === 1,
            'accepted_at' => (string) ($row['accepted_at'] ?? ''),
            'consent_version' => (string) ($row['consent_version'] ?? ''),
            'consent_text_snapshot' => (string) ($row['consent_text_snapshot'] ?? ''),
            'ip_address' => (string) ($row['ip_address'] ?? ''),
            'user_agent' => (string) ($row['user_agent'] ?? ''),
            'signature_hash' => (string) ($row['signature_hash'] ?? ''),
            'context_type' => $contextType,
            'context_id' => $contextId,
            'booking_scheduled_at' => isset($row['booking_scheduled_at']) ? (string) $row['booking_scheduled_at'] : null,
            'request_status' => isset($row['request_status']) ? (string) $row['request_status'] : null,
            'request_created_at' => isset($row['request_created_at']) ? (string) $row['request_created_at'] : null,
        ];
    }

    /**
     * 
     * +----------------------------------------------------------+     
     * | Fetch History events for a given client ID.        |
     * +----------------------------------------------------------+
     * 
     */

    /** @return array<int, array<string, mixed>> */
    private function fetchHistoryEvents(int $clientId): array
    {
        $events = [];

        $events = array_merge($events, $this->fetchRequestHistoryEvents($clientId));
        $events = array_merge($events, $this->fetchBookingCreatedEvents($clientId));
        $events = array_merge($events, $this->fetchBookingStatusHistoryEvents($clientId));
        $events = array_merge($events, $this->fetchEmailHistoryEvents($clientId));

        return $events;
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchRequestHistoryEvents(int $clientId): array
    {
        try {
            $rows = db('client_requests')
                ->where('client_id', $clientId)
                ->select(['id', 'booking_id', 'status', 'service_slug', 'created_at'])
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->get();

            if (!is_array($rows)) {
                return [];
            }

            $events = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $requestId = (int) ($row['id'] ?? 0);
                $bookingId = isset($row['booking_id']) && $row['booking_id'] !== null ? (int) $row['booking_id'] : 0;
                $status = strtolower(trim((string) ($row['status'] ?? 'new')));

                $events[] = [
                    'id' => 'request:' . $requestId,
                    'kind' => 'request',
                    'title' => 'Anfrage #' . $requestId,
                    'description' => 'Status: ' . ($status !== '' ? $status : 'new') . ' · Service: ' . (string) ($row['service_slug'] ?? '-'),
                    'happened_at' => (string) ($row['created_at'] ?? ''),
                    'booking_id' => $bookingId > 0 ? $bookingId : null,
                    'booking_url' => $bookingId > 0 ? '/admin/bookings/' . $bookingId : null,
                ];
            }

            return $events;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchBookingCreatedEvents(int $clientId): array
    {
        try {
            $rows = db('bookings')
                ->where('client_id', $clientId)
                ->select(['id', 'status', 'scheduled_at', 'created_at'])
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->get();

            if (!is_array($rows)) {
                return [];
            }

            $events = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $bookingId = (int) ($row['id'] ?? 0);
                if ($bookingId <= 0) {
                    continue;
                }

                $events[] = [
                    'id' => 'booking:' . $bookingId,
                    'kind' => 'booking',
                    'title' => 'Buchung #' . $bookingId . ' erstellt',
                    'description' => 'Status: ' . (string) ($row['status'] ?? 'pending') . ' · Termin: ' . (string) ($row['scheduled_at'] ?? '-'),
                    'happened_at' => (string) ($row['created_at'] ?? ''),
                    'booking_id' => $bookingId,
                    'booking_url' => '/admin/bookings/' . $bookingId,
                ];
            }

            return $events;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchBookingStatusHistoryEvents(int $clientId): array
    {
        try {
            $pdo = app(Database::class)->connection();
            $stmt = $pdo->prepare(
                'SELECT
                    a.id,
                    a.booking_id,
                    a.old_status,
                    a.new_status,
                    a.changed_at
                 FROM booking_status_audit_log a
                 INNER JOIN bookings b ON b.id = a.booking_id
                 WHERE b.client_id = :client_id
                 ORDER BY a.changed_at DESC, a.id DESC'
            );
            $stmt->execute([':client_id' => $clientId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (!is_array($rows)) {
                return [];
            }

            $events = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $bookingId = (int) ($row['booking_id'] ?? 0);
                if ($bookingId <= 0) {
                    continue;
                }

                $oldStatus = (string) ($row['old_status'] ?? '-');
                $newStatus = (string) ($row['new_status'] ?? '-');

                $events[] = [
                    'id' => 'booking-status:' . (int) ($row['id'] ?? 0),
                    'kind' => 'booking_status',
                    'title' => 'Buchungsstatus geändert',
                    'description' => 'Buchung #' . $bookingId . ': ' . $oldStatus . ' -> ' . $newStatus,
                    'happened_at' => (string) ($row['changed_at'] ?? ''),
                    'booking_id' => $bookingId,
                    'booking_url' => '/admin/bookings/' . $bookingId,
                ];
            }

            return $events;
        } catch (\Throwable) {
            return [];
        }
    }



    /**
     * 
     * +----------------------------------------------------------+     
     * | Fetch email history events for a given client ID.        |
     * +----------------------------------------------------------+
     * 
     */

    /** @return array<int, array<string, mixed>> */
    private function fetchEmailHistoryEvents(int $clientId): array
    {
        try {
            $privacy = app(EmailLogPrivacyService::class);

            $selectColumns = [
                'id',
                'trigger_event',
                'template_key',
                'recipient_email',
                'subject',
                'sent_at',
            ];
            if ($this->isEmailLogRecipientEncryptedColumnAvailable()) {
                $selectColumns[] = 'recipient_email_encrypted';
            }
            if ($this->isEmailLogSenderColumnAvailable()) {
                $selectColumns[] = 'sender_email';
            }
            if ($this->isEmailLogSenderEncryptedColumnAvailable()) {
                $selectColumns[] = 'sender_email_encrypted';
            }
            $query = db('email_logs')
                ->select($selectColumns)
                ->orderBy('sent_at', 'desc')
                ->orderBy('id', 'desc');

            if ($this->isEmailLogClientRefHashColumnAvailable()) {
                $clientRefHash = $privacy->clientRefHash($clientId);
                $query->where('client_ref_hash', $clientRefHash);
            } else {
                $query->where('client_id', $clientId);
            }

            $rows = $query->get();

            if (!is_array($rows)) {
                return [];
            }

            $events = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $eventId = (int) ($row['id'] ?? 0);
                $trigger = (string) ($row['trigger_event'] ?? 'mail.sent');
                $templateKey = trim((string) ($row['template_key'] ?? ''));

                $recipient = (string) ($row['recipient_email'] ?? '-');
                if ($this->isEmailLogRecipientEncryptedColumnAvailable()) {
                    $recipient = $privacy->decryptAddress($row['recipient_email_encrypted'] ?? null, $recipient);
                }

                $sender = (string) ($row['sender_email'] ?? '');
                if ($this->isEmailLogSenderEncryptedColumnAvailable()) {
                    $sender = $privacy->decryptAddress($row['sender_email_encrypted'] ?? null, $sender);
                }

                if ($sender === '') {
                    $sender = '-';
                }

                $subject = $privacy->decryptText($row['subject'] ?? null, (string) ($row['subject'] ?? '-'));

                $description = 'Empfänger: ' . $recipient
                    . ' · Sender: ' . $sender
                    . ' · Trigger: ' . $trigger;
                if ($templateKey !== '') {
                    $description .= ' · Template: ' . $templateKey;
                }

                $events[] = [
                    'id' => 'email:' . $eventId,
                    'kind' => 'email',
                    'title' => 'E-Mail versendet',
                    'description' => $subject,
                    'happened_at' => (string) ($row['sent_at'] ?? ''),
                    'booking_id' => null,
                    'booking_url' => null,
                    'details' => $description,
                ];
            }

            return $events;
        } catch (\Throwable) {
            return [];
        }
    }

    private function isEmailLogClientRefHashColumnAvailable(): bool
    {
        if ($this->emailLogClientRefHashColumnAvailable !== null) {
            return $this->emailLogClientRefHashColumnAvailable;
        }

        $this->emailLogClientRefHashColumnAvailable = app(EmailLogPrivacyService::class)->hasColumn('client_ref_hash');
        return $this->emailLogClientRefHashColumnAvailable;
    }

    private function isEmailLogRecipientEncryptedColumnAvailable(): bool
    {
        if ($this->emailLogRecipientEncryptedColumnAvailable !== null) {
            return $this->emailLogRecipientEncryptedColumnAvailable;
        }

        $this->emailLogRecipientEncryptedColumnAvailable = app(EmailLogPrivacyService::class)->hasColumn('recipient_email_encrypted');
        return $this->emailLogRecipientEncryptedColumnAvailable;
    }

    private function isEmailLogSenderColumnAvailable(): bool
    {
        if ($this->emailLogSenderColumnAvailable !== null) {
            return $this->emailLogSenderColumnAvailable;
        }

        $this->emailLogSenderColumnAvailable = app(EmailLogPrivacyService::class)->hasColumn('sender_email');
        return $this->emailLogSenderColumnAvailable;
    }

    private function isEmailLogSenderEncryptedColumnAvailable(): bool
    {
        if ($this->emailLogSenderEncryptedColumnAvailable !== null) {
            return $this->emailLogSenderEncryptedColumnAvailable;
        }

        $this->emailLogSenderEncryptedColumnAvailable = app(EmailLogPrivacyService::class)->hasColumn('sender_email_encrypted');
        return $this->emailLogSenderEncryptedColumnAvailable;
    }
}
