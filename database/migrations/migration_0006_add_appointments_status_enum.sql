SET @has_status := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'appointments'
      AND column_name = 'status'
);

SET @add_status_sql := IF(
    @has_status = 0,
    "ALTER TABLE appointments ADD COLUMN status ENUM('pending','accepted','declined','completed','storno') NOT NULL DEFAULT 'pending' AFTER appointment_date",
    'SELECT 1'
);
PREPARE add_status_stmt FROM @add_status_sql;
EXECUTE add_status_stmt;
DEALLOCATE PREPARE add_status_stmt;

UPDATE appointments SET status = 'accepted' WHERE status IN ('confirmed', 'paid');
UPDATE appointments SET status = 'storno' WHERE status = 'cancelled';
UPDATE appointments SET status = 'declined' WHERE status = 'no_show';

ALTER TABLE appointments
    MODIFY COLUMN status ENUM('pending','accepted','declined','completed','storno') NOT NULL DEFAULT 'pending';
