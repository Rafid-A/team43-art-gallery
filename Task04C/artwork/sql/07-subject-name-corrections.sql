USE `art`;

-- Corrects which name belongs to each reserved SubjectID. Issue #6.
--
-- 05-subject-identities.sql numbered the course's names 1 to 21 in file order.
-- The course files supply no IDs, and PaintingSubjects shows that order is wrong
-- from ID 8 upward: no artwork uses ID 8, and IDs 9 to 21 fit the name one row
-- earlier (for example ID 11 holds portraits, not botanical works).
--
-- This mapping is team-defined from that assignment evidence; no original course
-- list was found. Placing the second Abstract at ID 8 is a choice, since no artwork
-- shows its real ID.
--
-- Only names change. IDs and PaintingSubjects are never written, deleted seed rows
-- are not recreated, and custom subjects are left alone.

-- This routine belongs to this migration. A failed batch CALL can skip its
-- final DROP, so replace the routine on retry.
DROP PROCEDURE IF EXISTS `migrate_subject_names`;
DELIMITER //
CREATE PROCEDURE `migrate_subject_names`()
BEGIN
  DECLARE invalid_count INTEGER DEFAULT 0;
  DECLARE map_created BOOLEAN DEFAULT FALSE;
  DECLARE migration_lock INTEGER DEFAULT 0;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    IF map_created THEN DROP TEMPORARY TABLE `_SubjectNameCorrections`; END IF;
    IF migration_lock = 1 THEN DO RELEASE_LOCK('art.subject-identities'); END IF;
    RESIGNAL;
  END;

  -- Shares 05's lock, so the two subject upgrades cannot overlap.
  SELECT GET_LOCK('art.subject-identities', 10) INTO migration_lock;
  IF COALESCE(migration_lock, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Subject upgrade is already running. Retry after it finishes.';
  END IF;

  SELECT COUNT(*) INTO invalid_count FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'Subjects'
      AND table_type = 'BASE TABLE' AND engine = 'InnoDB';
  IF invalid_count <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Subject name correction requires the supported InnoDB Subjects table.';
  END IF;
  SELECT COUNT(*) INTO invalid_count FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'Subjects' AND column_name = 'SubjectID'
      AND data_type = 'int' AND column_type NOT LIKE '%unsigned%'
      AND is_nullable = 'NO' AND extra = 'auto_increment';
  IF invalid_count <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Subjects has no supported SubjectID. Run 05-subject-identities.sql first; no names were changed.';
  END IF;
  SELECT COUNT(*) INTO invalid_count FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'Subjects' AND column_name = 'SubjectName'
      AND data_type = 'varchar' AND character_maximum_length = 255 AND is_nullable = 'NO';
  IF invalid_count <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Subject name correction requires SubjectName VARCHAR(255) NOT NULL; no names were changed.';
  END IF;

  CREATE TEMPORARY TABLE `_SubjectNameCorrections` (
    `SubjectID` INTEGER NOT NULL PRIMARY KEY,
    `OldName` VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
    `NewName` VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL
  );
  SET map_created = TRUE;
  INSERT INTO `_SubjectNameCorrections` (`SubjectID`, `OldName`, `NewName`) VALUES
    (1, 'Animals', 'Animals'), (2, 'Landscape', 'Landscape'), (3, 'Music', 'Music'),
    (4, 'Sports', 'Sports'), (5, 'Abstract', 'Abstract'),
    (6, 'Transportation', 'Transportation'), (7, 'Architecture', 'Architecture'),
    (8, 'Religion', 'Abstract'), (9, 'Still Life', 'Religion'),
    (10, 'People', 'Still Life'), (11, 'Botanical', 'People'),
    (12, 'Cuisine', 'Botanical'), (13, 'History', 'Cuisine'), (14, 'Love', 'History'),
    (15, 'Family', 'Love'), (16, 'Performing Arts', 'Family'),
    (17, 'Allegory', 'Performing Arts'), (18, 'Mythological', 'Allegory'),
    (19, 'Power', 'Mythological'), (20, 'Military', 'Power'), (21, 'Abstract', 'Military');

  START TRANSACTION;
  SELECT COUNT(*) INTO invalid_count FROM `Subjects` WHERE SubjectID BETWEEN 1 AND 21 FOR UPDATE;
  -- A reserved row holding any other name was edited outside this contract. Stop
  -- rather than overwrite it. Old and new names are both accepted, so reruns are safe.
  SELECT COUNT(*) INTO invalid_count FROM `Subjects` s
    JOIN `_SubjectNameCorrections` m ON m.SubjectID = s.SubjectID
    WHERE BINARY s.SubjectName <> BINARY m.OldName AND BINARY s.SubjectName <> BINARY m.NewName;
  IF invalid_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A reserved SubjectID has a name outside the expected old and corrected values; no names were changed.';
  END IF;
  UPDATE `Subjects` s JOIN `_SubjectNameCorrections` m ON m.SubjectID = s.SubjectID
    SET s.SubjectName = m.NewName
    WHERE BINARY s.SubjectName = BINARY m.OldName AND BINARY m.OldName <> BINARY m.NewName;
  COMMIT;

  DROP TEMPORARY TABLE `_SubjectNameCorrections`;
  SET map_created = FALSE;
  DO RELEASE_LOCK('art.subject-identities');
  SET migration_lock = 0;
END//
DELIMITER ;
CALL `migrate_subject_names`();
DROP PROCEDURE `migrate_subject_names`;
