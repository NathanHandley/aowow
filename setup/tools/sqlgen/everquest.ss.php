<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');

if (!CLI)
    die('not in cli mode');


/*
    EQWOW: compiles EverQuest-specific lookup data from the mod_everquest_* tables that the EQWOW
    converter deploys into the world database.

    aowow_everquest_item        - per-item EQ class restriction mask (bit = 1 << (eqClassId - 1))
    aowow_everquest_spell_learn - which EQ class learns which spell at which level.
                                  Sourced from spell scroll items (class 9, spelltrigger_2 = 6, where
                                  RequiredLevel is the EQ learn level) and from the mod's level-1
                                  auto-learn table.

    EQ class ids (1-14): Warrior, Cleric, Paladin, Ranger, Shadow Knight, Druid, Monk, Bard, Rogue,
                         Shaman, Necromancer, Wizard, Magician, Enchanter
*/

CLISetup::registerSetup("sql", new class extends SetupScript
{
    protected $info = array(
        'everquest' => [[], CLISetup::ARGV_PARAM, 'Compiles EverQuest class data for items and spells from the EQWOW mod tables in the world db.']
    );

    protected $worldDependency = ['item_template', 'mod_everquest_item_template', 'mod_everquest_playerautolearnspells'];

    public function generate(array $ids = []) : bool
    {
        DB::Aowow()->query(
           'CREATE TABLE IF NOT EXISTS ?_everquest_item (
                `id`          INT UNSIGNED NOT NULL,
                `eqClassMask` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        DB::Aowow()->query(
           'CREATE TABLE IF NOT EXISTS ?_everquest_spell_learn (
                `spellId`      INT UNSIGNED NOT NULL,
                `eqClass`      TINYINT UNSIGNED NOT NULL,
                `learnLevel`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `scrollItemId` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`spellId`, `eqClass`),
                INDEX `idx_eqclass` (`eqClass`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        DB::Aowow()->query('TRUNCATE ?_everquest_item');
        DB::Aowow()->query('TRUNCATE ?_everquest_spell_learn');

        // EQWOW: joke page subtitles are not wanted on this site; keep them deactivated even if a
        // fresh db_structure.sql import re-seeds them
        DB::Aowow()->query('UPDATE ?_home_titles SET `active` = 0');

        if (!DB::World()->selectCell('SHOW TABLES LIKE ?', 'mod_everquest_item_template'))
        {
            CLI::write('[everquest] mod_everquest_item_template not found in world db - EQWOW data not deployed? Skipping.', CLI::LOG_WARN);
            return true;
        }

        // item -> EQ class restrictions
        $items = DB::World()->select('SELECT `ItemTemplateID` AS ARRAY_KEY, `AllowedEQClassMask` FROM mod_everquest_item_template');
        foreach (array_chunk($items, 1000, true) as $chunk)
        {
            $rows = [];
            foreach ($chunk as $itemId => $row)
                $rows[] = [$itemId, $row['AllowedEQClassMask']];
            DB::Aowow()->query('INSERT IGNORE INTO ?_everquest_item VALUES (?a)', $rows);
        }
        CLI::write('[everquest] '.count($items).' item class masks copied', CLI::LOG_BLANK, true, true);

        // spell learn data from class-split scrolls: RequiredLevel is the EQ learn level
        $scrolls = DB::World()->select(
           'SELECT    i.`entry` AS ARRAY_KEY, i.`RequiredLevel`, i.`spellid_2` AS spellId, m.`AllowedEQClassMask`
            FROM      item_template i
            JOIN      mod_everquest_item_template m ON m.`ItemTemplateID` = i.`entry`
            WHERE     i.`class` = 9 AND i.`spelltrigger_2` = 6 AND i.`spellid_2` > 0'
        );

        $learnRows = [];
        foreach ($scrolls as $itemId => $scroll)
            for ($clsId = 1; $clsId <= 14; $clsId++)
                if ($scroll['AllowedEQClassMask'] & (1 << ($clsId - 1)))
                    $learnRows[] = [$scroll['spellId'], $clsId, $scroll['RequiredLevel'], $itemId];

        foreach (array_chunk($learnRows, 1000) as $chunk)
            DB::Aowow()->query('INSERT IGNORE INTO ?_everquest_spell_learn VALUES (?a)', $chunk);

        CLI::write('[everquest] '.count($learnRows).' spell learn entries from scrolls', CLI::LOG_BLANK, true, true);

        // level-1 abilities granted by the mod at login (Bind, Gate, Forage, ...); scroll data wins on conflict.
        // Restricted to the EQWOW custom spell id range (mod_everquest_systemconfigs) so stock WoW proficiency
        // spells the mod also auto-grants don't pollute the EQ class spell lists.
        if (DB::World()->selectCell('SHOW TABLES LIKE ?', 'mod_everquest_playerautolearnspells'))
        {
            $eqSpellMin = (int)DB::World()->selectCell('SELECT `Value` FROM mod_everquest_systemconfigs WHERE `Key` = ?', 'SpellDBCIDMin') ?: 86900;
            $eqSpellMax = (int)DB::World()->selectCell('SELECT `Value` FROM mod_everquest_systemconfigs WHERE `Key` = ?', 'SpellDBCIDMax') ?: 130000;
            $auto = DB::World()->select('SELECT `eqclass`, `spell`, MIN(`level`) AS lvl FROM mod_everquest_playerautolearnspells WHERE `spell` BETWEEN ?d AND ?d GROUP BY `eqclass`, `spell`', $eqSpellMin, $eqSpellMax);
            $rows = [];
            foreach ($auto as $a)
                $rows[] = [$a['spell'], $a['eqclass'], $a['lvl'], 0];

            if ($rows)
                foreach (array_chunk($rows, 1000) as $chunk)
                    DB::Aowow()->query('INSERT IGNORE INTO ?_everquest_spell_learn VALUES (?a)', $chunk);

            CLI::write('[everquest] '.count($rows).' auto-learn entries merged', CLI::LOG_BLANK, true, true);
        }

        return true;
    }
});

?>
