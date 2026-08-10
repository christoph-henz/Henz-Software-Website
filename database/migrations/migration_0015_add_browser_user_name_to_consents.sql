USE henz_software_main;

SET @table_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'consents'
);

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'consents'
      AND column_name = 'browser_user_name'
);

SET @sql := IF(
    @table_exists > 0 AND @column_exists = 0,
    'ALTER TABLE consents ADD COLUMN browser_user_name VARCHAR(255) NOT NULL DEFAULT ''unknown'' AFTER device_name',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
