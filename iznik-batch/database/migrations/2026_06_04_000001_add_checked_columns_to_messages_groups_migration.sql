-- Idempotent production SQL for 2026_06_04_000001_add_checked_columns_to_messages_groups.php
--
-- messages_groups.checkedat / checkedby: a moderator (or two microvolunteers) has looked at a
-- post that published itself (post-moderation, docs/moderators/post-moderation.md). Drives the
-- Check queue and its count, and counts as "a human look" for the earned-reach gate.
--
-- The two nullable columns are ALGORITHM=INSTANT: metadata only, no rebuild. The foreign key
-- and the (groupid, checkedat) index are INPLACE and read the whole of messages_groups, the
-- largest table, so they run online but take minutes; lock_wait_timeout makes each statement
-- fail fast rather than queue behind a long transaction. Run once BEFORE deploying the code.
SET SESSION lock_wait_timeout = 5;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'messages_groups'
      AND column_name = 'checkedat'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE messages_groups ADD COLUMN checkedat TIMESTAMP NULL DEFAULT NULL AFTER rejectedat, ADD COLUMN checkedby BIGINT UNSIGNED NULL DEFAULT NULL AFTER checkedat, ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'messages_groups'
      AND constraint_name = 'messages_groups_checkedby_foreign'
);
SET @ddl := IF(@fk_exists = 0,
    'ALTER TABLE messages_groups ADD CONSTRAINT messages_groups_checkedby_foreign FOREIGN KEY (checkedby) REFERENCES users (id) ON DELETE SET NULL, ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'messages_groups'
      AND index_name = 'messages_groups_groupid_checkedat_idx'
);
SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE messages_groups ADD INDEX messages_groups_groupid_checkedat_idx (groupid, checkedat), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
