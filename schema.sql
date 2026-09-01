-- DuskRail database schema.
--
-- The database name is configurable (DB_DATABASE in .env, defaults to
-- "duskrail"). `php bin/install.php` creates the database; to apply the
-- schema manually instead:
--   mysql -u root -p duskrail < schema.sql

CREATE TABLE `Hosts` (
  `hostId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `host` varchar(255) NOT NULL,
  `domain` varchar(255) NOT NULL DEFAULT '',
  `robotsTxt` mediumtext DEFAULT NULL,
  `robotsTxtFetched` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `robotsTxtFetchedTime` int(10) unsigned DEFAULT NULL,
  `crawlDelaySeconds` int(10) unsigned DEFAULT NULL,
  `consecutiveFailures` smallint(5) unsigned NOT NULL DEFAULT 0,
  `crawledTime` int(10) unsigned DEFAULT NULL,
  `nextCrawlTime` int(10) unsigned DEFAULT NULL,
  `inc` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`hostId`),
  UNIQUE KEY `host` (`host`),
  KEY `nextCrawlTime` (`nextCrawlTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `Items` (
  `itemId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(767) NOT NULL,
  `hostId` int(10) unsigned NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `keywords` varchar(255) DEFAULT NULL,
  `fullText` longtext DEFAULT NULL,
  `fullHTML` longblob DEFAULT NULL,
  `crawledTime` int(10) unsigned DEFAULT NULL,
  `noindex` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `contentHash` char(40) DEFAULT NULL,
  `recrawlAfterSeconds` int(10) unsigned NOT NULL DEFAULT 604800,
  `recrawlDueTime` int(10) unsigned GENERATED ALWAYS AS (`crawledTime` + `recrawlAfterSeconds`) STORED,
  `claimedUntil` int(10) unsigned DEFAULT NULL,
  `inc` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`itemId`),
  UNIQUE KEY `url` (`url`),
  KEY `hostId_crawledTime` (`hostId`,`crawledTime`),
  KEY `crawledTime_type` (`crawledTime`,`type`),
  KEY `recrawlDueTime` (`recrawlDueTime`),
  FULLTEXT KEY `title_description_fullText` (`title`,`description`,`fullText`),
  CONSTRAINT `Items_ibfk_1` FOREIGN KEY (`hostId`) REFERENCES `Hosts` (`hostId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `Links` (
  `parentId` int(10) unsigned NOT NULL,
  `childId` int(10) unsigned NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`parentId`,`childId`),
  KEY `childId_parentId` (`childId`,`parentId`),
  FULLTEXT KEY `description` (`description`),
  CONSTRAINT `Links_ibfk_1` FOREIGN KEY (`parentId`) REFERENCES `Items` (`itemId`) ON DELETE CASCADE,
  CONSTRAINT `Links_ibfk_2` FOREIGN KEY (`childId`) REFERENCES `Items` (`itemId`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- URLs the crawler has already resolved as unusable. Their Items rows are
-- gone (crawledTime means real, presentable content), and this is what keeps
-- rediscovering one from starting the whole fetch-and-delete cycle again.
CREATE TABLE `DeadURLs` (
  `deadURLId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(767) NOT NULL,
  `reason` varchar(50) NOT NULL,
  `deadTime` int(10) unsigned NOT NULL,
  PRIMARY KEY (`deadURLId`),
  UNIQUE KEY `url` (`url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Named values the web side and the crawler pass between them - currently
-- just the focused-crawl topic. A row rather than a file, so the web server
-- needs no write access anywhere inside the checkout.
CREATE TABLE `Settings` (
  `settingId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `value` text NOT NULL,
  PRIMARY KEY (`settingId`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Request budgets for the public endpoints (see RateLimit). One row per
-- (budget, caller, minute); the primary key is what makes counting a request
-- a single atomic upsert, and finished windows are cleared out as they go.
CREATE TABLE `RateLimits` (
  `bucket` varchar(16) NOT NULL,
  `identifier` varchar(64) NOT NULL,
  `windowStart` int(10) unsigned NOT NULL,
  `requests` int(10) unsigned NOT NULL,
  PRIMARY KEY (`bucket`,`identifier`,`windowStart`),
  KEY `windowStart` (`windowStart`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which schema deltas bin/install.php has already applied.
CREATE TABLE `Migrations` (
  `migrationId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `appliedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`migrationId`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
