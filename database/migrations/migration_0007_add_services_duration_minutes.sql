SET @has_duration_minutes := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'services'
      AND column_name = 'duration_minutes'
);

SET @add_duration_minutes_sql := IF(
    @has_duration_minutes = 0,
    'ALTER TABLE services ADD COLUMN duration_minutes INT UNSIGNED NOT NULL DEFAULT 60 AFTER slug',
    'SELECT 1'
);
PREPARE add_duration_minutes_stmt FROM @add_duration_minutes_sql;
EXECUTE add_duration_minutes_stmt;
DEALLOCATE PREPARE add_duration_minutes_stmt;

UPDATE services
SET duration_minutes = 60
WHERE duration_minutes IS NULL OR duration_minutes = 0;