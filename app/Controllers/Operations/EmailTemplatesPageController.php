<?php

declare(strict_types=1);

namespace App\Controllers\Operations;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfTokenManager;

final class EmailTemplatesPageController
{
    /** manage_settings (1024) | manage_admin_settings (2048) */
    private const ACCESS_BITS = 3072;

    public function index(Request $request): Response
    {
        $adminUser = $this->adminUser($request);
        $roleMask = (int) ($adminUser['role_mask'] ?? 0);

        if (($roleMask & self::ACCESS_BITS) === 0) {
            return $this->renderError(
                403,
                'Zugriff verweigert',
                'Du hast keine Berechtigung, die E-Mail-Vorlagen aufzurufen.'
            );
        }

        return $this->render('admin-email-templates-page.php', [
            'pageTitle' => 'E-Mail-Vorlagen - Henz Software',
            'adminUser' => $adminUser,
            'logoutAction' => '/logout',
            'csrfToken' => app(CsrfTokenManager::class)->token(),
            'templates' => $this->loadTemplates(),
            'placeholderGroups' => $this->placeholderGroups(),
        ]);
    }

    public function update(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            admin_flash('error', 'Vorlage konnte nicht gespeichert werden: ungueltige ID.');
            return Response::redirect('/email-templates');
        }

        $subject = trim((string) $request->input('subject_template', ''));
        $html = trim((string) $request->input('html_template', ''));
        $isActive = $this->normalizeBool($request->input('is_active', '0'));

        if ($subject === '' || $html === '') {
            admin_flash('error', 'Betreff und HTML-Inhalt sind Pflichtfelder.');
            return Response::redirect('/email-templates');
        }

        $updated = db('email_templates')
            ->where('id', $id)
            ->update([
                'subject_template' => $subject,
                'html_template' => $html,
                'is_active' => $isActive ? 1 : 0,
            ]);

        if ($updated <= 0) {
            admin_flash('error', 'Vorlage wurde nicht gespeichert (nicht gefunden oder unverändert).');
        } else {
            admin_flash('success', 'Vorlage wurde gespeichert.');
        }

        return Response::redirect('/email-templates');
    }

    public function sendTest(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            admin_flash('error', 'Testmail konnte nicht gesendet werden: ungueltige ID.');
            return Response::redirect('/email-templates');
        }

        $row = db('email_templates')
            ->where('id', $id)
            ->select(['id', 'template_key', 'display_name', 'subject_template', 'html_template'])
            ->first();

        if (!is_array($row)) {
            admin_flash('error', 'Testmail konnte nicht gesendet werden: Vorlage nicht gefunden.');
            return Response::redirect('/email-templates');
        }

        $subjectTemplate = trim((string) $request->input('subject_template', (string) ($row['subject_template'] ?? '')));
        $htmlTemplate = trim((string) $request->input('html_template', (string) ($row['html_template'] ?? '')));

        if ($subjectTemplate === '' || $htmlTemplate === '') {
            admin_flash('error', 'Testmail konnte nicht gesendet werden: Betreff oder HTML-Inhalt ist leer.');
            return Response::redirect('/admin/email-templates');
        }

        $dummyData = $this->dummyTemplateData();
        $subject = $this->renderTemplate($subjectTemplate, $dummyData, false);
        $htmlBody = $this->renderTemplate($htmlTemplate, $dummyData, true);
        $htmlBodyForDelivery = $this->absolutizeEmailAssetUrls($htmlBody);

        $recipient = $this->supportMailAddress();
        $sent = $this->sendHtmlMail($recipient, $subject, $htmlBodyForDelivery);

        if ($sent['success']) {
            if (($sent['transport'] ?? 'mail') === 'file') {
                admin_flash(
                    'warning',
                    sprintf(
                        'SMTP/mail nicht verfuegbar. Testmail für "%s" wurde als Datei gespeichert: %s',
                        (string) ($row['display_name'] ?? 'Vorlage'),
                        (string) ($sent['fallback_path'] ?? 'storage/logs/mail-fallback')
                    )
                );
            } else {
                admin_flash(
                    'success',
                    sprintf('Testmail für "%s" wurde an %s gesendet.', (string) ($row['display_name'] ?? 'Vorlage'), $recipient)
                );
            }
        } else {
            admin_flash(
                'error',
                sprintf('Testmail konnte nicht gesendet werden: %s', (string) ($sent['error'] ?? 'Unbekannter Fehler'))
            );
        }

        return Response::redirect('/email-templates');
    }

    public function preview(Request $request): Response
    {
        $adminUser = $this->adminUser($request);
        $roleMask = (int) ($adminUser['role_mask'] ?? 0);

        if (($roleMask & self::ACCESS_BITS) === 0) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Keine Berechtigung für die Vorschau.',
            ], 403);
        }

        $submittedToken = trim((string) $request->input('_csrf', ''));
        if (!app(CsrfTokenManager::class)->isValid($submittedToken)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Ungueltiges Sicherheitstoken. Seite neu laden und erneut versuchen.',
            ], 403);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Ungueltige Vorlagen-ID.',
            ], 400);
        }

        $row = db('email_templates')
            ->where('id', $id)
            ->select(['id', 'display_name', 'subject_template', 'html_template'])
            ->first();

        if (!is_array($row)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Vorlage wurde nicht gefunden.',
            ], 404);
        }

        $subjectTemplate = trim((string) $request->input('subject_template', (string) ($row['subject_template'] ?? '')));
        $htmlTemplate = trim((string) $request->input('html_template', (string) ($row['html_template'] ?? '')));

        if ($subjectTemplate === '' || $htmlTemplate === '') {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Betreff und HTML-Inhalt duerfen nicht leer sein.',
            ], 422);
        }

        $dummyData = $this->dummyTemplateData();
        $renderedSubject = $this->renderTemplate($subjectTemplate, $dummyData, false);
        $renderedHtml = $this->renderTemplate($htmlTemplate, $dummyData, true);
        $unknownPlaceholders = $this->findUnknownPlaceholders([$subjectTemplate, $htmlTemplate], $dummyData);

        return $this->jsonResponse([
            'success' => true,
            'template' => [
                'id' => (int) ($row['id'] ?? 0),
                'display_name' => (string) ($row['display_name'] ?? 'Vorlage'),
            ],
            'subject' => $renderedSubject,
            'html' => $renderedHtml,
            'warnings' => [
                'unknown_placeholders' => $unknownPlaceholders,
            ],
        ], 200);
    }

    /** @return array<string, mixed>|null */
    private function guardWrite(Request $request): ?Response
    {
        $adminUser = $this->adminUser($request);
        $roleMask = (int) ($adminUser['role_mask'] ?? 0);

        if (($roleMask & self::ACCESS_BITS) === 0) {
            return $this->renderError(
                403,
                'Zugriff verweigert',
                'Du hast keine Berechtigung, E-Mail-Vorlagen zu bearbeiten.'
            );
        }

        $submittedToken = trim((string) $request->input('_csrf', ''));
        if (!app(CsrfTokenManager::class)->isValid($submittedToken)) {
            return $this->renderError(
                403,
                'Ungueltige Anfrage',
                'Das Sicherheitstoken ist ungueltig. Bitte lade die Seite neu und versuche es erneut.'
            );
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function loadTemplates(): array
    {
        $rows = db('email_templates')
            ->select(['id', 'template_key', 'display_name', 'subject_template', 'html_template', 'is_active', 'updated_at'])
            ->orderBy('id', 'asc')
            ->get();

        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, list<string>> */
    private function placeholderGroups(): array
    {
        return [
            'client' => [
                '{{client.id}}',
                '{{client.name}}',
                '{{client.email}}',
                '{{client.phone}}',
                '{{client.address}}',
                '{{client.created_at}}',
                '{{client.updated_at}}',
            ],
            'appointment' => [
                '{{appointment.id}}',
                '{{appointment.client_id}}',
                '{{appointment.service_slug}}',
                '{{appointment.status}}',
                '{{appointment.desired_at}}',
                '{{appointment.contact_preference}}',
                '{{appointment.notes}}',
                '{{appointment.created_at}}',
                '{{appointment.updated_at}}',
            ],
            'contract / projects' => [
                '{{contract.id}}',
                '{{contract.client_id}}',
                '{{contract.name}}',
                '{{contract.notes}}',
                '{{contract.created_at}}',
                '{{contract.updated_at}}',
                '{{project.id}}',
                '{{project.client_id}}',
                '{{project.name}}',
                '{{project.description}}',
                '{{project.status}}',
                '{{project.progress}}',
                '{{project.due_date}}',
                '{{project.created_at}}',
                '{{project.updated_at}}',
            ],
            'invoice' => [
                '{{invoice.id}}',
                '{{invoice.invoice_number}}',
                '{{invoice.client_id}}',
                '{{invoice.project_id}}',
                '{{invoice.contract_id}}',
                '{{invoice.currency_code}}',
                '{{invoice.sub_total_amount}}',
                '{{invoice.discount_amount}}',
                '{{invoice.total_amount}}',
                '{{invoice.status}}',
                '{{invoice.invoice_date}}',
                '{{invoice.due_date}}',
                '{{invoice.acceptance_message}}',
                '{{invoice.payment_notice}}',
                '{{invoice.tax_exemption_notice}}',
                '{{invoice.items_html}}',
                '{{invoice.sent_at}}',
                '{{invoice.created_at}}',
                '{{invoice.updated_at}}',
            ],
            'payment' => [
                '{{payment.summary_html}}',
                '{{payment.summary_text}}',
            ],
            'system' => [
                '{{system.site_name}}',
                '{{system.contact_email}}',
                '{{system.support_email}}',
                '{{system.profile_image_url}}',
                '{{system.generated_at}}',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function dummyTemplateData(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
        $appointment = $now->modify('+10 days')->setTime(10, 0);
        $rawServiceSlug = 'einzelbegleitung-60';
        $parsedServiceSlug = $this->formatServiceSlugForEmail($rawServiceSlug);

        return [
            'client' => [
                'id' => 501,
                'first_name' => 'Testina',
                'last_name' => 'Muster',
                'email' => 'testina.muster@example.test',
                'phone' => '+49 151 23456789',
                'date_of_birth' => '1992-07-19',
                'medical_notes' => 'Dummy-Notiz fÜr Testmail',
                'created_at' => $now->format('d.m.Y H:i'),
                'updated_at' => $now->format('d.m.Y H:i'),
            ],
            'request' => [
                'id' => 1201,
                'client_id' => 501,
                'service_slug' => $parsedServiceSlug,
                'status' => 'new',
                'desired_at' => $appointment->format('d.m.Y H:i'),
                'contact_preference' => 'email',
                'notes' => 'Dies ist ein Dummy-Datensatz fÜr den Testversand.',
                'package_id' => 0,
                'created_at' => $now->format('d.m.Y H:i'),
                'updated_at' => $now->format('d.m.Y H:i'),
            ],
            'booking' => [
                'id' => 3401,
                'client_id' => 501,
                'service_id' => 2,
                'status' => 'pending',
                'payment_status' => 'pending',
                'scheduled_at' => $appointment->format('d.m.Y H:i'),
                'started_at' => $appointment->format('d.m.Y H:i'),
                'notes' => 'Dummy-Buchung fÜr Testmail',
                'cancellation_reason' => '',
                'cancelled_at' => '',
                'package_purchase_id' => 0,
                'is_package_booking' => 0,
                'package_session_no' => 0,
                'package_session_state' => 'none',
                'created_at' => $now->format('d.m.Y H:i'),
                'updated_at' => $now->format('d.m.Y H:i'),
            ],
            'invoice' => [
                'id' => 7701,
                'invoice_number' => 20260001,
                'client_id' => 501,
                'booking_id' => 3401,
                'currency_code' => 'EUR',
                'sub_total_amount' => '260.00',
                'discount_amount' => '0.00',
                'total_amount' => '260.00',
                'status' => 'created',
                'invoice_date' => $now->format('d.m.Y'),
                'due_date' => $now->modify('+7 days')->format('d.m.Y'),
                'acceptance_message' => 'deine Anfrage wurde angenommen. Deinen Termin reserviere ich für dich, sobald der Rechnungsbetrag eingegangen ist.',
                'payment_notice' => 'Bitte überweise den offenen Betrag bis zum Fälligkeitsdatum. Erst nach Zahlungseingang wird der Termin verbindlich bestätigt.',
                'tax_exemption_notice' => 'Umsatzsteuerfreie Heilbehandlung gemäß § 4 Nr. 14 UStG.',
                'items_html' => '<tr><td style="padding:10px 0;border-bottom:1px solid #e7ddd1;color:#3d3127;">Paket: 3er-Begleitung (Einzelbegleitung (60 Minuten))</td><td style="padding:10px 0;border-bottom:1px solid #e7ddd1;text-align:center;color:#6a5645;">1</td><td style="padding:10px 0;border-bottom:1px solid #e7ddd1;text-align:right;color:#3d3127;">260,00 EUR</td></tr>',
                'sent_at' => '',
                'created_at' => $now->format('d.m.Y H:i'),
                'updated_at' => $now->format('d.m.Y H:i'),
            ],
            'payment' => $this->paymentTemplateData(20260001),
            'system' => [
                'site_name' => (string) config('app.name', 'Henz Software'),
                'contact_email' => $this->contactMailAddress(),
                'support_email' => $this->supportMailAddress(),
                'profile_image_url' => $this->profileImageUrl(),
                'generated_at' => $now->format('d.m.Y H:i'),
            ],
        ];
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
            // Continue with slug fallback if service lookup is unavailable.
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

            if ($value instanceof \DateTimeInterface) {
                $flat[$fullKey] = $value->format(DATE_ATOM);
                continue;
            }

            $flat[$fullKey] = (string) $value;
        }

        return $flat;
    }

    /**
     * @param list<string> $templates
     * @param array<string, mixed> $values
     * @return list<string>
     */
    private function findUnknownPlaceholders(array $templates, array $values): array
    {
        $known = array_keys($this->flattenTemplateValues($values));
        $knownMap = [];
        foreach ($known as $key) {
            $normalizedKey = $this->normalizePlaceholderKey($key);
            if ($normalizedKey !== '') {
                $knownMap[$normalizedKey] = true;
            }
        }

        $unknownMap = [];
        foreach ($templates as $template) {
            foreach ($this->extractPlaceholderKeys($template) as $placeholderKey) {
                if (!isset($knownMap[$placeholderKey])) {
                    $unknownMap['{{' . $placeholderKey . '}}'] = true;
                }
            }
        }

        $unknown = array_keys($unknownMap);
        sort($unknown);

        return $unknown;
    }

    /**
     * @return list<string>
     */
    private function extractPlaceholderKeys(string $template): array
    {
        if ($template === '') {
            return [];
        }

        $matchCount = preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $template, $matches);
        if ($matchCount === false || $matchCount === 0) {
            return [];
        }

        $keys = $matches[1] ?? [];
        if (!is_array($keys)) {
            return [];
        }

        $result = [];
        foreach ($keys as $key) {
            $normalized = $this->normalizePlaceholderKey((string) $key);
            if ($normalized !== '') {
                $result[$normalized] = true;
            }
        }

        return array_keys($result);
    }

    private function normalizePlaceholderKey(string $key): string
    {
        $trimmed = trim($key);
        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($trimmed, '{{') && str_ends_with($trimmed, '}}')) {
            $trimmed = trim(substr($trimmed, 2, -2));
        }

        $trimmed = preg_replace('/\s+/', '', $trimmed) ?? '';
        if ($trimmed === '') {
            return '';
        }

        return strtolower($trimmed);
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

    /** @return array<string, string> */
    private function paymentTemplateData(int $invoiceNumber = 0): array
    {
        $reference = trim($this->readSettingValue('bank_transfer_reference', ''));
        if ($reference === '' && $invoiceNumber > 0) {
            $reference = 'Rechnung #' . $invoiceNumber;
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
    private function sendHtmlMail(string $to, string $subject, string $htmlBody): array
    {
        $transport = strtolower(trim((string) config('mail.transport', 'smtp')));
        $fromAddress = trim((string) config('mail.senders.support.address', 'support@henz-software.de'));
        if ($fromAddress === '') {
            $fromAddress = 'support@henz-software.de';
        }

        $fromName = trim((string) config('mail.senders.support.name', 'Henz Software Support'));
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
            $smtpResult = $this->sendHtmlMailViaSmtp($to, $subjectHeader, $mailPayload['body'], $headers, $fromAddress, $fromName);
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
            return $this->storeMailFallback($to, $subject, $htmlBody, implode("\r\n", $headers), 'mail() function is unavailable');
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
    private function sendHtmlMailViaSmtp(string $to, string $subjectHeader, string $mailBody, array $headers, string $fromAddress, string $fromName): array
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
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Fallback fehlgeschlagen: ' . $e->getMessage(), 'transport' => 'file'];
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

    /** @param array<string, mixed> $payload */
    private function jsonResponse(array $payload, int $status): Response
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{"success":false,"message":"JSON encoding failed"}';
            $status = 500;
        }

        return new Response($json, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /** @return array<string, mixed> */
    private function adminUser(Request $request): array
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');

        return is_array($session[$sessionKey] ?? null) ? $session[$sessionKey] : [];
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
