-- users.sql  --  CS 4485 Task04C Part (4): login users for the Art Gallery
-- Phuc Tran (pmt220000), Team 43
--
-- Run AFTER art-small.sql (art-small.sql starts with DROP DATABASE art,
-- so re-importing it removes this table).
--   phpMyAdmin: select database "art" -> Import -> users.sql -> Go
--   monitor:    mysql -u root < users.sql
--
-- The four required users (firstN / lastN / userN / passN, N = 1..4).
-- Passwords are NOT stored in plain text: each one is a bcrypt hash made with
-- PHP's password_hash('passN', PASSWORD_BCRYPT); authenticate.php checks the
-- typed password with password_verify().
--   user1 / pass1    user2 / pass2    user3 / pass3    user4 / pass4

USE `art`;

DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id`        INT          NOT NULL AUTO_INCREMENT,
  `firstname` VARCHAR(50)  NOT NULL,
  `lastname`  VARCHAR(50)  NOT NULL,
  `userid`    VARCHAR(50)  NOT NULL,
  `password`  VARCHAR(255) NOT NULL,   -- bcrypt hash (60 chars), room for future algorithms
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_userid` (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `users` (`firstname`, `lastname`, `userid`, `password`) VALUES
  ('first1', 'last1', 'user1', '$2y$12$Bu1pJns1SDC1ZKqaXJtC7e1MWATciIZjklof75mEmPaWQfVTZPW/G'),  -- pass1
  ('first2', 'last2', 'user2', '$2y$12$jzzo2TiDD9vMCOh.WZ9WgeMnzdhjiXIL0T4Cx8/NquDAWsFbLrVwq'),  -- pass2
  ('first3', 'last3', 'user3', '$2y$12$O2da5Xw9yE.32NiOtSGjCOQ1mTextzjrJj5OAnlYqs8LSbayr0aVu'),  -- pass3
  ('first4', 'last4', 'user4', '$2y$12$ZTGzov7sTIxDSXuunEILOOCYvSSVSIeWTxaPb6tmWaSfgZ2WfDlYi');  -- pass4
