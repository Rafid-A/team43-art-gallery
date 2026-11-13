USE `art`;
DROP PROCEDURE IF EXISTS `undo_subject_names_07`;
DELIMITER //
CREATE PROCEDURE `undo_subject_names_07`()
BEGIN
  DECLARE bad INT DEFAULT 0;
  DECLARE got_lock INT DEFAULT 0;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
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
      AND table_name='_SubjectNameCorrection07' AND column_name='SubjectID' AND data_type='int' AND is_nullable='NO')
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
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported correction journal or triggers; rollback stopped.';
  END IF;
  START TRANSACTION;
  SELECT COUNT(*) INTO bad FROM Subjects WHERE SubjectID BETWEEN 9 AND 21 FOR UPDATE;
  SELECT COUNT(*) INTO bad FROM `_SubjectNameCorrection07` FOR UPDATE;
  IF NOT EXISTS (SELECT 1 FROM `_SubjectNameCorrection07` WHERE SubjectID=0
      AND BINARY OldName=BINARY 'completion' AND BINARY NewName=BINARY '07-v1') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Missing or unsupported completion marker; rollback stopped.';
  END IF;
  SELECT COUNT(*) INTO bad FROM `_SubjectNameCorrection07` j
    LEFT JOIN Subjects s ON s.SubjectID=j.SubjectID
    WHERE j.SubjectID <> 0 AND (s.SubjectID IS NULL OR BINARY s.SubjectName <> BINARY j.NewName
      OR BINARY CONCAT(j.SubjectID, ':', j.OldName, ':', j.NewName) NOT IN (
        '9:Still Life:Religion', '10:People:Still Life', '11:Botanical:People',
        '12:Cuisine:Botanical', '13:History:Cuisine', '14:Love:History', '15:Family:Love',
        '16:Performing Arts:Family', '17:Allegory:Performing Arts', '18:Mythological:Allegory',
        '19:Power:Mythological', '20:Military:Power', '21:Abstract:Military'));
  IF bad <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journaled subjects changed or were deleted; rollback stopped without changes.';
  END IF;
  UPDATE Subjects s JOIN `_SubjectNameCorrection07` j ON j.SubjectID=s.SubjectID SET s.SubjectName=j.OldName WHERE j.SubjectID <> 0;
  DELETE FROM `_SubjectNameCorrection07`;
  COMMIT;
  DO RELEASE_LOCK('art.subject-names-07');
END//
DELIMITER ;
CALL `undo_subject_names_07`();
DROP PROCEDURE `undo_subject_names_07`;
