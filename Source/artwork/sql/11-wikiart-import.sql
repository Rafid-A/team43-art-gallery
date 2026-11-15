-- Run as a database administrator after 10, for fresh or existing installations.
-- Adds art_import_sources for the offline WikiArt import (issue #16): one row
-- per saved source (an artist or painting page), naming the gallery record it
-- created or was linked to, the saved file and its SHA-256, and the extracted
-- values, including full birth and death dates that Artists stores as years.
-- Its primary key makes each source importable once. Additive only: no
-- existing table, row, account, or privilege changes, and it is safe to
-- repeat. The app's existing SELECT/INSERT/DELETE grant on art.* covers it.
-- Deleting an imported artist or artwork removes its provenance row with it,
-- so that source can be imported again.
USE `art`;
CREATE TABLE IF NOT EXISTS `art_import_sources` (
  `SourceKey` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `RecordType` ENUM('artist', 'painting') NOT NULL,
  `ArtistID` INT NULL,
  `PaintingID` INT NULL,
  `Outcome` ENUM('created', 'matched') NOT NULL,
  `SourceURL` VARCHAR(255) NOT NULL,
  `SnapshotFile` VARCHAR(255) NOT NULL,
  `SnapshotSHA256` CHAR(64) CHARACTER SET ascii NOT NULL,
  `ImageSHA256` CHAR(64) CHARACTER SET ascii NULL,
  `Extracted` LONGTEXT NOT NULL,
  `ImportedBy` VARCHAR(32) NULL,
  `ImportedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`SourceKey`),
  UNIQUE KEY `import_source_artist` (`ArtistID`),
  UNIQUE KEY `import_source_painting` (`PaintingID`),
  CONSTRAINT `import_source_artist_fk` FOREIGN KEY (`ArtistID`)
    REFERENCES `Artists` (`ArtistID`) ON DELETE CASCADE,
  CONSTRAINT `import_source_painting_fk` FOREIGN KEY (`PaintingID`)
    REFERENCES `Paintings` (`PaintingID`) ON DELETE CASCADE,
  CONSTRAINT `import_source_kind` CHECK (
    (`RecordType` = 'artist' AND `ArtistID` IS NOT NULL AND `PaintingID` IS NULL)
    OR (`RecordType` = 'painting' AND `PaintingID` IS NOT NULL AND `ArtistID` IS NULL)),
  CONSTRAINT `import_source_extracted` CHECK (JSON_VALID(`Extracted`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='team43 WikiArt import provenance v1';
