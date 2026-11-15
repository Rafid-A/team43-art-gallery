USE `art`;

-- ID 0 is the completion marker; positive IDs record names actually changed.
-- Keep this table for rollback. An incompatible table stops the migration.
CREATE TABLE IF NOT EXISTS `_SubjectNameCorrection07` (
  `SubjectID` INT NOT NULL PRIMARY KEY,
  `OldName` VARCHAR(255) NOT NULL,
  `NewName` VARCHAR(255) NOT NULL
) ENGINE=InnoDB COMMENT='team43 subject name correction 07 journal v1';

DROP PROCEDURE IF EXISTS `correct_subject_names_07`;
DELIMITER //
CREATE PROCEDURE `correct_subject_names_07`()
main: BEGIN
  DECLARE bad INT DEFAULT 0;
  DECLARE got_lock INT DEFAULT 0;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    DROP TEMPORARY TABLE IF EXISTS `_SubjectNames07Map`;
    IF got_lock = 1 THEN DO RELEASE_LOCK('art.subject-names-07'); END IF;
    RESIGNAL;
  END;
  SELECT GET_LOCK('art.subject-names-07', 10) INTO got_lock;
  IF COALESCE(got_lock, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Subject name correction is already running.';
  END IF;
  IF (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()
      AND table_name IN ('_SubjectNameCorrection07', 'Subjects') AND engine='InnoDB') <> 2
    OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE()
      AND table_name='_SubjectNameCorrection07' AND table_comment='team43 subject name correction 07 journal v1')
    OR (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE()
      AND table_name='_SubjectNameCorrection07') <> 3
    OR NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE()
      AND table_name='_SubjectNameCorrection07' AND column_name='SubjectID' AND data_type='int' AND column_type NOT LIKE '%unsigned%' AND is_nullable='NO')
    OR (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE()
      AND table_name='_SubjectNameCorrection07' AND column_name IN ('OldName','NewName')
      AND data_type='varchar' AND character_maximum_length=255 AND is_nullable='NO') <> 2
    OR (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE()
      AND table_name='_SubjectNameCorrection07' AND index_name='PRIMARY') <> 1
    OR NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE()
      AND table_name='_SubjectNameCorrection07' AND index_name='PRIMARY' AND column_name='SubjectID')
    OR EXISTS (SELECT 1 FROM information_schema.triggers WHERE event_object_schema=DATABASE()
      AND event_object_table IN ('_SubjectNameCorrection07', 'Subjects'))
    OR EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE()
      AND (table_name='_SubjectNameCorrection07' OR referenced_table_name='_SubjectNameCorrection07')
      AND referenced_table_name IS NOT NULL) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported correction journal or triggers; stopped without changing subjects.';
  END IF;
  CREATE TEMPORARY TABLE `_SubjectNames07Map` (
    id INT PRIMARY KEY, old_name VARCHAR(255) NOT NULL, new_name VARCHAR(255) NOT NULL
  );
  INSERT INTO `_SubjectNames07Map` VALUES
    (9,'Still Life','Religion'), (10,'People','Still Life'), (11,'Botanical','People'),
    (12,'Cuisine','Botanical'), (13,'History','Cuisine'), (14,'Love','History'),
    (15,'Family','Love'), (16,'Performing Arts','Family'), (17,'Allegory','Performing Arts'),
    (18,'Mythological','Allegory'), (19,'Power','Mythological'), (20,'Military','Power'),
    (21,'Abstract','Military');
  START TRANSACTION;
  -- Lock surviving rows and the gaps covering this range before checking them.
  SELECT COUNT(*) INTO bad FROM Subjects WHERE SubjectID BETWEEN 9 AND 21 FOR UPDATE;
  SELECT COUNT(*) INTO bad FROM `_SubjectNameCorrection07` FOR UPDATE;
  -- A completed migration must never reinterpret subsequent user edits as legacy data.
  IF EXISTS (SELECT 1 FROM `_SubjectNameCorrection07` WHERE SubjectID=0
      AND BINARY OldName=BINARY 'completion' AND BINARY NewName=BINARY '07-v1') THEN
    SELECT COUNT(*) INTO bad FROM `_SubjectNameCorrection07` j
      LEFT JOIN `_SubjectNames07Map` m ON m.id=j.SubjectID
      WHERE j.SubjectID <> 0 AND (m.id IS NULL OR BINARY j.OldName <> BINARY m.old_name
        OR BINARY j.NewName <> BINARY m.new_name);
    IF bad <> 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported correction journal; no subject names changed.';
    END IF;
    COMMIT;
    DROP TEMPORARY TABLE `_SubjectNames07Map`;
    DO RELEASE_LOCK('art.subject-names-07');
    LEAVE main;
  END IF;
  IF EXISTS (SELECT 1 FROM `_SubjectNameCorrection07`) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Incomplete or unsupported correction journal; no subject names changed.';
  END IF;
  SELECT COUNT(*) INTO bad FROM `_SubjectNameCorrection07` j
    LEFT JOIN `_SubjectNames07Map` m ON m.id=j.SubjectID
    LEFT JOIN Subjects s ON s.SubjectID=j.SubjectID
    WHERE m.id IS NULL OR BINARY j.OldName <> BINARY m.old_name
       OR BINARY j.NewName <> BINARY m.new_name
       OR (s.SubjectID IS NOT NULL AND BINARY s.SubjectName <> BINARY j.NewName);
  IF bad <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Correction journal conflicts with current data; no subject names changed.';
  END IF;
  SELECT COUNT(*) INTO bad FROM Subjects s JOIN `_SubjectNames07Map` m ON m.id=s.SubjectID
    WHERE BINARY s.SubjectName <> BINARY m.old_name AND BINARY s.SubjectName <> BINARY m.new_name;
  IF bad <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Subject names conflict with both supported states; no subject names changed.';
  END IF;
  INSERT INTO `_SubjectNameCorrection07` (SubjectID, OldName, NewName)
    SELECT s.SubjectID, m.old_name, m.new_name FROM Subjects s
    JOIN `_SubjectNames07Map` m ON m.id=s.SubjectID
    WHERE BINARY s.SubjectName = BINARY m.old_name;
  UPDATE Subjects s JOIN `_SubjectNameCorrection07` j ON j.SubjectID=s.SubjectID
    SET s.SubjectName=j.NewName WHERE BINARY s.SubjectName = BINARY j.OldName;
  INSERT INTO `_SubjectNameCorrection07` VALUES (0, 'completion', '07-v1');
  COMMIT;
  DROP TEMPORARY TABLE `_SubjectNames07Map`;
  DO RELEASE_LOCK('art.subject-names-07');
END//
DELIMITER ;
CALL `correct_subject_names_07`();
DROP PROCEDURE `correct_subject_names_07`;
