<?php

declare(strict_types=1);

/**
 * Admin settings page configuration.
 *
 * Defines display metadata for the settings form.
 * The actual settings values and types are loaded from the database;
 * this config provides human-readable labels, input hints, and
 * the canonical group order / labels for rendering.
 *
 * group_order  – defines the render order of group sections.
 * group_labels – German display names for each group key.
 * field_meta   – per-key overrides: label, input_type, placeholder, min, max.
 *                If a key is missing here the DB `description` column is used as label.
 */

return [

    'hidden_keys' => [
        'appointment_advance_days',
    ],

    'group_order' => ['general', 'clients', 'notifications', 'payment'],

    'group_labels' => [
        'general'       => 'Allgemein',
        'clients'       => 'Klienten',
        'notifications' => 'Benachrichtigungen',
        'payment'       => 'Zahlung',
    ],

    'field_meta' => [
        // ── general ──────────────────────────────────────────────────────────
        'site_name' => [
            'label'      => 'Website-Name',
            'input_type' => 'text',
            'placeholder' => 'z.B. Henz Software',
        ],
        'contact_email' => [
            'label'      => 'Kontakt-E-Mail',
            'input_type' => 'email',
            'placeholder' => 'kontakt@example.com',
        ],
        'contact_phone' => [
            'label'      => 'Telefonnummer',
            'input_type' => 'tel',
            'placeholder' => '+49 …',
        ],

        // ── booking ───────────────────────────────────────────────────────────
        'booking_enabled' => [
            'label'      => 'Online-Buchung aktiviert',
            'input_type' => 'checkbox',
        ],
        'booking_slot_interval_minutes' => [
            'label'      => 'Slot-Intervall (Minuten)',
            'input_type' => 'number',
            'min'        => 15,
            'max'        => 120,
        ],
        'media_max_file_size' => [
            'label'      => 'Maximale Medien-Dateigrösse (MB)',
            'input_type' => 'number',
            'min'        => 1,
            'max'        => 5120,
        ],
        'booking_cancellation_hours' => [
            'label'      => 'Stornofrist (Stunden vor Termin)',
            'input_type' => 'number',
            'min'        => 0,
            'max'        => 720,
        ],
        'booking_min_fill_seconds' => [
            'label'      => 'Mindest-Ausfüllzeit Formular (Sekunden)',
            'input_type' => 'number',
            'min'        => 0,
            'max'        => 300,
        ],
        'booking_duplicate_window_minutes' => [
            'label'      => 'Duplikate-Fenster (Minuten)',
            'input_type' => 'number',
            'min'        => 0,
            'max'        => 1440,
        ],

        // ── notifications ─────────────────────────────────────────────────────
        'notification_admin_email' => [
            'label'      => 'Admin-Benachrichtigungs-E-Mail',
            'input_type' => 'email',
            'placeholder' => 'admin@example.com',
        ],
        'notification_reply_to' => [
            'label'      => 'Reply-To-Adresse für E-Mails',
            'input_type' => 'email',
            'placeholder' => 'noreply@example.com',
        ],

        // ── payment ───────────────────────────────────────────────────────────
        'bank_transfer_enabled' => [
            'label'      => 'Banküberweisung aktiviert',
            'input_type' => 'checkbox',
        ],
        'bank_transfer_account_holder' => [
            'label'      => 'Kontoinhaber',
            'input_type' => 'text',
            'placeholder' => 'z.B. Henz Software',
        ],
        'bank_transfer_iban' => [
            'label'      => 'IBAN',
            'input_type' => 'text',
            'placeholder' => 'DE00 0000 0000 0000 0000 00',
        ],
        'bank_transfer_bic' => [
            'label'      => 'BIC',
            'input_type' => 'text',
            'placeholder' => 'GENODEF1XXX',
        ],
        'bank_transfer_bank_name' => [
            'label'      => 'Bankname',
            'input_type' => 'text',
            'placeholder' => 'Name der Bank',
        ],
        'bank_transfer_reference' => [
            'label'      => 'Standard-Verwendungszweck',
            'input_type' => 'text',
            'placeholder' => 'z.B. Rechnung #1234',
        ],
        'paypal_enabled' => [
            'label'      => 'PayPal aktiviert',
            'input_type' => 'checkbox',
        ],
        'paypal_email' => [
            'label'      => 'PayPal-E-Mail-Adresse',
            'input_type' => 'email',
            'placeholder' => 'paypal@example.com',
        ],
    ],

];
