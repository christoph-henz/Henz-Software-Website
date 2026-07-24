USE henz_software_main;
-- ============================================================================
-- Reset Development Database
-- Clears all transaction, booking, client, and session record data while
-- keeping schema and form templates intact
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- Clear booking-related tables (in dependency order)
TRUNCATE TABLE invoice_items;
TRUNCATE TABLE invoices;
TRUNCATE TABLE invoice_number_sequences;
TRUNCATE TABLE contracts;

TRUNCATE TABLE project_members;
TRUNCATE TABLE project_phase;
TRUNCATE TABLE projects;

-- Clear log tables
USE henz_software_logging;
TRUNCATE TABLE consent_audit_log;
TRUNCATE TABLE data_access_audit;
-- TRUNCATE TABLE email_logs;

USE henz_software_main;
TRUNCATE TABLE consents;

-- Clear client and request tables
TRUNCATE TABLE appointments;
TRUNCATE TABLE clients;

-- Clear communication logs and reminder queue/history

TRUNCATE TABLE reminders;

-- Clear form documentation data (keep form templates)
TRUNCATE TABLE form_attachments;
TRUNCATE TABLE form_record_revisions;
TRUNCATE TABLE form_records;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Re-seed invoice number sequence baseline
INSERT INTO invoice_number_sequences (id, next_invoice_number)
VALUES (1, 20260001)
ON DUPLICATE KEY UPDATE
	next_invoice_number = VALUES(next_invoice_number);

-- Verify: Show row counts
SELECT 'invoices', COUNT(*) FROM invoices
UNION ALL
SELECT 'invoice_items', COUNT(*) FROM invoice_items
UNION ALL
SELECT 'invoice_number_sequences', COUNT(*) FROM invoice_number_sequences
UNION ALL
SELECT 'clients', COUNT(*) FROM clients
UNION ALL
SELECT 'appointments', COUNT(*) FROM appointments
UNION ALL
SELECT 'consents', COUNT(*) FROM consents
UNION ALL
SELECT 'consent_audit_log', COUNT(*) FROM henz_software_logging.consent_audit_log
UNION ALL
SELECT 'data_access_audit', COUNT(*) FROM henz_software_logging.data_access_audit
UNION ALL
-- SELECT 'email_logs', COUNT(*) FROM henz_software_logging.email_logs
-- UNION ALL
SELECT 'reminders', COUNT(*) FROM reminders
UNION ALL
SELECT 'form_records', COUNT(*) FROM form_records
UNION ALL
SELECT 'form_record_revisions', COUNT(*) FROM form_record_revisions
UNION ALL
SELECT 'form_attachments', COUNT(*) FROM form_attachments
UNION ALL
SELECT 'form_templates (preserved)', COUNT(*) FROM form_templates
UNION ALL
SELECT 'form_template_versions (preserved)', COUNT(*) FROM form_template_versions;
