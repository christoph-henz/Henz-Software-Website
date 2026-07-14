<?php

declare(strict_types=1);

/**
 * Admin sidebar navigation.
 *
 * Expected in scope (inherited from admin-layout.php):
 *   $adminUser  (array)  – session user, must contain 'role_mask' (int)
 *
 * Reads nav config from public/ui/_config/admin-navigation.php.
 * Filters items by permission_bit against $adminUser['role_mask'].
 * Marks the active item using the current request path.
 */

$_navConfig = require base_path('public/ui/_config/operations/admin-navigation.php');

$_currentPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$_roleMask    = (int) ($adminUser['role_mask'] ?? 0);

if (!function_exists('_adminNavIsActive')) {
    function _adminNavIsActive(array $item, string $path): bool
    {
        $href    = (string) ($item['href'] ?? '/');
        $exact   = (bool)   ($item['exact'] ?? false);
        $patterns = (array) ($item['match_patterns'] ?? []);

        if ($exact) {
            if ($path === $href) {
                return true;
            }
        } else {
            if ($href !== '/' && str_starts_with($path, $href)) {
                return true;
            }
            if ($href === '/' && $path === '/') {
                return true;
            }
        }

        foreach ($patterns as $pattern) {
            if (str_starts_with($path, (string) $pattern)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('_adminIcon')) {
    function _adminIcon(string $name): string
    {
        static $cache = [];

        if (!isset($cache[$name])) {
            $file = base_path('public/ui/_assets/images/admin-icons/' . $name . '.svg');
            $cache[$name] = is_file($file) ? ((string) file_get_contents($file)) : '';
        }

        return $cache[$name];
    }
}

if (!function_exists('_adminRenderNavItems')) {
    function _adminRenderNavItems(array $items, string $currentPath, int $roleMask, int $depth = 0): void
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $requiredBit = (int) ($item['permission_bit'] ?? 0);
            if ($requiredBit !== 0 && ($roleMask & $requiredBit) === 0) {
                continue;
            }

            $label = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $hrefRaw = (string) ($item['href'] ?? '/');
            $href = htmlspecialchars($hrefRaw, ENT_QUOTES, 'UTF-8');
            $icon = (string) ($item['icon'] ?? '');
            $isActive = _adminNavIsActive($item, $currentPath);
            $children = $item['children'] ?? [];

            $visibleChildren = [];
            if (is_array($children)) {
                foreach ($children as $child) {
                    if (!is_array($child)) {
                        continue;
                    }

                    $childRequiredBit = (int) ($child['permission_bit'] ?? 0);
                    if ($childRequiredBit !== 0 && ($roleMask & $childRequiredBit) === 0) {
                        continue;
                    }

                    $visibleChildren[] = $child;
                }
            }

            $hasChildren = $visibleChildren !== [];

            if ($hasChildren) {
                foreach ($visibleChildren as $child) {
                    if (_adminNavIsActive($child, $currentPath)) {
                        $isActive = true;
                        break;
                    }
                }
            }

            $itemClasses = 'admin-sidebar-nav-item' . ($isActive ? ' is-active' : '');
            if ($depth > 0) {
                $itemClasses .= ' is-child';
            }

            if ($hasChildren) {
                $groupClasses = 'admin-sidebar-nav-group' . ($isActive ? ' is-expanded' : '');
                echo '<div class="' . $groupClasses . '">';
                echo '<button type="button" class="' . $itemClasses . ' admin-sidebar-nav-toggle" role="listitem" aria-expanded="' . ($isActive ? 'true' : 'false') . '">';
                echo '<span class="admin-sidebar-icon" aria-hidden="true">' . _adminIcon($icon) . '</span>';
                echo '<span>' . $label . '</span>';
                echo '<span class="admin-sidebar-caret" aria-hidden="true">';
                echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>';
                echo '</span>';
                echo '</button>';
                echo '<div class="admin-sidebar-nav-children" role="list">';
                _adminRenderNavItems($visibleChildren, $currentPath, $roleMask, $depth + 1);
                echo '</div>';
                echo '</div>';
                continue;
            }

            echo '<a class="' . $itemClasses . '" href="' . $href . '" role="listitem"' . ($isActive ? ' aria-current="page"' : '') . '>';
            echo '<span class="admin-sidebar-icon" aria-hidden="true">' . _adminIcon($icon) . '</span>';
            echo '<span>' . $label . '</span>';

            if ($hrefRaw === '/requests') {
                echo '<span class="admin-badge admin-badge--warning admin-sidebar-item-badge" id="adminSidebarRequestsBadge" hidden aria-hidden="true">!</span>';
            } elseif ($hrefRaw === '/bookings') {
                echo '<span class="admin-badge admin-badge--warning admin-sidebar-item-badge" id="adminSidebarBookingsBadge" hidden aria-hidden="true">!</span>';
            }

            echo '</a>';
        }
    }
}

?>
<nav class="admin-sidebar" id="adminSidebar" aria-label="Hauptnavigation">

    <a href="/dashboard" class="admin-sidebar-brand" tabindex="0">
        <div>
            <span class="admin-sidebar-brand-label"><?= htmlspecialchars(env('APP_NAME', 'Henz Software'), ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="admin-sidebar-brand-sub">Operations</span>
        </div>
    </a>

    <div class="admin-sidebar-nav" role="list">
        <?php _adminRenderNavItems($_navConfig, $_currentPath, $_roleMask); ?>
    </div>

</nav>
