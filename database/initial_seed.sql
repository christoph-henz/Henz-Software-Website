USE henz_software_main;

INSERT INTO users (first_name, last_name, email, password_hash, role_mask, is_active)
VALUES ('System', 'Administrator', 'webmaster@henz-software.de', '$2y$10$placeholder', 8191, TRUE);

INSERT INTO henz_software_main.permissions (name, slug, bit_value, description) VALUES
('View Appointments', 'view_appointments', 1, 'Can view all appointment records'),
('Manage Appointments', 'manage_appointments', 2, 'Can create, update, and delete appointments'),
('Storno Appointment Status', 'storno_appointment', 4, 'Can storno appointment'),
('Delete Appointment', 'delete_appointment', 8, 'Can delete appointment'),
('View Analytics', 'view_analytics', 16, 'Can view dashboard analytics'),

('View Projects', 'view_projects', 32, 'Can view projects'),
('Manage Projects', 'manage_projects', 64, 'Can manage projects'),
('Delete Projects', 'delete_projects', 128, 'Can delete projects'),

('Manage Openings', 'manage_openings', 256, 'Can manage opening hours and availability'),
('Manage Holidays', 'manage_holidays', 512, 'Can manage holidays and special dates'),

('View Services', 'view_services', 1024, 'Can view service records'),
('Manage Services', 'manage_services', 2048, 'Can create and update service records'),
('Delete Services', 'delete_services', 4096, 'Can delete service records'),

('View Media', 'view_media', 8192, 'Can view media assets and galleries'),
('Manage Media', 'manage_media', 16384, 'Can upload and manage media assets and galleries'),

('View Clients', 'view_clients', 32768, 'Can view client information'),
('Manage Clients', 'manage_clients', 65536, 'Can create and update client records'),
('Delete Clients', 'delete_clients', 131072, 'Can delete client records'),

('View Payments', 'view_payments', 262144, 'Can view payment records'),
('Manage Payments', 'manage_payments', 524288, 'Can record and manage payments'),

('View Users', 'view_users', 1048576, 'Can view users as workers'),
('Manage Users', 'manage_users', 2097152, 'Can manage admin users and roles'),

('View Finances', 'view_finances', 4194304, 'Can view financial records'),
('Manage Finances', 'manage_finances', 8388608, 'Can manage financial records'),

('View Settings', 'view_settings', 16777216, 'Can view system settings'),
('Manage Settings', 'manage_settings', 33554432, 'Can edit operational settings');



INSERT INTO settings (`key`, value, type, `group`, description, is_public) VALUES
('site_name', 'Henz Software', 'string', 'general', 'Displayed site name', TRUE),
('contact_email', 'info@henz-software.de', 'string', 'general', 'Main contact email', TRUE),
('support_email', 'support@henz-software.de', 'string', 'general', 'Support contact', TRUE),
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
('bank_data_name', 'Detusche Kredit Bank', 'string', 'payment', 'Bankname', FALSE),
('bank_data_iban', 'DE20 1203 0000 1035 7553 94', 'string', 'payment', 'IBAN', FALSE),
('bank_data_bic', 'BYLADEM1001', 'string', 'payment', 'BIC', FALSE),
('paypal_enabled', '0', 'boolean', 'payment', 'Accept PayPal', FALSE),
('paypal_email', '', 'string', 'payment', 'PayPal account email', FALSE),
('package_expiration_months', '8', 'integer', 'booking', 'Default package validity in months', FALSE),
('19_ust_true', '1', 'boolean', 'payment', 'Kleinunternehmerregel aktiv', FALSE),
('ust_id', 'DE0123456789', 'string', 'payment', 'USt Id', FALSE);

INSERT INTO availability_rules (`rule_key`, `rule_value`, `description`) VALUES
('appointments_enabled',1,'Terminbuchung aktiviert (1) oder deaktiviert (0)'),
('tickets_enabled',1,'Ticketsystem aktiviert (1) oder deaktiviert (0)'),
('buffer_minutes',0,'Pufferzeit zwischen Terminen in Minuten'),
('max_appointments_per_day',0,'Maximale Anzahl Termine pro Tag (0 = unbegrenzt)'),
('appointments_min_hours_notice',24,'Mindestvorlaufzeit in Stunden'),
('appointments_advance_days',60,'Maximale Vorausplanung in Tagen'),
('appointments_day_start_hour',8,'Früheste Stunde für Tagesansicht'),
('appointments_day_end_hour',18,'Späteste Stunde für Tagesansicht'),
('cancellation_hours_notice',48,'Stornofrist in Stunden vor Termin'),
('reminder_hours_before',24,'Erinnerung in Stunden vor Termin');

SET @individual_structure = JSON_ARRAY(
    JSON_OBJECT('type', 'intro', 'slug', 'slug', 'eyebrow', 'eyebrow', 'title', 'title', 'accent', 'title_accent', 'lead', 'lead', 'points', 'highlights', 'primary_cta', 'primary_cta', 'secondary_cta', 'secondary_cta', 'image_var', 'main_image', 'image_alt', 'main_image_alt'),
    JSON_OBJECT('type', 'stats', 'items', 'stats'),
    JSON_OBJECT('type', 'split_panel', 'slug', 'workflow_slug', 'title', 'workflow_title', 'accent', 'workflow_accent', 'body', 'workflow_body', 'points', 'workflow_points', 'image_var', 'detail_image', 'image_alt', 'detail_image_alt', 'reverse', TRUE),
    JSON_OBJECT('type', 'feature_grid', 'slug', 'feature_slug', 'title', 'feature_title', 'accent', 'feature_accent', 'lead', 'feature_lead', 'items', 'features')
);

SET @individual_data = JSON_OBJECT(
    'slug', 'individual-software',
    'eyebrow', 'Individuelle Software',
    'title', 'Digitale Prozesse, die wirklich passen',
    'title_accent', 'zu Ihrem Unternehmen',
    'lead', 'Wir entwickeln Anwendungen, Portale und interne Tools, die bestehende Ablaufe nicht verbiegen, sondern sauber digital abbilden.',
    'highlights', JSON_ARRAY('Analyse Ihrer bestehenden Workflows und Schnittstellen', 'Saubere Implementierung fur Web, Admin und interne Prozesse', 'Weiterentwicklung mit Fokus auf Stabilitat, Tempo und Wartbarkeit'),
    'primary_cta', JSON_OBJECT('label', 'Projekt besprechen', 'href', '/kontakt'),
    'secondary_cta', JSON_OBJECT('label', 'Referenzen ansehen', 'href', '/projekte'),
    'main_image_alt', 'Visualisierung einer individuellen Softwarelösung',
    'stats', JSON_ARRAY(
        JSON_OBJECT('value', 'API', 'label', 'Integrationen & Datenflüsse'),
        JSON_OBJECT('value', 'UX', 'label', 'Klar strukturierte Nutzerführung'),
        JSON_OBJECT('value', 'CMS', 'label', 'Inhalte und Prozesse zentral pflegen'),
        JSON_OBJECT('value', 'Scale', 'label', 'Erweiterbar für neue Anforderungen')
    ),
    'workflow_slug', 'vom briefing bis zur auslieferung',
    'workflow_title', 'Architektur und Umsetzung',
    'workflow_accent', 'aus einer Hand',
    'workflow_body', 'Von der Konzeption über Datenmodelle bis zur produktiven Oberfläche entsteht jede Lösung auf einer konsistenten technischen Basis. So bleibt das Produkt nachvollziehbar, performant und wartbar.',
    'workflow_points', JSON_ARRAY('Modulare Komponenten für Frontend und Backend', 'Anbindung vorhandener Systeme per API oder Importprozesse', 'Deployment- und Betriebsabläufe, die mit Ihrem Alltag funktionieren'),
    'detail_image_alt', 'Detailansicht eines individuellen Software-Dashboards',
    'feature_slug', 'was enthalten ist',
    'feature_title', 'Leistungsbereiche für',
    'feature_accent', 'digitale Sonderlösungen',
    'feature_lead', 'Je nach Projekt kombinieren wir Konzeption, Entwicklung und Prozessabbildung zu einer Lösung, die auf Ihr Team und Ihre Nutzer zugeschnitten ist.',
    'features', JSON_ARRAY(
        JSON_OBJECT('slug', 'planung', 'title', 'Prozessanalyse', 'description', 'Bestehende Abläufe, Rollen und Engpässe werden in eine belastbare technische Struktur überführt.'),
        JSON_OBJECT('slug', 'plattform', 'title', 'Individuelle Anwendungen', 'description', 'Wir bauen exakt die Oberflächen und Funktionen, die für Ihren Anwendungsfall relevant sind.'),
        JSON_OBJECT('slug', 'anbindung', 'title', 'Schnittstellen & Automatisierung', 'description', 'Drittanbieter, interne Systeme und wiederkehrende Schritte werden sauber verbunden und automatisiert.')
    )
);

SET @platform_structure = JSON_ARRAY(
    JSON_OBJECT('type', 'intro', 'slug', 'slug', 'eyebrow', 'eyebrow', 'title', 'title', 'accent', 'title_accent', 'lead', 'lead', 'points', 'highlights', 'primary_cta', 'primary_cta', 'secondary_cta', 'secondary_cta', 'image_var', 'main_image', 'image_alt', 'main_image_alt'),
    JSON_OBJECT('type', 'stats', 'items', 'stats'),
    JSON_OBJECT('type', 'split_panel', 'slug', 'workflow_slug', 'title', 'workflow_title', 'accent', 'workflow_accent', 'body', 'workflow_body', 'points', 'workflow_points', 'image_var', 'detail_image', 'image_alt', 'detail_image_alt'),
    JSON_OBJECT('type', 'feature_grid', 'slug', 'feature_slug', 'title', 'feature_title', 'accent', 'feature_accent', 'lead', 'feature_lead', 'items', 'features')
);

SET @platform_data = JSON_OBJECT(
    'slug', 'platform-development',
    'eyebrow', 'Platform Development',
    'title', 'Plattformen, die Teams',
    'title_accent', 'und Prozesse verbinden',
    'lead', 'Wir entwickeln zentrale Plattformen fur interne Ablaufe, Partnerportale und digitale Services, die mehrere Rollen, Datenquellen und Workflows in einer stabilen Anwendung zusammenfuhren.',
    'highlights', JSON_ARRAY('Mehrmandantenfahige Architektur fur Teams, Partner oder Kundenbereiche', 'Klare Rechte- und Rollenmodelle fur komplexe Organisationsstrukturen', 'Skalierbare technische Grundlage fur Wachstum, Module und Integrationen'),
    'primary_cta', JSON_OBJECT('label', 'Plattform planen', 'href', '/kontakt'),
    'secondary_cta', JSON_OBJECT('label', 'Architektur ansehen', 'href', '/projekte'),
    'main_image_alt', 'Visualisierung einer zentralen digitalen Plattform',
    'stats', JSON_ARRAY(
        JSON_OBJECT('value', 'Portal', 'label', 'Zentrale Arbeitsbereiche'),
        JSON_OBJECT('value', 'Roles', 'label', 'Rechte und Nutzerlogik'),
        JSON_OBJECT('value', 'Flows', 'label', 'Abgebildete Kernprozesse'),
        JSON_OBJECT('value', 'Scale', 'label', 'Module fur neue Anforderungen')
    ),
    'workflow_slug', 'plattform-architektur',
    'workflow_title', 'Von der Systemlogik bis zur Bedienoberflache',
    'workflow_accent', 'durchdacht aufgebaut',
    'workflow_body', 'Plattformprojekte brauchen mehr als einzelne Screens. Wir modellieren Zustande, Freigaben, Datenbeziehungen und Nutzerpfade so, dass aus vielen Anforderungen ein belastbares Gesamtsystem entsteht.',
    'workflow_points', JSON_ARRAY('Saubere Domanenlogik fur Vorgange, Status und Freigaben', 'API-first Anbindungen an bestehende Dienste, ERP oder CRM', 'Dashboards, Verwaltungsbereiche und Self-Service-Flows in einer Plattform'),
    'detail_image_alt', 'Admin- und Dashboardansicht einer Plattformanwendung',
    'feature_slug', 'typische bausteine',
    'feature_title', 'Was eine moderne',
    'feature_accent', 'Plattform leisten muss',
    'feature_lead', 'Je nach Einsatzgebiet kombinieren wir Management-Oberflachen, Nutzerbereiche und Integrationsschichten zu einer Plattform, die betrieblich wirklich tragfahig ist.',
    'features', JSON_ARRAY(
        JSON_OBJECT('slug', 'zugange', 'title', 'Rollenbasierte Nutzerbereiche', 'description', 'Unterschiedliche Benutzergruppen erhalten eigene Sichten, Berechtigungen und Prozesse innerhalb einer gemeinsamen Anwendung.'),
        JSON_OBJECT('slug', 'steuerung', 'title', 'Prozess- und Statuslogik', 'description', 'Vorgange, Freigaben und Bearbeitungsschritte werden nachvollziehbar in Plattformlogik uberfuhrt.'),
        JSON_OBJECT('slug', 'integration', 'title', 'Systemanbindung', 'description', 'Bestehende Tools, Datenquellen und externe Services werden uber APIs, Importe oder Automationen eingebunden.')
    )
);

SET @web_structure = JSON_ARRAY(
    JSON_OBJECT('type', 'intro', 'slug', 'slug', 'eyebrow', 'eyebrow', 'title', 'title', 'accent', 'title_accent', 'lead', 'lead', 'points', 'highlights', 'primary_cta', 'primary_cta', 'secondary_cta', 'secondary_cta', 'image_var', 'main_image', 'image_alt', 'main_image_alt'),
    JSON_OBJECT('type', 'stats', 'items', 'stats'),
    JSON_OBJECT('type', 'split_panel', 'slug', 'workflow_slug', 'title', 'workflow_title', 'accent', 'workflow_accent', 'body', 'workflow_body', 'points', 'workflow_points', 'image_var', 'detail_image', 'image_alt', 'detail_image_alt', 'reverse', TRUE),
    JSON_OBJECT('type', 'feature_grid', 'slug', 'feature_slug', 'title', 'feature_title', 'accent', 'feature_accent', 'lead', 'feature_lead', 'items', 'features')
);

SET @web_data = JSON_OBJECT(
    'slug', 'web-development',
    'eyebrow', 'Web^und Mobile Development',
    'title', 'Websites und Webapps mit',
    'title_accent', 'klarem Produktfokus',
    'lead', 'Wir entwickeln performante Websites, Conversions-starke Landingpages und individuelle Webanwendungen, die nicht nur gut aussehen, sondern messbar funktionieren.',
    'highlights', JSON_ARRAY('Technische Umsetzung fur Marketing, Vertrieb und operative Prozesse', 'Responsive Interfaces mit klarer Informationsstruktur und sauberer UX', 'Wartbare Codebasis fur Inhalte, Features und spatere Erweiterungen'),
    'primary_cta', JSON_OBJECT('label', 'Webprojekt starten', 'href', '/kontakt'),
    'secondary_cta', JSON_OBJECT('label', 'Web-Referenzen ansehen', 'href', '/projekte'),
    'main_image_alt', 'Moderne Webentwicklung fur Websites und Webanwendungen',
    'stats', JSON_ARRAY(
        JSON_OBJECT('value', 'SEO', 'label', 'Saubere technische Grundlagen'),
        JSON_OBJECT('value', 'CMS', 'label', 'Redaktionell pflegbare Inhalte'),
        JSON_OBJECT('value', 'UX', 'label', 'Verstandliche Nutzerfuhrung'),
        JSON_OBJECT('value', 'Perf', 'label', 'Schnelle Ladezeiten')
    ),
    'workflow_slug', 'web-umsetzung',
    'workflow_title', 'Design, Frontend und Funktionen',
    'workflow_accent', 'sauber verzahnt',
    'workflow_body', 'Webprojekte gelingen dann, wenn Inhalte, Gestaltung und Technik konsistent zusammenspielen. Wir verbinden Gestaltungssysteme, Komponentenlogik und Backend-Anbindung zu einem belastbaren Webprodukt.',
    'workflow_points', JSON_ARRAY('Komponentenbasierte Frontends fur saubere Wiederverwendung', 'Flexible Inhaltsbereiche fur Seiten, Kampagnen und Redaktionen', 'Formulare, APIs und individuelle Funktionen ohne technische Reibung'),
    'detail_image_alt', 'Responsives Interface einer Webanwendung',
    'feature_slug', 'leistungsumfang',
    'feature_title', 'Typische Bereiche in der',
    'feature_accent', 'Webentwicklung',
    'feature_lead', 'Abhangig von Ziel und Projektumfang kombinieren wir Markenauftritt, Content-Struktur und Anwendungslogik in einer Weblosung, die langfristig tragfahig bleibt.',
    'features', JSON_ARRAY(
        JSON_OBJECT('slug', 'seiten', 'title', 'Websites & Landingpages', 'description', 'Prasentationen, Kampagnenseiten und Unternehmensauftritte werden technisch sauber und mit klarem Fokus auf Wirkung umgesetzt.'),
        JSON_OBJECT('slug', 'apps', 'title', 'Individuelle Webanwendungen', 'description', 'Wenn Standardseiten nicht reichen, entwickeln wir Webapps mit Logik, Nutzerzustanden und angebundenen Prozessen.'),
        JSON_OBJECT('slug', 'content', 'title', 'CMS & Inhaltsstrukturen', 'description', 'Redaktionell nutzbare Strukturen machen Inhalte, SEO und Pflegeprozesse fur Ihr Team beherrschbar.')
    )
);

INSERT INTO services(name,description,icon_path,slug,cta_url,price_min,structure,data) VALUES 
('Individual Software','Maßgeschneiderte Anwendungen, von Grund auf neu entwickelt. Wir konzipieren Systeme, die mit Ihrem Unternehmen wachsen.','html_tag.svg','individual-software','/services',2500,@individual_structure,@individual_data),
('Plattformentwicklung','Cloud-native Infrastruktur und DevOps-Pipelines, die schneller liefern und seltener ausfallen.','stack.svg','platform-development','/services',2500,@platform_structure,@platform_data),
('Leistungsoptimierung','Wir analysieren und bewerten die Engpässe, die das Wachstumspotenzial Ihres Produkts begrenzen, und gestalten diese neu.','power.svg','ops','/services',2500, JSON_OBJECT(), JSON_OBJECT()),
('Sicherheit & Compliance','Auf Sicherheit ausgerichtete Entwicklungszyklen, Penetrationstests und Rahmenwerke zur Einhaltung regulatorischer Vorgaben.','shield.svg','sec','/services',2500, JSON_OBJECT(), JSON_OBJECT()),
('API & Integrationen','Verbindung Ihres Stacks mit Drittanbieterdiensten, internen Tools und Partner-Ökosystemen.','connect.svg','api','/services',2500, JSON_OBJECT(), JSON_OBJECT()),
('Web- und Mobilprodukte','Pixelgenaue Benutzeroberflächen, gestützt durch robuste Mobile-First-Entwicklung über alle Plattformen hinweg.','globe.svg','web-development','/services',2500,@web_structure,@web_data);

INSERT INTO page_media_assignments(page_key,section_key,slot_key,asset_id,gallery_id,sort_order) VALUES
('service','individual-software','main_image',NULL,NULL,1),
('service','individual-software','detail_image',NULL,NULL,1),
('service','platform-development','main_image',NULL,NULL,1),
('service','platform-development','detail_image',NULL,NULL,1),
('service','performance-optimization','main_image',NULL,NULL,1),
('service','performance-optimization','detail_image',NULL,NULL,1),
('service','security-compliance','main_image',NULL,NULL,1),
('service','security-compliance','detail_image',NULL,NULL,1),
('service','api-integrations','main_image',NULL,NULL,1),
('service','api-integrations','detail_image',NULL,NULL,1),
('service','web-development','main_image',NULL,NULL,1),
('service','web-development','detail_image',NULL,NULL,1);

USE henz_software_main;
SET @project_structure_default = JSON_ARRAY(
    JSON_OBJECT('type', 'intro', 'slug', 'slug', 'eyebrow', 'eyebrow', 'title', 'title', 'accent', 'title_accent', 'lead', 'lead', 'primary_cta', 'primary_cta', 'image_var', 'main_image', 'image_alt', 'main_image_alt'),
    JSON_OBJECT('type', 'split_panel', 'slug', 'detail_slug', 'title', 'detail_title', 'accent', 'detail_accent', 'body', 'detail_body', 'image_var', 'detail_image', 'image_alt', 'detail_image_alt', 'reverse', TRUE)
);

INSERT INTO referenced_projects(slug,title,description,project_image_path,project_slug,project_url,structure,data) VALUES
('Restaurant Dionysos','Restaurantwebsite','Landingpage mit relevanten Informationen und Speisekarte','Dionysos-Website-1.mp4','project-dionysos-website','www.dionysos-aburg.de',
@project_structure_default,
JSON_OBJECT(
    'slug', 'restaurant-dionysos',
    'eyebrow', 'Referenzprojekt',
    'title', 'Restaurant Dionysos',
    'title_accent', 'online erlebbar gemacht',
    'lead', 'Landingpage mit relevanten Informationen und Speisekarte',
    'primary_cta', JSON_OBJECT('label', 'Website besuchen', 'href', 'https://www.dionysos-aburg.de'),
    'main_image_alt', 'Restaurant Dionysos Website',
    'detail_slug', 'projektüberblick',
    'detail_title', 'Digitale Präsenz für',
    'detail_accent', 'Gastronomie',
    'detail_body', 'Fokus auf Auffindbarkeit, klare Informationsstruktur und einen zeitgemaessen Online-Auftritt für Restaurantgaeste.',
    'detail_image_alt', 'Restaurant Dionysos Vorschau'
)),
('Villa Athina','Hotelmanagementsystem','Entwicklung eines webbasierten Hotelmanagementsystems zur Verwaltung von Buchungen, Zimmern, Gästen und administrativen Abläufen. Das System automatisiert zentrale Geschäftsprozesse und ermöglicht eine effiziente Verwaltung des Hotelbetriebs.','Villa-Athina-Website.png','project-villa-athina-website','www.villa-athina.eu',
@project_structure_default,
JSON_OBJECT(
    'slug', 'villa-athina',
    'eyebrow', 'Referenzprojekt',
    'title', 'Villa Athina',
    'title_accent', 'Hotelbetrieb digitalisiert',
    'lead', 'Entwicklung eines webbasierten Hotelmanagementsystems zur Verwaltung von Buchungen, Zimmern, Gästen und administrativen Abläufen.',
    'primary_cta', JSON_OBJECT('label', 'Projekt ansehen', 'href', 'https://www.villa-athina.eu'),
    'main_image_alt', 'Villa Athina Managementsystem',
    'detail_slug', 'systemüberblick',
    'detail_title', 'Verwaltung, Buchung und',
    'detail_accent', 'Betriebsprozesse',
    'detail_body', 'Das System automatisiert zentrale Geschäftsprozesse und ermöglicht eine effiziente Verwaltung des Hotelbetriebs.',
    'detail_image_alt', 'Villa Athina Dashboard'
)),
('Restaurant Parga','Restaurantmanagementsystem','Entwicklung eines webbasierten Restaurantmanagementsystems zur Verwaltung von Bestellungen, Speisekarten, Reservierungen und Benutzern. Das System umfasst zudem ein Administrationspanel sowie eine REST-API zur Anbindung externer Anwendungen.','Parga-Management.png','project-parga-management',NULL,
@project_structure_default,
JSON_OBJECT(
    'slug', 'restaurant-parga',
    'eyebrow', 'Referenzprojekt',
    'title', 'Restaurant Parga',
    'title_accent', 'Managementsystem im Einsatz',
    'lead', 'Entwicklung eines webbasierten Restaurantmanagementsystems zur Verwaltung von Bestellungen, Speisekarten, Reservierungen und Benutzern.',
    'primary_cta', JSON_OBJECT('label', 'Zurueck zu Referenzen', 'href', '/#referenzen'),
    'main_image_alt', 'Restaurant Parga Managementsystem',
    'detail_slug', 'prozesslandschaft',
    'detail_title', 'Bestellungen, Reservierungen und',
    'detail_accent', 'Administration',
    'detail_body', 'Das System umfasst ein Administrationspanel sowie eine REST-API zur Anbindung externer Anwendungen.',
    'detail_image_alt', 'Restaurant Parga Adminoberflaeche'
));

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