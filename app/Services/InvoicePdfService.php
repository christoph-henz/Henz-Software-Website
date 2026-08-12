<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Database;
use DateTimeImmutable;
use DateTimeZone;
use Dompdf\Dompdf;
use Dompdf\Options;

final class InvoicePdfService
{
    private const STORAGE_PATH = 'storage/media/invoices';
    private ?bool $bookingsTableAvailable = null;
    private ?bool $invoiceHasBookingIdColumn = null;

    /**
     * @return array{relative_path: string, mime_type: string, file_size: int, sha256: string, generated_at: string}
     */
    public function generateForInvoice(int $invoiceId): array
    {
        if ($invoiceId <= 0) {
            throw new \RuntimeException('Invalid invoice id');
        }

        $invoice = db('invoices')->where('id', $invoiceId)->first();
        if (!is_array($invoice)) {
            throw new \RuntimeException('Invoice not found');
        }

        $client = db('clients')
            ->where('id', (int) ($invoice['client_id'] ?? 0))
            ->first();
        if (!is_array($client)) {
            throw new \RuntimeException('Client for invoice not found');
        }

        $client = app(ClientFieldEncryptionService::class)->decryptClientRow($client);

        $booking = null;
        $bookingId = isset($invoice['booking_id']) ? (int) $invoice['booking_id'] : 0;
        if ($bookingId > 0 && $this->isBookingsTableAvailable() && $this->invoiceHasBookingIdColumn()) {
            $candidate = db('bookings')
                ->where('id', $bookingId)
                ->first();
            $booking = is_array($candidate) ? $candidate : null;
        }

        $items = db('invoice_items')
            ->where('invoice_id', $invoiceId)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();


        $html = $this->renderHtml($invoice, $client, is_array($booking) ? $booking : [], is_array($items) ? $items : []);
        $pdf = $this->renderPdf($html);

        $invoiceDate = trim((string) ($invoice['invoice_date'] ?? ''));
        $date = $invoiceDate !== '' ? new DateTimeImmutable($invoiceDate, new DateTimeZone('Europe/Berlin')) : new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
        $relativeDirectory = $date->format('Y/m');
        $fileName = sprintf(
            'invoice-%d-%d.pdf',
            (int) ($invoice['invoice_number'] ?? $invoiceId),
            $invoiceId
        );

        $absoluteDirectory = base_path(self::STORAGE_PATH . '/' . $relativeDirectory);
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
            throw new \RuntimeException('Could not create invoice PDF directory');
        }

        $absoluteFilePath = $absoluteDirectory . '/' . $fileName;
        if (file_put_contents($absoluteFilePath, $pdf) === false) {
            throw new \RuntimeException('Could not write invoice PDF');
        }

        return [
            'relative_path' => 'invoices/' . $relativeDirectory . '/' . $fileName,
            'mime_type' => 'application/pdf',
            'file_size' => strlen($pdf),
            'sha256' => hash('sha256', $pdf),
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s'),
        ];
    }

    private function isBookingsTableAvailable(): bool
    {
        if ($this->bookingsTableAvailable !== null) {
            return $this->bookingsTableAvailable;
        }

        try {
            $pdo = app(Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                 LIMIT 1'
            );
            $statement->execute(['table_name' => 'bookings']);

            $this->bookingsTableAvailable = $statement->fetchColumn() !== false;
            return $this->bookingsTableAvailable;
        } catch (\Throwable) {
            $this->bookingsTableAvailable = false;
            return false;
        }
    }

    private function invoiceHasBookingIdColumn(): bool
    {
        if ($this->invoiceHasBookingIdColumn !== null) {
            return $this->invoiceHasBookingIdColumn;
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
                'column_name' => 'booking_id',
            ]);

            $this->invoiceHasBookingIdColumn = $statement->fetchColumn() !== false;
            return $this->invoiceHasBookingIdColumn;
        } catch (\Throwable) {
            $this->invoiceHasBookingIdColumn = false;
            return false;
        }
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

    /** @param array<int, array<string, mixed>> $items */
    private function renderHtml(array $invoice, array $client, array $booking, array $items): string
    {
        $clientName = trim((string) (($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? '')));
        if ($clientName === '') {
            $clientName = trim((string) ($client['name'] ?? ''));
        }
        $companyName = trim((string) ($client['company_name'] ?? ''));
        $invoiceNumber = (int) ($invoice['invoice_number'] ?? 0);
        $currency = strtoupper(trim((string) ($invoice['currency_code'] ?? 'EUR')));
        if ($currency === '') {
            $currency = 'EUR';
        }
        $address = trim((string)($client['address'] ?? ''));

        $street = '';
        $city = '';

        if (preg_match(
            '/^(?<street>.+?\s+\d+[a-zA-Z]?(?:[-\/]\d+[a-zA-Z]?)?)\s*,?\s*(?<zip>\d{5})\s+(?<city>.+)$/u',
            $address,
            $matches
        )) {
            $street = trim($matches['street']);
            $city = trim($matches['zip'] . ' ' . $matches['city']);
        }

        $database = app(Database::class);
        $pdo = $database->connection();

        $sql = "
            SELECT `key`, `value`
            FROM settings
            WHERE `key` IN (
                '19_ust_true',
                'bank_data_name',
                'bank_data_iban',
                'bank_data_bic',
                'ust_id'
            )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $bank_data = [];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $bank_data[$row['key']] = $row['value'];
        }

        $rowsHtml = '';
        $position = 1;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $description = htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8');
            $quantity = $this->formatNumber((float) ($item['quantity'] ?? 1.0));
            $unitPrice = $this->formatMoney((float) ($item['unit_price'] ?? 0.0));
            $lineTotal = $this->formatMoney((float) ($item['line_total'] ?? 0.0));

            $rowsHtml .= '<tr>'
                . '<td style="padding:5px; text-align:center;">' . $position . '</td>'
                . '<td style="padding:5px; text-align:center;">' . $quantity . '</td>'
                . '<td style="padding:5px 5px 5px 10px; text-align:left;">' . $description . '</td>'
                . '<td style="padding:5px; text-align:right;">' . $unitPrice . ' ' . $currency . '</td>'
                . '<td style="padding:5px; text-align:right;">' . $lineTotal . ' ' . $currency . '</td>'
                . '</tr>';

            $position++;
        }

        $scheduledAt = trim((string) ($booking['scheduled_at'] ?? ''));
        $scheduledLabel = $scheduledAt !== '' ? $this->formatDateTime($scheduledAt) : '-';
        $dueDateRaw = trim((string) ($invoice['due_date'] ?? ''));
        $dueDateLabel = $dueDateRaw !== ''
            ? $this->formatDate($dueDateRaw)
            : 'Keine Fälligkeit (Zahlung vor Termin erforderlich)';
        $paymentNotice = $dueDateRaw === ''
            ? 'Wichtiger Hinweis: Die Leistung wird nur erbracht, wenn der Betrag vor Leistungsantritt des vollständig beglichen wurde.'
            : 'Hinweis: Die Leistung wird erst nach vollständigem Zahlungseingang erbracht.';

        $html = '<!DOCTYPE html>'
            . '<html lang="de"><head><meta charset="UTF-8"><title>Rechnung #' . $invoiceNumber . '</title>'
            . '<style>
                @page {
                    size: A4 portrait;
                    margin: 12mm 15mm 25mm 20mm;
                }

                body {
                    margin: 0;
                    font-family: "DejaVu Sans", sans-serif;
                    color: #151f3b;
                    font-size: 12px;
                }

                .invoice-content {
                    padding-bottom: 8mm;
                }

                .invoice-footer {
                    position: fixed;
                    left: 0;
                    right: 0;
                    bottom: -23mm;
                    width: 100%;
                    padding-top: 12px;
                    border-top: 1px solid #d1d5db;
                    color: #6b7280;
                    font-size: 10px;
                    line-height: 1.45;
                }
            </style></head>'
            . '<body>'
            . '<div class="invoice-content">'
            . '<div style="margin-top: 20px; margin-bottom: 1cm; display: flex; justify-content:flex-end;">'
            . '<div class="flex items-right gap-2"
                style="background-color:#c2c2cd; border-radius: 10px 0 0 10px; padding: 20px;"
                data-fg-d3bl14="0.8:1.32440:/src/app/App.tsx:178:11:6104:397:e:div:ete" data-fgid-d3bl14=":r3:"><svg
                xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                class="lucide lucide-terminal w-5 h-5"
                data-fg-d3bl15="0.8:1.32440:node_modules/lucide-react:179:13:6158:61:e:Terminal::::::DcQ8"
                data-fgid-d3bl15=":r4:" style="color: #0288ae;">
                <polyline points="4 17 10 11 4 5"></polyline>
                <line x1="12" x2="20" y1="19" y2="19"></line>
                </svg><span class="text-lg font-bold tracking-tight"
                data-fg-d3bl16="0.8:1.32440:/src/app/App.tsx:180:13:6232:252:e:span:te" data-fgid-d3bl16=":r5:"
                style="font-family: &quot;JetBrains Mono&quot;, monospace; color: #151f3b;"><span
                style="color: #0288ae;">&gt;_</span> Henz Software
                <span data-fg-d3bl18="0.8:1.32440:/src/app/App.tsx:184:23:6419:45:e:span:t" data-fgid-d3bl18=":r6:"
                style="color: #0288ae;">Solutions</span></span></div>'
            . '</div>'
            . '<table cellspacing="0" cellpadding="0" style="width:100%; margin:0 0 4mm 0; border-collapse:collapse; table-layout:fixed;">
                <!-- Absenderzeile über dem Empfänger -->
               <tr>'
            . '<td colspan="2" style="font-size:9px; color:#303030; padding:1cm 2mm 10px 0;">
                Henz Software Solutions · Güterberg 30a · 63739 Aschaffenburg
               </td>
               <tr>'
            . '<tr>
                    <td width="55%" style="vertical-align:top; padding-right:4mm;">';
            $html .= '
                    <div>' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</div>
                    <div>' . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . '</div>
                    <div>' . htmlspecialchars($street, ENT_QUOTES, 'UTF-8') . '</div>
                    <div>' . htmlspecialchars($city, ENT_QUOTES, 'UTF-8') . '</div>
                    </td>
                    <td width="45%" style="vertical-align:top; text-align:right; padding-right:2mm;">
                        <div style=" margin-top:-2cm;">
                            <strong style="font-size:15px;">Henz Software Solutions</strong><br>
                            Inhaber Christoph Henz<br>
                            Güterberg 30a<br>
                            63739 Aschaffenburg
                        </div>
                    </td>
                </tr>'
            . '<!-- Abstand -->
                <tr>
                    <td></td>
                    <td style="height:25px;"></td>
                </tr>'
            . '<!-- Rechnungsdaten -->
                <tr>
                    <td></td>'
            . '<td style="text-align:right; vertical-align:top; padding-right:2mm;">'
            . '<table align="right" cellspacing="0" cellpadding="2" style="display:inline-table; margin-top: -1cm; max-width:100%;">
                    <tr>
                        <td style="font-weight:bold;">Kundennummer: </td>
                        <td style="text-align:left;">&nbsp;0' . $client['id'] . '0</td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold;">Rechnungsnummer: </td>
                        <td style="text-align:left;">&nbsp;' . $invoiceNumber . '</td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold;">Rechnungsdatum: </td>
                        <td style="text-align:left;">&nbsp;' . date('d.M.Y') . '</td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold;">Leistungsdatum: </td>
                        <td style="text-align:left;">&nbsp;' . '</td>
                    </tr>
                </table>'
            . '</td>
                    </tr>
                </table>';

            $html .= '<h1 style="font-size:24px; margin-bottom:20px;">Rechnung</h1>'
            .   '<div>
                    <p>Vielen Dank für Ihren Auftrag und das mir entgegengebrachte Vertrauen. Vereinbarungsgemäß berechne ich Ihnen hiermit folgende Leistungen:</p>
                    <br>
                </div>'
            . '<table style="width:100%; border-collapse:collapse; margin-bottom:20px;" cellspacing="0" cellpadding="0">
                <thead>
                    <tr style="background:#dddddd; color:black;">'
            .  '<th style="padding:10px; text-align:left; width: 2%;">Pos.</th>
                <th style="padding:10px;text-align:center; width: 2%;">Menge</th>
                <th style="padding:10px;text-align:left;" width:auto>Beschreibung</th>
                <th style="padding:10px;text-align:right; width:7%;">Einzelpreis</th>
                <th style="padding:10px;text-align:right; width:15%;">Betrag</th>
            '
            . '</tr>
            </thead>
            <tbody>';
            $html .= $rowsHtml;
            $html .= '<tr>
                <td colspan="5" style="border-bottom:1px solid #151f3b;"></td>
            </tr>';
            if (!($bank_data['19_ust_true'] ?? false)) {
            $html .= '<tr>
                <td colspan="2" style="padding:10px; text-align:left;">Nettopreis:</td>
                <td></td>
                <td></td>
                <td style="padding:10px; text-align:right;">150,00 €</td>
            </tr>';
            $html .= '<tr>
                        <td colspan="2" style="padding:10px; text-align:left;">MwSt. (19%):</td>
                        <td></td>
                        <td></td>
                        <td style="padding:10px; text-align:right;">' . $this->formatMoney((float) ($invoice['ust_amount'] ?? 0.0)) . ' ' . $currency . '</td>
                    </tr>';
            }
            $html .= '<tr style="background:#dddddd; color:black;">
                    <td colspan="3" style="padding:10px; text-align:left; font-weight:bold;">Rechnungsbetrag:</td>
                    <td></td>
                    <td style="padding:10px 5px 10px; text-align:right; font-weight:bold;">' . $this->formatMoney((float) ($invoice['total_amount'] ?? 0.0)) . ' ' . $currency . '</td>
                </tr>'
            . '</tbody>
               </table>';
        if ($bank_data['19_ust_true'] ?? false) {
            $html .= '<div><p>Es wird die Kleinunternehmerregelung nach § 19 UStG in Anspruch genommen.</p>';
        }
        else {
            $html .= '<div>';
        }
        $html .= '<p>Bitte überweisen Sie den Rechnungsbetrag bis zum ' . $dueDateLabel . ' mit Angabe der Rechnungsnummer auf das unten genannte Konto.</p>
                <p>Für weitere Fragen stehe ich Ihnen sehr gerne zur Verfügung. </p>
                <br>
                <p>Mit freundlichen Grüßen</p>
                <br>
                <p>Christoph Henz</p>
                </div>
            </div>';
        
        $html .= '</div>';

        $html .= '<footer class="invoice-footer">
                <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse; table-layout:fixed;">

                    <tr>

                        <td width="33%" style="vertical-align:top; text-align:center; padding:0 10px;">

                            <strong style="display:block; color:#374151; margin-bottom:6px;">
                                Henz Software Solutions
                            </strong>

                            Güterberg 30a<br>
                            63739 Aschaffenburg<br>
                            Tel.: +49 1522 7434327<br>
                            E-Mail: info@henz-software.de

                        </td>'
        .'<td width="33%" style="vertical-align:top; text-align:center; padding:0 10px;">

                    <strong style="display:block; color:#374151; margin-bottom:6px;">
                        Bankverbindung
                    </strong>'
        . $bank_data['bank_data_name'] .'<br>'
        . 'IBAN: ' . $bank_data['bank_data_iban'] .'<br>'
        . 'BIC: ' . $bank_data['bank_data_bic'] .'<br>'
        . 'Kontoinhaber: Christoph Henz'
        . '</td>

                <td width="34%" style="vertical-align:top; text-align:center; padding:0 10px;">

                    <strong style="display:block; color:#374151; margin-bottom:6px;">
                        Unternehmensdaten
                    </strong>'
        . 'USt-IdNr: ' . $bank_data['ust_id'] .'<br>'
        . 'USt-IdNr: wird nachgetragen<br>'
        . 'Amtsgericht: Aschaffenburg<br>
                    Inhaber: Christoph Henz<br>
                    www.henz-software.de'
        . '</td>
                        </tr>
                    </table>
                </footer>
            </body>
            </html>';

        return $html;
    }

    private function formatDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value)
            ?: new DateTimeImmutable($value, new DateTimeZone('Europe/Berlin'));

        return $date->format('d.m.Y');
    }

    private function formatDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $value)
            ?: new DateTimeImmutable($value, new DateTimeZone('Europe/Berlin'));

        return $date->format('d.m.Y H:i');
    }

    private function formatMoney(float $value): string
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
}