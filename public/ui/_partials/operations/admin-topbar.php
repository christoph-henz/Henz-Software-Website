<?php

declare(strict_types=1);

/**
 * Admin topbar.
 *
 * Expected in scope (inherited from admin-layout.php):
 *   $adminUser   (array)  – session user with first_name, last_name, email
 *   $csrfToken   (string) – CSRF token for logout form
 *   $logoutAction(string) – form action URL for logout
 */

$_displayName = trim(
    ((string) ($adminUser['first_name'] ?? '')) . ' ' . ((string) ($adminUser['last_name'] ?? ''))
);

if ($_displayName === '') {
    $_displayName = (string) ($adminUser['email'] ?? 'Administrator');
}

?>
<header class="admin-topbar">
    <div class="admin-topbar-left">
        <button type="button"
                class="admin-menu-toggle"
                id="adminMenuToggle"
                aria-label="Navigation öffnen"
                aria-controls="adminSidebar"
                aria-expanded="false">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
                <line x1="3" y1="6" x2="21" y2="6"/>
                <line x1="3" y1="12" x2="21" y2="12"/>
                <line x1="3" y1="18" x2="21" y2="18"/>
            </svg>
            <span class="admin-badge admin-menu-badge admin-badge--warning" id="adminMenuBadge" hidden aria-hidden="true"></span>
        </button>
    </div>

    <div class="admin-topbar-right">
        <span class="admin-topbar-user"><?= htmlspecialchars($_displayName, ENT_QUOTES, 'UTF-8'); ?></span>

        <form method="post"
              action="<?= htmlspecialchars($logoutAction, ENT_QUOTES, 'UTF-8'); ?>"
              style="margin: 0;">
            <input type="hidden"
                   name="_token"
                   value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
            <button type="submit" class="admin-logout-btn">Abmelden</button>
        </form>
    </div>
</header>
