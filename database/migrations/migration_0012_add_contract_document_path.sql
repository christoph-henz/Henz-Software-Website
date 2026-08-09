ALTER TABLE `henz_software_main`.`contracts`
  ADD COLUMN `document_path` VARCHAR(500) NULL AFTER `terms`;

CREATE INDEX `idx_contracts_document_path`
  ON `henz_software_main`.`contracts` (`document_path`);
