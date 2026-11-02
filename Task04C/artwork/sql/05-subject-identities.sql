USE `art`;

-- This routine belongs to this migration. A failed batch CALL can skip its
-- final DROP, so replace the routine on retry, never any pre-existing tables.
DROP PROCEDURE IF EXISTS `migrate_subject_identities`;
DELIMITER //
CREATE PROCEDURE `migrate_subject_identities`()
BEGIN
  DECLARE identified INTEGER DEFAULT 0;
  DECLARE invalid_count INTEGER DEFAULT 0;
  DECLARE has_subject_fk INTEGER DEFAULT 0;
  DECLARE map_created BOOLEAN DEFAULT FALSE;
  DECLARE stage_created BOOLEAN DEFAULT FALSE;
  DECLARE swapped BOOLEAN DEFAULT FALSE;
  DECLARE migration_lock INTEGER DEFAULT 0;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    IF map_created THEN DROP TEMPORARY TABLE `_SubjectIdentityMap`; END IF;
    IF stage_created AND NOT swapped THEN DROP TABLE `Subjects_migrated`; END IF;
    IF migration_lock = 1 THEN DO RELEASE_LOCK('art.subject-identities'); END IF;
    RESIGNAL;
  END;

  SELECT GET_LOCK('art.subject-identities', 10) INTO migration_lock;
  IF COALESCE(migration_lock, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Subject upgrade is already running. Retry after it finishes.';
  END IF;
  -- Retain f846d9c's explicit ID/name contract, including both Abstract rows.
  -- This file does not establish its external course provenance. Never derive
  -- IDs from legacy row order or update PaintingSubjects associations.
  CREATE TEMPORARY TABLE `_SubjectIdentityMap` (
    `SubjectID` INTEGER NOT NULL PRIMARY KEY,
    `SubjectName` VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL
  );
  SET map_created = TRUE;
  INSERT INTO `_SubjectIdentityMap` (`SubjectID`, `SubjectName`) VALUES
    (1, 'Animals'), (2, 'Landscape'), (3, 'Music'), (4, 'Sports'),
    (5, 'Abstract'), (6, 'Transportation'), (7, 'Architecture'),
    (8, 'Religion'), (9, 'Still Life'), (10, 'People'), (11, 'Botanical'),
    (12, 'Cuisine'), (13, 'History'), (14, 'Love'), (15, 'Family'),
    (16, 'Performing Arts'), (17, 'Allegory'), (18, 'Mythological'),
    (19, 'Power'), (20, 'Military'), (21, 'Abstract');

  SELECT COUNT(*) INTO invalid_count FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name IN ('Subjects', 'PaintingSubjects')
      AND table_type = 'BASE TABLE' AND engine = 'InnoDB';
  IF invalid_count <> 2 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Subject upgrade requires the supported InnoDB Subjects and PaintingSubjects tables.';
  END IF;
  SELECT COUNT(*) INTO invalid_count FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'PaintingSubjects'
      AND column_name = 'SubjectID' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%';
  IF invalid_count <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PaintingSubjects requires its existing signed INT SubjectID; unsupported schemas are not changed.';
  END IF;
  SELECT COUNT(*) INTO identified FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'Subjects' AND column_name = 'SubjectID';
  IF identified = 1 THEN
    SELECT COUNT(*) INTO invalid_count FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'Subjects' AND column_name = 'SubjectID'
        AND data_type = 'int' AND column_type NOT LIKE '%unsigned%'
        AND is_nullable = 'NO' AND extra = 'auto_increment';
    IF invalid_count <> 1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SubjectID must be a signed INT NOT NULL AUTO_INCREMENT primary key; partial schemas are not changed.';
    END IF;
    SELECT COUNT(*) INTO invalid_count FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 'Subjects' AND index_name = 'PRIMARY';
    IF invalid_count <> 1 OR NOT EXISTS (
      SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE()
        AND table_name = 'Subjects' AND index_name = 'PRIMARY' AND column_name = 'SubjectID'
    ) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Subjects needs a single-column SubjectID primary key; existing IDs are never renumbered.';
    END IF;
    SELECT COUNT(*) INTO invalid_count FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'Subjects' AND column_name = 'SubjectName'
        AND data_type = 'varchar' AND character_maximum_length = 255 AND is_nullable = 'NO';
    IF invalid_count <> 1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Identified Subjects requires SubjectName VARCHAR(255) NOT NULL; unsupported schemas are not changed.';
    END IF;
    SELECT COUNT(*) INTO invalid_count FROM `Subjects` s
      JOIN `_SubjectIdentityMap` m ON m.SubjectID = s.SubjectID
      WHERE BINARY s.SubjectName <> BINARY m.SubjectName;
    IF invalid_count <> 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Existing reserved SubjectID/name values conflict with the established mapping; no IDs are changed.';
    END IF;
    -- Legitimate CRUD can delete unreferenced seed rows or add custom IDs and
    -- duplicate names. Validate survivors/references, never reseed or count 21.
    SELECT COUNT(*) INTO invalid_count FROM `PaintingSubjects` j
      LEFT JOIN `Subjects` s ON s.SubjectID = j.SubjectID WHERE s.SubjectID IS NULL;
  ELSE
    -- Only the original single-column shape has a lossless known conversion.
    SELECT COUNT(*) INTO invalid_count FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'Subjects';
    IF invalid_count <> 1 OR NOT EXISTS (
      SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = 'Subjects' AND column_name = 'SubjectName'
        AND data_type = 'varchar' AND character_maximum_length = 255 AND extra = ''
        AND is_nullable = 'YES' AND (column_default IS NULL OR column_default = 'NULL')
        AND column_comment = '' AND collation_name = (
          SELECT table_collation FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'Subjects')
    ) OR EXISTS (
      SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'Subjects'
    ) OR EXISTS (
      SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'Subjects'
    ) OR EXISTS (
      SELECT 1 FROM information_schema.triggers WHERE event_object_schema = DATABASE() AND event_object_table = 'Subjects'
    ) OR EXISTS (
      SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema = DATABASE()
        AND (table_name = 'Subjects' OR referenced_table_name = 'Subjects')
    ) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported legacy Subjects metadata; stopped before changing existing tables.';
    END IF;
    IF EXISTS (
      SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE()
        AND table_name IN ('Subjects_migrated', 'Subjects_legacy')
    ) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Subjects_migrated or Subjects_legacy already exists. Preserve and inspect it; this upgrade will not overwrite it.';
    END IF;
    SELECT COUNT(*) INTO invalid_count FROM `Subjects`;
    IF invalid_count <> 21 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Legacy subject count does not match the established mapping; no tables were changed.';
    END IF;
    SELECT COUNT(*) INTO invalid_count FROM (
      SELECT BINARY SubjectName AS name_bytes, COUNT(*) AS n FROM `_SubjectIdentityMap` GROUP BY BINARY SubjectName
    ) expected LEFT JOIN (
      SELECT BINARY SubjectName AS name_bytes, COUNT(*) AS n FROM `Subjects` GROUP BY BINARY SubjectName
    ) actual ON actual.name_bytes = expected.name_bytes
      WHERE actual.n IS NULL OR actual.n <> expected.n;
    IF invalid_count <> 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Legacy names/duplicates do not exactly match the established mapping; no tables were changed.';
    END IF;
    SELECT COUNT(*) INTO invalid_count FROM `PaintingSubjects` j
      LEFT JOIN `_SubjectIdentityMap` m ON m.SubjectID = j.SubjectID WHERE m.SubjectID IS NULL;
  END IF;
  IF invalid_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An assignment references an unmapped SubjectID; no associations are repaired or removed.';
  END IF;

  -- Preserve the teammate's existing FK contract, never replace conflicting FKs.
  IF EXISTS (
    SELECT 1 FROM information_schema.key_column_usage k
    WHERE k.constraint_schema = DATABASE() AND k.table_name = 'PaintingSubjects'
      AND k.column_name = 'SubjectID' AND k.referenced_table_name IS NOT NULL
      AND (k.referenced_table_schema <> DATABASE()
        OR k.referenced_table_name <> 'Subjects' OR k.referenced_column_name <> 'SubjectID'
        OR (SELECT COUNT(*) FROM information_schema.key_column_usage c
          WHERE c.constraint_schema = k.constraint_schema AND c.table_name = k.table_name
            AND c.constraint_name = k.constraint_name) <> 1)
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Conflicting subject foreign key; stopped without replacing it.';
  END IF;
  SELECT COUNT(*) INTO has_subject_fk FROM information_schema.key_column_usage
    WHERE constraint_schema = DATABASE() AND table_name = 'PaintingSubjects'
      AND column_name = 'SubjectID' AND referenced_table_schema = DATABASE()
      AND referenced_table_name = 'Subjects' AND referenced_column_name = 'SubjectID';
  IF has_subject_fk = 0 AND EXISTS (
    SELECT 1 FROM information_schema.referential_constraints
      WHERE constraint_schema = DATABASE() AND constraint_name = 'fk_painting_subjects_subject'
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The subject FK name is already used by another constraint; no tables were changed.';
  END IF;

  IF identified = 0 THEN
    CREATE TABLE `Subjects_migrated` LIKE `Subjects`;
    SET stage_created = TRUE;
    ALTER TABLE `Subjects_migrated`
      ADD COLUMN `SubjectID` INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST,
      MODIFY COLUMN `SubjectName` VARCHAR(255) NOT NULL;
    INSERT INTO `Subjects_migrated` (`SubjectID`, `SubjectName`)
      SELECT SubjectID, SubjectName FROM `_SubjectIdentityMap`;
    -- Atomic swap; keep the complete original table as a recovery copy.
    RENAME TABLE `Subjects` TO `Subjects_legacy`, `Subjects_migrated` TO `Subjects`;
    SET swapped = TRUE;
  END IF;
  IF has_subject_fk = 0 THEN
    ALTER TABLE `PaintingSubjects` ADD CONSTRAINT `fk_painting_subjects_subject`
      FOREIGN KEY (`SubjectID`) REFERENCES `Subjects` (`SubjectID`);
  END IF;
  DROP TEMPORARY TABLE `_SubjectIdentityMap`;
  SET map_created = FALSE;
  DO RELEASE_LOCK('art.subject-identities');
  SET migration_lock = 0;
END//
DELIMITER ;
CALL `migrate_subject_identities`();
DROP PROCEDURE `migrate_subject_identities`;
