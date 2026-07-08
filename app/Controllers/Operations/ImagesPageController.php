<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

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

        $config = require base_path('public/ui/_config/admin-images.php');
        $config['can_manage_media'] = $canManage;

        $configuredMax = $this->readMediaMaxFileSizeBytes();
        $chunkSize = (int) ($config['upload_chunk_size_bytes'] ?? (500 * 1024));

        $config['max_file_size_bytes'] = $configuredMax;
        $config['max_file_size_label'] = $this->formatBytesLabel($configuredMax);
        $config['upload_chunk_size_bytes'] = $chunkSize > 0 ? $chunkSize : (500 * 1024);
        $config['upload_chunk_size_label'] = $this->formatBytesLabel((int) $config['upload_chunk_size_bytes']);

        return $this->render('admin-images-page.php', [
            'pageTitle' => 'Bilderverwaltung - Getragen Begleiten',
            'adminUser' => $adminUser,
            'logoutAction' => '/admin/logout',
            'csrfToken' => app(CsrfTokenManager::class)->token(),
            'imagesConfig' => $config,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data = [], int $status = 200): Response
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require base_path('public/ui/_templates/' . $template);
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
