<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Dompdf\Dompdf;
use Dompdf\Options;

final class FormTemplatePdfService
{
    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $version
     * @return array{content: string, mime_type: string, file_name: string}
     */
    public function renderTemplateVersionPdf(array $template, array $version): array
    {
        $html = $this->renderHtml($template, $version);
        $pdfContent = $this->renderPdf($html);

        $templateKey = trim((string) ($template['template_key'] ?? 'template'));
        $safeKey = $this->slugify($templateKey !== '' ? $templateKey : 'template');
        $versionNo = max(1, (int) ($version['version_no'] ?? 1));

        return [
            'content' => $pdfContent,
            'mime_type' => 'application/pdf',
            'file_name' => sprintf('%s-v%d.pdf', $safeKey, $versionNo),
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

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $version
     */
    private function renderHtml(array $template, array $version): string
    {
        $title = trim((string) ($template['name'] ?? 'Formularvorlage'));
        $versionNo = (int) ($version['version_no'] ?? 0);
        $publishedAt = $this->formatDateTime((string) ($version['published_at'] ?? ''));
        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('d.m.Y H:i');

        $schema = $version['schema_json'] ?? [];
        if (!is_array($schema)) {
            $schema = [];
        }

        $letterhead = $this->extractLetterhead($schema, $title);
        $schemaHtml = $this->renderSchema($this->schemaWithoutLetterhead($schema));

        return '<!DOCTYPE html>'
            . '<html lang="de"><head><meta charset="UTF-8"><title>' . $this->esc($title) . '</title></head>'
            . '<body style="font-family: DejaVu Sans, sans-serif; color:#1f2937; font-size:12px; line-height:1.45;">'
            . '<header style="margin-bottom:18px; border-bottom:1px solid #d1d5db; padding-bottom:10px;">'
            . '<div style="margin-bottom:10px;">'
            . '<div style="font-size:18px; font-weight:700;">' . $this->esc($letterhead['practice_name']) . '</div>'
            . '<h1 style="margin:2px 0 4px; font-size:22px;">' . $this->esc($letterhead['form_title']) . '</h1>'
            . ($letterhead['subtitle'] !== '' ? '<div style="color:#4b5563;">' . $this->esc($letterhead['subtitle']) . '</div>' : '')
            . ($letterhead['context_line'] !== '' ? '<div style="margin-top:2px; color:#6b7280;">' . $this->esc($letterhead['context_line']) . '</div>' : '')
            . '</div>'
            . '<div>Version: <strong>' . $this->esc((string) $versionNo) . '</strong></div>'
            . '<div>Veroeffentlicht: <strong>' . $this->esc($publishedAt) . '</strong></div>'
            . '<div>PDF erstellt: <strong>' . $this->esc($generatedAt) . '</strong></div>'
            . '</header>'
            . '<section><h2 style="font-size:14px; margin:0 0 8px;">Formularaufbau</h2>' . $schemaHtml . '</section>'
            . '</body></html>';
    }

    /** @param array<int, mixed> $schema */
    private function extractLetterhead(array $schema, string $defaultTitle): array
    {
        $default = [
            'practice_name' => 'Henz Software',
            'form_title' => $defaultTitle,
            'subtitle' => '',
            'context_line' => '',
        ];

        foreach ($schema as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (strtolower(trim((string) ($item['type'] ?? ''))) !== 'letterhead') {
                continue;
            }

            return [
                'practice_name' => trim((string) ($item['practice_name'] ?? $default['practice_name'])),
                'form_title' => trim((string) ($item['form_title'] ?? $item['label'] ?? $default['form_title'])),
                'subtitle' => trim((string) ($item['subtitle'] ?? '')),
                'context_line' => trim((string) ($item['context_line'] ?? '')),
            ];
        }

        return $default;
    }

    /** @param array<int, mixed> $schema */
    private function schemaWithoutLetterhead(array $schema): array
    {
        return array_values(array_filter($schema, static function ($item): bool {
            if (!is_array($item)) {
                return true;
            }

            return strtolower(trim((string) ($item['type'] ?? ''))) !== 'letterhead';
        }));
    }

    /** @param array<int, mixed> $schema */
    private function renderSchema(array $schema): string
    {
        if ($schema === []) {
            return '<p>Keine Felder definiert.</p>';
        }

        $html = '';
        foreach ($schema as $item) {
            if (!is_array($item)) {
                continue;
            }

            $html .= $this->renderSchemaItem($item, 0);
        }

        return $html !== '' ? $html : '<p>Keine darstellbaren Felder gefunden.</p>';
    }

    /** @param array<string, mixed> $item */
    private function renderSchemaItem(array $item, int $depth): string
    {
        $type = strtolower(trim((string) ($item['type'] ?? 'text')));
        $label = trim((string) ($item['label'] ?? 'Unbenannt'));
        $indent = max(0, $depth) * 18;

        if ($type === 'section') {
            $children = $item['items'] ?? [];
            if (!is_array($children)) {
                $children = [];
            }

            $childrenHtml = '';
            foreach ($children as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $childrenHtml .= $this->renderSchemaItem($child, $depth + 1);
            }

            return '<section style="margin:10px 0; margin-left:' . $indent . 'px;">'
                . '<h3 style="margin:0 0 6px; font-size:13px;">' . $this->esc($label) . '</h3>'
                . ($childrenHtml !== '' ? $childrenHtml : '<div style="color:#6b7280;">Keine Felder in dieser Sektion.</div>')
                . '</section>';
        }

        return $this->renderField($item, $depth);
    }

    /** @param array<string, mixed> $field */
    private function renderField(array $field, int $depth): string
    {
        $label = trim((string) ($field['label'] ?? 'Feld'));
        $type = strtolower(trim((string) ($field['type'] ?? 'text')));
        $required = (bool) ($field['required'] ?? false);
        $helpText = trim((string) ($field['help_text'] ?? ''));
        $placeholder = trim((string) ($field['placeholder'] ?? ''));
        $indent = max(0, $depth) * 18;
        $requiredMark = $required ? ' <span style="color:#b91c1c;">*</span>' : '';

        $fieldLine = '<div style="margin:6px 0 4px; margin-left:' . $indent . 'px;">'
            . '<strong>' . $this->esc($label) . $requiredMark . '</strong>'
            . '</div>';

        if ($type === 'checkbox' || $type === 'radio' || $type === 'select') {
            $options = $field['options'] ?? [];
            if (!is_array($options)) {
                $options = [];
            }

            $optionsHtml = '';
            foreach ($options as $option) {
                $labelText = trim((string) $option);
                if ($labelText === '') {
                    continue;
                }
                $optionsHtml .= '<li style="margin:2px 0;">[ ] ' . $this->esc($labelText) . '</li>';
            }

            if ($optionsHtml !== '') {
                $fieldLine .= '<ul style="margin:0 0 6px ' . ($indent + 16) . 'px; padding:0; list-style:none;">' . $optionsHtml . '</ul>';
            }
        } else {
            $hint = $placeholder !== '' ? $placeholder : '................................................';
            $fieldLine .= '<div style="margin:0 0 8px ' . ($indent + 16) . 'px; padding:4px 0; border-bottom:1px solid #d1d5db; color:#6b7280;">'
                . $this->esc($hint)
                . '</div>';
        }

        if ($helpText !== '') {
            $fieldLine .= '<div style="margin:0 0 10px ' . ($indent + 16) . 'px; color:#6b7280; font-size:11px;">'
                . $this->esc($helpText)
                . '</div>';
        }

        return $fieldLine;
    }

    private function formatDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }

        try {
            $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value)
                ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $value)
                ?: new DateTimeImmutable($value, new DateTimeZone('Europe/Berlin'));

            return $date->format('d.m.Y H:i');
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
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? 'template';
        $value = trim($value, '-');

        return $value !== '' ? $value : 'template';
    }
}
