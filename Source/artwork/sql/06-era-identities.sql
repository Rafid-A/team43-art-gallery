USE `art`;

-- Era identity contract (issue #2, proposed for team approval in the PR):
-- EraID is a signed INT NOT NULL AUTO_INCREMENT primary key. The six supplied
-- eras keep fixed IDs, matched on their exact name and years, never on row
-- order: 1 Medieval (Before 1400), 2 Renaissance (1400-1550),
-- 3 Post Renaissance (1550-1700), 4 Early Modern (1700-1875),
-- 5 Modern (1875-1945), 6 Contemporary (After 1945). New eras take the next
-- AUTO_INCREMENT value. No table references Eras, so nothing else changes.

-- This routine belongs to this migration. A failed batch CALL can skip its
-- final DROP, so replace the routine on retry, never any pre-existing tables.
DROP PROCEDURE IF EXISTS `migrate_era_identities`;
DELIMITER //
CREATE PROCEDURE `migrate_era_identities`()
BEGIN
  DECLARE identified INTEGER DEFAULT 0;
  DECLARE invalid_count INTEGER DEFAULT 0;
  DECLARE map_created BOOLEAN DEFAULT FALSE;
  DECLARE stage_created BOOLEAN DEFAULT FALSE;
  DECLARE swapped BOOLEAN DEFAULT FALSE;
  DECLARE migration_lock INTEGER DEFAULT 0;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    IF map_created THEN DROP TEMPORARY TABLE `_EraIdentityMap`; END IF;
    IF stage_created AND NOT swapped THEN DROP TABLE `Eras_migrated`; END IF;
    IF migration_lock = 1 THEN DO RELEASE_LOCK('art.era-identities'); END IF;
    RESIGNAL;
  END;

  SELECT GET_LOCK('art.era-identities', 10) INTO migration_lock;
  IF COALESCE(migration_lock, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Era upgrade is already running. Retry after it finishes.';
  END IF;
  CREATE TEMPORARY TABLE `_EraIdentityMap` (
    `EraID` INTEGER NOT NULL PRIMARY KEY,
    `EraName` VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
    `EraYears` VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL
  );
  SET map_created = TRUE;
  INSERT INTO `_EraIdentityMap` (`EraID`, `EraName`, `EraYears`) VALUES
    (1, 'Medieval', 'Before 1400'), (2, 'Renaissance', '1400-1550'),
    (3, 'Post Renaissance', '1550-1700'), (4, 'Early Modern', '1700-1875'),
    (5, 'Modern', '1875-1945'), (6, 'Contemporary', 'After 1945');

  SELECT COUNT(*) INTO invalid_count FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'Eras'
      AND table_type = 'BASE TABLE' AND engine = 'InnoDB';
  IF invalid_count <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Era upgrade requires the supported InnoDB Eras table; nothing was changed.';
  END IF;
  SELECT COUNT(*) INTO identified FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'Eras' AND column_name = 'EraID';
  IF identified = 1 THEN
    -- Already identified (fresh install or a previous run): validate, change nothing.
    SELECT COUNT(*) INTO invalid_count FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'Eras' AND column_name = 'EraID'
        AND data_type = 'int' AND column_type NOT LIKE '%unsigned%'
        AND is_nullable = 'NO' AND extra = 'auto_increment';
    IF invalid_count <> 1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EraID must be a signed INT NOT NULL AUTO_INCREMENT primary key; partial schemas are not changed.';
    END IF;
    SELECT COUNT(*) INTO invalid_count FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 'Eras' AND index_name = 'PRIMARY';
    IF invalid_count <> 1 OR NOT EXISTS (
      SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE()
        AND table_name = 'Eras' AND index_name = 'PRIMARY' AND column_name = 'EraID'
    ) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Eras needs a single-column EraID primary key; existing IDs are never renumbered.';
    END IF;
    SELECT COUNT(*) INTO invalid_count FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'Eras'
        AND ((column_name = 'EraName' AND data_type = 'varchar' AND character_maximum_length = 255 AND is_nullable = 'NO')
          OR (column_name = 'EraYears' AND data_type = 'varchar' AND character_maximum_length = 255));
    IF invalid_count <> 2 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Identified Eras requires EraName VARCHAR(255) NOT NULL and EraYears VARCHAR(255); unsupported schemas are not changed.';
    END IF;
    -- Legitimate CRUD may delete a supplied era or add new ones. A surviving
    -- reserved ID must still carry its supplied name and years.
    SELECT COUNT(*) INTO invalid_count FROM `Eras` e
      JOIN `_EraIdentityMap` m ON m.EraID = e.EraID
      WHERE BINARY e.EraName <> BINARY m.EraName OR BINARY COALESCE(e.EraYears, '') <> BINARY m.EraYears;
    IF invalid_count <> 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Existing reserved EraID values conflict with the era contract; no IDs are changed.';
    END IF;
  ELSE
    -- Only the original two-column shape has a lossless known conversion.
    SELECT COUNT(*) INTO invalid_count FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'Eras';
    IF invalid_count <> 2 OR (
      SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = 'Eras' AND column_name IN ('EraName', 'EraYears')
        AND data_type = 'varchar' AND character_maximum_length = 255 AND extra = ''
        AND is_nullable = 'YES' AND (column_default IS NULL OR column_default = 'NULL')
        AND column_comment = '' AND collation_name = (
          SELECT table_collation FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'Eras')
    ) <> 2 OR EXISTS (
      SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'Eras'
    ) OR EXISTS (
      SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'Eras'
    ) OR EXISTS (
      SELECT 1 FROM information_schema.triggers WHERE event_object_schema = DATABASE() AND event_object_table = 'Eras'
    ) OR EXISTS (
      SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema = DATABASE()
        AND (table_name = 'Eras' OR referenced_table_name = 'Eras')
    ) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported legacy Eras metadata; stopped before changing existing tables.';
    END IF;
    IF EXISTS (
      SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE()
        AND table_name IN ('Eras_migrated', 'Eras_legacy')
    ) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Eras_migrated or Eras_legacy already exists. Preserve and inspect it; this upgrade will not overwrite it.';
    END IF;
    SELECT COUNT(*) INTO invalid_count FROM `Eras`;
    IF invalid_count <> 6 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Legacy era count does not match the six supplied eras; no tables were changed.';
    END IF;
    SELECT COUNT(*) INTO invalid_count FROM `_EraIdentityMap` m
      WHERE (SELECT COUNT(*) FROM `Eras` e
        WHERE BINARY e.EraName = BINARY m.EraName AND BINARY e.EraYears = BINARY m.EraYears) <> 1;
    IF invalid_count <> 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Legacy era names and years do not exactly match the supplied eras; no tables were changed.';
    END IF;

    CREATE TABLE `Eras_migrated` LIKE `Eras`;
    SET stage_created = TRUE;
    ALTER TABLE `Eras_migrated`
      ADD COLUMN `EraID` INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST,
      MODIFY COLUMN `EraName` VARCHAR(255) NOT NULL;
    -- Copy each original row's own values under its contract ID.
    INSERT INTO `Eras_migrated` (`EraID`, `EraName`, `EraYears`)
      SELECT m.EraID, e.EraName, e.EraYears FROM `_EraIdentityMap` m
      JOIN `Eras` e ON BINARY e.EraName = BINARY m.EraName AND BINARY e.EraYears = BINARY m.EraYears;
    -- Atomic swap; keep the complete original table as a recovery copy.
    RENAME TABLE `Eras` TO `Eras_legacy`, `Eras_migrated` TO `Eras`;
    SET swapped = TRUE;
  END IF;
  DROP TEMPORARY TABLE `_EraIdentityMap`;
  SET map_created = FALSE;
  DO RELEASE_LOCK('art.era-identities');
  SET migration_lock = 0;
END//
DELIMITER ;
CALL `migrate_era_identities`();
DROP PROCEDURE `migrate_era_identities`;
