# Database Migrations

SQL migrations to be executed on live server. Always backup first, test on staging, deploy code changes before/after as noted.

---

## Pending Migrations

### Messages Isochrones Table
**Related feature:** Message-based isochrone expansion for reaching more users
**Prerequisites:** Deploy code changes for Message isochrone functions FIRST

```sql
-- Create messages_isochrones table to track isochrone expansion for messages
CREATE TABLE `messages_isochrones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint unsigned NOT NULL,
  `isochroneid` bigint unsigned NOT NULL,
  `minutes` int NOT NULL,
  `activeUsers` int NOT NULL DEFAULT '0' COMMENT 'Number of active users in isochrone when created',
  `replies` int NOT NULL DEFAULT '0' COMMENT 'Number of replies when isochrone was created',
  `views` int NOT NULL DEFAULT '0' COMMENT 'Number of views when isochrone was created',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `msgid` (`msgid`),
  KEY `isochroneid` (`isochroneid`),
  KEY `timestamp` (`timestamp`),
  CONSTRAINT `messages_isochrones_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `messages_isochrones_ibfk_2` FOREIGN KEY (`isochroneid`) REFERENCES `isochrones` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

### SMS Notifications Removal
**Related plan:** remove-sms-notifications.md
**Prerequisites:** Deploy code changes to remove SMS functionality FIRST

```sql
-- Drop users_phones table (stores phone numbers for SMS - no longer used)
DROP TABLE IF EXISTS `users_phones`;
```

### Message Isochrone Simulation Tables
**Related feature:** Simulation system to analyze historical message data and optimize isochrone expansion parameters
**Prerequisites:** Deploy code changes for simulation script and API FIRST

```sql
-- Simulation runs - metadata about each simulation
CREATE TABLE `simulation_message_isochrones_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `description` text,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed` timestamp NULL DEFAULT NULL,
  `status` enum('pending','running','completed','failed') DEFAULT 'pending',
  `parameters` json NOT NULL COMMENT 'Simulation parameters',
  `filters` json NOT NULL COMMENT 'Date range, groupid filter',
  `message_count` int DEFAULT 0,
  `metrics` json DEFAULT NULL COMMENT 'Aggregate metrics across all messages',
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Per-message simulation results
CREATE TABLE `simulation_message_isochrones_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `runid` bigint unsigned NOT NULL,
  `msgid` bigint unsigned NOT NULL,
  `sequence` int NOT NULL COMMENT 'Order in run (0-based)',
  `arrival` timestamp NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `locationid` bigint unsigned DEFAULT NULL,
  `lat` decimal(10,6) DEFAULT NULL,
  `lng` decimal(10,6) DEFAULT NULL,
  `groupid` bigint unsigned NOT NULL,
  `groupname` varchar(255) DEFAULT NULL,
  `group_cga_polygon` json DEFAULT NULL COMMENT 'Group coverage area GeoJSON',
  `total_group_users` int DEFAULT 0,
  `total_replies_actual` int DEFAULT 0,
  `metrics` json DEFAULT NULL COMMENT 'Summary metrics for this message',
  PRIMARY KEY (`id`),
  UNIQUE KEY `runid_msgid` (`runid`,`msgid`),
  KEY `runid_sequence` (`runid`,`sequence`),
  KEY `msgid` (`msgid`),
  CONSTRAINT `simulation_message_isochrones_messages_ibfk_1` FOREIGN KEY (`runid`) REFERENCES `simulation_message_isochrones_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Expansion timeline for each message
CREATE TABLE `simulation_message_isochrones_expansions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sim_msgid` bigint unsigned NOT NULL COMMENT 'FK to simulation_message_isochrones_messages',
  `sequence` int NOT NULL COMMENT 'Expansion number (0 = initial)',
  `timestamp` timestamp NOT NULL,
  `minutes_after_arrival` int NOT NULL,
  `minutes` int NOT NULL COMMENT 'Isochrone size in minutes',
  `transport` enum('walk','cycle','drive') DEFAULT 'walk',
  `isochrone_polygon` json DEFAULT NULL COMMENT 'Isochrone GeoJSON',
  `users_in_isochrone` int DEFAULT 0,
  `new_users_reached` int DEFAULT 0,
  `replies_at_time` int DEFAULT 0,
  `replies_in_isochrone` int DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `sim_msgid_sequence` (`sim_msgid`,`sequence`),
  CONSTRAINT `simulation_message_isochrones_expansions_ibfk_1` FOREIGN KEY (`sim_msgid`) REFERENCES `simulation_message_isochrones_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- User locations and reply status for each message
CREATE TABLE `simulation_message_isochrones_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sim_msgid` bigint unsigned NOT NULL,
  `user_hash` varchar(64) NOT NULL COMMENT 'Anonymized user ID',
  `lat` decimal(10,6) NOT NULL COMMENT 'Blurred location from users_approxlocs',
  `lng` decimal(10,6) NOT NULL,
  `in_group` tinyint(1) DEFAULT 1,
  `replied` tinyint(1) DEFAULT 0,
  `reply_time` timestamp NULL DEFAULT NULL,
  `reply_minutes` int DEFAULT NULL COMMENT 'Minutes after message arrival',
  `distance_km` decimal(10,2) DEFAULT NULL COMMENT 'Distance from message location',
  PRIMARY KEY (`id`),
  KEY `sim_msgid` (`sim_msgid`),
  KEY `replied` (`replied`),
  CONSTRAINT `simulation_message_isochrones_users_ibfk_1` FOREIGN KEY (`sim_msgid`) REFERENCES `simulation_message_isochrones_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

---

## Completed Migrations

(Migrations moved here after execution on production with date)
