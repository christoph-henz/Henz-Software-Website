<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\BaseApiController;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\ClientFieldEncryptionService;
use App\Services\EmailLogPrivacyService;
use App\Services\InvoicePdfService;
use App\Core\Support\PermissionBits;
use DateTimeImmutable;

final class ClientAdminController extends BaseApiController
{
    private const VIEW_CLIENTS_BIT = 8;
    private const MANAGE_CLIENTS_BIT = 16;
    private ?bool $invoicePdfColumnAvailable = null;
    private ?bool $clientTimezoneColumnAvailable = null;
    private ?bool $emailLogClientRefHashColumnAvailable = null;
    private ?bool $emailLogRecipientEncryptedColumnAvailable = null;
    private ?bool $emailLogSenderColumnAvailable = null;
    private ?bool $emailLogSenderEncryptedColumnAvailable = null;

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

        $sort = strtolower(trim((string) $request->query('sort', 'last_name')));
        $direction = strtolower(trim((string) $request->query('direction', 'asc')));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';

        $sortMap = [
            'first_name',
            'last_name',
            'date_of_birth',
            'email',
            'created_at',
        ];
        if (!in_array($sort, $sortMap, true)) {
            $sort = 'last_name';
        }

        $search = trim((string) $request->query('q', ''));

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->query('SELECT id, first_name, last_name, date_of_birth, email, created_at FROM clients');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $rows = app(ClientFieldEncryptionService::class)->decryptClientRows(is_array($rows) ? $rows : []);

        if ($search !== '') {
            $needle = strtolower($search);
            $rows = array_values(array_filter($rows, static function (array $row) use ($needle): bool {
                $name = strtolower(trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))));
                $email = strtolower(trim((string) ($row['email'] ?? '')));

                return str_contains($name, $needle) || str_contains($email, $needle);
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
                fn (array $row): array => $this->formatClientListItem($row),
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

        $firstName = trim((string) ($payload['first_name'] ?? $payload['firstname'] ?? ''));
        if ($firstName === '') {
            $errors['first_name'][] = 'required';
        }

        $lastName = trim((string) ($payload['last_name'] ?? $payload['lastname'] ?? ''));
        if ($lastName === '') {
            $errors['last_name'][] = 'required';
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ($email === '') {
            $errors['email'][] = 'required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'invalid_email';
        } elseif ($this->isEmailAlreadyUsed($email)) {
            $errors['email'][] = 'already_exists';
        }

        $dobRaw = trim((string) ($payload['date_of_birth'] ?? $payload['dob'] ?? ''));
        $dateOfBirth = null;
        if ($dobRaw !== '') {
            $dateOfBirth = $this->normalizeDate($dobRaw);
            if ($dateOfBirth === null) {
                $errors['date_of_birth'][] = 'invalid_date';
            }
        }

        $timezone = null;
        if ($this->isClientTimezoneColumnAvailable()) {
            $timezoneRaw = trim((string) ($payload['timezone'] ?? $payload['time_zone'] ?? ''));
            if ($timezoneRaw !== '') {
                $timezone = $this->normalizeTimezone($timezoneRaw);
                if ($timezone === null) {
                    $errors['timezone'][] = 'invalid_timezone';
                }
            }
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        $phone = trim((string) ($payload['phone'] ?? ''));
        $notes = trim((string) ($payload['notes'] ?? ''));
        $now = date('Y-m-d H:i:s');

        $columns = [
            'first_name',
            'last_name',
            'date_of_birth',
            'email',
            'phone',
            'medical_notes',
            'created_at',
            'updated_at',
        ];
        $placeholders = [
            ':first_name',
            ':last_name',
            ':date_of_birth',
            ':email',
            ':phone',
            ':medical_notes',
            ':created_at',
            ':updated_at',
        ];
        $bindings = [
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':date_of_birth' => $dateOfBirth,
            ':email' => $email,
            ':phone' => $phone !== '' ? $phone : null,
            ':medical_notes' => $notes !== '' ? $notes : null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ];

        if ($this->isClientTimezoneColumnAvailable()) {
            $columns[] = 'timezone';
            $placeholders[] = ':timezone';
            $bindings[':timezone'] = $timezone;
        }

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
            $stmt->execute($bindings);

            $newId = (int) $pdo->lastInsertId();
            if ($newId <= 0) {
                return $this->fail('Fehler', 500);
            }

            $created = $this->fetchClient($newId);
            if ($created === null) {
                return $this->fail('Fehler', 500);
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

            return $this->fail('Fehler', 500);
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

        if (array_key_exists('first_name', $payload) || array_key_exists('firstname', $payload)) {
            $firstName = trim((string) ($payload['first_name'] ?? $payload['firstname'] ?? ''));
            if ($firstName === '') {
                $errors['first_name'][] = 'required';
            } else {
                $fields[] = 'first_name = :first_name';
                $bindings[':first_name'] = $firstName;
            }
        }

        if (array_key_exists('last_name', $payload) || array_key_exists('lastname', $payload)) {
            $lastName = trim((string) ($payload['last_name'] ?? $payload['lastname'] ?? ''));
            if ($lastName === '') {
                $errors['last_name'][] = 'required';
            } else {
                $fields[] = 'last_name = :last_name';
                $bindings[':last_name'] = $lastName;
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

        if (array_key_exists('notes', $payload)) {
            $notes = trim((string) ($payload['notes'] ?? ''));
            $fields[] = 'medical_notes = :medical_notes';
            $bindings[':medical_notes'] = $notes !== '' ? $notes : null;
        }

        if (array_key_exists('timezone', $payload) || array_key_exists('time_zone', $payload)) {
            if ($this->isClientTimezoneColumnAvailable()) {
                $timezone = trim((string) ($payload['timezone'] ?? $payload['time_zone'] ?? ''));
                if ($timezone === '') {
                    $fields[] = 'timezone = :timezone';
                    $bindings[':timezone'] = null;
                } else {
                    $normalizedTimezone = $this->normalizeTimezone($timezone);
                    if ($normalizedTimezone === null) {
                        $errors['timezone'][] = 'invalid_timezone';
                    } else {
                        $fields[] = 'timezone = :timezone';
                        $bindings[':timezone'] = $normalizedTimezone;
                    }
                }
            }
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

    public function packages(Request $request): Response
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
                pp.id,
                pp.package_id,
                pp.total_sessions,
                pp.reserved_sessions,
                pp.consumed_sessions,
                pp.remaining_sessions,
                pp.status,
                pp.payment_status,
                pp.purchased_at,
                pp.expires_at,
                pp.notes,
                pp.package_name_snapshot,
                pp.package_slug_snapshot,
                pp.package_price_snapshot,
                pp.service_name_snapshot,
                pp.service_slug_snapshot,
                pp.service_price_snapshot,
                pp.package_session_count_snapshot,
                sp.name AS package_name,
                sp.slug AS package_slug,
                s.name AS service_name
             FROM package_purchases pp
             LEFT JOIN service_packages sp ON sp.id = pp.package_id
             LEFT JOIN services s ON s.id = pp.service_id
             WHERE pp.client_id = :client_id
               AND pp.status = :status
             ORDER BY pp.purchased_at DESC, pp.id DESC'
        );
        $stmt->execute([
            ':client_id' => $id,
            ':status' => 'active',
        ]);

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $this->ok([
            'packages' => array_map(
                fn (array $row): array => $this->formatPackageRow($row),
                is_array($rows) ? $rows : []
            ),
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
                b.id AS booking_id,
                b.scheduled_at AS booking_scheduled_at,
                b.status AS booking_status,
                b.payment_status AS booking_payment_status,
                b.created_at AS booking_created_at,
                inv.id AS invoice_id,
                inv.invoice_number,
                inv.status AS invoice_status,
                inv.total_amount,
                inv.currency_code,
                inv.invoice_date,
                inv.due_date,
                     inv.sent_at,
                     ' . $pdfSelect . '
             FROM bookings b
             LEFT JOIN invoices inv
                ON inv.id = (
                    SELECT i2.id
                    FROM invoices i2
                    WHERE i2.booking_id = b.id
                    ORDER BY i2.id DESC
                    LIMIT 1
                )
             WHERE b.client_id = :client_id
             ORDER BY b.scheduled_at ASC, b.id ASC'
        );
        $stmt->execute([
            ':client_id' => $id,
        ]);

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $this->ok([
            'bookings' => array_map(
                fn (array $row): array => $this->formatInvoiceBookingRow($row, $id),
                is_array($rows) ? $rows : []
            ),
        ]);
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

    public function consents(Request $request): Response
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
                c.id,
                c.client_request_id,
                c.booking_id,
                c.consent_key,
                c.accepted,
                c.accepted_at,
                c.consent_version,
                c.consent_text_snapshot,
                c.ip_address,
                c.user_agent,
                c.signature_hash,
                b.scheduled_at AS booking_scheduled_at,
                cr.status AS request_status,
                cr.created_at AS request_created_at
             FROM consents c
             LEFT JOIN bookings b ON b.id = c.booking_id
             LEFT JOIN client_requests cr ON cr.id = c.client_request_id
             WHERE (b.client_id = :client_id_booking OR cr.client_id = :client_id_request)
             ORDER BY c.accepted_at DESC, c.id DESC'
        );
        $stmt->execute([
            ':client_id_booking' => $id,
            ':client_id_request' => $id,
        ]);

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $this->ok([
            'consents' => array_map(
                fn (array $row): array => $this->formatConsentRow($row),
                is_array($rows) ? $rows : []
            ),
        ]);
    }

    public function invoicePdf(Request $request): Response
    {
        if (!$this->canViewClients($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        if (!$this->isInvoicePdfColumnAvailable()) {
            return $this->fail('Invoice PDF not found', 404, [
                'invoice' => ['pdf_not_available'],
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

        $invoice = db('invoices')
            ->where('id', $invoiceId)
            ->where('client_id', $clientId)
            ->select(['id', 'invoice_number', 'pdf_path', 'pdf_mime_type'])
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

                db('invoices')
                    ->where('id', $invoiceId)
                    ->update([
                        'pdf_path' => $relativePath,
                        'pdf_mime_type' => (string) ($pdfMeta['mime_type'] ?? 'application/pdf'),
                        'pdf_file_size' => (int) ($pdfMeta['file_size'] ?? 0),
                        'pdf_sha256' => (string) ($pdfMeta['sha256'] ?? ''),
                        'pdf_generated_at' => (string) ($pdfMeta['generated_at'] ?? date('Y-m-d H:i:s')),
                    ]);

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
        if ($mimeType === '') {
            $mimeType = 'application/pdf';
        }

        return new Response((string) $body, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
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

    private function isClientTimezoneColumnAvailable(): bool
    {
        if ($this->clientTimezoneColumnAvailable !== null) {
            return $this->clientTimezoneColumnAvailable;
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
                'table_name' => 'clients',
                'column_name' => 'timezone',
            ]);

            $this->clientTimezoneColumnAvailable = $statement->fetchColumn() !== false;
            return $this->clientTimezoneColumnAvailable;
        } catch (\Throwable) {
            $this->clientTimezoneColumnAvailable = false;
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

    private function normalizeTimezone(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (in_array($value, timezone_identifiers_list(), true)) {
            return $value;
        }

        $normalizedInput = strtolower(str_replace(' ', '_', $value));

        $aliases = [
            'berlin' => 'Europe/Berlin',
            'de' => 'Europe/Berlin',
            'germany' => 'Europe/Berlin',
            'cet' => 'Europe/Berlin',
            'cest' => 'Europe/Berlin',
        ];

        if (isset($aliases[$normalizedInput])) {
            return $aliases[$normalizedInput];
        }

        $candidate = 'Europe/' . ucfirst($normalizedInput);
        if (in_array($candidate, timezone_identifiers_list(), true)) {
            return $candidate;
        }

        static $lookup = null;
        if (!is_array($lookup)) {
            $lookup = [];
            foreach (timezone_identifiers_list() as $identifier) {
                $lookup[strtolower($identifier)] = $identifier;
            }
        }

        return $lookup[$normalizedInput] ?? null;
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
            'first_name',
            'last_name',
            'date_of_birth',
            'email',
            'phone',
            'medical_notes',
            'created_at',
            'updated_at',
        ];
        if ($this->isClientTimezoneColumnAvailable()) {
            $selectColumns[] = 'timezone';
        }

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
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'date_of_birth' => (string) ($row['date_of_birth'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $row */
    private function formatClientDetail(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'date_of_birth' => (string) ($row['date_of_birth'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'timezone' => isset($row['timezone']) ? (string) $row['timezone'] : '',
            'notes' => (string) ($row['medical_notes'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

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

    /** @param array<string, mixed> $row */
    private function formatInvoiceBookingRow(array $row, int $clientId): array
    {
        $invoiceId = isset($row['invoice_id']) ? (int) $row['invoice_id'] : 0;
        $totalAmount = isset($row['total_amount']) ? (float) $row['total_amount'] : null;
        $pdfPath = trim((string) ($row['pdf_path'] ?? ''));
        $pdfAvailable = $invoiceId > 0 && $pdfPath !== '';

        return [
            'booking_id' => (int) ($row['booking_id'] ?? 0),
            'booking_scheduled_at' => (string) ($row['booking_scheduled_at'] ?? ''),
            'booking_status' => (string) ($row['booking_status'] ?? 'pending'),
            'booking_payment_status' => (string) ($row['booking_payment_status'] ?? 'pending'),
            'booking_created_at' => (string) ($row['booking_created_at'] ?? ''),
            'invoice' => $invoiceId > 0 ? [
                'id' => $invoiceId,
                'invoice_number' => isset($row['invoice_number']) ? (int) $row['invoice_number'] : 0,
                'status' => (string) ($row['invoice_status'] ?? 'created'),
                'total_amount' => $totalAmount,
                'currency_code' => (string) ($row['currency_code'] ?? 'EUR'),
                'invoice_date' => isset($row['invoice_date']) ? (string) $row['invoice_date'] : null,
                'due_date' => isset($row['due_date']) ? (string) $row['due_date'] : null,
                'sent_at' => isset($row['sent_at']) ? (string) $row['sent_at'] : null,
                'pdf_available' => $pdfAvailable,
                'pdf_generated_at' => isset($row['pdf_generated_at']) ? (string) $row['pdf_generated_at'] : null,
                'pdf_url' => $pdfAvailable
                    ? '/admin/clients/data/' . $clientId . '/invoices/' . $invoiceId . '/pdf'
                    : null,
            ] : null,
        ];
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
