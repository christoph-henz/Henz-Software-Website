<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Database;
use DateTimeImmutable;
use DateTimeZone;
use Dompdf\Dompdf;
use Dompdf\Options;

final class ContractPdfService
{
    private const STORAGE_PATH = 'storage/media/contracts/generated';

    /**
     * @param array<string, mixed> $builderData
     * @return array{relative_path: string, mime_type: string, file_size: int, sha256: string, generated_at: string, original_filename: string, storage_path: string}
     */
    public function generateAndStore(int $contractId, string $title, string $contractText, array $builderData = []): array
    {
        if ($contractId <= 0) {
            throw new \RuntimeException('invalid_contract_id');
        }

        $pdfContent = $this->renderPdf($this->renderHtml($contractId, $title, $contractText, $builderData));

        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
        $relativeDirectory = $now->format('Y/m');
        $absoluteDirectory = base_path(self::STORAGE_PATH . '/' . $relativeDirectory);
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
            throw new \RuntimeException('storage_not_writable');
        }

        $safeTitle = $this->slugify($title !== '' ? $title : ('vertrag-' . $contractId));
        if (preg_match('/-' . preg_quote((string) $contractId, '/') . '$/', $safeTitle) === 1) {
            $fileName = $safeTitle . '.pdf';
        } else {
            $fileName = $safeTitle . '-' . $contractId . '.pdf';
        }
        $absoluteFilePath = $absoluteDirectory . '/' . $fileName;

        if (file_put_contents($absoluteFilePath, $pdfContent) === false) {
            throw new \RuntimeException('pdf_write_failed');
        }

        $relativePath = 'contracts/generated/' . $relativeDirectory . '/' . $fileName;

        return [
            'relative_path' => $relativePath,
            'mime_type' => 'application/pdf',
            'file_size' => strlen($pdfContent),
            'sha256' => hash('sha256', $pdfContent),
            'generated_at' => $now->format('Y-m-d H:i:s'),
            'original_filename' => $fileName,
            'storage_path' => 'storage/media/' . $relativePath,
        ];
    }

    private function renderPdf(string $html): string
    {
        $previousErrorReporting = error_reporting();
        error_reporting($previousErrorReporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        try {
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            return $dompdf->output();
        } finally {
            error_reporting($previousErrorReporting);
        }
    }

    /** @param array<string, mixed> $builderData */
    private function renderHtml(int $contractId, string $title, string $contractText, array $builderData): string
    {
        $contractorName = trim((string) ($builderData['contractor_name'] ?? ''));
        $contractorOwner = trim((string) ($builderData['contractor_owner'] ?? ''));
        $contractorAddress = trim((string) ($builderData['contractor_address'] ?? ''));

        $startDate = trim((string) ($builderData['start_date'] ?? ''));
        $endDate = trim((string) ($builderData['end_date'] ?? ''));

        $startLabel = $this->formatDate($startDate);
        $endLabel = $this->formatDate($endDate);

        $headerTitle = trim($title) !== '' ? trim($title) : 'Website-Erstellungs-, Hosting- und Verwaltungsvertrag';
        $normalizedText = $this->normalizeContractText($contractText);
        $normalizedText = $this->stripDuplicatedLeadIn($normalizedText);
        $bodyHtml = $this->renderContractSections($normalizedText, $builderData, $startLabel, $endLabel);

        return '<!DOCTYPE html>'
            . '<html lang="de"><head><meta charset="UTF-8"><title>' . $this->esc($headerTitle) . '</title>'
            . '<style>'
            . '@page { size: A4 portrait; margin: 20mm 16mm 26mm 16mm; }'
            . 'body { margin:0; font-family:"DejaVu Sans", sans-serif; color:#111827; font-size:11.5px; line-height:1.5; font-weight:400; }'
            . '.title { text-align:center; font-size:32px; font-weight:700; margin:8mm 0 12mm; letter-spacing:0.01em; }'
            . '.contract-no { text-align:center; margin:-8mm 0 8mm; font-size:11px; color:#374151; }'
            . '.between { text-align:center; margin:0 0 6mm; font-weight:400; }'
            . '.parties { width:100%; border-collapse:collapse; margin-bottom:10mm; }'
            . '.parties td { width:50%; vertical-align:top; }'
            . '.party-name { font-weight:700; }'
            . '.party-right { text-align:right; margin-top:8px; font-weight:700; }'
            . '.lead { text-align:center; margin:8mm 0 12mm; font-weight:400; }'
            . '.content p { margin:0 0 7px; text-align:justify; font-weight:400; }'
            . '.content .page-break { page-break-before: always; }'
            . '.section-block { margin:0 0 8mm; }'
            . '.section-title { margin:0 0 3mm; font-size:15px; font-weight:700; }'
            . '.section-body p { margin:0 0 7px; font-weight:400; }'
            . '.section-list { margin:2px 0 10px 18px; padding:0; }'
            . '.section-list li { margin:0 0 5px; font-weight:400; }'
            . '.bank-block { margin-top:8px; text-align:center; }'
            . '.footer-signature { margin-top:14mm; }'
            . '.signature-grid { width:100%; border-collapse:collapse; margin-top:10mm; }'
            . '.signature-grid td { width:50%; vertical-align:top; }'
            . '.line-space { height:20mm; }'
            . '.muted { color:#374151; }'
            . '</style></head><body>'
            . '<div class="title">' . $this->esc($headerTitle) . '</div>'
            . '<div class="contract-no">Vertragsnummer: ' . $contractId . '</div>'
            . '<div class="between">zwischen</div>'
            . '<table class="parties"><tr>'
            . '<td>'
            . '<div class="party-name">Henz Software Solutions</div>'
            . '<div>Inhaber Christoph Henz, Sitz in</div>'
            . '<div>G&uuml;terberg 30a</div>'
            . '<div>63739 Aschaffenburg</div>'
            . '<div class="party-right">&ndash; nachfolgend &bdquo;Auftraggeber&ldquo; &ndash;</div>'
            . '</td>'
            . '<td>'
            . ($contractorName !== '' ? '<div class="party-name">' . $this->esc($contractorName) . '</div>' : '<div class="party-name">Auftragnehmer</div>')
            . ($contractorOwner !== '' ? '<div>' . $this->esc($contractorOwner) . ', Sitz in</div>' : '<div>Sitz in</div>')
            . ($contractorAddress !== '' ? '<div>' . $this->esc($contractorAddress) . '</div>' : '<div>-</div>')
            . '<div class="party-right">&ndash; nachfolgend &bdquo;Auftragnehmer&ldquo; &ndash;</div>'
            . '</td>'
            . '</tr></table>'
            . '<div class="lead">wird folgender Vertrag geschlossen:</div>'
            . '<div class="content">' . $bodyHtml . '</div>'
            . '<div class="footer-signature">'
            . '<p class="muted"><strong>Vertragslaufzeit:</strong> ' . $this->esc($startLabel) . ' bis ' . $this->esc($endLabel) . '</p>'
            . '<p><strong>Ort, Datum</strong></p>'
            . '<table class="signature-grid"><tr><td>'
            . '<p><strong>Auftraggeber</strong></p>'
            . '<p>Henz Software Solutions, Inhaber Christoph Henz</p>'
            . '<div class="line-space"></div>'
            . '<p>(Unterschrift)</p>'
            . '</td><td>'
            . '<p><strong>Auftragnehmer</strong></p>'
            . '<p>' . $this->esc($contractorName !== '' ? $contractorName : '-') . ($contractorOwner !== '' ? ', ' . $this->esc($contractorOwner) : '') . '</p>'
            . '<div class="line-space"></div>'
            . '<p>(Unterschrift)</p>'
            . '</td></tr></table>'
            . '</div>'
            . '</body></html>';
    }

    /** @param array<string, mixed> $builderData */
    private function renderContractSections(string $contractText, array $builderData, string $startLabel, string $endLabel): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($contractText));
        if ($text === '') {
            return '<p>-</p>';
        }

        $bankData = $this->loadBankData();
        $sections = $this->splitIntoSections($text);
        if ($sections === []) {
            return '<p>' . str_replace("\n", '<br>', $this->esc($text)) . '</p>';
        }

        $html = '';
        $preambleHandled = false;

        foreach ($sections as $section) {
            $title = trim((string) ($section['title'] ?? ''));
            $body = trim((string) ($section['body'] ?? ''));
            $number = $section['number'] ?? null;

            if (!$preambleHandled && isset($section['type']) && $section['type'] === 'preamble') {
                $html .= $this->renderSectionBlock($title !== '' ? $title : 'Präambel', $body);
                $html .= '<div class="page-break"></div>';
                $preambleHandled = true;
                continue;
            }

            if ($number === 2) {
                $html .= $this->renderSectionBlock(
                    $title !== '' ? $title : '§ 2 Vertragslaufzeit',
                    'Die Vertragslaufzeit beginnt am ' . $startLabel . ' und endet am ' . $endLabel . '.\n'
                    . 'Eine Verlängerung bedarf einer schriftlichen Vereinbarung beider Vertragsparteien.'
                );
                continue;
            }

            if ($number === 4) {
                $html .= $this->renderSection4Block($title !== '' ? $title : '§ 4 Leistungen des Auftraggebers', $builderData, $body);
                continue;
            }

            if ($number === 10) {
                $html .= $this->renderSection10Block($title !== '' ? $title : '§ 10 Vergütung', $builderData, $bankData);
                continue;
            }

            $html .= $this->renderSectionBlock($title !== '' ? $title : 'Abschnitt', $body);
        }

        return $html !== '' ? $html : '<p>-</p>';
    }

    /** @param array<string, mixed> $builderData */
    private function renderSection4Block(string $title, array $builderData, string $fallbackBody): string
    {
        $services = [];
        if (is_array($builderData['services'] ?? null)) {
            foreach ($builderData['services'] as $service) {
                $text = trim((string) $service);
                if ($text !== '') {
                    $services[] = $text;
                }
            }
        }

        if ($services === []) {
            return $this->renderSectionBlock($title, $fallbackBody);
        }

        $html = '<section class="section-block">'
            . '<h2 class="section-title">' . $this->esc($title) . '</h2>'
            . '<div class="section-body">'
            . '<p>Der Auftraggeber verpflichtet sich während der Vertragslaufzeit zur Bereitstellung folgender Leistungen:</p>'
            . '<ul class="section-list">';

        foreach ($services as $service) {
            $html .= '<li>' . $this->esc($service) . '</li>';
        }

        $html .= '</ul></div></section>';
        return $html;
    }

    /** @param array<string, mixed> $builderData
     *  @param array<string, string> $bankData
     */
    private function renderSection10Block(string $title, array $builderData, array $bankData): string
    {
        $setupFeeRaw = $this->parseMoneyValue((string) ($builderData['setup_fee'] ?? '200.00'));
        $setupFee = $this->formatMoneyAmount($setupFeeRaw);
        $monthlyFee = $this->formatMoneyValue((string) ($builderData['monthly_fee'] ?? '85.00'));

        $accountName = trim((string) ($bankData['bank_data_name'] ?? 'Christoph Henz'));
        $iban = trim((string) ($bankData['bank_data_iban'] ?? ''));
        $bic = trim((string) ($bankData['bank_data_bic'] ?? ''));

        $setupFeeLine = $setupFeeRaw > 0
            ? '<p>Einmalige Einrichtungsgebühr bei Vertragsbeginn: <strong>' . $this->esc($setupFee) . ' €</strong>.</p>'
            : '';

        return '<section class="section-block">'
            . '<h2 class="section-title">' . $this->esc($title) . '</h2>'
            . '<div class="section-body">'
            . $setupFeeLine
            . '<p>Monatliche Vergütung: <strong>' . $this->esc($monthlyFee) . ' €</strong>, fällig zum ersten Kalendertag eines Monats im Voraus.</p>'
            . '<p>Der Auftraggeber nimmt die Kleinunternehmerregelung gemäß § 19 UStG in Anspruch. Es wird keine Umsatzsteuer berechnet und ausgewiesen.</p>'
            . '<div class="bank-block">'
            . '<p><strong>Zahlungsdaten</strong></p>'
            . '<p>' . $this->esc($accountName) . '</p>'
            . ($iban !== '' ? '<p>IBAN: ' . $this->esc($iban) . '</p>' : '')
            . ($bic !== '' ? '<p>BIC: ' . $this->esc($bic) . '</p>' : '')
            . '</div>'
            . '</div>'
            . '</section>';
    }

    private function renderSectionBlock(string $title, string $body): string
    {
        $cleanBody = trim($body);
        $html = '<section class="section-block">'
            . '<h2 class="section-title">' . $this->esc($title) . '</h2>'
            . '<div class="section-body">';

        if ($cleanBody === '') {
            $html .= '<p>-</p>';
        } else {
            $parts = preg_split('/\n\s*\n/', $cleanBody) ?: [];
            foreach ($parts as $part) {
                $line = trim((string) $part);
                if ($line === '') {
                    continue;
                }
                $html .= '<p>' . str_replace("\n", '<br>', $this->esc($line)) . '</p>';
            }
        }

        $html .= '</div></section>';
        return $html;
    }

    /** @return array<int, array{type: string, title: string, body: string, number?: int}> */
    private function splitIntoSections(string $text): array
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/(?=^§\s*\d+[^\n]*$)/m', $normalized) ?: [];
        $sections = [];

        $first = trim((string) ($parts[0] ?? ''));
        if ($first !== '') {
            $firstLines = preg_split('/\n+/', $first) ?: [];
            $firstHeadline = trim((string) ($firstLines[0] ?? ''));
            $isPreamble = in_array(mb_strtolower($firstHeadline), ['praeambel', 'präambel'], true);

            if ($isPreamble) {
                $sections[] = [
                    'type' => 'preamble',
                    'title' => $firstHeadline,
                    'body' => trim(implode("\n", array_slice($firstLines, 1))),
                ];
            } else {
                $sections[] = [
                    'type' => 'preamble',
                    'title' => 'Präambel',
                    'body' => $first,
                ];
            }
        }

        $startIndex = count($parts) > 1 ? 1 : 0;
        for ($i = $startIndex; $i < count($parts); $i += 1) {
            $chunk = trim((string) $parts[$i]);
            if ($chunk === '') {
                continue;
            }

            $lines = preg_split('/\n+/', $chunk) ?: [];
            $heading = trim((string) ($lines[0] ?? ''));
            $body = trim(implode("\n", array_slice($lines, 1)));

            if (preg_match('/^§\s*(\d+)/', $heading, $m) === 1) {
                $sections[] = [
                    'type' => 'section',
                    'title' => $heading,
                    'body' => $body,
                    'number' => (int) $m[1],
                ];
            } else {
                $sections[] = [
                    'type' => 'section',
                    'title' => 'Abschnitt',
                    'body' => $chunk,
                ];
            }
        }

        return $sections;
    }

    /** @return array<string, string> */
    private function loadBankData(): array
    {
        try {
            $pdo = app(Database::class)->connection();
            $stmt = $pdo->prepare(
                'SELECT `key`, `value` FROM settings WHERE `key` IN (\'bank_data_name\', \'bank_data_iban\', \'bank_data_bic\')'
            );
            $stmt->execute();

            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $key = trim((string) ($row['key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $out[$key] = trim((string) ($row['value'] ?? ''));
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function formatMoneyValue(string $value): string
    {
        $amount = $this->parseMoneyValue($value);
        return $this->formatMoneyAmount($amount);
    }

    private function parseMoneyValue(string $value): float
    {
        $normalized = str_replace(',', '.', trim($value));
        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function formatMoneyAmount(float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }

    private function stripDuplicatedLeadIn(string $contractText): string
    {
        $text = trim($this->normalizeContractText($contractText));
        if ($text === '') {
            return '';
        }

        $lower = mb_strtolower($text);
        $needle = 'wird folgender vertrag geschlossen:';
        $pos = mb_strpos($lower, $needle);
        if ($pos === false) {
            return $text;
        }

        $start = $pos + mb_strlen($needle);
        $trimmed = trim(mb_substr($text, $start));
        return $trimmed !== '' ? $trimmed : $text;
    }

    private function normalizeContractText(string $value): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $value);
        $text = $this->extractCanonicalContractText($text);

        // Older records may contain literal escape sequences ("\\n") instead of real newlines.
        if (str_contains($text, '\\n') || str_contains($text, '\\r') || str_contains($text, '\\t')) {
            $text = str_replace('\\r\\n', "\n", $text);
            $text = str_replace('\\n', "\n", $text);
            $text = str_replace('\\r', "\n", $text);
            $text = str_replace('\\t', "\t", $text);
        }

        // Legacy payloads can include escaped JSON sequences which should be visible as plain text.
        if (str_contains($text, '\\/') || str_contains($text, '\\\"') || str_contains($text, "\\\\'")) {
            $text = str_replace('\\/', '/', $text);
            $text = str_replace('\\"', '"', $text);
            $text = str_replace("\\'", "'", $text);
        }

        $text = str_replace("\\\n", "\n", $text);
        $text = preg_replace('/(^|\n)\s*\\+\s*(?=\n|$)/', '$1', $text) ?? $text;

        return $text;
    }

    private function extractCanonicalContractText(string $value): string
    {
        $candidate = trim($value);
        if ($candidate === '') {
            return '';
        }

        for ($depth = 0; $depth < 5; $depth++) {
            if (str_starts_with($candidate, '__CONTRACT_PAYLOAD__')) {
                $candidate = trim(substr($candidate, strlen('__CONTRACT_PAYLOAD__')));
                continue;
            }

            $decoded = json_decode($candidate, true);
            if (!is_array($decoded) || !array_key_exists('text', $decoded)) {
                break;
            }

            $next = trim((string) ($decoded['text'] ?? ''));
            if ($next === '' || $next === $candidate) {
                $candidate = $next;
                break;
            }

            $candidate = $next;
        }

        $payloadMarkers = [
            '{"active":',
            '{\\"active\\":',
            ',"active":',
            ',\\"active\\":',
        ];
        foreach ($payloadMarkers as $marker) {
            $markerPos = strpos($candidate, $marker);
            if ($markerPos !== false) {
                $candidate = rtrim(substr($candidate, 0, $markerPos));
                break;
            }
        }

        return trim($candidate);
    }

    private function formatDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }

        try {
            $date = DateTimeImmutable::createFromFormat('Y-m-d', $value)
                ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value)
                ?: new DateTimeImmutable($value, new DateTimeZone('Europe/Berlin'));

            return $date->format('d.m.Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? 'vertrag';
        $value = trim($value, '-');
        return $value !== '' ? $value : 'vertrag';
    }
}
