USE `art`;

DELIMITER //

CREATE PROCEDURE `migrate_subject_identities`()
subject_migration: BEGIN
  DECLARE subject_count INTEGER DEFAULT 0;
  DECLARE mapping_count INTEGER DEFAULT 0;
  DECLARE mismatch_count INTEGER DEFAULT 0;
  DECLARE orphan_count INTEGER DEFAULT 0;

  IF EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'Subjects'
      AND column_name = 'SubjectID'
  ) THEN
    LEAVE subject_migration;
  END IF;

  CREATE TEMPORARY TABLE `_SubjectIdentityMap` (
    `SubjectID` INTEGER NOT NULL PRIMARY KEY,
    `SubjectName` VARCHAR(255) NOT NULL
  );

  INSERT INTO `_SubjectIdentityMap` (`SubjectID`, `SubjectName`) VALUES
    (1, 'Animals'),
    (2, 'Landscape'),
    (3, 'Music'),
    (4, 'Sports'),
    (5, 'Abstract'),
    (6, 'Transportation'),
    (7, 'Architecture'),
    (8, 'Religion'),
    (9, 'Still Life'),
    (10, 'People'),
    (11, 'Botanical'),
    (12, 'Cuisine'),
    (13, 'History'),
    (14, 'Love'),
    (15, 'Family'),
    (16, 'Performing Arts'),
    (17, 'Allegory'),
    (18, 'Mythological'),
    (19, 'Power'),
    (20, 'Military'),
    (21, 'Abstract');

  SELECT COUNT(*) INTO subject_count FROM `Subjects`;
  SELECT COUNT(*) INTO mapping_count FROM `_SubjectIdentityMap`;
  IF subject_count <> mapping_count THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Subject identity migration rejected: legacy subject count does not match the authoritative mapping.';
  END IF;

  SELECT COUNT(*) INTO mismatch_count
  FROM (
    SELECT names.`SubjectName`
    FROM (
      SELECT `SubjectName` FROM `Subjects`
      UNION
      SELECT `SubjectName` FROM `_SubjectIdentityMap`
    ) names
    LEFT JOIN (
      SELECT `SubjectName`, COUNT(*) AS `CountValue`
      FROM `Subjects`
      GROUP BY `SubjectName`
    ) legacy ON legacy.`SubjectName` = names.`SubjectName`
    LEFT JOIN (
      SELECT `SubjectName`, COUNT(*) AS `CountValue`
      FROM `_SubjectIdentityMap`
      GROUP BY `SubjectName`
    ) mapped ON mapped.`SubjectName` = names.`SubjectName`
    WHERE COALESCE(legacy.`CountValue`, 0) <> COALESCE(mapped.`CountValue`, 0)
  ) differences;
  IF mismatch_count <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Subject identity migration rejected: missing, ambiguous, or conflicting legacy subject names.';
  END IF;

  SELECT COUNT(*) INTO orphan_count
  FROM `PaintingSubjects` assignments
  LEFT JOIN `_SubjectIdentityMap` mapped ON mapped.`SubjectID` = assignments.`SubjectID`
  WHERE mapped.`SubjectID` IS NULL;
  IF orphan_count <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Subject identity migration rejected: an assignment references an unmapped SubjectID.';
  END IF;

  DROP TABLE IF EXISTS `Subjects_migrated`;
  CREATE TABLE `Subjects_migrated` (
    `SubjectID` INTEGER NOT NULL AUTO_INCREMENT,
    `SubjectName` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`SubjectID`)
  ) ENGINE=innodb DEFAULT CHARSET=utf8;
  INSERT INTO `Subjects_migrated` (`SubjectID`, `SubjectName`)
    SELECT `SubjectID`, `SubjectName` FROM `_SubjectIdentityMap`;
  RENAME TABLE `Subjects` TO `Subjects_legacy`, `Subjects_migrated` TO `Subjects`;
  DROP TABLE `Subjects_legacy`;
  ALTER TABLE `PaintingSubjects`
    ADD CONSTRAINT `fk_painting_subjects_subject`
    FOREIGN KEY (`SubjectID`) REFERENCES `Subjects` (`SubjectID`);
  DROP TEMPORARY TABLE `_SubjectIdentityMap`;
END//

DELIMITER ;
CALL `migrate_subject_identities`();
DROP PROCEDURE `migrate_subject_identities`;