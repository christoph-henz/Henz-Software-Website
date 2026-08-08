ALTER TABLE henz_software_main.contracts
  DROP FOREIGN KEY service_id,
  CHANGE COLUMN service_id project_id INT NOT NULL,
  ADD FOREIGN KEY (project_id) REFERENCES henz_software_main.projects(id);

ALTER TABLE `henz_software_main`.`invoices` 
  DROP FOREIGN KEY `fk_invoices_contract`;
ALTER TABLE `henz_software_main`.`invoices` 
  CHANGE COLUMN `contract_id` `contract_id` INT NULL,
  ADD COLUMN `project_id` INT NOT NULL AFTER `client_id`;
ALTER TABLE `henz_software_main`.`invoices` 
  ADD CONSTRAINT `fk_invoices_contract`
    FOREIGN KEY (`contract_id`)
    REFERENCES `henz_software_main`.`contracts` (`id`)
    ON DELETE RESTRICT;
ALTER TABLE `henz_software_main`.`invoices` 
  ADD CONSTRAINT `fk_invoices_project`
    FOREIGN KEY (`project_id`)
    REFERENCES `henz_software_main`.`projects` (`id`)
    ON DELETE RESTRICT;

ALTER TABLE `henz_software_main`.`clients` 
  CHANGE COLUMN `name` `name` TEXT NOT NULL ,
  CHANGE COLUMN `email` `email` VARCHAR(150) NOT NULL,
  CHANGE COLUMN `phone` `phone` TEXT NULL DEFAULT NULL;
