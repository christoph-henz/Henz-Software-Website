USE henz_software_main;

INSERT INTO users (first_name, last_name, email, password_hash, role_mask, is_active)
VALUES ('System', 'Administrator', 'webmaster@henz-software.com', '$2y$10$placeholder', 8191, TRUE);

INSERT INTO permissions (name, slug, bit_value, description) VALUES
('View Appointments', 'view_appointments', 1, 'Can view all appointment records'),
('Manage Appointments', 'manage_appointments', 2, 'Can create, update, and delete appointments'),
('Storno Appointment Status', 'storno_appointment', 4, 'Can storno appointment'),
('View Clients', 'view_clients', 8, 'Can view client information'),
('Manage Clients', 'manage_clients', 16, 'Can create and update client records'),
('View Payments', 'view_payments', 32, 'Can view payment records'),
('Manage Payments', 'manage_payments', 64, 'Can record and manage payments'),
('View Analytics', 'view_analytics', 128, 'Can view dashboard analytics'),
('Manage Users', 'manage_users', 256, 'Can manage admin users and roles'),
('View Settings', 'view_settings', 512, 'Can view system settings'),
('Manage Settings', 'manage_settings', 1024, 'Can edit operational settings'),
('Manage Media', 'manage_media', 2048, 'Can upload and manage media assets and galleries'),
('Manage Services', 'manage_services', 4096, 'Can manage services and service packages');

INSERT INTO settings (`key`, value, type, `group`, description, is_public) VALUES
('site_name', 'Henz Software', 'string', 'general', 'Displayed site name', TRUE),
('contact_email', 'info@henz-software.com', 'string', 'general', 'Main contact email', TRUE),
('support_email', 'support@henz-software.com', 'string', 'general', 'Support contact', TRUE),
('contact_phone', '', 'string', 'general', 'Contact phone', TRUE),
('appointments_enabled', '1', 'boolean', 'booking', 'Enable booking form', TRUE),
('appointments_advance_days', '60', 'integer', 'booking', 'Days ahead clients can book', FALSE),
('appointments_min_hours_notice', '24', 'integer', 'booking', 'Minimum hours notice', FALSE),
('appointments_day_start_hour', '9', 'integer', 'booking', 'Day start hour', FALSE),
('appointments_day_end_hour', '18', 'integer', 'booking', 'Day end hour', FALSE),
('media_max_file_size', '5', 'integer', 'booking', 'Max media file size MB', FALSE),
('appointments_min_fill_seconds', '5', 'integer', 'booking', 'Minimum form fill seconds', FALSE),
('cancellation_hours_notice', '48', 'integer', 'booking', 'Hours notice for cancellation', FALSE),
('reminder_hours_before', '24', 'integer', 'notifications', 'Hours before appointment for reminder', FALSE),
('auto_confirmation_enabled', '1', 'boolean', 'notifications', 'Automatic booking confirmation', FALSE),
('bank_transfer_enabled', '1', 'boolean', 'payment', 'Accept bank transfer', FALSE),
('paypal_enabled', '0', 'boolean', 'payment', 'Accept PayPal', FALSE),
('paypal_email', '', 'string', 'payment', 'PayPal account email', FALSE),
('package_expiration_months', '8', 'integer', 'booking', 'Default package validity in months', FALSE);

INSERT INTO services(name,description,icon_path,slug,cta_url,price_min) VALUES 
("Individual Software","Maßgeschneiderte Anwendungen, von Grund auf neu entwickelt. Wir konzipieren Systeme, die mit Ihrem Unternehmen wachsen.","html_tag.svg","core","/services",2500),
("Plattformentwicklung","Cloud-native Infrastruktur und DevOps-Pipelines, die schneller liefern und seltener ausfallen.","stack.svg","infra","/services",2500),
("Leistungsoptimierung","Wir analysieren und bewerten die Engpässe, die das Wachstumspotenzial Ihres Produkts begrenzen, und gestalten diese neu.","power.svg","ops","/services",2500),
("Sicherheit & Compliance","Auf Sicherheit ausgerichtete Entwicklungszyklen, Penetrationstests und Rahmenwerke zur Einhaltung regulatorischer Vorgaben.","shield.svg","sec","/services",2500),
("API & Integrationen","Verbindung Ihres Stacks mit Drittanbieterdiensten, internen Tools und Partner-Ökosystemen.","connect.svg","api","/services",2500),
("Web- und Mobilprodukte","Pixelgenaue Benutzeroberflächen, gestützt durch robuste Mobile-First-Entwicklung über alle Plattformen hinweg.","globe.svg","ui","/services",2500);

USE henz_software_main;
INSERT INTO referenced_projects(slug,title,description,project_image_path,project_slug,project_url) VALUES
("Restaurant Dionysos","Restaurantwebsite","Landingpage mit relevanten Informationen und Speisekarte","Dionysos-Website-1.mp4","project-dionysos-website","www.dionysos-aburg.de"),
("Villa Athina","Hotelmanagementsystem","Entwicklung eines webbasierten Hotelmanagementsystems zur Verwaltung von Buchungen, Zimmern, Gästen und administrativen Abläufen. Das System automatisiert zentrale Geschäftsprozesse und ermöglicht eine effiziente Verwaltung des Hotelbetriebs.","Villa-Athina-Website.png","project-villa-athina-website","www.villa-athina.eu"),
("Restaurant Parga","Restaurantmanagementsystem","Entwicklung eines webbasierten Restaurantmanagementsystems zur Verwaltung von Bestellungen, Speisekarten, Reservierungen und Benutzern. Das System umfasst zudem ein Administrationspanel sowie eine REST-API zur Anbindung externer Anwendungen.","Parga-Management.png","project-parga-management",NULL);

-- ============================================================================
-- Triggers (target-state)
-- ============================================================================

DROP TRIGGER IF EXISTS consents_prevent_update;
DROP TRIGGER IF EXISTS consents_prevent_delete;
DROP TRIGGER IF EXISTS consents_audit_insert;
DROP TRIGGER IF EXISTS trg_bookings_auto_confirm_on_paid_before_update;
DROP TRIGGER IF EXISTS trg_bookings_auto_confirm_on_paid_after_update;

DELIMITER $$

CREATE TRIGGER consents_prevent_update BEFORE UPDATE ON consents
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Consent records are immutable (append-only model).';
END$$

CREATE TRIGGER consents_prevent_delete BEFORE DELETE ON consents
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Consent records cannot be deleted (append-only model).';
END$$

USE henz_software_logging;

CREATE TRIGGER consents_audit_insert AFTER INSERT ON consents
FOR EACH ROW
BEGIN
    INSERT INTO consent_audit_log (
        consent_id,
        booking_id,
        client_request_id,
        action,
        new_signature_hash,
        attempted_by
    ) VALUES (
        NEW.id,
        NEW.booking_id,
        NEW.client_request_id,
        'created',
        NEW.signature_hash,
        NEW.ip_address
    );
END$$