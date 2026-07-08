<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Admin;

use App\Core\Http\Response;
use App\Core\Http\Request;
use App\Controllers\Api\BaseApiController;
use App\Support\PermissionBits;

class GalleryController extends BaseApiController
{
    /**
     * GET /v1/admin/galleries
     * List all galleries
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

        $query = db('media_galleries');

        if ($isActive !== null) {
            $query = $query->where('is_active', (int) filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
        }

        $total = $query->count();
        $offset = ($page - 1) * $perPage;

        $galleries = $query
            ->select(['*'])
            ->offset($offset)
            ->limit($perPage)
            ->orderBy('created_at', 'DESC')
            ->get();

        return Response::json([
            'data' => $galleries,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
            ],
        ], 200);
    }

    /**
     * POST /v1/admin/galleries
     * Create a new gallery
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

        $slug = (string) ($request->input('slug') ?? '');
        $title = (string) ($request->input('title') ?? '');
        $description = (string) ($request->input('description') ?? '');

        // Validate required fields
        $errors = [];
        if (empty($slug)) {
            $errors['slug'] = 'Slug is required';
        }
        if (empty($title)) {
            $errors['title'] = 'Title is required';
        }

        if (!empty($errors)) {
            return Response::json([
                'error' => true,
                'message' => 'Validation failed',
                'errors' => $errors,
            ], 422);
        }

        // Check for duplicate slug
        $exists = db('media_galleries')
            ->where('slug', $slug)
            ->count();

        if ($exists > 0) {
            return Response::json([
                'error' => true,
                'message' => 'Gallery with this slug already exists',
                'field' => 'slug',
            ], 422);
        }

        // Insert gallery
        db('media_galleries')->insert([
            'slug' => $slug,
            'title' => $title,
            'description' => $description,
            'is_active' => 1,
        ]);

        $gallery = db('media_galleries')
            ->where('slug', $slug)
            ->select(['*'])
            ->first();

        return Response::json([
            'data' => $gallery,
            'message' => 'Gallery created successfully',
        ], 201);
    }

    /**
     * GET /v1/admin/galleries/{id}
     * Get gallery with items
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

        $gallery = db('media_galleries')
            ->where('id', $id)
            ->select(['*'])
            ->first();

        if (!$gallery) {
            return Response::json([
                'error' => true,
                'message' => 'Gallery not found',
            ], 404);
        }

        // Get gallery items with asset details
        $items = db('media_gallery_items')
            ->where('gallery_id', $id)
            ->join('media_assets', 'media_gallery_items.asset_id', '=', 'media_assets.id')
            ->select('media_gallery_items.*', 'media_assets.filename', 'media_assets.mime_type', 'media_assets.width', 'media_assets.height', 'media_assets.alt_text')
            ->orderBy('media_gallery_items.sort_order', 'ASC')
            ->get();

        return Response::json([
            'data' => array_merge($gallery, ['items' => $items]),
        ], 200);
    }

    /**
     * PATCH /v1/admin/galleries/{id}
     * Update gallery metadata
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

        $gallery = db('media_galleries')
            ->where('id', $id)
            ->select(['*'])
            ->first();

        if (!$gallery) {
            return Response::json([
                'error' => true,
                'message' => 'Gallery not found',
            ], 404);
        }

        $updates = [];
        if ($request->has('title')) {
            $updates['title'] = (string) $request->input('title');
        }
        if ($request->has('description')) {
            $updates['description'] = (string) $request->input('description');
        }
        if ($request->has('is_active')) {
            $updates['is_active'] = (int) filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN);
        }

        if (empty($updates)) {
            return Response::json(['data' => $gallery], 200);
        }

        db('media_galleries')
            ->where('id', $id)
            ->update($updates);

        $updated = db('media_galleries')
            ->where('id', $id)
            ->select(['*'])
            ->first();

        return Response::json(['data' => $updated], 200);
    }

    /**
     * DELETE /v1/admin/galleries/{id}
     * Delete gallery (cascade deletes items)
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

        $gallery = db('media_galleries')
            ->where('id', $id)
            ->select(['*'])
            ->first();

        if (!$gallery) {
            return Response::json([
                'error' => true,
                'message' => 'Gallery not found',
            ], 404);
        }

        // Delete gallery items (cascade)
        db('media_gallery_items')
            ->where('gallery_id', $id)
            ->delete();

        // Delete gallery
        db('media_galleries')
            ->where('id', $id)
            ->delete();

        return Response::json([
            'message' => 'Gallery deleted successfully',
        ], 200);
    }

    /**
     * POST /v1/admin/galleries/{id}/items
     * Add asset to gallery
     */
    public function addItem(Request $request): Response
    {
        if (!$this->canManageMedia($request)) {
            return Response::json([
                'success' => false,
                'error' => true,
                'message' => 'Forbidden',
                'errors' => ['permission' => ['insufficient_role']],
            ], 403);
        }

        $galleryId = (int) $request->attribute('id', 0);
        $assetId = (int) ($request->input('asset_id') ?? 0);

        // Validate gallery exists
        $gallery = db('media_galleries')
            ->where('id', $galleryId)
            ->count();

        if ($gallery === 0) {
            return Response::json([
                'error' => true,
                'message' => 'Gallery not found',
            ], 404);
        }

        // Validate asset exists
        $asset = db('media_assets')
            ->where('id', $assetId)
            ->count();

        if ($asset === 0) {
            return Response::json([
                'error' => true,
                'message' => 'Asset not found',
            ], 404);
        }

        // Check if already in gallery
        $exists = db('media_gallery_items')
            ->where('gallery_id', $galleryId)
            ->where('asset_id', $assetId)
            ->count();

        if ($exists > 0) {
            return Response::json([
                'error' => true,
                'message' => 'Asset already in this gallery',
            ], 409);
        }

        // Get max sort order
        $maxSort = db('media_gallery_items')
            ->where('gallery_id', $galleryId)
            ->max('sort_order');

        db('media_gallery_items')->insert([
            'gallery_id' => $galleryId,
            'asset_id' => $assetId,
            'sort_order' => ($maxSort ?? 0) + 1,
        ]);

        $item = db('media_gallery_items')
            ->where('gallery_id', $galleryId)
            ->where('asset_id', $assetId)
            ->select(['*'])
            ->first();

        return Response::json([
            'data' => $item,
            'message' => 'Asset added to gallery',
        ], 201);
    }

    /**
     * PATCH /v1/admin/galleries/{id}/items/{item_id}
     * Update gallery item sort order
     */
    public function updateItem(Request $request): Response
    {
        if (!$this->canManageMedia($request)) {
            return Response::json([
                'success' => false,
                'error' => true,
                'message' => 'Forbidden',
                'errors' => ['permission' => ['insufficient_role']],
            ], 403);
        }

        $galleryId = (int) $request->attribute('id', 0);
        $itemId = (int) $request->attribute('item_id', 0);
        $sortOrder = (int) ($request->input('sort_order') ?? 0);

        $item = db('media_gallery_items')
            ->where('id', $itemId)
            ->where('gallery_id', $galleryId)
            ->select(['*'])
            ->first();

        if (!$item) {
            return Response::json([
                'error' => true,
                'message' => 'Item not found',
            ], 404);
        }

        db('media_gallery_items')
            ->where('id', $itemId)
            ->update(['sort_order' => $sortOrder]);

        $updated = db('media_gallery_items')
            ->where('id', $itemId)
            ->select(['*'])
            ->first();

        return Response::json(['data' => $updated], 200);
    }

    /**
     * DELETE /v1/admin/galleries/{id}/items/{item_id}
     * Remove asset from gallery
     */
    public function removeItem(Request $request): Response
    {
        if (!$this->canManageMedia($request)) {
            return Response::json([
                'success' => false,
                'error' => true,
                'message' => 'Forbidden',
                'errors' => ['permission' => ['insufficient_role']],
            ], 403);
        }

        $galleryId = (int) $request->attribute('id', 0);
        $itemId = (int) $request->attribute('item_id', 0);

        $item = db('media_gallery_items')
            ->where('id', $itemId)
            ->where('gallery_id', $galleryId)
            ->select(['*'])
            ->first();

        if (!$item) {
            return Response::json([
                'error' => true,
                'message' => 'Item not found',
            ], 404);
        }

        db('media_gallery_items')
            ->where('id', $itemId)
            ->delete();

        return Response::json([
            'message' => 'Item removed from gallery',
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
}
