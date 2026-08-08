SET @appointments_client_fk := (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'appointments'
      AND COLUMN_NAME = 'client_id'
      AND REFERENCED_TABLE_NAME = 'clients'
    LIMIT 1
);

SET @drop_appointments_client_fk_sql := IF(
    @appointments_client_fk IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE appointments DROP FOREIGN KEY ', @appointments_client_fk)
);

PREPARE drop_appointments_client_fk_stmt FROM @drop_appointments_client_fk_sql;
EXECUTE drop_appointments_client_fk_stmt;
DEALLOCATE PREPARE drop_appointments_client_fk_stmt;

ALTER TABLE appointments
    MODIFY client_id INT NULL;

ALTER TABLE appointments
    ADD CONSTRAINT fk_appointments_client
        FOREIGN KEY (client_id) REFERENCES clients(id)
        ON DELETE SET NULL ON UPDATE CASCADE;

SET @has_prospect_name := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'appointments'
      AND column_name = 'prospect_name'
);

SET @add_prospect_name_sql := IF(
    @has_prospect_name = 0,
    'ALTER TABLE appointments ADD COLUMN prospect_name VARCHAR(255) NULL AFTER client_id',
    'SELECT 1'
);

PREPARE add_prospect_name_stmt FROM @add_prospect_name_sql;
EXECUTE add_prospect_name_stmt;
DEALLOCATE PREPARE add_prospect_name_stmt;

SET @has_prospect_email := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'appointments'
      AND column_name = 'prospect_email'
);

SET @add_prospect_email_sql := IF(
    @has_prospect_email = 0,
    'ALTER TABLE appointments ADD COLUMN prospect_email VARCHAR(190) NULL AFTER prospect_name',
    'SELECT 1'
);

PREPARE add_prospect_email_stmt FROM @add_prospect_email_sql;
EXECUTE add_prospect_email_stmt;
DEALLOCATE PREPARE add_prospect_email_stmt;

SET @has_origin := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'appointments'
      AND column_name = 'origin'
);

SET @add_origin_sql := IF(
    @has_origin = 0,
    'ALTER TABLE appointments ADD COLUMN origin VARCHAR(50) NULL AFTER notes',
    'SELECT 1'
);

PREPARE add_origin_stmt FROM @add_origin_sql;
EXECUTE add_origin_stmt;
DEALLOCATE PREPARE add_origin_stmt;

SET @consents_client_request_fk := (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'consents'
      AND COLUMN_NAME = 'client_request_id'
      AND REFERENCED_TABLE_NAME IS NOT NULL
    LIMIT 1
);

SET @drop_consents_client_request_fk_sql := IF(
    @consents_client_request_fk IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE consents DROP FOREIGN KEY ', @consents_client_request_fk)
);

PREPARE drop_consents_client_request_fk_stmt FROM @drop_consents_client_request_fk_sql;
EXECUTE drop_consents_client_request_fk_stmt;
DEALLOCATE PREPARE drop_consents_client_request_fk_stmt;

ALTER TABLE consents
    ADD CONSTRAINT fk_consents_client_request_appointment
        FOREIGN KEY (client_request_id) REFERENCES appointments(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;

SET @has_consent_audit_table := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'consent_audit_log'
);

SET @consent_audit_fk := (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'consent_audit_log'
      AND COLUMN_NAME = 'client_request_id'
      AND REFERENCED_TABLE_NAME IS NOT NULL
    LIMIT 1
);

SET @drop_consent_audit_fk_sql := IF(
    @has_consent_audit_table = 0 OR @consent_audit_fk IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE consent_audit_log DROP FOREIGN KEY ', @consent_audit_fk)
);

PREPARE drop_consent_audit_fk_stmt FROM @drop_consent_audit_fk_sql;
EXECUTE drop_consent_audit_fk_stmt;
DEALLOCATE PREPARE drop_consent_audit_fk_stmt;

SET @add_consent_audit_fk_sql := IF(
    @has_consent_audit_table = 0,
    'SELECT 1',
    'ALTER TABLE consent_audit_log ADD CONSTRAINT fk_consent_audit_client_request_appointment FOREIGN KEY (client_request_id) REFERENCES appointments(id) ON DELETE CASCADE ON UPDATE CASCADE'
);

PREPARE add_consent_audit_fk_stmt FROM @add_consent_audit_fk_sql;
EXECUTE add_consent_audit_fk_stmt;
DEALLOCATE PREPARE add_consent_audit_fk_stmt;

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
