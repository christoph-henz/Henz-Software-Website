<?php

declare(strict_types=1);

/**
 * Admin sidebar navigation config.
 *
 * Each entry:
 *   label           – displayed text
 *   href            – target URL
 *   exact           – if true: active only on exact path match; otherwise prefix match
 *   icon            – filename (without .svg) inside public/ui/_assets/images/admin-icons/
 *   permission_bit  – required bit(s) in role_mask; check is ($roleMask & $bit) !== 0
 *                     Use OR-combined bits (e.g. 4|8 = 12) to allow any of several permissions.
 *                     0 = every logged-in admin.
 *   match_patterns  – optional array of additional URL prefixes that also activate this item
 *                     (e.g. detail pages under a section)
 *
 * Permission bits used here must match the actual values in the `permissions` table.
 * The bit values are the raw integers stored in role_mask (bitwise AND check).
 */

return [
    [
        'label' => 'Dashboard',
        'href' => '/dashboard',
        'exact' => true,
        'icon' => 'home',
        'permission_bit' => 0, // every logged-in admin
        'match_patterns' => ['/dashboard', '/calender'],
    ],
    [
        'label' => 'Klientenverwaltung',
        'href' => '/clients',
        'exact' => true,
        'icon' => 'users',
        'permission_bit' => 0, // every logged-in admin
        'children' => [
            [
                'label' => 'Clientverwaltung',
                'href' => '/clients',
                'exact' => false,
                'icon' => 'users',
                'permission_bit' => 24, // view_clients (8) | manage_clients (16)
                'match_patterns' => [],
            ],
            [
                'label' => 'Kalender',
                'href' => '/calendar',
                'exact' => false,
                'icon' => 'calendar',
                'permission_bit' => 0, // every logged-in admin
                'match_patterns' => [],
            ],
            [
                'label' => 'Termine',
                'href' => '/appointments',
                'exact' => false,
                'icon' => 'calendar',
                'permission_bit' => 3, // view_bookings (1) | manage_bookings (2)
                'match_patterns' => [],
            ],
        ],
        'match_patterns' => [],
    ],
    [
        'label' => 'Medienverwaltung',
        'href' => '/media',
        'exact' => false,
        'icon' => 'inbox',
        'permission_bit' => 3, // view_media (1) | manage_media (2)
        'children' => [
            [
                'label' => 'Formulare',
                'href' => '/session-templates',
                'exact' => false,
                'icon' => 'inbox',
                'permission_bit' => 1, // manage_form_templates
                'match_patterns' => [],
            ],
            [
                'label' => 'Bilderverwaltung',
                'href' => '/images',
                'exact' => false,
                'icon' => 'images',
                'permission_bit' => 4096, // manage_media
                'match_patterns' => [],
            ],
            [
                'label' => 'E-Mail-Vorlagen',
                'href' => '/email-templates',
                'exact' => false,
                'icon' => 'inbox',
                'permission_bit' => 3072, // manage_settings (1024) | manage_admin_settings (2048)
                'match_patterns' => [],
            ],
        ],
        'match_patterns' => [],
    ],
    [
        'label' => 'Gewerbeverwaltung',
        'href' => '/admin',
        'exact' => false,
        'icon' => 'factory',
        'permission_bit' => 3, // view_media (1) | manage_media (2)
        'children' => [
            [
                'label' => 'Leistungen',
                'href' => '/services',
                'exact' => false,
                'icon' => 'power',
                'permission_bit' => 1, // manage_services
                'match_patterns' => [],
            ],
            [
                'label' => 'Finanzverwaltung',
                'href' => '/finance',
                'exact' => false,
                'icon' => 'finance',
                'permission_bit' => 1, // manage_finance
                'match_patterns' => [],
            ],
            [
                'label' => 'Projekte',
                'href' => '/projects',
                'exact' => false,
                'icon' => 'calendar',
                'permission_bit' => 3, // view_bookings (1) | manage_bookings (2)
                'match_patterns' => [],
            ],
        ],
        'match_patterns' => [],
    ],
    [
        'label' => 'Seitenverwaltung',
        'href' => '/admin',
        'exact' => false,
        'icon' => 'users',
        'permission_bit' => 3, // view_media (1) | manage_media (2)
        'children' => [
            [
                'label' => 'Mitarbeiterverwaltung',
                'href' => '/users',
                'exact' => false,
                'icon' => 'users',
                'permission_bit' => 256, // manage_users
                'match_patterns' => [],
            ],
            [
                'label' => 'Einstellungen',
                'href' => '/settings',
                'exact' => false,
                'icon' => 'settings',
                'permission_bit' => 3072, // manage_settings (1024) | manage_admin_settings (2048)
                'match_patterns' => [],
            ],
        ],
        'match_patterns' => [],
    ],
];
