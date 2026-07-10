CREATE DATABASE henz_software_main
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
CREATE DATABASE henz_software_logging
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
CREATE USER 'henz_software_user'@'localhost'
IDENTIFIED BY 'UrAnus7Planet!6,67';
GRANT ALL PRIVILEGES
ON henz_software_main.*
TO 'henz_software_user'@'localhost';
GRANT ALL PRIVILEGES
ON henz_software_logging.*
TO 'henz_software_user'@'localhost';
FLUSH PRIVILEGES;