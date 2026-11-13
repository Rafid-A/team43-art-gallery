USE `art`;

-- Account roles (issue #11). Run as a database administrator (root); the
-- gallery's own database account cannot change tables or grants.
--
-- 1. art_users gets two columns in one ALTER, so a run cannot leave only one:
--      role   ENUM('client','admin')     NOT NULL DEFAULT 'client'
--      status ENUM('active','disabled')  NOT NULL DEFAULT 'active'
--    Existing usernames, password hashes, names and every other table are
--    left as they are. New public registrations are always clients.
-- 2. Initial administrator: only while adding the columns, and only the
--    trusted classroom account user1 if it exists. Every other account starts
--    as a client. Valid existing columns mark a completed initial upgrade;
--    reruns never bootstrap, even if no administrator remains. If no trusted
--    user1 exists at initial upgrade, provision an administrator explicitly.
-- 3. The gallery account testuser gets UPDATE on art_users only, for role
--    and status changes on the Accounts page. No other privilege changes.
--
-- A database that already has both columns with exactly these definitions
-- is accepted unchanged. One column alone, or a different definition, stops
-- the migration before any change.

-- This routine belongs to this migration. A failed batch CALL can skip its
-- final DROP, so replace the routine on retry.
DROP PROCEDURE IF EXISTS `migrate_account_roles`;
DELIMITER //
CREATE PROCEDURE `migrate_account_roles`()
BEGIN
  DECLARE role_type TEXT DEFAULT NULL;
  DECLARE status_type TEXT DEFAULT NULL;
  DECLARE role_nullable TEXT DEFAULT NULL;
  DECLARE status_nullable TEXT DEFAULT NULL;
  DECLARE grantee_host VARCHAR(255);
  DECLARE no_more_hosts BOOLEAN DEFAULT FALSE;
  DECLARE migration_lock INTEGER DEFAULT 0;
  DECLARE hosts CURSOR FOR
    SELECT `Host` FROM mysql.user
    WHERE `User` = 'testuser' AND `Host` IN ('%', 'localhost', '127.0.0.1')
    ORDER BY `Host`;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET no_more_hosts = TRUE;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    IF migration_lock = 1 THEN DO RELEASE_LOCK('art.account-roles'); END IF;
    RESIGNAL;
  END;

  SET migration_lock = GET_LOCK('art.account-roles', 30);
  IF migration_lock IS NULL OR migration_lock <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '08: another account-role migration is running; nothing was changed';
  END IF;

  IF (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = 'art' AND table_name = 'art_users'
        AND table_type = 'BASE TABLE') <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '08: art_users table not found; nothing was changed';
  END IF;

  SELECT MAX(IF(column_name = 'role', column_type, NULL)),
         MAX(IF(column_name = 'status', column_type, NULL)),
         MAX(IF(column_name = 'role', is_nullable, NULL)),
         MAX(IF(column_name = 'status', is_nullable, NULL))
    INTO role_type, status_type, role_nullable, status_nullable
    FROM information_schema.columns
    WHERE table_schema = 'art' AND table_name = 'art_users'
      AND column_name IN ('role', 'status');

  IF role_type IS NULL AND status_type IS NULL THEN
    ALTER TABLE `art_users`
      ADD COLUMN `role` ENUM('client','admin') NOT NULL DEFAULT 'client' AFTER `password`,
      ADD COLUMN `status` ENUM('active','disabled') NOT NULL DEFAULT 'active' AFTER `role`;
    UPDATE `art_users` SET `role` = 'admin' WHERE `username` = 'user1';
  ELSEIF NOT (role_type <=> 'enum(''client'',''admin'')' AND role_nullable <=> 'NO'
          AND status_type <=> 'enum(''active'',''disabled'')' AND status_nullable <=> 'NO') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '08: art_users has a partial or different role/status definition; nothing was changed';
  END IF;

  OPEN hosts;
  grant_hosts: LOOP
    FETCH hosts INTO grantee_host;
    IF no_more_hosts THEN LEAVE grant_hosts; END IF;
    SET @account_roles_grant = CONCAT('GRANT UPDATE ON `art`.`art_users` TO ''testuser''@', QUOTE(grantee_host));
    PREPARE account_roles_grant FROM @account_roles_grant;
    EXECUTE account_roles_grant;
    DEALLOCATE PREPARE account_roles_grant;
  END LOOP;
  CLOSE hosts;
  SET @account_roles_grant = NULL;

  DO RELEASE_LOCK('art.account-roles');
  SET migration_lock = 0;
END//
DELIMITER ;
CALL `migrate_account_roles`();
DROP PROCEDURE `migrate_account_roles`;
