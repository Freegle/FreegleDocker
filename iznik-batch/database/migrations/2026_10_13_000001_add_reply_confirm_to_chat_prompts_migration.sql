-- Production idempotent SQL: chat_prompts.userid and chat_prompts.heldchatmsgid.
--
-- A 'confirm_reply' prompt is asked of the replier only (userid) about the reply that waits
-- for their answer (heldchatmsgid). chat_prompts is small, so a plain ALTER is fine.
SET SESSION lock_wait_timeout = 5;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'chat_prompts' AND column_name = 'userid');
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE chat_prompts
        ADD COLUMN userid BIGINT UNSIGNED NULL AFTER msgids,
        ADD COLUMN heldchatmsgid BIGINT UNSIGNED NULL AFTER userid,
        ADD COLUMN reason VARCHAR(16) NULL AFTER kind,
        ADD COLUMN answered_via VARCHAR(16) NULL AFTER answered_at,
        ADD KEY chat_prompts_heldchatmsgid (heldchatmsgid),
        ADD CONSTRAINT chat_prompts_userid_foreign FOREIGN KEY (userid) REFERENCES users (id) ON DELETE CASCADE,
        ADD CONSTRAINT chat_prompts_heldchatmsgid_foreign FOREIGN KEY (heldchatmsgid) REFERENCES chat_messages (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
