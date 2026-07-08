<?php

declare(strict_types=1);

/**
 * Admin notification banner.
 *
 * Priority order:
 *  1. Session flash ($_SESSION['admin_flash']) – survives a redirect, shown once then cleared.
 *  2. Direct template variable $notification    – set by the controller for same-request renders.
 *
 * Expected format (both sources):
 *   ['type' => 'error|warning|success|info', 'message' => 'Text to display']
 *
 * To set a flash notification from a controller (before redirecting):
 *   admin_flash('success', 'Änderungen gespeichert.');
 */

$_flash = null;

if (session_status() === PHP_SESSION_ACTIVE
    && isset($_SESSION['admin_flash'])
    && is_array($_SESSION['admin_flash'])
) {
    $_flash = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);
}

$_activeNote = $_flash
    ?? (isset($notification) && is_array($notification) ? $notification : null);

if ($_activeNote !== null):
    $_allowedTypes = ['error', 'warning', 'success', 'info'];
    $_noteType     = in_array($_activeNote['type'] ?? '', $_allowedTypes, true)
        ? $_activeNote['type']
        : 'info';
    $_noteMsg      = (string) ($_activeNote['message'] ?? '');
    $_noteTypeJs   = json_encode($_noteType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $_noteMsgJs    = json_encode($_noteMsg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($_noteTypeJs)) {
        $_noteTypeJs = '"info"';
    }

    if (!is_string($_noteMsgJs)) {
        $_noteMsgJs = '""';
    }
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.adminShowNotification === 'function') {
        window.adminShowNotification(<?= $_noteTypeJs; ?>, <?= $_noteMsgJs; ?>);
    }
});
</script>
<?php endif; ?>
