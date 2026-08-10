ALTER TABLE `henz_software_main`.`contracts` 
ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT '1' AFTER `document_path`;
