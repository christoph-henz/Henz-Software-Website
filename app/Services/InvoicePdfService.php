<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Dompdf\Dompdf;
use Dompdf\Options;

final class InvoicePdfService
{
    private const STORAGE_PATH = 'storage/media/invoices';

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

        $booking = db('bookings')
            ->where('id', (int) ($invoice['booking_id'] ?? 0))
            ->first();

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
        $invoiceNumber = (int) ($invoice['invoice_number'] ?? 0);
        $currency = strtoupper(trim((string) ($invoice['currency_code'] ?? 'EUR')));
        if ($currency === '') {
            $currency = 'EUR';
        }

        $rowsHtml = '';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $description = htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8');
            $quantity = $this->formatNumber((float) ($item['quantity'] ?? 1.0));
            $unitPrice = $this->formatMoney((float) ($item['unit_price'] ?? 0.0));
            $lineTotal = $this->formatMoney((float) ($item['line_total'] ?? 0.0));

            $rowsHtml .= '<tr>'
                . '<td style="border-bottom:1px solid #ddd;padding:8px;">' . $description . '</td>'
                . '<td style="border-bottom:1px solid #ddd;padding:8px;text-align:center;">' . $quantity . '</td>'
                . '<td style="border-bottom:1px solid #ddd;padding:8px;text-align:right;">' . $unitPrice . ' ' . $currency . '</td>'
                . '<td style="border-bottom:1px solid #ddd;padding:8px;text-align:right;">' . $lineTotal . ' ' . $currency . '</td>'
                . '</tr>';
        }

        $scheduledAt = trim((string) ($booking['scheduled_at'] ?? ''));
        $scheduledLabel = $scheduledAt !== '' ? $this->formatDateTime($scheduledAt) : '-';
        $dueDateRaw = trim((string) ($invoice['due_date'] ?? ''));
        $dueDateLabel = $dueDateRaw !== ''
            ? $this->formatDate($dueDateRaw)
            : 'Keine Faelligkeit (Zahlung vor Termin erforderlich)';
        $paymentNotice = $dueDateRaw === ''
            ? 'Wichtiger Hinweis: Die Leistung wird nur erbracht, wenn der Betrag vor Antritt des Termins vollstaendig beglichen wurde.'
            : 'Hinweis: Der Termin gilt erst nach Zahlungseingang als verbindlich bestaetigt.';

        return '<!DOCTYPE html>'
            . '<html lang="de"><head><meta charset="UTF-8"><title>Invoice #' . $invoiceNumber . '</title></head>'
            . '<body style="font-family: DejaVu Sans, sans-serif; color:#1f2937; font-size:12px; line-height:1.5;">'
            . '<div style="margin-bottom:20px;">'
            . '<h1 style="margin:0 0 8px; font-size:24px;">Rechnung #' . $invoiceNumber . '</h1>'
            . '<div>Henz Software</div>'
            . '<div>' . htmlspecialchars((string) config('mail.senders.communication.address', ''), ENT_QUOTES, 'UTF-8') . '</div>'
            . '</div>'
            . '<table width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:16px;">'
            . '<tr><td width="50%" style="vertical-align:top;">'
            . '<strong>Empfaenger</strong><br>'
            . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . '<br>'
            . htmlspecialchars((string) ($client['email'] ?? ''), ENT_QUOTES, 'UTF-8')
            . '</td><td width="50%" style="vertical-align:top; text-align:right;">'
            . '<div>Rechnungsdatum: ' . htmlspecialchars($this->formatDate((string) ($invoice['invoice_date'] ?? '')), ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div>Faellig bis: ' . htmlspecialchars($dueDateLabel, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div>Termin: ' . htmlspecialchars($scheduledLabel, ENT_QUOTES, 'UTF-8') . '</div>'
            . '</td></tr></table>'
            . '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse; margin-bottom:16px;">'
            . '<thead><tr>'
            . '<th style="border-bottom:2px solid #222;padding:8px;text-align:left;">Beschreibung</th>'
            . '<th style="border-bottom:2px solid #222;padding:8px;text-align:center;">Menge</th>'
            . '<th style="border-bottom:2px solid #222;padding:8px;text-align:right;">Einzelpreis</th>'
            . '<th style="border-bottom:2px solid #222;padding:8px;text-align:right;">Betrag</th>'
            . '</tr></thead><tbody>'
            . $rowsHtml
            . '</tbody></table>'
            . '<div style="text-align:right;">Zwischensumme: ' . $this->formatMoney((float) ($invoice['sub_total_amount'] ?? 0.0)) . ' ' . $currency . '</div>'
            . '<div style="text-align:right;">Rabatt: ' . $this->formatMoney((float) ($invoice['discount_amount'] ?? 0.0)) . ' ' . $currency . '</div>'
            . '<div style="text-align:right; font-weight:bold; font-size:14px; margin-top:4px;">Gesamt: '
            . $this->formatMoney((float) ($invoice['total_amount'] ?? 0.0)) . ' ' . $currency . '</div>'
                . '<p style="margin-top:18px; color:#374151;">' . htmlspecialchars($paymentNotice, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="margin-top:24px; color:#4b5563;">Umsatzsteuerfreie Heilbehandlung gemaess Paragraf 4 Nr. 14 UStG.</p>'
            . '</body></html>';
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