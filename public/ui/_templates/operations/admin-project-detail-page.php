<?php

declare(strict_types=1);

$pageTitle   = (string) ($pageTitle ?? 'Projektdetails');
$adminUser   = is_array($adminUser ?? null) ? $adminUser : [];
$logoutAction = (string) ($logoutAction ?? '/admin/logout');
$csrfToken   = (string) ($csrfToken ?? '');
$projectDetailConfig = is_array($projectDetailConfig ?? null) ? $projectDetailConfig : [];

$extraHead = '';
$extraScripts = '<script>window.__ADMIN_PROJECT_DETAIL_CONFIG = ' . json_encode(
    $projectDetailConfig,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) . ';</script>';
$extraScripts .= '<script src="/ui/_assets/js/admin-project-detail.js" defer></script>';

ob_start();
?>
<div class="admin-page-header admin-project-header">
    <div>
        <h1 class="admin-page-title">Projektdetails</h1>
        <p class="admin-page-subtitle">Projektphasen und Teammitglieder anlegen, einsehen und verwalten.</p>
    </div>
    <a href="/projects" class="admin-users-action-btn admin-project-detail-back" id="projectBackToList">Zurueck zur Liste</a>
</div>

<section class="admin-users-section" id="adminProjectDetailRoot" data-project-id="<?= (int) ($projectDetailConfig['project_id'] ?? 0); ?>">
    <div class="admin-users-toolbar admin-project-detail-toolbar">
        <strong id="projectDetailTitle" class="admin-project-detail-title">Projekt wird geladen...</strong>
        <span class="admin-project-detail-id">ID: <?= (int) ($projectDetailConfig['project_id'] ?? 0); ?></span>
    </div>

    <div class="admin-project-detail-summary">
        <div id="projectDetailMeta" class="admin-project-meta-grid">Lade Projektdaten...</div>
    </div>

    <div class="admin-project-detail-block admin-project-detail-block--phases">
        <h2 class="admin-project-detail-heading">Projektphasen</h2>

        <form id="createPhaseForm" class="admin-users-field admin-project-detail-form admin-project-detail-form--phases">
            <div>
                <label class="admin-users-label" for="phaseName">Phasenname *</label>
                <input id="phaseName" class="admin-users-input" type="text" required />
            </div>
            <div>
                <label class="admin-users-label" for="phaseDueDate">Faelligkeit</label>
                <input id="phaseDueDate" class="admin-users-input" type="date" />
            </div>
            <div>
                <label class="admin-users-label" for="phaseStatus">Status</label>
                <select id="phaseStatus" class="admin-users-input">
                    <option value="pending">Pending</option>
                    <option value="in_progress">In Progress</option>
                    <option value="review">Review</option>
                    <option value="completed">Completed</option>
                    <option value="on_hold">On Hold</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <div>
                <label class="admin-users-label" for="phaseProgress">Fortschritt (%)</label>
                <input id="phaseProgress" class="admin-users-input" type="number" min="0" max="100" value="0" />
            </div>
            <div>
                <button type="submit" class="admin-users-create-btn admin-project-detail-submit">Phase hinzufuegen</button>
            </div>
        </form>
        <div id="phaseAlert" class="admin-users-alert" style="display:none"></div>
        <div id="projectPhasesContainer" class="admin-project-detail-table-wrap">Keine Phasen vorhanden.</div>
    </div>

    <div class="admin-project-detail-block">
        <h2 class="admin-project-detail-heading">Projektmitglieder</h2>

        <form id="createMemberForm" class="admin-users-field admin-project-detail-form admin-project-detail-form--members">
            <div>
                <label class="admin-users-label" for="memberUserId">User *</label>
                <select id="memberUserId" class="admin-users-input" required>
                    <option value="">User auswaehlen</option>
                </select>
            </div>
            <div>
                <label class="admin-users-label" for="memberRole">Rolle</label>
                <select id="memberRole" class="admin-users-input">
                    <option value="owner">Owner</option>
                    <option value="manager">Manager</option>
                    <option value="developer" selected>Developer</option>
                    <option value="designer">Designer</option>
                    <option value="tester">Tester</option>
                </select>
            </div>
            <div>
                <button type="submit" class="admin-users-create-btn admin-project-detail-submit">Mitglied hinzufuegen</button>
            </div>
        </form>
        <div id="memberAlert" class="admin-users-alert" style="display:none"></div>
        <div id="projectMembersContainer" class="admin-project-detail-table-wrap">Keine Mitglieder vorhanden.</div>
    </div>
</section>
<?php
$content = ob_get_clean();

require __DIR__ . '/../../_partials/operations/admin-layout.php';
