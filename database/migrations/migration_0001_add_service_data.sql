ALTER TABLE henz_software_main.services
ADD COLUMN service_slug VARCHAR(100) AFTER price_min,
ADD COLUMN data JSON AFTER service_slug;

ALTER TABLE henz_software_main.referenced_projects
ADD COLUMN structure JSON AFTER project_slug,
ADD COLUMN data JSON AFTER structure,
DROP COLUMN content_sections,
DROP COLUMN content_image_paths;