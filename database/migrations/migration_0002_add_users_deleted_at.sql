ALTER TABLE `henz_software_main`.`users` 
CHANGE COLUMN `is_deleted` `is_deleted` TIMESTAMP NULL DEFAULT NULL,
DROP COLUMN username;