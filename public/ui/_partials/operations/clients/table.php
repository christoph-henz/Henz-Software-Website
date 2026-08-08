<?php declare(strict_types=1);

$viewMode = is_array($clientsConfig ?? null) ? (string) ($clientsConfig['view_mode'] ?? 'list') : 'list';
$title = $viewMode === 'tickets' ? 'Ticket-Liste' : 'Client-Liste';
$subtitle = $viewMode === 'tickets'
    ? 'Alle Tickets mit direktem Sprung in die zugehörige Klientenansicht.'
    : 'Sortieren, suchen und Details inkl. aktiver Pakete einsehen.';
?>
<section class="admin-clients-section">
    <div class="admin-clients-head">
        <h2 class="admin-clients-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="admin-clients-subtitle"><?php echo htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

    <div id="adminClientsRoot"></div>
</section>
