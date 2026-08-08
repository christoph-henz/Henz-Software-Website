-- Migration: add form data module core tables
-- Date: 2026-06-28
-- Scope: S-001 (templates, versions, records, revisions, attachments, audit)
USE `henz_software_main`;
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

USE henz_software_logging;
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

DROP TRIGGER IF EXISTS form_templates_prevent_delete;
DROP TRIGGER IF EXISTS form_record_revisions_validate_sequence;
DROP TRIGGER IF EXISTS form_attachments_prevent_update;

DELIMITER $$

CREATE TRIGGER henz_software_main.form_templates_prevent_delete
BEFORE DELETE ON henz_software_main.form_templates
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Form templates cannot be hard-deleted due to version and record dependencies.';
END$$

CREATE TRIGGER henz_software_main.form_record_revisions_validate_sequence
BEFORE INSERT ON henz_software_main.form_record_revisions
FOR EACH ROW
BEGIN
    DECLARE expected_revision INT;

    SELECT COALESCE(MAX(revision_no), 0) + 1
      INTO expected_revision
      FROM form_record_revisions
     WHERE form_record_id = NEW.form_record_id;

    IF NEW.revision_no <> expected_revision THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'revision_no must be strictly sequential per form_record_id.';
    END IF;
END$$

CREATE TRIGGER henz_software_main.form_attachments_prevent_update
BEFORE UPDATE ON henz_software_main.form_attachments
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Form attachments are immutable and cannot be updated.';
END$$

DELIMITER ;
