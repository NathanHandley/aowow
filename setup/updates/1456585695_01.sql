SET FOREIGN_KEY_CHECKS=0;

-- create system account
REPLACE INTO `aowow_account` (`id`, `user`, `displayName`) VALUES (0, '<system>', 'AoWoW');

-- restructure weightscales (sorry for your loss...)
DROP TABLE IF EXISTS `aowow_account_weightscales`;
CREATE TABLE IF NOT EXISTS `aowow_account_weightscales` (
  `id` int(32) NOT NULL AUTO_INCREMENT,
  `userId` int(10) unsigned NOT NULL,
  `name` varchar(32) NOT NULL,
  `class` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `icon` varchar(48) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`,`userId`),
  KEY `FK_acc_weights` (`userId`),
  CONSTRAINT `FK_acc_weights` FOREIGN KEY (`userId`) REFERENCES `aowow_account` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8 ROW_FORMAT=COMPACT;

INSERT INTO `aowow_account_weightscales` (`id`, `userId`, `name`, `class`, `icon`) VALUES
    (1, 0, 'arms', 1, 'ability_rogue_eviscerate'),
    (2, 0, 'fury', 1, 'ability_warrior_innerrage'),
    (3, 0, 'prot', 1, 'ability_warrior_defensivestance'),
    (4, 0, 'holy', 2, 'spell_holy_holybolt'),
    (5, 0, 'prot', 2, 'ability_paladin_shieldofthetemplar'),
    (6, 0, 'retrib', 2, 'spell_holy_auraoflight'),
    (7, 0, 'beast', 3, 'ability_hunter_beasttaming'),
    (8, 0, 'marks', 3, 'ability_marksmanship'),
    (9, 0, 'surv', 3, 'ability_hunter_swiftstrike'),
    (10, 0, 'assas', 4, 'ability_rogue_eviscerate'),
    (11, 0, 'combat', 4, 'ability_backstab'),
    (12, 0, 'subtle', 4, 'ability_stealth'),
    (13, 0, 'disc', 5, 'spell_holy_wordfortitude'),
    (14, 0, 'holy', 5, 'spell_holy_guardianspirit'),
    (15, 0, 'shadow', 5, 'spell_shadow_shadowwordpain'),
    (16, 0, 'blooddps', 6, 'spell_deathknight_bloodpresence'),
    (17, 0, 'frostdps', 6, 'spell_deathknight_frostpresence'),
    (18, 0, 'frosttank', 6, 'spell_deathknight_frostpresence'),
    (19, 0, 'unholydps', 6, 'spell_deathknight_unholypresence'),
    (20, 0, 'elem', 7, 'spell_nature_lightning'),
    (21, 0, 'enhance', 7, 'spell_nature_lightningshield'),
    (22, 0, 'resto', 7, 'spell_nature_magicimmunity'),
    (23, 0, 'arcane', 8, 'spell_holy_magicalsentry'),
    (24, 0, 'fire', 8, 'spell_fire_firebolt02'),
    (25, 0, 'frost', 8, 'spell_frost_frostbolt02'),
    (26, 0, 'afflic', 9, 'spell_shadow_deathcoil'),
    (27, 0, 'demo', 9, 'spell_shadow_metamorphosis'),
    (28, 0, 'destro', 9, 'spell_shadow_rainoffire'),
    (29, 0, 'balance', 11, 'spell_nature_starfall'),
    (30, 0, 'feraltank', 11, 'ability_racial_bearform'),
    (31, 0, 'resto', 11, 'spell_nature_healingtouch'),
    (32, 0, 'feraldps', 11, 'ability_druid_catform');

DROP TABLE IF EXISTS `aowow_account_weightscale_data`;
CREATE TABLE IF NOT EXISTS `aowow_account_weightscale_data` (
  `id` int(32) NOT NULL,
  `field` varchar(15) NOT NULL,
  `val` smallint(6) unsigned NOT NULL,
  KEY `id` (`id`),
  CONSTRAINT `FK_acc_weightscales` FOREIGN KEY (`id`) REFERENCES `aowow_account_weightscales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `aowow_account_weightscale_data` (`id`, `field`, `val`) VALUES
    (2, 'exprtng', 100),
    (2, 'str', 82),
    (2, 'critstrkrtng', 66),
    (2, 'agi', 53),
    (2, 'armorpenrtng', 52),
    (2, 'hitrtng', 48),
    (2, 'hastertng', 36),
    (2, 'atkpwr', 31),
    (2, 'armor', 5),
    (3, 'sta', 100),
    (3, 'dodgertng', 90),
    (3, 'defrtng', 86),
    (3, 'block', 81),
    (3, 'agi', 67),
    (3, 'parryrtng', 67),
    (3, 'blockrtng', 48),
    (3, 'str', 48),
    (3, 'exprtng', 19),
    (3, 'hitrtng', 10),
    (3, 'armorpenrtng', 10),
    (3, 'critstrkrtng', 7),
    (3, 'armor', 6),
    (3, 'hastertng', 1),
    (3, 'atkpwr', 1),
    (4, 'int', 100),
    (4, 'manargn', 88),
    (4, 'splpwr', 58),
    (4, 'critstrkrtng', 46),
    (4, 'hastertng', 35),
    (5, 'sta', 100),
    (5, 'dodgertng', 94),
    (5, 'block', 86),
    (5, 'defrtng', 86),
    (5, 'exprtng', 79),
    (5, 'agi', 76),
    (5, 'parryrtng', 76),
    (5, 'hitrtng', 58),
    (5, 'blockrtng', 52),
    (5, 'str', 50),
    (5, 'armor', 6),
    (5, 'atkpwr', 6),
    (5, 'splpwr', 4),
    (5, 'critstrkrtng', 3),
    (6, 'mledps', 470),
    (6, 'hitrtng', 100),
    (6, 'str', 80),
    (6, 'exprtng', 66),
    (6, 'critstrkrtng', 40),
    (6, 'atkpwr', 34),
    (6, 'agi', 32),
    (6, 'hastertng', 30),
    (6, 'armorpenrtng', 22),
    (6, 'splpwr', 9),
    (7, 'rgddps', 213),
    (7, 'hitrtng', 100),
    (7, 'agi', 58),
    (7, 'critstrkrtng', 40),
    (7, 'int', 37),
    (7, 'atkpwr', 30),
    (7, 'armorpenrtng', 28),
    (7, 'hastertng', 21),
    (8, 'rgddps', 379),
    (8, 'hitrtng', 100),
    (8, 'agi', 74),
    (8, 'critstrkrtng', 57),
    (8, 'armorpenrtng', 40),
    (8, 'int', 39),
    (8, 'atkpwr', 32),
    (8, 'hastertng', 24),
    (9, 'rgddps', 181),
    (9, 'hitrtng', 100),
    (9, 'agi', 76),
    (9, 'critstrkrtng', 42),
    (9, 'int', 35),
    (9, 'hastertng', 31),
    (9, 'atkpwr', 29),
    (9, 'armorpenrtng', 26),
    (10, 'mledps', 170),
    (10, 'agi', 100),
    (10, 'exprtng', 87),
    (10, 'hitrtng', 83),
    (10, 'critstrkrtng', 81),
    (10, 'atkpwr', 65),
    (10, 'armorpenrtng', 65),
    (10, 'hastertng', 64),
    (10, 'str', 55),
    (11, 'mledps', 220),
    (11, 'armorpenrtng', 100),
    (11, 'agi', 100),
    (11, 'exprtng', 82),
    (11, 'hitrtng', 80),
    (11, 'critstrkrtng', 75),
    (11, 'hastertng', 73),
    (11, 'str', 55),
    (11, 'atkpwr', 50),
    (12, 'mledps', 228),
    (12, 'exprtng', 100),
    (12, 'agi', 100),
    (12, 'hitrtng', 80),
    (12, 'armorpenrtng', 75),
    (12, 'critstrkrtng', 75),
    (12, 'hastertng', 75),
    (12, 'str', 55),
    (12, 'atkpwr', 50),
    (13, 'splpwr', 100),
    (13, 'manargn', 67),
    (13, 'int', 65),
    (13, 'hastertng', 59),
    (13, 'critstrkrtng', 48),
    (13, 'spi', 22),
    (14, 'manargn', 100),
    (14, 'int', 69),
    (14, 'splpwr', 60),
    (14, 'spi', 52),
    (14, 'critstrkrtng', 38),
    (14, 'hastertng', 31),
    (15, 'hitrtng', 100),
    (15, 'shasplpwr', 76),
    (15, 'splpwr', 76),
    (15, 'critstrkrtng', 54),
    (15, 'hastertng', 50),
    (15, 'spi', 16),
    (15, 'int', 16),
    (16, 'mledps', 360),
    (16, 'armorpenrtng', 100),
    (16, 'str', 99),
    (16, 'hitrtng', 91),
    (16, 'exprtng', 90),
    (16, 'critstrkrtng', 57),
    (16, 'hastertng', 55),
    (16, 'atkpwr', 36),
    (16, 'armor', 1),
    (17, 'mledps', 337),
    (17, 'hitrtng', 100),
    (17, 'str', 97),
    (17, 'exprtng', 81),
    (17, 'armorpenrtng', 61),
    (17, 'critstrkrtng', 45),
    (17, 'atkpwr', 35),
    (17, 'hastertng', 28),
    (17, 'armor', 1),
    (18, 'mledps', 419),
    (18, 'parryrtng', 100),
    (18, 'hitrtng', 97),
    (18, 'str', 96),
    (18, 'defrtng', 85),
    (18, 'exprtng', 69),
    (18, 'dodgertng', 61),
    (18, 'agi', 61),
    (18, 'sta', 61),
    (18, 'critstrkrtng', 49),
    (18, 'atkpwr', 41),
    (18, 'armorpenrtng', 31),
    (18, 'armor', 5),
    (19, 'mledps', 209),
    (19, 'str', 100),
    (19, 'hitrtng', 66),
    (19, 'exprtng', 51),
    (19, 'hastertng', 48),
    (19, 'critstrkrtng', 45),
    (19, 'atkpwr', 34),
    (19, 'armorpenrtng', 32),
    (19, 'armor', 1),
    (20, 'hitrtng', 100),
    (20, 'splpwr', 60),
    (20, 'hastertng', 56),
    (20, 'critstrkrtng', 40),
    (20, 'int', 11),
    (21, 'mledps', 135),
    (21, 'hitrtng', 100),
    (21, 'exprtng', 84),
    (21, 'agi', 55),
    (21, 'int', 55),
    (21, 'critstrkrtng', 55),
    (21, 'hastertng', 42),
    (21, 'str', 35),
    (21, 'atkpwr', 32),
    (21, 'splpwr', 29),
    (21, 'armorpenrtng', 26),
    (22, 'manargn', 100),
    (22, 'int', 85),
    (22, 'splpwr', 77),
    (22, 'critstrkrtng', 62),
    (22, 'hastertng', 35),
    (23, 'hitrtng', 100),
    (23, 'hastertng', 54),
    (23, 'arcsplpwr', 49),
    (23, 'splpwr', 49),
    (23, 'critstrkrtng', 37),
    (23, 'int', 34),
    (23, 'frosplpwr', 24),
    (23, 'firsplpwr', 24),
    (23, 'spi', 14),
    (24, 'hitrtng', 100),
    (24, 'hastertng', 53),
    (24, 'firsplpwr', 46),
    (24, 'splpwr', 46),
    (24, 'critstrkrtng', 43),
    (24, 'frosplpwr', 23),
    (24, 'arcsplpwr', 23),
    (24, 'int', 13),
    (25, 'hitrtng', 100),
    (25, 'hastertng', 42),
    (25, 'frosplpwr', 39),
    (25, 'splpwr', 39),
    (25, 'arcsplpwr', 19),
    (25, 'firsplpwr', 19),
    (25, 'critstrkrtng', 19),
    (25, 'int', 6),
    (26, 'hitrtng', 100),
    (26, 'shasplpwr', 72),
    (26, 'splpwr', 72),
    (26, 'hastertng', 61),
    (26, 'critstrkrtng', 38),
    (26, 'firsplpwr', 36),
    (26, 'spi', 34),
    (26, 'int', 15),
    (27, 'hitrtng', 100),
    (27, 'hastertng', 50),
    (27, 'firsplpwr', 45),
    (27, 'shasplpwr', 45),
    (27, 'splpwr', 45),
    (27, 'critstrkrtng', 31),
    (27, 'spi', 29),
    (27, 'int', 13),
    (28, 'hitrtng', 100),
    (28, 'firsplpwr', 47),
    (28, 'splpwr', 47),
    (28, 'hastertng', 46),
    (28, 'spi', 26),
    (28, 'shasplpwr', 23),
    (28, 'critstrkrtng', 16),
    (28, 'int', 13),
    (29, 'hitrtng', 100),
    (29, 'splpwr', 66),
    (29, 'hastertng', 54),
    (29, 'critstrkrtng', 43),
    (29, 'spi', 22),
    (29, 'int', 22),
    (30, 'agi', 100),
    (30, 'sta', 75),
    (30, 'dodgertng', 65),
    (30, 'defrtng', 60),
    (30, 'exprtng', 16),
    (30, 'str', 10),
    (30, 'armor', 10),
    (30, 'hitrtng', 8),
    (30, 'hastertng', 5),
    (30, 'atkpwr', 4),
    (30, 'feratkpwr', 4),
    (30, 'critstrkrtng', 3),
    (31, 'splpwr', 100),
    (31, 'manargn', 73),
    (31, 'hastertng', 57),
    (31, 'int', 51),
    (31, 'spi', 32),
    (31, 'critstrkrtng', 11),
    (32, 'agi', 100),
    (32, 'armorpenrtng', 90),
    (32, 'str', 80),
    (32, 'critstrkrtng', 55),
    (32, 'exprtng', 50),
    (32, 'hitrtng', 50),
    (32, 'feratkpwr', 40),
    (32, 'atkpwr', 40),
    (32, 'hastertng', 35);

-- add cascading to comments
DELETE r FROM aowow_comments_rates r LEFT JOIN aowow_comments c ON c.id = r.commentId WHERE c.Id IS NULL;
DELETE r FROM aowow_comments_rates r LEFT JOIN aowow_account  a ON a.id = r.userId    WHERE a.Id IS NULL;

ALTER TABLE `aowow_comments`
    ENGINE=InnoDB,
	CHANGE COLUMN `id` `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Comment ID' FIRST,
	CHANGE COLUMN `replyTo` `replyTo` INT(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Reply To, comment ID' AFTER `flags`,
    CHANGE COLUMN `userId` `userId` INT(10) UNSIGNED NULL COMMENT 'User ID' AFTER `typeId`,
    ADD CONSTRAINT `FK_acc_co` FOREIGN KEY (`userId`) REFERENCES `aowow_account` (`id`) ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE `aowow_comments_rates`
	ENGINE=InnoDB,
	ADD CONSTRAINT `FK_acc_co_rate` FOREIGN KEY (`commentId`) REFERENCES `aowow_comments` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
	ADD CONSTRAINT `FK_acc_co_rate_user` FOREIGN KEY (`userId`) REFERENCES `aowow_account` (`id`) ON UPDATE CASCADE ON DELETE NO ACTION;

-- auto-create datasets/weight-presets
UPDATE `aowow_dbversion` SET `build` = CONCAT(`build`, ' weightPresets');

SET FOREIGN_KEY_CHECKS=1;
