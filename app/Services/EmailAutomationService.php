<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class EmailAutomationService
{
    private ?bool $invoiceTableAvailable = null;

    /**
     * @var array<string, list<array{template_key: string, recipient: string, sender: string}>>
     */
    private const EVENT_TEMPLATE_MAP = [
        'request.submitted' => [
            ['template_key' => 'request_confirmation', 'recipient' => 'client', 'sender' => 'communication'],
            ['template_key' => 'admin_request_info', 'recipient' => 'support', 'sender' => 'support'],
        ],
        'request.accepted' => [
            ['template_key' => 'request_accepted', 'recipient' => 'client', 'sender' => 'communication'],
        ],
        'request.rejected' => [
            ['template_key' => 'request_rejected', 'recipient' => 'client', 'sender' => 'communication'],
        ],
        'booking.canceled' => [
            ['template_key' => 'booking_cancelled', 'recipient' => 'client', 'sender' => 'communication'],
        ],
        'booking.no_show' => [
            ['template_key' => 'booking_no_show', 'recipient' => 'client', 'sender' => 'communication'],
        ],
        'booking.payment_received' => [
            ['template_key' => 'booking_payment_received', 'recipient' => 'client', 'sender' => 'communication'],
        ],
        'booking.payment_reminder_1' => [
            ['template_key' => 'payment_reminder_1', 'recipient' => 'client', 'sender' => 'communication'],
        ],
        'booking.payment_reminder_2' => [
            ['template_key' => 'payment_reminder_2', 'recipient' => 'client', 'sender' => 'communication'],
        ],
        'invoice.created' => [
            ['template_key' => 'invoice_created', 'recipient' => 'client', 'sender' => 'communication'],
        ],
    ];

    /**
     * @param array{request_id?: int, booking_id?: int, client_id?: int, invoice_id?: int} $references
     * @return array{event: string, sent: int, skipped: int, results: list<array<string, mixed>>}
     */
    public function dispatch(string $event, array $references = []): array
    {
        $definitions = self::EVENT_TEMPLATE_MAP[$event] ?? [];
        if ($definitions === []) {
            return [
                'event' => $event,
                'sent' => 0,
                'skipped' => 0,
                'results' => [],
            ];
        }
        $results = [];
        $sent = 0;
        $skipped = 0;
        $automationEnabled = filter_var((string) config('mail.automation.enabled', false), FILTER_VALIDATE_BOOL) === true;

        try {
            $context = $this->buildContext($references);
        } catch (\Throwable $exception) {
            return [
                'event' => $event,
                'sent' => 0,
                'skipped' => count($definitions),
                'results' => [[
                    'status' => 'skipped',
                    'reason' => 'context_load_failed: ' . $exception->getMessage(),
                ]],
            ];
        }

        foreach ($definitions as $definition) {
            if (!$automationEnabled && !$this->shouldSendWhenAutomationDisabled($event, $definition['template_key'])) {
                $results[] = [
                    'template_key' => $definition['template_key'],
                    'status' => 'skipped',
                    'reason' => 'automation_disabled',
                ];
                $skipped++;
                continue;
            }

            $result = $this->sendTemplate(
                $event,
                $definition['template_key'],
                $definition['recipient'],
                $definition['sender'],
                $context
            );

            $results[] = $result;
            if (($result['status'] ?? 'skipped') === 'sent') {
                $sent++;
                continue;
            }

            $skipped++;
        }

        return [
            'event' => $event,
            'sent' => $sent,
            'skipped' => $skipped,
            'results' => $results,
        ];
    }

    /** @return array<string, mixed> */
    private function buildContext(array $references): array
    {
        $requestId = (int) ($references['request_id'] ?? 0);
        $bookingId = (int) ($references['booking_id'] ?? 0);
        $clientId = (int) ($references['client_id'] ?? 0);
        $invoiceId = (int) ($references['invoice_id'] ?? 0);

        $request = $requestId > 0 ? $this->loadRequestData($requestId) : [];
        if ($bookingId <= 0) {
            $bookingId = (int) ($request['booking_id'] ?? 0);
        }

        $booking = $bookingId > 0 ? $this->loadBookingData($bookingId) : [];
        if ($invoiceId <= 0) {
            $invoiceId = (int) ($booking['invoice_id'] ?? 0);
        }

        $invoice = $invoiceId > 0 ? $this->loadInvoiceData($invoiceId) : [];
        if ($clientId <= 0) {
            $clientId = (int) ($request['client_id'] ?? ($booking['client_id'] ?? ($invoice['client_id'] ?? 0)));
        }

        $client = $clientId > 0 ? $this->loadClientData($clientId) : [];

        return [
            'client' => $client,
            'request' => $request,
            'booking' => $booking,
            'invoice' => $invoice,
            'payment' => $this->paymentTemplateData($invoice),
            'system' => $this->systemTemplateData(),
        ];
    }

    /** @param array<string, mixed> $invoice
     *  @return array<string, string>
     */
    private function paymentTemplateData(array $invoice): array
    {
        $reference = trim($this->readSettingValue('bank_transfer_reference', ''));
        if ($reference === '') {
            $invoiceNumber = (string) ($invoice['invoice_number'] ?? '');
            if ($invoiceNumber !== '') {
                $reference = 'Rechnung #' . $invoiceNumber;
            }
        }

        $methods = [];

        if ($this->normalizeBool($this->readSettingValue('bank_transfer_enabled', '0'))) {
            $methods[] = [
                'type' => 'bank_transfer',
                'title' => 'Banküberweisung',
                'lines' => [
                    'Kontoinhaber: ' . $this->fallbackText($this->readSettingValue('bank_transfer_account_holder', ''), '-'),
                    'IBAN: ' . $this->fallbackText($this->readSettingValue('bank_transfer_iban', ''), '-'),
                    'BIC: ' . $this->fallbackText($this->readSettingValue('bank_transfer_bic', ''), '-'),
                    'Bank: ' . $this->fallbackText($this->readSettingValue('bank_transfer_bank_name', ''), '-'),
                    'Verwendungszweck: ' . $this->fallbackText($reference, '-'),
                ],
            ];
        }

        if ($this->normalizeBool($this->readSettingValue('paypal_enabled', '0'))) {
            $paypalEmail = trim($this->readSettingValue('paypal_email', ''));
            $methods[] = [
                'type' => 'paypal',
                'title' => 'PayPal',
                'lines' => [
                    'PayPal-Konto: ' . $this->fallbackText($paypalEmail, '-'),
                    'PayPal-Link: ' . $this->fallbackText($this->buildPaypalUrl($paypalEmail), '-'),
                    'Verwendungszweck: ' . $this->fallbackText($reference, '-'),
                ],
            ];
        }

        $summaryText = trim($this->buildPaymentSummaryText($methods));
        $summaryHtml = trim($this->buildPaymentSummaryHtml($methods));

        return [
            'summary_text' => $summaryText,
            'summary_html' => $summaryHtml,
        ];
    }

    /** @param list<array{type: string, title: string, lines: list<string>}> $methods */
    private function buildPaymentSummaryText(array $methods): string
    {
        if ($methods === []) {
            return 'Derzeit sind keine zusätzlichen Zahlungsinformationen hinterlegt.';
        }

        $parts = [];
        foreach ($methods as $method) {
            $title = trim((string) ($method['title'] ?? 'Zahlungsart'));
            $lines = is_array($method['lines'] ?? null) ? $method['lines'] : [];
            $parts[] = $title . ': ' . implode(' | ', array_map(static fn ($line) => trim((string) $line), $lines));
        }

        return implode("\n", $parts);
    }

    /** @param list<array{type: string, title: string, lines: list<string>}> $methods */
    private function buildPaymentSummaryHtml(array $methods): string
    {
        if ($methods === []) {
            return '<div style="margin:0 0 16px;padding:14px 16px;background:#fbf6f0;border:1px solid #eadfd3;border-radius:12px;color:#6b5848;font-size:14px;line-height:1.6;">Derzeit sind keine zusätzlichen Zahlungsinformationen hinterlegt.</div>';
        }

        $blocks = [];
        foreach ($methods as $method) {
            $title = htmlspecialchars(trim((string) ($method['title'] ?? 'Zahlungsart')), ENT_QUOTES, 'UTF-8');
            $lines = is_array($method['lines'] ?? null) ? $method['lines'] : [];
            $lineHtml = '';
            foreach ($lines as $line) {
                $lineText = trim((string) $line);
                if (preg_match('/^PayPal-Link:\s*(https?:\/\/\S+)$/i', $lineText, $matches) === 1) {
                    $url = trim((string) ($matches[1] ?? ''));
                    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                    $lineHtml .= '<div style="margin:8px 0 10px;">'
                        . '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer"'
                        . ' style="display:inline-block;min-width:220px;padding:14px 28px;border-radius:999px;border:1px solid #d7a400;background:linear-gradient(180deg,#ffd65a 0%,#ffc439 100%);color:#003087;font-weight:800;font-size:18px;line-height:1;text-align:center;text-decoration:none;box-shadow:0 2px 0 rgba(153,116,0,0.35),inset 0 1px 0 rgba(255,255,255,0.65);font-family:Arial,\'Helvetica Neue\',Helvetica,sans-serif;letter-spacing:0.2px;">'
                        . 'Pay<span style="color:#009cde;">Pal</span>'
                        . '</a>'
                        . '</div>';
                    continue;
                }

                $lineHtml .= '<div style="margin:0 0 4px;">' . htmlspecialchars($lineText, ENT_QUOTES, 'UTF-8') . '</div>';
            }

            $blocks[] = '<div style="margin:0 0 12px;padding:14px 16px;background:#fbf6f0;border:1px solid #eadfd3;border-radius:12px;color:#5d4a3b;font-size:14px;line-height:1.6;">'
                . '<div style="font-weight:700;color:#2f241c;margin:0 0 8px;">' . $title . '</div>'
                . $lineHtml
                . '</div>';
        }

        return implode('', $blocks);
    }

    private function buildPaypalUrl(string $paypalEmail): string
    {
        $email = trim($paypalEmail);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return '';
        }

        return 'https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=' . rawurlencode($email);
    }

    private function fallbackText(string $value, string $fallback): string
    {
        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : $fallback;
    }

    /** @return array<string, mixed> */
    private function loadRequestData(int $requestId): array
    {
        $row = db('client_requests')
            ->where('id', $requestId)
            ->select([
                'id',
                'client_id',
                'booking_id',
                'service_slug',
                'status',
                'desired_at',
                'message',
                'package_id',
                'created_at',
            ])
            ->first();

        if (!is_array($row)) {
            return [];
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'client_id' => (int) ($row['client_id'] ?? 0),
            'booking_id' => isset($row['booking_id']) && $row['booking_id'] !== null ? (int) $row['booking_id'] : 0,
            'service_slug' => $this->formatServiceSlugForEmail((string) ($row['service_slug'] ?? '')),
            'status' => (string) ($row['status'] ?? ''),
            'desired_at' => $this->formatDateTimeForTemplate((string) ($row['desired_at'] ?? '')),
            'contact_preference' => '',
            'notes' => (string) ($row['message'] ?? ''),
            'package_id' => isset($row['package_id']) && $row['package_id'] !== null ? (int) $row['package_id'] : 0,
            'created_at' => $this->formatDateTimeForTemplate((string) ($row['created_at'] ?? '')),
            'updated_at' => $this->formatDateTimeForTemplate((string) ($row['created_at'] ?? '')),
        ];
    }

    /** @return array<string, mixed> */
    private function loadBookingData(int $bookingId): array
    {
        $row = db('bookings')
            ->where('id', $bookingId)
            ->select([
                'id',
                'client_id',
                'service_id',
                'scheduled_at',
                'status',
                'payment_status',
                'notes',
                'cancellation_reason',
                'cancelled_at',
                'package_purchase_id',
                'is_package_booking',
                'package_session_no',
                'package_session_state',
                'created_at',
            ])
            ->first();

        if (!is_array($row)) {
            return [];
        }

        $scheduledAt = (string) ($row['scheduled_at'] ?? '');

        return [
            'id' => (int) ($row['id'] ?? 0),
            'client_id' => (int) ($row['client_id'] ?? 0),
            'invoice_id' => $this->loadLatestInvoiceIdForBooking((int) ($row['id'] ?? 0)),
            'service_id' => (int) ($row['service_id'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'payment_status' => (string) ($row['payment_status'] ?? ''),
            'scheduled_at' => $this->formatDateTimeForTemplate($scheduledAt),
            'started_at' => $this->formatDateTimeForTemplate($scheduledAt),
            'notes' => (string) ($row['notes'] ?? ''),
            'cancellation_reason' => (string) ($row['cancellation_reason'] ?? ''),
            'cancelled_at' => $this->formatDateTimeForTemplate((string) ($row['cancelled_at'] ?? '')),
            'package_purchase_id' => isset($row['package_purchase_id']) && $row['package_purchase_id'] !== null ? (int) $row['package_purchase_id'] : 0,
            'is_package_booking' => (int) ($row['is_package_booking'] ?? 0),
            'package_session_no' => isset($row['package_session_no']) && $row['package_session_no'] !== null ? (int) $row['package_session_no'] : 0,
            'package_session_state' => (string) ($row['package_session_state'] ?? 'none'),
            'created_at' => $this->formatDateTimeForTemplate((string) ($row['created_at'] ?? '')),
            'updated_at' => $this->formatDateTimeForTemplate((string) ($row['created_at'] ?? '')),
        ];
    }

    /** @return array<string, mixed> */
    private function loadInvoiceData(int $invoiceId): array
    {
        if (!$this->isInvoiceTableAvailable()) {
            return [];
        }

        $row = db('invoices')
            ->where('id', $invoiceId)
            ->first();

        if (!is_array($row)) {
            return [];
        }

        $dueDateRaw = trim((string) ($row['due_date'] ?? ''));
        $paymentNotice = 'Bitte beachten Sie: Der Termin gilt erst nach Zahlungseingang als verbindlich bestätigt.';
        if ($dueDateRaw === '') {
            $paymentNotice = 'Wichtiger Hinweis: Es wurde aufgrund der knappen Terminbuchung kein Fälligkeitsdatum gesetzt.
 Die Leistung wird nur erbracht, wenn der Betrag vor Antritt des Termins vollständig beglichen wurde.';
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'invoice_number' => (int) ($row['invoice_number'] ?? 0),
            'client_id' => (int) ($row['client_id'] ?? 0),
            'booking_id' => (int) ($row['booking_id'] ?? 0),
            'currency_code' => (string) ($row['currency_code'] ?? 'EUR'),
            'sub_total_amount' => isset($row['sub_total_amount']) ? (float) $row['sub_total_amount'] : 0.0,
            'discount_amount' => isset($row['discount_amount']) ? (float) $row['discount_amount'] : 0.0,
            'total_amount' => isset($row['total_amount']) ? (float) $row['total_amount'] : 0.0,
            'status' => (string) ($row['status'] ?? 'created'),
            'invoice_date' => $this->formatDateForTemplate((string) ($row['invoice_date'] ?? '')),
            'due_date' => $this->formatDateForTemplate($dueDateRaw),
            'acceptance_message' => 'Ihre Anfrage wurde angenommen. Mit dieser Mail erhalten Sie die Rechnung zu Ihrem Termin.',
            'payment_notice' => $paymentNotice,
            'tax_exemption_notice' => 'Umsatzsteuerfreie Heilbehandlung gemäß § 4 Nr. 14 UStG.',
            'items_html' => $this->loadInvoiceItemsHtml($invoiceId),
            'sent_at' => $this->formatDateTimeForTemplate((string) ($row['sent_at'] ?? '')),
            'created_at' => $this->formatDateTimeForTemplate((string) ($row['created_at'] ?? '')),
            'updated_at' => $this->formatDateTimeForTemplate((string) ($row['updated_at'] ?? '')),
        ];
    }

    private function loadInvoiceItemsHtml(int $invoiceId): string
    {
        if ($invoiceId <= 0 || !$this->isInvoiceTableAvailable()) {
            return '';
        }

        $rows = db('invoice_items')
            ->where('invoice_id', $invoiceId)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        if (!is_array($rows) || $rows === []) {
            return '';
        }

        $html = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $description = htmlspecialchars((string) ($row['description'] ?? ''), ENT_QUOTES, 'UTF-8');
            $quantity = $this->formatNumber((float) ($row['quantity'] ?? 1.0));
            $lineTotal = $this->formatMoneyForTemplate((float) ($row['line_total'] ?? 0.0));

            $html .= '<tr>'
                . '<td style="padding:10px 0;border-bottom:1px solid #e7ddd1;color:#3d3127;">' . $description . '</td>'
                . '<td style="padding:10px 0;border-bottom:1px solid #e7ddd1;color:#6a5645;text-align:center;">' . $quantity . '</td>'
                . '<td style="padding:10px 0;border-bottom:1px solid #e7ddd1;color:#3d3127;text-align:right;white-space:nowrap;">' . $lineTotal . ' EUR</td>'
                . '</tr>';
        }

        return $html;
    }

    private function loadLatestInvoiceIdForBooking(int $bookingId): int
    {
        if ($bookingId <= 0 || !$this->isInvoiceTableAvailable()) {
            return 0;
        }

        $row = db('invoices')
            ->where('booking_id', $bookingId)
            ->select(['id'])
            ->orderBy('id', 'desc')
            ->first();

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }

    private function isInvoiceTableAvailable(): bool
    {
        if ($this->invoiceTableAvailable !== null) {
            return $this->invoiceTableAvailable;
        }

        try {
            $pdo = app(\App\Core\Database\Database::class)->connection();
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

    /** @return array<string, mixed> */
    private function loadClientData(int $clientId): array
    {
        $row = db('clients')
            ->where('id', $clientId)
            ->select([
                'id',
                'first_name',
                'last_name',
                'email',
                'phone',
                'date_of_birth',
                'medical_notes',
                'created_at',
            ])
            ->first();

        if (!is_array($row)) {
            return [];
        }

        $row = app(ClientFieldEncryptionService::class)->decryptClientRow($row);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'email' => strtolower(trim((string) ($row['email'] ?? ''))),
            'phone' => (string) ($row['phone'] ?? ''),
            'date_of_birth' => (string) ($row['date_of_birth'] ?? ''),
            'medical_notes' => (string) ($row['medical_notes'] ?? ''),
            'created_at' => $this->formatDateTimeForTemplate((string) ($row['created_at'] ?? '')),
            'updated_at' => $this->formatDateTimeForTemplate((string) ($row['created_at'] ?? '')),
        ];
    }

    /** @return array<string, mixed> */
    private function systemTemplateData(): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));

        return [
            'site_name' => (string) config('app.name', 'Henz Software'),
            'contact_email' => $this->contactMailAddress(),
            'support_email' => $this->supportMailAddress(),
            'profile_image_url' => $this->profileImageUrl(),
            'generated_at' => $now->format('d.m.Y H:i'),
        ];
    }

    private function formatDateTimeForTemplate(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $dateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $trimmed)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $trimmed)
            ?: new DateTimeImmutable($trimmed);

        return $dateTime->format('d.m.Y H:i');
    }

    private function formatDateForTemplate(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $dateTime = DateTimeImmutable::createFromFormat('Y-m-d', $trimmed)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $trimmed)
            ?: new DateTimeImmutable($trimmed);

        return $dateTime->format('d.m.Y');
    }

    private function formatMoneyForTemplate(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    private function formatNumber(float $value): string
    {
        if ((float) (int) $value === $value) {
            return (string) (int) $value;
        }

        return number_format($value, 2, ',', '.');
    }

    private function shouldSendWhenAutomationDisabled(string $event, string $templateKey): bool
    {
        return $event === 'request.submitted' && $templateKey === 'admin_request_info';
    }

    /** @return array<string, mixed> */
    private function sendTemplate(string $event, string $templateKey, string $recipientType, string $senderType, array $context): array
    {
        try {
            $template = db('email_templates')
                ->where('template_key', $templateKey)
                ->select(['template_key', 'display_name', 'subject_template', 'html_template', 'is_active'])
                ->first();

            if (!is_array($template)) {
                return [
                    'template_key' => $templateKey,
                    'status' => 'skipped',
                    'reason' => 'template_missing',
                ];
            }

            if (!$this->normalizeBool($template['is_active'] ?? false)) {
                return [
                    'template_key' => $templateKey,
                    'status' => 'skipped',
                    'reason' => 'template_inactive',
                ];
            }

            $recipient = $this->resolveRecipientAddress($recipientType, $context);
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                return [
                    'template_key' => $templateKey,
                    'status' => 'skipped',
                    'reason' => 'recipient_missing',
                ];
            }

            $subject = $this->renderTemplate((string) ($template['subject_template'] ?? ''), $context, false);
            $htmlBody = $this->renderTemplate((string) ($template['html_template'] ?? ''), $context, true);
            $deliveryHtml = $this->absolutizeEmailAssetUrls($htmlBody);
            $mailResult = $this->sendHtmlMail($recipient, $subject, $deliveryHtml, $senderType);

            if ($mailResult['success']) {
                $this->logSentEmail($event, $templateKey, $context, $recipient, $subject, $deliveryHtml, $senderType);
            }

            return [
                'template_key' => $templateKey,
                'display_name' => (string) ($template['display_name'] ?? $templateKey),
                'recipient' => $recipient,
                'status' => $mailResult['success'] ? 'sent' : 'skipped',
                'transport' => (string) ($mailResult['transport'] ?? ''),
                'reason' => $mailResult['success'] ? '' : (string) ($mailResult['error'] ?? 'send_failed'),
                'fallback_path' => $mailResult['fallback_path'] ?? null,
            ];
        } catch (\Throwable $exception) {
            return [
                'template_key' => $templateKey,
                'status' => 'skipped',
                'reason' => $exception->getMessage(),
            ];
        }
    }

    private function resolveRecipientAddress(string $recipientType, array $context): string
    {
        if ($recipientType === 'support') {
            return $this->supportMailAddress();
        }

        return strtolower(trim((string) ($context['client']['email'] ?? '')));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function renderTemplate(string $template, array $values, bool $escapeForHtml): string
    {
        $flat = $this->flattenTemplateValues($values);
        $replacements = [];

        foreach ($flat as $key => $value) {
            $isTrustedHtml = $escapeForHtml && str_ends_with($key, '_html');
            $safeValue = $isTrustedHtml
                ? (string) $value
                : ($escapeForHtml
                    ? htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')
                    : (string) $value);
            $replacements['{{' . $key . '}}'] = $safeValue;
            $replacements['{{ ' . $key . ' }}'] = $safeValue;
        }

        return strtr($template, $replacements);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private function flattenTemplateValues(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $keyName = trim((string) $key);
            if ($keyName === '') {
                continue;
            }

            $fullKey = $prefix === '' ? $keyName : $prefix . '.' . $keyName;
            if (is_array($value)) {
                $flat += $this->flattenTemplateValues($value, $fullKey);
                continue;
            }

            if ($value instanceof DateTimeInterface) {
                $flat[$fullKey] = $value->format(DATE_ATOM);
                continue;
            }

            $flat[$fullKey] = (string) $value;
        }

        return $flat;
    }

    private function formatServiceSlugForEmail(string $serviceSlug): string
    {
        $slug = trim($serviceSlug);
        if ($slug === '') {
            return '';
        }

        try {
            $service = db('services')
                ->where('slug', $slug)
                ->select(['name'])
                ->first();

            $serviceName = trim((string) ($service['name'] ?? ''));
            if ($serviceName !== '') {
                return $serviceName;
            }
        } catch (\Throwable) {
            // Use slug fallback if lookup is unavailable.
        }

        $normalized = str_replace(['-', '_'], ' ', strtolower($slug));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        return $normalized !== '' ? ucwords($normalized) : $slug;
    }

    private function profileImageUrl(): string
    {
        return '/storage/media/persistent/email_profile.png';
    }

    private function absolutizeEmailAssetUrls(string $htmlBody): string
    {
        $relative = '/storage/media/persistent/email_profile.png';
        $absolute = $this->publicBaseUrlForEmail() . $relative;

        return str_replace(
            ['src="' . $relative . '"', "src='" . $relative . "'"],
            ['src="' . $absolute . '"', "src='" . $absolute . "'"],
            $htmlBody
        );
    }

    private function publicBaseUrlForEmail(): string
    {
        $env = strtolower(trim((string) config('app.env', 'production')));
        $localEnvs = ['local', 'development', 'dev', 'testing', 'test'];

        if (in_array($env, $localEnvs, true)) {
            return 'http://henz-software.local';
        }

        return 'https://henz-software.de';
    }

    private function supportMailAddress(): string
    {
        $supportSetting = db('settings')
            ->where('`key`', 'support_email')
            ->select(['value'])
            ->first();

        $supportValue = trim((string) ($supportSetting['value'] ?? ''));
        if ($supportValue !== '' && filter_var($supportValue, FILTER_VALIDATE_EMAIL)) {
            return $supportValue;
        }

        $configValue = trim((string) config('mail.senders.support.address', 'support@henz-software.de'));
        if ($configValue !== '' && filter_var($configValue, FILTER_VALIDATE_EMAIL)) {
            return $configValue;
        }

        return 'support@henz-software.de';
    }

    private function contactMailAddress(): string
    {
        $contactSetting = db('settings')
            ->where('`key`', 'contact_email')
            ->select(['value'])
            ->first();

        $contactValue = trim((string) ($contactSetting['value'] ?? ''));
        if ($contactValue !== '' && filter_var($contactValue, FILTER_VALIDATE_EMAIL)) {
            return $contactValue;
        }

        $configValue = trim((string) config('mail.senders.communication.address', 'info@henz-software.de'));
        if ($configValue !== '' && filter_var($configValue, FILTER_VALIDATE_EMAIL)) {
            return $configValue;
        }

        return 'info@henz-software.de';
    }

    private function readSettingValue(string $key, string $fallback = ''): string
    {
        $row = db('settings')
            ->where('`key`', $key)
            ->select(['value'])
            ->first();

        $value = trim((string) ($row['value'] ?? ''));
        return $value !== '' ? $value : $fallback;
    }

    /** @return array{success: bool, error: string, transport: string, fallback_path?: string} */
    private function sendHtmlMail(string $to, string $subject, string $htmlBody, string $senderType): array
    {
        $transport = strtolower(trim((string) config('mail.transport', 'smtp')));
        $senderConfigKey = 'mail.senders.' . ($senderType !== '' ? $senderType : 'support');
        $fromAddress = trim((string) config($senderConfigKey . '.address', 'support@henz-software.de'));
        if ($fromAddress === '') {
            $fromAddress = 'support@henz-software.de';
        }

        $fromName = trim((string) config($senderConfigKey . '.name', 'Henz Software'));
        $subjectHeader = $subject;
        if (function_exists('mb_encode_mimeheader')) {
            $subjectHeader = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
        }

        $mailPayload = $this->buildMailPayload($htmlBody);

        $headers = array_merge($mailPayload['headers'], [
            sprintf('From: %s <%s>', $fromName !== '' ? $fromName : 'Support', $fromAddress),
            'X-Mailer: PHP/' . PHP_VERSION,
        ]);

        if ($transport === 'smtp') {
            $smtpResult = $this->sendHtmlMailViaSmtp($to, $subjectHeader, $mailPayload['body'], $headers, $fromAddress);
            if ($smtpResult['success']) {
                return $smtpResult;
            }

            return $this->storeMailFallback(
                $to,
                $subject,
                $mailPayload['body'],
                implode("\r\n", $headers),
                'SMTP send failed: ' . (string) ($smtpResult['error'] ?? 'unknown SMTP error')
            );
        }

        if (!function_exists('mail')) {
            return $this->storeMailFallback($to, $subject, $mailPayload['body'], implode("\r\n", $headers), 'mail() function is unavailable');
        }

        $sent = @mail($to, $subjectHeader, $mailPayload['body'], implode("\r\n", $headers));
        if ($sent) {
            return ['success' => true, 'error' => '', 'transport' => 'mail'];
        }

        $lastError = error_get_last();
        $message = is_array($lastError) ? (string) ($lastError['message'] ?? 'mail() returned false') : 'mail() returned false';

        return $this->storeMailFallback($to, $subject, $mailPayload['body'], implode("\r\n", $headers), $message);
    }

    /** @return array{success: bool, error: string, transport: string} */
    private function sendHtmlMailViaSmtp(string $to, string $subjectHeader, string $mailBody, array $headers, string $fromAddress): array
    {
        $host = trim((string) config('mail.smtp.host', ''));
        $port = (int) config('mail.smtp.port', 587);
        $encryption = strtolower(trim((string) config('mail.smtp.encryption', 'tls')));
        $username = trim((string) config('mail.smtp.username', ''));
        $password = (string) config('mail.smtp.password', '');
        $timeout = (int) config('mail.smtp.timeout_seconds', 10);

        if ($host === '') {
            return ['success' => false, 'error' => 'MAIL_HOST is empty', 'transport' => 'smtp'];
        }

        if ($port <= 0) {
            return ['success' => false, 'error' => 'MAIL_PORT is invalid', 'transport' => 'smtp'];
        }

        $remoteHost = ($encryption === 'ssl' ? 'ssl://' : '') . $host;
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($remoteHost, $port, $errno, $errstr, max(1, $timeout));
        if ($socket === false) {
            return ['success' => false, 'error' => sprintf('SMTP connect failed (%d): %s', $errno, $errstr), 'transport' => 'smtp'];
        }

        stream_set_timeout($socket, max(1, $timeout));

        $greeting = $this->smtpReadResponse($socket, [220]);
        if (!$greeting['success']) {
            fclose($socket);
            return ['success' => false, 'error' => $greeting['error'], 'transport' => 'smtp'];
        }

        $clientHost = (string) parse_url((string) config('app.url', 'http://localhost'), PHP_URL_HOST);
        if ($clientHost === '') {
            $clientHost = 'localhost';
        }

        $ehlo = $this->smtpSendCommand($socket, 'EHLO ' . $clientHost, [250]);
        if (!$ehlo['success']) {
            $ehlo = $this->smtpSendCommand($socket, 'HELO ' . $clientHost, [250]);
            if (!$ehlo['success']) {
                fclose($socket);
                return ['success' => false, 'error' => $ehlo['error'], 'transport' => 'smtp'];
            }
        }

        if ($encryption === 'tls') {
            $startTls = $this->smtpSendCommand($socket, 'STARTTLS', [220]);
            if (!$startTls['success']) {
                fclose($socket);
                return ['success' => false, 'error' => $startTls['error'], 'transport' => 'smtp'];
            }

            $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                fclose($socket);
                return ['success' => false, 'error' => 'STARTTLS negotiation failed', 'transport' => 'smtp'];
            }

            $ehloAfterTls = $this->smtpSendCommand($socket, 'EHLO ' . $clientHost, [250]);
            if (!$ehloAfterTls['success']) {
                fclose($socket);
                return ['success' => false, 'error' => $ehloAfterTls['error'], 'transport' => 'smtp'];
            }
        }

        if ($username !== '' || $password !== '') {
            $authStart = $this->smtpSendCommand($socket, 'AUTH LOGIN', [334]);
            if (!$authStart['success']) {
                fclose($socket);
                return ['success' => false, 'error' => $authStart['error'], 'transport' => 'smtp'];
            }

            $authUser = $this->smtpSendCommand($socket, base64_encode($username), [334]);
            if (!$authUser['success']) {
                fclose($socket);
                return ['success' => false, 'error' => $authUser['error'], 'transport' => 'smtp'];
            }

            $authPass = $this->smtpSendCommand($socket, base64_encode($password), [235]);
            if (!$authPass['success']) {
                fclose($socket);
                return ['success' => false, 'error' => $authPass['error'], 'transport' => 'smtp'];
            }
        }

        $mailFrom = $this->smtpSendCommand($socket, 'MAIL FROM:<' . $fromAddress . '>', [250]);
        if (!$mailFrom['success']) {
            fclose($socket);
            return ['success' => false, 'error' => $mailFrom['error'], 'transport' => 'smtp'];
        }

        $rcptTo = $this->smtpSendCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        if (!$rcptTo['success']) {
            fclose($socket);
            return ['success' => false, 'error' => $rcptTo['error'], 'transport' => 'smtp'];
        }

        $data = $this->smtpSendCommand($socket, 'DATA', [354]);
        if (!$data['success']) {
            fclose($socket);
            return ['success' => false, 'error' => $data['error'], 'transport' => 'smtp'];
        }

        $toHeader = sprintf('To: <%s>', $to);
        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $mailBody);
        $normalizedBody = str_replace("\n", "\r\n", $normalizedBody);
        $messageData = implode("\r\n", [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            $toHeader,
            'Subject: ' . $subjectHeader,
            ...$headers,
            '',
            $normalizedBody,
        ]);

        $messageData = preg_replace('/(^|\r\n)\./', '$1..', $messageData) ?? $messageData;

        fwrite($socket, $messageData . "\r\n.\r\n");
        $dataSent = $this->smtpReadResponse($socket, [250]);
        if (!$dataSent['success']) {
            fclose($socket);
            return ['success' => false, 'error' => $dataSent['error'], 'transport' => 'smtp'];
        }

        $this->smtpSendCommand($socket, 'QUIT', [221]);
        fclose($socket);

        return ['success' => true, 'error' => '', 'transport' => 'smtp'];
    }

    /** @return array{headers: list<string>, body: string} */
    private function buildMailPayload(string $htmlBody): array
    {
        $normalizedHtml = str_replace(["\r\n", "\r"], "\n", $htmlBody);
        $normalizedHtml = str_replace("\n", "\r\n", $normalizedHtml);

        $embedInlineImages = filter_var((string) config('mail.embed_inline_images', false), FILTER_VALIDATE_BOOL);
        if ($embedInlineImages !== true) {
            return [
                'headers' => [
                    'MIME-Version: 1.0',
                    'Content-Type: text/html; charset=UTF-8',
                    'Content-Transfer-Encoding: 8bit',
                ],
                'body' => $normalizedHtml,
            ];
        }

        $profilePath = base_path('storage/media/persistent/email_profile.png');
        if (!is_file($profilePath)) {
            return [
                'headers' => [
                    'MIME-Version: 1.0',
                    'Content-Type: text/html; charset=UTF-8',
                    'Content-Transfer-Encoding: 8bit',
                ],
                'body' => $normalizedHtml,
            ];
        }

        $inlineCid = 'email_profile_img';
        $relativeSrc = '/storage/media/persistent/email_profile.png';
        $absoluteSrc = $this->publicBaseUrlForEmail() . $relativeSrc;
        $htmlWithCid = str_replace(
            ['src="' . $relativeSrc . '"', "src='" . $relativeSrc . "'", 'src="' . $absoluteSrc . '"', "src='" . $absoluteSrc . "'"],
            ['src="cid:' . $inlineCid . '"', "src='cid:" . $inlineCid . "'", 'src="cid:' . $inlineCid . '"', "src='cid:" . $inlineCid . "'"],
            $normalizedHtml
        );

        if ($htmlWithCid === $normalizedHtml) {
            return [
                'headers' => [
                    'MIME-Version: 1.0',
                    'Content-Type: text/html; charset=UTF-8',
                    'Content-Transfer-Encoding: 8bit',
                ],
                'body' => $normalizedHtml,
            ];
        }

        $binary = file_get_contents($profilePath);
        if (!is_string($binary) || $binary === '') {
            return [
                'headers' => [
                    'MIME-Version: 1.0',
                    'Content-Type: text/html; charset=UTF-8',
                    'Content-Transfer-Encoding: 8bit',
                ],
                'body' => $normalizedHtml,
            ];
        }

        $boundary = 'gb-rel-' . bin2hex(random_bytes(12));
        $body = '';
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlWithCid . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: image/png; name=\"email_profile.png\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= 'Content-ID: <' . $inlineCid . ">\r\n";
        $body .= "Content-Disposition: inline; filename=\"email_profile.png\"\r\n\r\n";
        $body .= chunk_split(base64_encode($binary), 76, "\r\n");
        $body .= '--' . $boundary . "--\r\n";

        return [
            'headers' => [
                'MIME-Version: 1.0',
                'Content-Type: multipart/related; boundary="' . $boundary . '"; type="text/html"',
            ],
            'body' => $body,
        ];
    }

    /** @param list<int> $expectedCodes @return array{success: bool, error: string, response: string, code: int} */
    private function smtpSendCommand(mixed $socket, string $command, array $expectedCodes): array
    {
        fwrite($socket, $command . "\r\n");
        return $this->smtpReadResponse($socket, $expectedCodes, $command);
    }

    /** @param list<int> $expectedCodes @return array{success: bool, error: string, response: string, code: int} */
    private function smtpReadResponse(mixed $socket, array $expectedCodes, string $command = ''): array
    {
        $response = '';
        $code = 0;

        for ($i = 0; $i < 30; $i++) {
            $line = fgets($socket, 515);
            if ($line === false) {
                return [
                    'success' => false,
                    'error' => 'SMTP read failed after command: ' . $command,
                    'response' => trim($response),
                    'code' => $code,
                ];
            }

            $response .= $line;
            if (preg_match('/^(\d{3})([\s-])/', $line, $matches) === 1) {
                $code = (int) $matches[1];
                if (($matches[2] ?? '-') === ' ') {
                    break;
                }
            }
        }

        if ($code === 0 || !in_array($code, $expectedCodes, true)) {
            return [
                'success' => false,
                'error' => sprintf('SMTP unexpected response %d after "%s": %s', $code, $command, trim($response)),
                'response' => trim($response),
                'code' => $code,
            ];
        }

        return [
            'success' => true,
            'error' => '',
            'response' => trim($response),
            'code' => $code,
        ];
    }

    /** @return array{success: bool, error: string, transport: string, fallback_path?: string} */
    private function storeMailFallback(string $to, string $subject, string $htmlBody, string $headers, string $reason): array
    {
        $dir = base_path('storage/logs/mail-fallback');

        try {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                return ['success' => false, 'error' => 'Konnte Mail-Fallback-Verzeichnis nicht erstellen.', 'transport' => 'file'];
            }

            $random = bin2hex(random_bytes(4));
            $filename = sprintf('mail_%s_%s.eml', date('Ymd_His'), $random);
            $path = $dir . DIRECTORY_SEPARATOR . $filename;
            $eml = implode("\r\n", [
                'To: ' . $to,
                'Subject: ' . $subject,
                $headers,
                'X-Fallback-Reason: ' . $reason,
                '',
                $htmlBody,
            ]);

            $written = file_put_contents($path, $eml, LOCK_EX);
            if ($written === false) {
                return ['success' => false, 'error' => 'Konnte Fallback-Datei nicht schreiben.', 'transport' => 'file'];
            }

            return [
                'success' => true,
                'error' => '',
                'transport' => 'file',
                'fallback_path' => 'storage/logs/mail-fallback/' . $filename,
            ];
        } catch (\Throwable $exception) {
            return ['success' => false, 'error' => 'Fallback fehlgeschlagen: ' . $exception->getMessage(), 'transport' => 'file'];
        }
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        $text = strtolower(trim((string) $value));
        return in_array($text, ['1', 'true', 'yes', 'on'], true);
    }

    /** @param array<string, mixed> $context */
    private function logSentEmail(
        string $triggerEvent,
        string $templateKey,
        array $context,
        string $recipient,
        string $subject,
        string $bodyHtml,
        string $senderType
    ): void {
        try {
            $privacy = app(EmailLogPrivacyService::class);
            $clientId = isset($context['client']['id']) ? (int) $context['client']['id'] : 0;
            $senderAddress = $senderType === 'communication'
                ? $this->contactMailAddress()
                : $this->supportMailAddress();

            $payload = [
                'trigger_event' => $triggerEvent,
                'template_key' => $templateKey,
                'client_id' => $clientId > 0 ? $clientId : null,
                'recipient_email' => $privacy->maskAddress($recipient),
                'subject' => (string) ($privacy->encryptText($subject) ?? ''),
                'body_html' => (string) ($privacy->encryptText($bodyHtml) ?? ''),
                'sent_at' => date('Y-m-d H:i:s'),
            ];

            if ($privacy->hasColumn('client_ref_hash')) {
                $payload['client_ref_hash'] = $privacy->clientRefHash($clientId);
                $payload['client_id'] = null;
            }

            if ($privacy->hasColumn('recipient_email_encrypted')) {
                $payload['recipient_email_encrypted'] = $privacy->encryptAddress($recipient);
            }

            if ($privacy->hasColumn('sender_email')) {
                $payload['sender_email'] = $privacy->maskAddress($senderAddress);
            }

            if ($privacy->hasColumn('sender_email_encrypted')) {
                $payload['sender_email_encrypted'] = $privacy->encryptAddress($senderAddress);
            }

            db('email_logs')->insert($payload);
        } catch (\Throwable) {
            // Logging must never break the functional flow.
        }
    }
}