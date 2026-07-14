-- DuskRail database schema.
--
-- The database name is configurable (DB_DATABASE in .env, defaults to
-- "duskrail"). `php bin/install.php` creates the database; to apply the
-- schema manually instead:
--   mysql -u root -p duskrail < schema.sql

CREATE TABLE `Items` (
  `itemId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(767) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `keywords` varchar(255) DEFAULT NULL,
  `fullText` longtext DEFAULT NULL,
  `fullHTML` longtext DEFAULT NULL,
  `crawledTime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`itemId`),
  UNIQUE KEY `url` (`url`),
  FULLTEXT KEY `title_description_keywords_fullText` (`title`,`description`,`keywords`,`fullText`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `Links` (
  `parentId` int(10) unsigned NOT NULL,
  `childId` int(10) unsigned NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`parentId`,`childId`),
  KEY `childId_parentId` (`childId`,`parentId`),
  CONSTRAINT `Links_ibfk_1` FOREIGN KEY (`parentId`) REFERENCES `Items` (`itemId`) ON DELETE CASCADE,
  CONSTRAINT `Links_ibfk_2` FOREIGN KEY (`childId`) REFERENCES `Items` (`itemId`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
