-- Run as a database administrator after 08, for fresh or existing installations.
-- Grant only the editor columns to existing classroom app accounts. Repeating
-- this script preserves users, passwords, data, and all unrelated privileges.
USE `art`;
DROP PROCEDURE IF EXISTS `grant_editor_columns`;
DELIMITER //
CREATE PROCEDURE `grant_editor_columns`()
BEGIN
  IF EXISTS (SELECT 1 FROM mysql.user WHERE User='testuser' AND Host='%') THEN
    GRANT UPDATE (FirstName, LastName, Nationality, YearOfBirth, YearOfDeath) ON art.Artists TO 'testuser'@'%';
    GRANT UPDATE (Title, ArtistID, GalleryID, YearOfWork, Medium) ON art.Paintings TO 'testuser'@'%';
  END IF;
  IF EXISTS (SELECT 1 FROM mysql.user WHERE User='testuser' AND Host='localhost') THEN
    GRANT UPDATE (FirstName, LastName, Nationality, YearOfBirth, YearOfDeath) ON art.Artists TO 'testuser'@'localhost';
    GRANT UPDATE (Title, ArtistID, GalleryID, YearOfWork, Medium) ON art.Paintings TO 'testuser'@'localhost';
  END IF;
  IF EXISTS (SELECT 1 FROM mysql.user WHERE User='testuser' AND Host='127.0.0.1') THEN
    GRANT UPDATE (FirstName, LastName, Nationality, YearOfBirth, YearOfDeath) ON art.Artists TO 'testuser'@'127.0.0.1';
    GRANT UPDATE (Title, ArtistID, GalleryID, YearOfWork, Medium) ON art.Paintings TO 'testuser'@'127.0.0.1';
  END IF;
END//
DELIMITER ;
CALL `grant_editor_columns`();
DROP PROCEDURE `grant_editor_columns`;
