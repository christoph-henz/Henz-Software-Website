SET @has_duration_minutes := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'appointments'
      AND column_name = 'duration_minutes'
);

SET @has_scheduled_at := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'appointments'
      AND column_name = 'scheduled_at'
);

SET @has_appointment_date := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'appointments'
      AND column_name = 'appointment_date'
);

SET @duration_after_column := IF(
    @has_scheduled_at > 0,
    'scheduled_at',
    IF(@has_appointment_date > 0, 'appointment_date', 'service_id')
);

SET @add_duration_minutes_sql := IF(
    @has_duration_minutes = 0,
    CONCAT('ALTER TABLE appointments ADD COLUMN duration_minutes INT UNSIGNED NOT NULL DEFAULT 60 AFTER ', @duration_after_column),
    'SELECT 1'
);

PREPARE add_duration_minutes_stmt FROM @add_duration_minutes_sql;
EXECUTE add_duration_minutes_stmt;
DEALLOCATE PREPARE add_duration_minutes_stmt;

UPDATE appointments
SET duration_minutes = 60
WHERE duration_minutes IS NULL OR duration_minutes = 0;