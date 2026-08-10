USE henz_software_main;

-- ============================================================================
-- 1) System
-- ============================================================================

CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(150) NOT NULL UNIQUE,
    value TEXT,
    type ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string' COMMENT 'Data type hint for casting',
    `group` VARCHAR(100) DEFAULT 'general' COMMENT 'Logical grouping for admin UI display',
    description VARCHAR(255),
    is_public BOOLEAN DEFAULT FALSE COMMENT 'Exposed to frontend or API',
    min_permission_sum INT NOT NULL DEFAULT 1024 COMMENT 'Requires minimum permission bit sum to edit',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_key (`key`),
    INDEX idx_group (`group`),
    INDEX idx_is_public (is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions: All available admin permissions with bit-masking values
CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    slug VARCHAR(100) NOT NULL UNIQUE,
    bit_value INT NOT NULL UNIQUE COMMENT 'Power of 2 for bit-masking (1, 2, 4, 8, 16, 32, ...)',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_bit_value (bit_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    role_mask INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    last_login_at DATETIME,
    deleted_at TIMESTAMP DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_token (token),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

-- ============================================================================
-- 2) Clients / Requests
-- ============================================================================

CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name TEXT NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone TEXT,
    address TEXT,
    created_by INT,
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    updated_by INT,
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);


CREATE TABLE IF NOT EXISTS email_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(120) NOT NULL,
    display_name VARCHAR(180) NOT NULL,
    subject_template VARCHAR(255) NOT NULL,
    html_template LONGTEXT NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_templates_template_key (template_key),
    INDEX idx_email_templates_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(150) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_form_templates_template_key (template_key),
    INDEX idx_form_templates_is_active (is_active),
    INDEX idx_form_templates_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_template_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    version_no INT NOT NULL,
    schema_json JSON NOT NULL,
    published_at DATETIME NULL,
    created_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_form_template_versions_template
        FOREIGN KEY (template_id) REFERENCES form_templates(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_form_template_versions_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_form_template_versions_template_version (template_id, version_no),
    INDEX idx_form_template_versions_template_id (template_id),
    INDEX idx_form_template_versions_published_at (published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    template_id INT NOT NULL,
    template_version_id INT NOT NULL,
    status ENUM('draft', 'final') NOT NULL DEFAULT 'draft',
    encrypted_payload_json JSON NULL,
    created_by_user_id INT NULL,
    updated_by_user_id INT NULL,
    finalized_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    -- CONSTRAINT fk_form_records_booking
    --     FOREIGN KEY (booking_id) REFERENCES bookings(id)
    --     ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_form_records_client
        FOREIGN KEY (client_id) REFERENCES clients(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_form_records_template
        FOREIGN KEY (template_id) REFERENCES form_templates(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_form_records_template_version
        FOREIGN KEY (template_version_id) REFERENCES form_template_versions(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_form_records_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_form_records_updated_by
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    -- INDEX idx_form_records_booking_id (booking_id),
    INDEX idx_form_records_client_id (client_id),
    INDEX idx_form_records_template_id (template_id),
    INDEX idx_form_records_template_version_id (template_version_id),
    INDEX idx_form_records_status (status),
    INDEX idx_form_records_created_at (created_at),
    INDEX idx_form_records_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_record_revisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_record_id INT NOT NULL,
    revision_no INT NOT NULL,
    payload_json_snapshot JSON NULL,
    changed_fields_json JSON NULL,
    change_reason TEXT NOT NULL,
    changed_by_user_id INT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_form_record_revisions_form_record
        FOREIGN KEY (form_record_id) REFERENCES form_records(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_form_record_revisions_changed_by
        FOREIGN KEY (changed_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_form_record_revisions_record_revision (form_record_id, revision_no),
    INDEX idx_form_record_revisions_record_changed_at (form_record_id, changed_at),
    INDEX idx_form_record_revisions_changed_by (changed_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_record_id INT NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_form_attachments_form_record
        FOREIGN KEY (form_record_id) REFERENCES form_records(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    UNIQUE KEY uq_form_attachments_checksum (checksum_sha256),
    INDEX idx_form_attachments_record_id (form_record_id),
    INDEX idx_form_attachments_uploaded_at (uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `client_id` INT NOT NULL,
    `price_quote` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `price_quote_ongoing` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `final_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `final_price_ongoing` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `status` ENUM('pending', 'backlog', 'in_progress', 'review', 'completed', 'on_hold', 'cancelled') NOT NULL DEFAULT 'pending',
    `progress` INT NOT NULL DEFAULT 0,
    `system_tests_finished` BOOLEAN NOT NULL DEFAULT FALSE,
    `tested_by` INT NULL,
    `test_date` DATE NULL,
    `test_template` INT NULL,
    `test_data` JSON NULL,
    `due_date` DATE NOT NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
    `deleted_at` TIMESTAMP NULL,
    `created_by` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`client_id`) REFERENCES clients(id),
    FOREIGN KEY (`test_template`) REFERENCES form_templates(id),
    FOREIGN KEY (`created_by`) REFERENCES users(id),
    INDEX idx_project_id (`id`),
    INDEX idx_client_id (`client_id`)
);

CREATE TABLE IF NOT EXISTS project_phase (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `phase_name` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `status` ENUM('pending', 'in_progress', 'review', 'completed', 'on_hold', 'cancelled') NOT NULL DEFAULT 'pending',
    `progress` INT NOT NULL DEFAULT 0,
    `integration_tests_finished` BOOLEAN NOT NULL DEFAULT FALSE,
    `tested_by` INT NULL,
    `test_date` DATE NULL,
    `test_template` INT NULL,
    `test_data` JSON NULL,
    `due_date` DATE NOT NULL,
    `deleted_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`project_id`) REFERENCES projects(id),
    FOREIGN KEY (`test_template`) REFERENCES form_templates(id),
    INDEX idx_project_phase_id (`id`),
    INDEX idx_project_id (`project_id`)
);

CREATE TABLE IF NOT EXISTS project_members (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `role` ENUM('owner', 'manager', 'developer', 'designer', 'tester') NOT NULL DEFAULT 'developer',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`project_id`) REFERENCES projects(id),
    FOREIGN KEY (`user_id`) REFERENCES users(id),
    INDEX idx_project_member_id (`id`),
    INDEX idx_project_id (`project_id`),
    INDEX idx_user_id (`user_id`)
);

-- ============================================================================
-- 3) Services / Invoices
-- ============================================================================

CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    icon_path VARCHAR(100),
    slug VARCHAR(100) NOT NULL UNIQUE,
    cta_url VARCHAR(255),
    price_min INT UNSIGNED,
    duration_minutes INT UNSIGNED NOT NULL DEFAULT 60,
    structure JSON NOT NULL,
    data JSON NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    is_deleted BOOLEAN NOT NULL DEFAULT FALSE,
    created_by INT,
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    updated_by INT,
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    project_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE,
    terms TEXT,
    document_path VARCHAR(500) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT '1',
    created_by INT,
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    updated_by INT,
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number INT NOT NULL,
    client_id INT NOT NULL,
    project_id INT NOT NULL,
    contract_id INT NULL,
    currency_code CHAR(3) NOT NULL DEFAULT 'EUR',
    sub_total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    has_vat BOOLEAN NOT NULL DEFAULT FALSE,
    vat_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(40) NOT NULL DEFAULT 'created',
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    sent_at DATETIME NULL,
    created_by_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invoices_invoice_number (invoice_number),
    UNIQUE KEY uq_invoices_contract_id (contract_id),
    KEY idx_invoices_client_id (client_id),
    KEY idx_invoices_status (status),
    CONSTRAINT fk_invoices_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoices_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoices_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoices_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    item_type VARCHAR(40) NOT NULL DEFAULT 'additional',
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_invoice_items_invoice_id (invoice_id),
    CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_number_sequences (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    next_invoice_number INT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reminders: Scheduled appointment reminder jobs
CREATE TABLE IF NOT EXISTS reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    reminder_time INT NOT NULL COMMENT 'Hours before appointment',
    scheduled_for DATETIME NOT NULL,
    status ENUM('pending', 'sent', 'failed', 'cancelled') DEFAULT 'pending',
    sent_at DATETIME,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_contract_id (contract_id),
    INDEX idx_status (status),
    INDEX idx_scheduled_for (scheduled_for)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4) Communication / Consents
-- ============================================================================

CREATE TABLE IF NOT EXISTS appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NULL,
    service_id INT NOT NULL,
    appointment_date DATETIME NOT NULL,
    duration_minutes INT UNSIGNED NOT NULL DEFAULT 60,
    status ENUM('pending','accepted','declined','completed','storno') NOT NULL DEFAULT 'pending',
    prospect_name VARCHAR(255) NULL,
    prospect_email VARCHAR(190) NULL,
    notes TEXT,
    origin VARCHAR(50) NULL,
    created_by INT,
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    updated_by INT,
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL,
    FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NULL,
    ticket_type VARCHAR(40) NOT NULL DEFAULT 'support',
    category VARCHAR(100) NOT NULL DEFAULT 'general',
    priority VARCHAR(20) NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    payload_json JSON NULL,
    source VARCHAR(50) NOT NULL DEFAULT 'contact_form',
    status ENUM('new','open','in_progress','resolved','closed') NOT NULL DEFAULT 'new',
    assigned_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tickets_client
        FOREIGN KEY (client_id) REFERENCES clients(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_tickets_assigned_user
        FOREIGN KEY (assigned_user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_tickets_client_id (client_id),
    INDEX idx_tickets_ticket_type_status (ticket_type, status),
    INDEX idx_tickets_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE availability_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_key VARCHAR(100) NOT NULL,
    rule_value VARCHAR(255) NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_availability_rules_rule_key (rule_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recurring_availability (
    id INT AUTO_INCREMENT PRIMARY KEY,
    day_of_week TINYINT NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_recurring_availability_day (day_of_week),
    INDEX idx_recurring_availability_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blocked_times (
    id INT AUTO_INCREMENT PRIMARY KEY,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    reason TEXT NULL,
    created_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_blocked_times_starts_at (starts_at),
    INDEX idx_blocked_times_ends_at (ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NULL,
    client_request_id INT NULL,
    consent_key VARCHAR(100) NOT NULL,
    accepted BOOLEAN NOT NULL,
    accepted_at DATETIME NOT NULL,
    consent_version VARCHAR(50) NOT NULL,
    consent_text_snapshot TEXT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(512) NOT NULL,
    device_name VARCHAR(255) NOT NULL DEFAULT 'unknown',
    browser_user_name VARCHAR(255) NOT NULL DEFAULT 'unknown',
    signature_hash VARCHAR(255) NOT NULL,
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (client_request_id) REFERENCES appointments(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_contract_id (contract_id),
    INDEX idx_client_request_id (client_request_id),
    INDEX idx_consent_key (consent_key),
    INDEX idx_accepted_at (accepted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5) Media / CMS
-- ============================================================================

CREATE TABLE IF NOT EXISTS media_assets (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT NOT NULL,
    width INT NULL,
    height INT NULL,
    alt_text TEXT NULL,
    description TEXT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_is_active (is_active),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_galleries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_gallery_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    gallery_id BIGINT NOT NULL,
    asset_id BIGINT NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_gallery_asset (gallery_id, asset_id),
    FOREIGN KEY (gallery_id) REFERENCES media_galleries(id) ON DELETE CASCADE,
    FOREIGN KEY (asset_id) REFERENCES media_assets(id) ON DELETE CASCADE,
    INDEX idx_gallery_id (gallery_id),
    INDEX idx_sort_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_media_assignments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    page_key VARCHAR(100) NOT NULL,
    section_key VARCHAR(100) NOT NULL DEFAULT 'default',
    slot_key VARCHAR(100) NOT NULL,
    asset_id BIGINT NULL,
    gallery_id BIGINT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_assignment (page_key, section_key, slot_key, asset_id, gallery_id),
    FOREIGN KEY (asset_id) REFERENCES media_assets(id) ON DELETE CASCADE,
    FOREIGN KEY (gallery_id) REFERENCES media_galleries(id) ON DELETE CASCADE,
    INDEX idx_page_key (page_key),
    INDEX idx_slot_key (slot_key),
    INDEX idx_section_key (section_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referenced_projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    project_image_path VARCHAR(255),
    project_slug VARCHAR(100),
    structure JSON,
    data JSON,
    project_url VARCHAR(255),
    sort_order INT NOT NULL DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

USE henz_software_logging;

CREATE TABLE IF NOT EXISTS logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(255) NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES henz_software_main.users (id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS consent_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consent_id INT NULL,
    contract_id INT NULL,
    client_request_id INT NULL,
    action ENUM('created', 'update_attempted', 'delete_attempted') NOT NULL,
    old_signature_hash VARCHAR(255) NULL,
    new_signature_hash VARCHAR(255) NULL,
    attempted_by VARCHAR(255) NULL,
    error_message TEXT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contract_id) REFERENCES henz_software_main.contracts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (client_request_id) REFERENCES henz_software_main.appointments(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_contract_id (contract_id),
    INDEX idx_client_request_id (client_request_id),
    INDEX idx_consent_id (consent_id),
    INDEX idx_action (action),
    INDEX idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_access_audit (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NULL,
    action ENUM('view', 'create', 'update', 'export', 'delete') NOT NULL,
    resource_type VARCHAR(100) NOT NULL,
    resource_id VARCHAR(100) NOT NULL,
    field_scope VARCHAR(255) NULL,
    purpose_code VARCHAR(100) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_data_access_audit_actor
        FOREIGN KEY (actor_user_id) REFERENCES henz_software_main.users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_data_access_audit_actor_user_id (actor_user_id),
    INDEX idx_data_access_audit_action (action),
    INDEX idx_data_access_audit_resource (resource_type, resource_id),
    INDEX idx_data_access_audit_created_at (created_at),
    INDEX idx_data_access_audit_purpose_code (purpose_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;