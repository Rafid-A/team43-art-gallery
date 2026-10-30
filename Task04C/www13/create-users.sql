-- CS 4485 Part (2) - www13: login authentication
-- Phuc Tran (pmt220000), Team 43
-- Creates database www13db with a users table (userid + bcrypt password hash).
--
-- Test accounts (plain passwords, for testing only):
--   alice / alice123
--   bob   / bob123
--   carol / carol123
-- Hashes generated with PHP: password_hash('<pw>', PASSWORD_DEFAULT)

DROP DATABASE IF EXISTS www13db;
CREATE DATABASE www13db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE www13db;

CREATE TABLE users (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    userid    VARCHAR(50)  NOT NULL UNIQUE,
    password  VARCHAR(255) NOT NULL,   -- password_hash() output, never plain text
    firstname VARCHAR(50)  NOT NULL,
    lastname  VARCHAR(50)  NOT NULL
) ENGINE=InnoDB;

INSERT INTO users (userid, password, firstname, lastname) VALUES
('alice', '$2y$12$SHSAJ9CuoZTor.G/3Ew1sOhQSDMmzaz8kgoYKxPFlV2eTE4wDakV6', 'Alice', 'Johnson'),
('bob',   '$2y$12$sIn8WKiPWPPULWSTP.QHuupRWuCyTJmzG8Rdh9LTkhKRtInmvftw.', 'Bob',   'Smith'),
('carol', '$2y$12$V4wFTg23Ug5bOhAWs/iZbem0cO6iy2J91n81afgxCkbTey9HImLLK', 'Carol', 'Nguyen');

-- Web application account
GRANT SELECT, INSERT, UPDATE, DELETE ON www13db.* TO 'testuser'@'localhost';
FLUSH PRIVILEGES;
