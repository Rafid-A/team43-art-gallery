CREATE USER IF NOT EXISTS 'testuser'@'127.0.0.1' IDENTIFIED BY 'mypassword';
CREATE USER IF NOT EXISTS 'testuser'@'localhost' IDENTIFIED BY 'mypassword';
GRANT SELECT, INSERT, DELETE ON art.* TO 'testuser'@'127.0.0.1';
GRANT SELECT, INSERT, DELETE ON art.* TO 'testuser'@'localhost';
CREATE USER IF NOT EXISTS 'min'@'localhost' IDENTIFIED BY 'min';
GRANT SELECT, INSERT, DELETE, CREATE ON min.* TO 'min'@'localhost';
