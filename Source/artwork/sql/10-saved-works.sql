-- Run as a database administrator after 08, for fresh or existing installations.
-- Adds each signed-in user's saved artworks ("My collection"). Additive only:
-- no existing table, row, account, or privilege changes, and it is safe to
-- repeat. The app's existing SELECT/INSERT/DELETE grant on art.* covers it.
-- Deleting an account or a painting removes its saved entries with it.
USE `art`;
CREATE TABLE IF NOT EXISTS `art_saved_works` (
  `username` VARCHAR(32) NOT NULL,
  `PaintingID` INT NOT NULL,
  `SavedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`username`, `PaintingID`),
  KEY `saved_works_painting` (`PaintingID`),
  CONSTRAINT `saved_works_user` FOREIGN KEY (`username`)
    REFERENCES `art_users` (`username`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `saved_works_painting` FOREIGN KEY (`PaintingID`)
    REFERENCES `Paintings` (`PaintingID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
