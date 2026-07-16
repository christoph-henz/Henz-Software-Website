<?php

declare(strict_types=1);

namespace App\Controllers\Operations;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfTokenManager;
use App\Core\Support\PermissionBits;

final class ImagesPageController
{
    private const DEFAULT_MAX_FILE_SIZE_MB = 5;

    public function index(Request $request): Response
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = is_array($session[$sessionKey] ?? null) ? $session[$sessionKey] : [];

        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $manageBit = PermissionBits::resolve('manage_media', 4096);
        $canManage = (($roleMask & $manageBit) !== 0);

        if (!$canManage) {
            return Response::json([
                'success' => false,
                'message' => 'Forbidden',
                'errors' => [
                    'permission' => ['insufficient_role'],
                ],
            ], 403);
        }

        $config = require base_path('public/ui/_config/operations/admin-images.php');
        $config['can_manage_media'] = $canManage;
        $config['gallery_slots'] = $this->buildGallerySlotsFromAssignments();

        $configuredMax = $this->readMediaMaxFileSizeBytes();
        $chunkSize = (int) ($config['upload_chunk_size_bytes'] ?? (500 * 1024));

        $config['max_file_size_bytes'] = $configuredMax;
        $config['max_file_size_label'] = $this->formatBytesLabel($configuredMax);
        $config['upload_chunk_size_bytes'] = $chunkSize > 0 ? $chunkSize : (500 * 1024);
        $config['upload_chunk_size_label'] = $this->formatBytesLabel((int) $config['upload_chunk_size_bytes']);

        return $this->render('admin-images-page.php', [
            'pageTitle' => 'Bilderverwaltung - Henz Software',
            'adminUser' => $adminUser,
            'logoutAction' => '/logout',
            'csrfToken' => app(CsrfTokenManager::class)->token(),
            'imagesConfig' => $config,
        ]);
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

    private function readMediaMaxFileSizeBytes(): int
    {
        return $this->readMediaMaxFileSizeMb() * 1024 * 1024;
    }

    private function readMediaMaxFileSizeMb(): int
    {
        try {
            $row = db('settings')
                ->where('`key`', 'media_max_file_size')
                ->select(['value'])
                ->first();
        } catch (\Throwable) {
            return self::DEFAULT_MAX_FILE_SIZE_MB;
        }

        $value = (string) ($row['value'] ?? '');
        if (!is_numeric($value)) {
            return self::DEFAULT_MAX_FILE_SIZE_MB;
        }

        $parsed = (int) $value;
        if ($parsed < 1) {
            return self::DEFAULT_MAX_FILE_SIZE_MB;
        }

        return min($parsed, 5120);
    }

    /**
     * @return array<int, array{page_key:string,label:string,sections:array<int, array{section_key:string,label:string,slots:array<int, string>}>}>
     */
    private function buildGallerySlotsFromAssignments(): array
    {
        try {
            $rows = db('page_media_assignments')
                ->select(['page_key', 'section_key', 'slot_key'])
                ->orderBy('page_key', 'ASC')
                ->orderBy('section_key', 'ASC')
                ->orderBy('slot_key', 'ASC')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        /** @var array<string, array{page_key:string,label:string,sections:array<string, array{section_key:string,label:string,slots:array<int, string>}>}> $pages */
        $pages = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $pageKey = trim((string) ($row['page_key'] ?? ''));
            $sectionKey = trim((string) ($row['section_key'] ?? 'default'));
            $slotKey = trim((string) ($row['slot_key'] ?? ''));

            if ($pageKey === '' || $slotKey === '') {
                continue;
            }

            if (!isset($pages[$pageKey])) {
                $pages[$pageKey] = [
                    'page_key' => $pageKey,
                    'label' => $this->galleryPageLabel($pageKey),
                    'sections' => [],
                ];
            }

            if (!isset($pages[$pageKey]['sections'][$sectionKey])) {
                $pages[$pageKey]['sections'][$sectionKey] = [
                    'section_key' => $sectionKey,
                    'label' => $this->gallerySectionLabel($sectionKey),
                    'slots' => [],
                ];
            }

            if (!in_array($slotKey, $pages[$pageKey]['sections'][$sectionKey]['slots'], true)) {
                $pages[$pageKey]['sections'][$sectionKey]['slots'][] = $slotKey;
            }
        }

        $result = [];
        foreach ($pages as $page) {
            $sections = [];
            foreach ($page['sections'] as $section) {
                $section['slots'] = array_values(array_unique($section['slots']));
                $sections[] = $section;
            }

            $page['sections'] = $sections;
            $result[] = $page;
        }

        return $result;
    }

    private function galleryPageLabel(string $pageKey): string
    {
        $labels = [
            'home' => 'Startseite',
            'ueber-mich' => 'Über mich',
            'meine-geschichte' => 'Meine Geschichte',
            'booking' => 'Termin buchen',
            'prices' => 'Honorar & Ablauf',
            'begleitung' => 'Begleitung',
            'service' => 'Leistungen',
            'project' => 'Referenzen',
        ];

        if (isset($labels[$pageKey])) {
            return $labels[$pageKey];
        }

        return $this->labelFromKey($pageKey);
    }

    private function gallerySectionLabel(string $sectionKey): string
    {
        $labels = [
            'hero' => 'Hero',
            'about' => 'Über mich Abschnitt',
            'intro' => 'Intro',
        ];

        if (isset($labels[$sectionKey])) {
            return $labels[$sectionKey];
        }

        return $this->labelFromKey($sectionKey);
    }

    private function labelFromKey(string $value): string
    {
        $value = trim(str_replace(['-', '_'], ' ', $value));
        if ($value === '') {
            return 'Unbenannt';
        }

        if (function_exists('mb_convert_case')) {
            return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
        }

        return ucwords($value);
    }

    private function formatBytesLabel(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / (1024 * 1024 * 1024), 2, '.', ''), '0'), '.') . ' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / (1024 * 1024), 2, '.', ''), '0'), '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 2, '.', ''), '0'), '.') . ' KB';
        }

        return $bytes . ' B';
    }
}
