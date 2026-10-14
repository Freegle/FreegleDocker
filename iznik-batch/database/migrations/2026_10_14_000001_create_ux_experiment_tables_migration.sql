-- Idempotent production SQL for 2026_10_14_000001_create_ux_experiment_tables.php
--
-- The automated UX experiment framework. Run once on production BEFORE deploying the code that
-- writes these tables (ux:experiments-tick, ux:experiment) or reads them (the ModTools SysAdmin
-- UX tab, /modtools/ux/experiments, the UxPrompt microvolunteering challenge).
--
-- All five are new and small, and nothing else reads them, so CREATE TABLE IF NOT EXISTS takes
-- no lock on any existing table: TOI-safe, and safe to run twice. No existing table is altered:
-- microvolunteer answers go in ux_prompt_responses, not microactions.
SET SESSION lock_wait_timeout = 5;

CREATE TABLE IF NOT EXISTS `ux_experiments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `flow` varchar(32) NOT NULL,
  `name` varchar(64) NOT NULL,
  `title` varchar(160) NOT NULL,
  `hypothesis` text NOT NULL,
  `change_description` text NOT NULL,
  `expected` text NOT NULL,
  `arms` json NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'draft',
  `started_at` timestamp NULL DEFAULT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `decision` text,
  `result` json DEFAULT NULL,
  `pr_url` varchar(255) DEFAULT NULL,
  `cleanup_pr_url` varchar(255) DEFAULT NULL,
  `image_control` varchar(255) DEFAULT NULL,
  `image_variant` varchar(255) DEFAULT NULL,
  `idea_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_experiments_name` (`name`),
  KEY `ux_experiments_flow_status` (`flow`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ux_experiment_reads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `experiment_id` bigint unsigned NOT NULL,
  `read_at` timestamp NOT NULL,
  `hours` decimal(6,2) NOT NULL,
  `metrics` json NOT NULL,
  `summary` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ux_experiment_reads_lookup` (`experiment_id`,`read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ux_ideas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `flow` varchar(32) NOT NULL,
  `title` varchar(160) NOT NULL,
  `rationale` text NOT NULL,
  `expected` text NOT NULL,
  `evidence` json DEFAULT NULL,
  `score` decimal(6,2) NOT NULL DEFAULT '0.00',
  `status` varchar(16) NOT NULL DEFAULT 'open',
  `experiment_id` bigint unsigned DEFAULT NULL,
  `source` varchar(16) NOT NULL DEFAULT 'ai',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ux_ideas_flow_status` (`flow`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ux_prompts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(16) NOT NULL,
  `flow` varchar(32) DEFAULT NULL,
  `experiment_id` bigint unsigned DEFAULT NULL,
  `question` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `options` json NOT NULL,
  `images` json DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'open',
  `max_responses` int unsigned NOT NULL DEFAULT '200',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ux_prompts_status_kind` (`status`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ux_prompt_responses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `prompt_id` bigint unsigned NOT NULL,
  `userid` bigint unsigned NOT NULL,
  `answer` json NOT NULL,
  `comment` text,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_prompt_responses_once` (`prompt_id`,`userid`),
  KEY `ux_prompt_responses_user_time` (`userid`,`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
