<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Admin;

use App\Core\Http\Response;
use App\Core\Http\Request;
use App\Controllers\Api\BaseApiController;
use App\Core\Support\PermissionBits;

class MediaController extends BaseApiController
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const DEFAULT_MAX_FILE_SIZE_MB = 5;
    private const STORAGE_PATH = 'storage/media';
    private const CHUNK_TEMP_PATH = 'storage/media/_chunks';
    private const CHUNK_SIZE_BYTES = 500 * 1024; // 500KB

    /**
     * GET /v1/admin/media
     * List all media assets with pagination
     */
    public function index(Request $request): Response
    {
        if (!$this->canManageMedia($request)) {
            return Response::json([
                'success' => false,
                'error' => true,
                'message' => 'Forbidden',
                'errors' => ['permission' => ['insufficient_role']],
            ], 403);
        }

        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 20);
        $isActive = $request->query('is_active');

        $query = db('media_assets');

        if ($isActive !== null) {
            $query = $query->where('is_active', (int) filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
        }

        $total = $query->count();
        $offset = ($page - 1) * $perPage;

        $assets = $query
            ->select(['*'])
            ->offset($offset)
            ->limit($perPage)
            ->orderBy('created_at', 'DESC')
            ->get();

        return Response::json([
            'data' => $assets,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
            ],
        ], 200);
    }

    /**
     * POST /v1/admin/media
     * Upload a new media asset
     */
    public function store(Request $request): Response
    {
        if (!$this->canManageMedia($request)) {
            return Response::json([
                'success' => false,
                'error' => true,
                'message' => 'Forbidden',
                'errors' => ['permission' => ['insufficient_role']],
            ], 403);
        }

        // Validate request structure
        if (!isset($_FILES['file'])) {
            return Response::json([
                'error' => true,
                'message' => 'No file uploaded',
                'field' => 'file',
            ], 422);
        }

        $file = $_FILES['file'];
        $altText = (string) ($request->input('alt_text') ?? '');
        $effectiveMaxBytes = $this->effectiveMaxFileSizeBytes();
        $effectiveMaxLabel = $this->formatBytesLabel($effectiveMaxBytes);

        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $uploadErrorCode = (int) $file['error'];
            $uploadMessage = match ($uploadErrorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Datei zu gross. Erlaubt sind maximal ' . $effectiveMaxLabel . '.',
                UPLOAD_ERR_PARTIAL => 'Upload unvollständig. Bitte erneut versuchen.',
                UPLOAD_ERR_NO_FILE => 'Keine Datei hochgeladen.',
                UPLOAD_ERR_NO_TMP_DIR => 'Upload fehlgeschlagen: Temporarer Ordner fehlt.',
                UPLOAD_ERR_CANT_WRITE => 'Upload fehlgeschlagen: Datei konnte nicht gespeichert werden.',
                UPLOAD_ERR_EXTENSION => 'Upload wurde durch eine Server-Erweiterung abgebrochen.',
                default => 'File upload error',
            };

            return Response::json([
                'error' => true,
                'message' => $uploadMessage,
                'code' => $uploadErrorCode,
            ], 422);
        }

        // Check MIME type
        $mimeType = $this->detectMimeType((string) $file['tmp_name'], (string) ($file['type'] ?? ''));
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return Response::json([
                'error' => true,
                'message' => 'Invalid file type. Allowed: JPEG, PNG, WebP, GIF',
                'field' => 'file',
            ], 422);
        }

        // Check file size
        if ((int) $file['size'] > $effectiveMaxBytes) {
            return Response::json([
                'error' => true,
                'message' => 'Datei zu gross. Erlaubt sind maximal ' . $effectiveMaxLabel . '.',
                'field' => 'file',
            ], 422);
        }

        $relativeDirectory = date('Y/m');
        $storageDirectory = base_path(self::STORAGE_PATH . '/' . $relativeDirectory);

        if (!$this->ensureStorageDirectory($storageDirectory)) {
            return Response::json([
                'error' => true,
                'message' => 'Media storage directory is not writable',
            ], 500);
        }

        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $filename = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
        $relativeFilePath = $relativeDirectory . '/' . $filename;
        $absoluteFilePath = base_path(self::STORAGE_PATH . '/' . $relativeFilePath);

        // Move uploaded file
        if (!move_uploaded_file((string) $file['tmp_name'], $absoluteFilePath)) {
            return Response::json([
                'error' => true,
                'message' => 'Failed to save file',
            ], 500);
        }

        // Get image dimensions
        $imageInfo = getimagesize($absoluteFilePath);
        $width = $imageInfo[0] ?? null;
        $height = $imageInfo[1] ?? null;

        // Insert into database
        $assetId = db('media_assets')->insert([
            'filename' => $relativeFilePath,
            'original_filename' => $file['name'],
            'mime_type' => $mimeType,
            'file_size' => (int) $file['size'],
            'width' => $width,
            'height' => $height,
            'alt_text' => $altText,
            'is_active' => 1,
        ]);

        if (!$assetId) {
            if (file_exists($absoluteFilePath)) {
                unlink($absoluteFilePath);
            }

            return Response::json([
                'error' => true,
                'message' => 'Failed to save file metadata',
            ], 500);
        }

        $asset = db('media_assets')
            ->where('id', (int) $assetId)
            ->select(['*'])
            ->first();

        return Response::json([
            'data' => $asset,
            'message' => 'File uploaded successfully',
        ], 201);
    }

    /**
     * POST /v1/admin/media/chunk/init
     * Start a chunk upload session.
     */
    public function chunkInit(Request $request): Response
    {
        if (!$this->canManageMedia($request)) {
            return Response::json([
                'success' => false,
                'error' => true,
                'message' => 'Forbidden',
                'errors' => ['permission' => ['insufficient_role']],
            ], 403);
        }

        $originalFilename = trim((string) $request->input('filename', ''));
        $mimeType = trim((string) $request->input('mime_type', ''));
        $totalSize = (int) $request->input('total_size', 0);
        $altText = (string) ($request->input('alt_text') ?? '');

        if ($originalFilename === '' || $mimeType === '' || $totalSize <= 0) {
            return Response::json([
                'error' => true,
                'message' => 'Ungueltige Upload-Daten.',
            ], 422);
        }

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return Response::json([
                'error' => true,
                'message' => 'Invalid file type. Allowed: JPEG, PNG, WebP, GIF',
                'field' => 'file',
            ], 422);
        }

        $maxFileSizeBytes = $this->readMediaMaxFileSizeBytes();
        if ($totalSize > $maxFileSizeBytes) {
            return Response::json([
                'error' => true,
                'message' => 'Datei zu gross. Erlaubt sind maximal ' . $this->formatBytesLabel($maxFileSizeBytes) . '.',
                'field' => 'file',
            ], 422);
        }

        $chunksDirectory = base_path(self::CHUNK_TEMP_PATH);
        if (!$this->ensureStorageDirectory($chunksDirectory)) {
            return Response::json([
                'error' => true,
                'message' => 'Upload temporary directory is not writable',
            ], 500);
        }

        $uploadId = bin2hex(random_bytes(16));
        $chunkFilePath = $this->chunkFilePath($uploadId);
        $metaFilePath = $this->chunkMetaPath($uploadId);

        if (file_put_contents($chunkFilePath, '') === false) {
            return Response::json([
                'error' => true,
                'message' => 'Upload session could not be created',
            ], 500);
        }

        $meta = [
            'upload_id' => $uploadId,
            'original_filename' => $originalFilename,
            'mime_type' => $mimeType,
            'total_size' => $totalSize,
            'received_size' => 0,
            'alt_text' => $altText,
            'created_at' => time(),
        ];

        if (!$this->writeChunkMeta($metaFilePath, $meta)) {
            @unlink($chunkFilePath);
            return Response::json([
                'error' => true,
                'message' => 'Upload session could not be persisted',
            ], 500);
        }

        return Response::json([
            'data' => [
                'upload_id' => $uploadId,
                'chunk_size_bytes' => self::CHUNK_SIZE_BYTES,
                'max_file_size_bytes' => $maxFileSizeBytes,
            ],
        ], 201);
    }

    /**
     * POST /v1/admin/media/chunk/{upload_id}
     * Append one binary chunk to an existing upload session.
     */
    public function chunkAppend(Request $request): Response
    {
        if (!$this->canManageMedia($request)) {
            return Response::json([
                'success' => false,
                'error' => true,
                'message' => 'Forbidden',
                'errors' => ['permission' => ['insufficient_role']],
            ], 403);
        }

        $uploadId = trim((string) $request->attribute('upload_id', ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            return Response::json([
                'error' => true,
                'message' => 'Invalid upload session',
            ], 422);
        }

        $metaFilePath = $this->chunkMetaPath($uploadId);
        $chunkFilePath = $this->chunkFilePath($uploadId);
        $meta = $this->readChunkMeta($metaFilePath);

        if ($meta === null || !is_file($chunkFilePath)) {
            return Response::json([
                'error' => true,
                'message' => 'Upload session not found',
            ], 404);
        }

        $rawChunk = $request->rawBody();
        $chunkSize = strlen($rawChunk);
        if ($chunkSize <= 0) {
            return Response::json([
                'error' => true,
                'message' => 'Empty chunk payload',
            ], 422);
        }

        if ($chunkSize > self::CHUNK_SIZE_BYTES) {
            return Response::json([
                'error' => true,
                'message' => 'Chunk too large. Max ' . $this->formatBytesLabel(self::CHUNK_SIZE_BYTES) . '.',
            ], 422);
        }

        $nextReceived = (int) ($meta['received_size'] ?? 0) + $chunkSize;
        $totalSize = (int) ($meta['total_size'] ?? 0);

        if ($nextReceived > $totalSize) {
            return Response::json([
                'error' => true,
                'message' => 'Chunk exceeds declared file size',
            ], 422);
        }

        if (file_put_contents($chunkFilePath, $rawChunk, FILE_APPEND) === false) {
            return Response::json([
                'error' => true,
                'message' => 'Chunk write failed',
            ], 500);
        }

        $meta['received_size'] = $nextReceived;
        if (!$this->writeChunkMeta($metaFilePath, $meta)) {
            return Response::json([
                'error' => true,
                'message' => 'Chunk state update failed',
            ], 500);
        }

        return Response::json([
            'data' => [
                'upload_id' => $uploadId,
                'received_size' => $nextReceived,
                'total_size' => $totalSize,
                'is_complete' => $nextReceived === $totalSize,
            ],
        ], 200);
    }

    /**
     * POST /v1/admin/media/chunk/{upload_id}/finish
     * Finalize chunk upload and create media asset.
     */
    public function chunkFinish(Request $request): Response
    {
        if (!$this->canManageMedia($request)) {
            return Response::json([
                'success' => false,
                'error' => true,
                'message' => 'Forbidden',
                'errors' => ['permission' => ['insufficient_role']],
            ], 403);
        }

        $uploadId = trim((string) $request->attribute('upload_id', ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            return Response::json([
                'error' => true,
                'message' => 'Invalid upload session',
            ], 422);
        }

        $metaFilePath = $this->chunkMetaPath($uploadId);
        $chunkFilePath = $this->chunkFilePath($uploadId);
        $meta = $this->readChunkMeta($metaFilePath);

        if ($meta === null || !is_file($chunkFilePath)) {
            return Response::json([
                'error' => true,
                'message' => 'Upload session not found',
            ], 404);
        }

        $declaredTotal = (int) ($meta['total_size'] ?? 0);
        $receivedTotal = (int) ($meta['received_size'] ?? 0);
        $actualSize = (int) (filesize($chunkFilePath) ?: 0);
        if ($declaredTotal <= 0 || $receivedTotal !== $declaredTotal || $actualSize !== $declaredTotal) {
            return Response::json([
                'error' => true,
                'message' => 'Upload ist noch nicht vollständig.',
            ], 422);
        }

        $detectedMime = $this->detectMimeType($chunkFilePath, '');
        if (!in_array($detectedMime, self::ALLOWED_MIME_TYPES, true)) {
            $this->cleanupChunkUpload($chunkFilePath, $metaFilePath);
            return Response::json([
                'error' => true,
                'message' => 'Invalid file type. Allowed: JPEG, PNG, WebP, GIF',
                'field' => 'file',
            ], 422);
        }

        $relativeDirectory = date('Y/m');
        $storageDirectory = base_path(self::STORAGE_PATH . '/' . $relativeDirectory);
        if (!$this->ensureStorageDirectory($storageDirectory)) {
            return Response::json([
                'error' => true,
                'message' => 'Media storage directory is not writable',
            ], 500);
        }

        $originalFilename = (string) ($meta['original_filename'] ?? 'upload.bin');
        $extension = $this->resolveImageExtension($originalFilename, $detectedMime);
        $filename = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
        $relativeFilePath = $relativeDirectory . '/' . $filename;
        $absoluteFilePath = base_path(self::STORAGE_PATH . '/' . $relativeFilePath);

        if (!rename($chunkFilePath, $absoluteFilePath)) {
            return Response::json([
                'error' => true,
                'message' => 'Failed to save file',
            ], 500);
        }

        $asset = $this->persistAssetRecord(
            $absoluteFilePath,
            $relativeFilePath,
            $originalFilename,
            $detectedMime,
            $actualSize,
            (string) ($meta['alt_text'] ?? '')
        );

        @unlink($metaFilePath);

        if ($asset === null) {
            if (file_exists($absoluteFilePath)) {
                @unlink($absoluteFilePath);
            }

            return Response::json([
                'error' => true,
                'message' => 'Failed to save file metadata',
            ], 500);
        }

        return Response::json([
            'data' => $asset,
            'message' => 'File uploaded successfully',
        ], 201);
    }

    /**
     * GET /v1/admin/media/{id}
     * Get a single media asset
     */
    public function show(Request $request): Response
    {
        if (!$this->canManageMedia($request)) {
            return Response::json([
                'success' => false,
                'error' => true,
                'message' => 'Forbidden',
                'errors' => ['permission' => ['insufficient_role']],
            ], 403);
        }

        $id = (int) $request->attribute('id', 0);

        $asset = db('media_assets')
            ->where('id', $id)
            ->select(['*'])
            ->first();

        if (!$asset) {
            return Response::json([
                'error' => true,
                'message' => 'Asset not found',
            ], 404);
        }

        return Response::json(['data' => $asset], 200);
    }

    /**
     * PATCH /v1/admin/media/{id}
     * Update media asset metadata
     */
    public function update(Request $request): Response
    {
        if (!$this->canManageMedia($request)) {
            return Response::json([
                'success' => false,
                'error' => true,
                'message' => 'Forbidden',
                'errors' => ['permission' => ['insufficient_role']],
            ], 403);
        }

        $id = (int) $request->attribute('id', 0);

        $asset = db('media_assets')
            ->where('id', $id)
            ->select(['*'])
            ->first();

        if (!$asset) {
            return Response::json([
                'error' => true,
                'message' => 'Asset not found',
            ], 404);
        }

        $updates = [];
        if ($request->input('alt_text') !== null) {
            $updates['alt_text'] = (string) $request->input('alt_text');
        }
        if ($request->input('description') !== null) {
            $updates['description'] = (string) $request->input('description');
        }
        if ($request->input('is_active') !== null) {
            $updates['is_active'] = (int) filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN);
        }

        if (empty($updates)) {
            return Response::json(['data' => $asset], 200);
        }

        db('media_assets')
            ->where('id', $id)
            ->update($updates);

        $updated = db('media_assets')
            ->where('id', $id)
            ->select(['*'])
            ->first();

        return Response::json(['data' => $updated], 200);
    }

    /**
     * DELETE /v1/admin/media/{id}
     * Delete a media asset
     */
    public function destroy(Request $request): Response
    {
        if (!$this->canManageMedia($request)) {
            return Response::json([
                'success' => false,
                'error' => true,
                'message' => 'Forbidden',
                'errors' => ['permission' => ['insufficient_role']],
            ], 403);
        }

        $id = (int) $request->attribute('id', 0);

        $asset = db('media_assets')
            ->where('id', $id)
            ->select(['*'])
            ->first();

        if (!$asset) {
            return Response::json([
                'error' => true,
                'message' => 'Asset not found',
            ], 404);
        }

        // Delete file from filesystem
        $filepath = base_path(self::STORAGE_PATH . '/' . ltrim((string) ($asset['filename'] ?? ''), '/'));
        if (file_exists($filepath)) {
            unlink($filepath);
        }

        // Delete from database (cascade will handle gallery_items and assignments)
        db('media_assets')
            ->where('id', $id)
            ->delete();

        return Response::json([
            'message' => 'Asset deleted successfully',
        ], 200);
    }

    private function canManageMedia(Request $request): bool
    {
        $roleMask = $this->getUserRoleMask($request);
        $manageMask = PermissionBits::resolve('manage_media', 4096);

        return ($roleMask & $manageMask) !== 0;
    }

    private function getUserRoleMask(Request $request): int
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = $session[$sessionKey] ?? null;

        if (!is_array($adminUser)) {
            return 0;
        }

        return (int) ($adminUser['role_mask'] ?? 0);
    }

    private function ensureStorageDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return is_writable($directory);
        }

        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }

        return is_writable($directory);
    }

    private function chunkFilePath(string $uploadId): string
    {
        return base_path(self::CHUNK_TEMP_PATH . '/' . $uploadId . '.part');
    }

    private function chunkMetaPath(string $uploadId): string
    {
        return base_path(self::CHUNK_TEMP_PATH . '/' . $uploadId . '.json');
    }

    /** @return array<string, mixed>|null */
    private function readChunkMeta(string $metaFilePath): ?array
    {
        if (!is_file($metaFilePath)) {
            return null;
        }

        $raw = file_get_contents($metaFilePath);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /** @param array<string, mixed> $meta */
    private function writeChunkMeta(string $metaFilePath, array $meta): bool
    {
        $encoded = json_encode($meta, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return false;
        }

        return file_put_contents($metaFilePath, $encoded) !== false;
    }

    private function cleanupChunkUpload(string $chunkFilePath, string $metaFilePath): void
    {
        if (is_file($chunkFilePath)) {
            @unlink($chunkFilePath);
        }
        if (is_file($metaFilePath)) {
            @unlink($metaFilePath);
        }
    }

    private function resolveImageExtension(string $originalFilename, string $mimeType): string
    {
        $extension = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
        if ($extension !== '') {
            return $extension;
        }

        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => '',
        };
    }

    /** @return array<string, mixed>|null */
    private function persistAssetRecord(
        string $absoluteFilePath,
        string $relativeFilePath,
        string $originalFilename,
        string $mimeType,
        int $fileSize,
        string $altText
    ): ?array {
        $imageInfo = getimagesize($absoluteFilePath);
        $width = $imageInfo[0] ?? null;
        $height = $imageInfo[1] ?? null;

        $assetId = db('media_assets')->insert([
            'filename' => $relativeFilePath,
            'original_filename' => $originalFilename,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'width' => $width,
            'height' => $height,
            'alt_text' => $altText,
            'is_active' => 1,
        ]);

        if (!$assetId) {
            return null;
        }

        $asset = db('media_assets')
            ->where('id', (int) $assetId)
            ->select(['*'])
            ->first();

        return is_array($asset) ? $asset : null;
    }

    private function effectiveMaxFileSizeBytes(): int
    {
        $appLimit = $this->readMediaMaxFileSizeBytes();
        $uploadIni = $this->iniSizeToBytes((string) ini_get('upload_max_filesize'));
        $postIni = $this->iniSizeToBytes((string) ini_get('post_max_size'));

        $limits = array_values(array_filter([$appLimit, $uploadIni, $postIni], static fn (int $v): bool => $v > 0));
        if ($limits === []) {
            return $appLimit;
        }

        return min($limits);
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

    private function iniSizeToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }

        if (!preg_match('/^(\d+)([KMG]?)$/i', $value, $m)) {
            return 0;
        }

        $size = (int) $m[1];
        $unit = strtoupper((string) ($m[2] ?? ''));

        return match ($unit) {
            'G' => $size * 1024 * 1024 * 1024,
            'M' => $size * 1024 * 1024,
            'K' => $size * 1024,
            default => $size,
        };
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

    private function detectMimeType(string $absolutePath, string $fallback = ''): string
    {
        $fallback = trim($fallback);

        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($absolutePath);
            if (is_string($detected) && trim($detected) !== '') {
                return trim($detected);
            }
        }

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = @finfo_file($finfo, $absolutePath);
                @finfo_close($finfo);
                if (is_string($detected) && trim($detected) !== '') {
                    return trim($detected);
                }
            }
        }

        if (function_exists('getimagesize')) {
            $imageInfo = @getimagesize($absolutePath);
            if (is_array($imageInfo) && isset($imageInfo['mime']) && is_string($imageInfo['mime']) && trim($imageInfo['mime']) !== '') {
                return trim($imageInfo['mime']);
            }
        }

        return $fallback;
    }
}
