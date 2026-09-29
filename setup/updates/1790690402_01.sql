-- EQWOW - open world raid boss scaling, mirrors mod-everquest EverQuest.CreatureWorldBoss.* (keep in step with the server's Everquest.conf)
INSERT IGNORE INTO `aowow_config` (`key`, `value`, `default`, `cat`, `flags`, `comment`) VALUES
    ('eqwow_wboss_health_mult', '2.5', '2.5', 1, 130, 'EQWOW: open world raid boss health multiplier (mod-everquest EverQuest.CreatureWorldBoss.HealthMultiplier)'),
    ('eqwow_wboss_damage_mult', '1.5', '1.5', 1, 130, 'EQWOW: open world raid boss melee + spell damage multiplier (mod-everquest EverQuest.CreatureWorldBoss.DamageMultiplier)'),
    ('eqwow_wboss_loot_mult',   '2',   '2',     1, 129, 'EQWOW: open world raid boss loot table roll count (mod-everquest EverQuest.CreatureWorldBoss.LootMultiplier)');
