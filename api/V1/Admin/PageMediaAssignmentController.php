<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Admin;

use App\Core\Http\Response;
use App\Core\Http\Request;
use App\Controllers\Api\BaseApiController;
use App\Support\PermissionBits;

class PageMediaAssignmentController extends BaseApiController
{
    /**
     * GET /v1/admin/pages/{page_key}/media
     * List all media assignments for a page
     */
    public function indexByPage(Request $request): Response
    {
        if (!$this->canManageMedia($request)) {
            return Response::json([
                'success' => false,
                'error' => true,
                'message' => 'Forbidden',
                'errors' => ['permission' => ['insufficient_role']],
            ], 403);
        }

        $pageKey = (string) $request->attribute('page_key', '');

        $assignments = db('page_media_assignments')
            ->where('page_key', $pageKey)
            ->select(['*'])
            ->orderBy('sort_order', 'ASC')
            ->get();

        // Enhance with asset/gallery details
        $enhanced = array_map(function ($assignment) {
            if ($assignment['asset_id']) {
                $asset = db('media_assets')
                    ->where('id', $assignment['asset_id'])
                    ->select(['*'])
                    ->first();
                $assignment['asset'] = $asset;
            }
            if ($assignment['gallery_id']) {
                $gallery = db('media_galleries')
                    ->where('id', $assignment['gallery_id'])
                    ->select(['*'])
                    ->first();
                $assignment['gallery'] = $gallery;
            }
            return $assignment;
        }, $assignments);

        return Response::json(['data' => $enhanced], 200);
    }

    /**
     * GET /v1/admin/pages/{page_key}/media/{slot_key}
     * List media assignments for specific page slot
     */
    public function indexBySlot(Request $request): Response
    {
        if (!$this->canManageMedia($request)) {
            return Response::json([
                'success' => false,
                'error' => true,
                'message' => 'Forbidden',
                'errors' => ['permission' => ['insufficient_role']],
            ], 403);
        }

        $pageKey = (string) $request->attribute('page_key', '');
        $slotKey = (string) $request->attribute('slot_key', '');
        $sectionKey = trim((string) $request->query('section_key', ''));

        $query = db('page_media_assignments')
            ->where('page_key', $pageKey)
            ->where('slot_key', $slotKey)
            ->select(['*'])
            ->orderBy('sort_order', 'ASC');

        if ($sectionKey !== '') {
            $query = $query->where('section_key', $sectionKey);
        }

        $assignments = $query
            ->select(['*'])
            ->get();

        // Enhance with asset/gallery details
        $enhanced = array_map(function ($assignment) {
            if ($assignment['asset_id']) {
                $asset = db('media_assets')
                    ->where('id', $assignment['asset_id'])
                    ->select(['*'])
                    ->first();
                $assignment['asset'] = $asset;
            }
            if ($assignment['gallery_id']) {
                $gallery = db('media_galleries')
                    ->where('id', $assignment['gallery_id'])
                    ->select(['*'])
                    ->first();
                $assignment['gallery'] = $gallery;
            }
            return $assignment;
        }, $assignments);

        return Response::json(['data' => $enhanced], 200);
    }

    /**
     * POST /v1/admin/pages/{page_key}/media
     * Create new media assignment
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

        $pageKey = (string) $request->attribute('page_key', '');
        $sectionKey = (string) ($request->input('section_key') ?? '');
        $slotKey = (string) ($request->input('slot_key') ?? '');
        $assetId = $request->input('asset_id') ? (int) $request->input('asset_id') : null;
        $galleryId = $request->input('gallery_id') ? (int) $request->input('gallery_id') : null;

        // Validation
        $errors = [];
        if (empty($sectionKey)) {
            $errors['section_key'] = 'Section is required';
        }
        if (empty($slotKey)) {
            $errors['slot_key'] = 'Slot is required';
        }
        if (!$assetId && !$galleryId) {
            $errors['media'] = 'Either asset_id or gallery_id is required';
        }
        if ($assetId && $galleryId) {
            $errors['media'] = 'Cannot assign both asset and gallery to same slot';
        }

        if (!empty($errors)) {
            return Response::json([
                'error' => true,
                'message' => 'Validation failed',
                'errors' => $errors,
            ], 422);
        }

        // Validate asset/gallery exists
        if ($assetId) {
            $assetExists = db('media_assets')
                ->where('id', $assetId)
                ->count();

            if ($assetExists === 0) {
                return Response::json([
                    'error' => true,
                    'message' => 'Asset not found',
                ], 404);
            }
        }

        if ($galleryId) {
            $galleryExists = db('media_galleries')
                ->where('id', $galleryId)
                ->count();

            if ($galleryExists === 0) {
                return Response::json([
                    'error' => true,
                    'message' => 'Gallery not found',
                ], 404);
            }
        }

        // A slot should only have one active assignment. Re-assigning replaces the previous record.
        $existingAssignments = db('page_media_assignments')
            ->where('page_key', $pageKey)
            ->where('section_key', $sectionKey)
            ->where('slot_key', $slotKey)
            ->select(['id'])
            ->orderBy('id', 'ASC')
            ->get();

        if (is_array($existingAssignments) && count($existingAssignments) > 0) {
            $primaryId = (int) ($existingAssignments[0]['id'] ?? 0);
            if ($primaryId <= 0) {
                return Response::json([
                    'error' => true,
                    'message' => 'Assignment could not be updated',
                ], 500);
            }

            db('page_media_assignments')
                ->where('id', $primaryId)
                ->update([
                    'asset_id' => $assetId,
                    'gallery_id' => $galleryId,
                    'sort_order' => 1,
                ]);

            // Clean up historical duplicates for the same slot to prevent stale assets rendering.
            for ($i = 1; $i < count($existingAssignments); $i++) {
                $duplicateId = (int) ($existingAssignments[$i]['id'] ?? 0);
                if ($duplicateId > 0) {
                    db('page_media_assignments')
                        ->where('id', $duplicateId)
                        ->delete();
                }
            }

            $assignment = db('page_media_assignments')
                ->where('id', $primaryId)
                ->select(['*'])
                ->first();

            return Response::json([
                'data' => $assignment,
                'message' => 'Media assignment replaced',
            ], 200);
        }

        $assignmentId = db('page_media_assignments')->insert([
            'page_key' => $pageKey,
            'section_key' => $sectionKey,
            'slot_key' => $slotKey,
            'asset_id' => $assetId,
            'gallery_id' => $galleryId,
            'sort_order' => 1,
        ]);

        $assignment = db('page_media_assignments')
            ->where('id', (int) $assignmentId)
            ->select(['*'])
            ->first();

        return Response::json([
            'data' => $assignment,
            'message' => 'Media assigned to page',
        ], 201);
    }

    /**
     * GET /v1/admin/pages/{page_key}/media/{id}
     * Get single assignment
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

        $pageKey = (string) $request->attribute('page_key', '');
        $id = (int) $request->attribute('id', 0);

        $assignment = db('page_media_assignments')
            ->where('id', $id)
            ->where('page_key', $pageKey)
            ->select(['*'])
            ->first();

        if (!$assignment) {
            return Response::json([
                'error' => true,
                'message' => 'Assignment not found',
            ], 404);
        }

        // Enhance with asset/gallery details
        if ($assignment['asset_id']) {
            $asset = db('media_assets')
                ->where('id', $assignment['asset_id'])
                ->select(['*'])
                ->first();
            $assignment['asset'] = $asset;
        }
        if ($assignment['gallery_id']) {
            $gallery = db('media_galleries')
                ->where('id', $assignment['gallery_id'])
                ->select(['*'])
                ->first();
            $assignment['gallery'] = $gallery;
        }

        return Response::json(['data' => $assignment], 200);
    }

    /**
     * PATCH /v1/admin/pages/{page_key}/media/{id}
     * Update assignment (sort_order)
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

        $pageKey = (string) $request->attribute('page_key', '');
        $id = (int) $request->attribute('id', 0);

        $assignment = db('page_media_assignments')
            ->where('id', $id)
            ->where('page_key', $pageKey)
            ->select(['*'])
            ->first();

        if (!$assignment) {
            return Response::json([
                'error' => true,
                'message' => 'Assignment not found',
            ], 404);
        }

        $updates = [];
        if ($request->has('sort_order')) {
            $updates['sort_order'] = (int) $request->input('sort_order');
        }

        if (empty($updates)) {
            return Response::json(['data' => $assignment], 200);
        }

        db('page_media_assignments')
            ->where('id', $id)
            ->update($updates);

        $updated = db('page_media_assignments')
            ->where('id', $id)
            ->select(['*'])
            ->first();

        return Response::json(['data' => $updated], 200);
    }

    /**
     * DELETE /v1/admin/pages/{page_key}/media/{id}
     * Remove media assignment from page
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

        $pageKey = (string) $request->attribute('page_key', '');
        $id = (int) $request->attribute('id', 0);

        $assignment = db('page_media_assignments')
            ->where('id', $id)
            ->where('page_key', $pageKey)
            ->select(['*'])
            ->first();

        if (!$assignment) {
            return Response::json([
                'error' => true,
                'message' => 'Assignment not found',
            ], 404);
        }

        db('page_media_assignments')
            ->where('id', $id)
            ->delete();

        return Response::json([
            'message' => 'Assignment removed',
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
