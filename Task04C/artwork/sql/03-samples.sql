CREATE DATABASE IF NOT EXISTS min;
USE min;
CREATE TABLE IF NOT EXISTS `classics` (
  `author` varchar(128) DEFAULT NULL,
  `title` varchar(128) DEFAULT NULL,
  `category` varchar(16) DEFAULT NULL,
  `year` char(4) DEFAULT NULL,
  `isbn` char(13) NOT NULL DEFAULT '',
  PRIMARY KEY (`isbn`),
  KEY `author` (`author`(20)),
  KEY `title` (`title`(20)),
  KEY `category` (`category`(4)),
  KEY `year` (`year`),
  KEY `isbn` (`isbn`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;
INSERT INTO `classics` (`author`, `title`, `category`, `year`, `isbn`) VALUES
('Mark Twain', 'The Adventures of Tom Sawyer', 'Fiction', '1876', '9781598184891'),
('Jane Austen', 'Pride and Prejudice', 'Fiction', '1811', '9780582506206'),
('Charles Darwin', 'The Origin of Species', 'Non-Fiction', '1856', '9780517123201'),
('Charles Dickens', 'The Old Curiosity Shop', 'Fiction', '1841', '9780099533474'),
('William Shakespeare', 'Romeo and Juliet', 'Play', '1594', '9780192814968'),
('Herman Melville', 'Moby Dick', 'Fiction', '1851', '9780199535729');
CREATE TABLE IF NOT EXISTS users (forename VARCHAR(32) NOT NULL, surname VARCHAR(32) NOT NULL, username VARCHAR(32) NOT NULL UNIQUE, password VARCHAR(32) NOT NULL);
CREATE TABLE IF NOT EXISTS users1 (forename VARCHAR(32) NOT NULL, surname VARCHAR(32) NOT NULL, username VARCHAR(32) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL);
INSERT INTO users VALUES ('Bill', 'Smith', 'bsmith', '79afa0c039e7b534db24391f3927698b');
INSERT INTO users1 VALUES ('Bill', 'Smith', 'bsmith', '$2y$10$XkkxSFDquWt6ap.XNRm0ZO.m/tiqMI4xYIMe3G/9fg9VzacCWgCga');
INSERT INTO users VALUES ('Pauline', 'Jones', 'pjones', 'e5f253b937a61216ceaa0d67011792bd');
INSERT INTO users1 VALUES ('Pauline', 'Jones', 'pjones', '$2y$10$b8oDMQfs19TcKOLQTMdKueCRhgu8zstFa8v/knekX1r8W5ied7udG');
