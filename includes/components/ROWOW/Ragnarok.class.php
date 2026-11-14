<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');

/*
    ROWOW - Ragnarok Online data for items, jobs and skills, compiled by setup/tools/sqlgen/ragnarok.ss.php into
    ?_ragnarok_item, ?_ragnarok_job, ?_ragnarok_skill, ?_ragnarok_job_skill and ?_ragnarok_skill_spell.

    RO items are WoW items of class 15 / 4 (or consumables) that carry no WoW stats; their RO stats live in ?_ragnarok_item.
    Prices: item_template Buy/SellPrice are zeny (RO_ITEM_COPPER_PER_ZENY = 1, and mod-rowow's Zeny is the player's money).
    The site is enUS only (as the EQWOW additions), so the texts live here.
*/
class Ragnarok
{
    // ROWOW_ITEM_CLASS_*
    public const ITEM_CLASS_NORMAL = 0x01;
    public const ITEM_CLASS_UPPER  = 0x02;
    public const ITEM_CLASS_BABY   = 0x04;

    // ROWOWItemJobNames, bit index = position
    public const ITEM_JOB_NAMES = ['Novice', 'Swordman', 'Mage', 'Archer', 'Acolyte', 'Merchant', 'Thief', 'Knight', 'Priest', 'Wizard', 'Blacksmith', 'Hunter',
        'Assassin', 'Crusader', 'Monk', 'Sage', 'Rogue', 'Alchemist', 'Bard / Dancer', 'Taekwon', 'Star Gladiator', 'Soul Linker', 'Gunslinger', 'Ninja', 'Super Novice'];

    public const JOB_TIERS = [0 => 'Novice', 1 => 'First Jobs', 2 => 'Second Jobs', 3 => 'Transcendent First Jobs', 4 => 'Transcendent Second Jobs', 5 => 'Expanded Jobs', 6 => 'Baby Jobs'];

    // ?_ragnarok_item.roCategory (sqlgen ragnarok.ss.php), also LANG.fidropdowns.rocategory / mn_items "Ragnarok"
    public const CATEGORIES = array(
         1 => 'Dagger',          2 => 'One-Handed Sword', 3 => 'Two-Handed Sword', 4 => 'One-Handed Spear', 5 => 'Two-Handed Spear',
         6 => 'One-Handed Axe',  7 => 'Two-Handed Axe',   8 => 'Mace',             9 => 'Staff',           10 => 'Bow',
        11 => 'Knuckle',        12 => 'Musical Instrument', 13 => 'Whip',         14 => 'Book',            15 => 'Katar',
        16 => 'Revolver',       17 => 'Rifle',           18 => 'Gatling Gun',     19 => 'Shotgun',         20 => 'Grenade Launcher',
        21 => 'Huuma Shuriken', 22 => 'Two-Handed Staff',
        30 => 'Upper Headgear', 31 => 'Middle Headgear', 32 => 'Lower Headgear',  33 => 'Body Armor',      34 => 'Shield',
        35 => 'Garment',        36 => 'Footgear',        37 => 'Accessory',       38 => 'Costume',         39 => 'Armor',
        40 => 'Card',           41 => 'Healing Item',    42 => 'Usable Item',     43 => 'Delayed Consumable', 44 => 'Ammunition',
        45 => 'Pet Egg',        46 => 'Pet Armor',       47 => 'Miscellaneous',   48 => 'Cash Shop Item'
    );

    // MergedWorlds: item filter cr=322 value for every weapon category (1 - 22), the Items > Ragnarok Items > Weapons menu
    public const CATEGORY_ANY_WEAPON = 100;

    public const CATEGORY_CARD = 40;

    // MergedWorlds: what a card compounds on, item filter cr=323 and the Items > Ragnarok Items > Cards menu (also LANG.fidropdowns.rocompound
    // and mn_items_mw.roCardSlots): value => [name, ?_ragnarok_item.locations LIKE pattern].  Headgear cards list all three head slots, and
    // the enchantment orbs (roSubType Enchant) none
    public const COMPOUND_SLOTS = array(
        1 => ['Weapon',    'Right_Hand'],
        2 => ['Shield',    'Left_Hand'],
        3 => ['Armor',     'Armor'],
        4 => ['Garment',   'Garment'],
        5 => ['Footgear',  'Shoes'],
        6 => ['Headgear',  '%Head_%'],
        7 => ['Accessory', '%Accessory%'],
        8 => ['Enchantments', '']
    );

    // the COMPOUND_SLOTS value of a card's ?_ragnarok_item.locations (0: none matches)
    public static function compoundSlot(string $locations) : int
    {
        foreach (self::COMPOUND_SLOTS as $slot => [, $pattern])
        {
            $regex = '/^'.str_replace(['%', '_'], ['.*', '.'], $pattern).'$/';
            if (preg_match($regex, $locations))
                return $slot;
        }

        return 0;
    }

    private const LOCATIONS = array(
        'Head_Top' => 'Upper Headgear', 'Head_Mid' => 'Middle Headgear', 'Head_Low' => 'Lower Headgear', 'Armor' => 'Body Armor',
        'Right_Hand' => 'Weapon', 'Left_Hand' => 'Shield', 'Both_Hand' => 'Two-Handed Weapon', 'Garment' => 'Garment', 'Shoes' => 'Footgear',
        'Right_Accessory' => 'Accessory', 'Left_Accessory' => 'Accessory', 'Both_Accessory' => 'Accessory', 'Ammo' => 'Ammunition',
        'Costume_Head_Top' => 'Costume Upper Headgear', 'Costume_Head_Mid' => 'Costume Middle Headgear', 'Costume_Head_Low' => 'Costume Lower Headgear',
        'Costume_Garment' => 'Costume Garment'
    );

    public static function enabled() : bool
    {
        static $enabled = null;
        if ($enabled === null)
            $enabled = (bool)DB::Aowow()->selectCell('SHOW TABLES LIKE ?', '%ragnarok_item'); // prefix + ragnarok_item (sqlgen ragnarok)

        return $enabled;
    }

    // [wowItemId => row] for the RO items among $ids
    public static function itemData(array $ids) : array
    {
        if (!$ids || !self::enabled())
            return [];

        return DB::Aowow()->select('SELECT `id` AS ARRAY_KEY, r.* FROM ?_ragnarok_item r WHERE `id` IN (?a)', array_values($ids));
    }

    // [jobId => row], in display order
    public static function jobs() : array
    {
        static $jobs = null;
        if ($jobs === null)
            $jobs = self::enabled() ? DB::Aowow()->select('SELECT `id` AS ARRAY_KEY, j.* FROM ?_ragnarok_job j ORDER BY `sortOrder`') : [];

        return $jobs;
    }

    public static function jobName(int $jobId) : string
    {
        return self::jobs()[$jobId]['displayName'] ?? 'Job #'.$jobId;
    }

    public static function jobLink(int $jobId) : string
    {
        return '<a href="?ragnarok-job='.$jobId.'">'.htmlspecialchars(self::jobName($jobId)).'</a>';
    }

    // the job page an item job name (ROWOWItemJobNames index) leads to: its normal (non transcendent, non baby) job
    private static function itemJobPage(int $idx) : ?int
    {
        foreach (self::jobs() as $id => $j)
            if ($j['itemJobIdx'] == $idx && $j['itemClass'] == self::ITEM_CLASS_NORMAL)
                return $id;

        return null;
    }

    // ids of the jobs that may use an item (jobs with no item job, the Peco variants, never)
    public static function jobsForItem(int $jobMask, int $classMask) : array
    {
        $out = [];
        foreach (self::jobs() as $id => $j)
            if ($j['itemJobIdx'] >= 0 && ($jobMask & (1 << $j['itemJobIdx'])) && ($classMask & $j['itemClass']))
                $out[] = $id;

        return $out;
    }

    public static function zeny(int $zeny, bool $html = true) : string
    {
        $n = number_format($zeny);
        return $html ? '<span class="moneyzeny">'.$n.' Zeny</span>' : $n.' Zeny';
    }

    public static function categoryName(int $cat) : string
    {
        return self::CATEGORIES[$cat] ?? 'Ragnarok Item';
    }

    private static function locationNames(string $locs) : array
    {
        $out = [];
        foreach (explode(',', $locs) as $l)
            if ($l !== '')
                $out[self::LOCATIONS[$l] ?? str_replace('_', ' ', $l)] = 1;

        return array_keys($out);
    }

    // readable "usable by" text: All jobs / All jobs except X / X, Y, Z (+ class and gender restrictions)
    public static function jobText(array $ro, bool $links = true) : string
    {
        $all     = (1 << count(self::ITEM_JOB_NAMES)) - 1;
        $mask    = (int)$ro['jobMask'] & $all;
        $name    = function (int $idx) use ($links) {
            $n = self::ITEM_JOB_NAMES[$idx];
            if ($links && ($j = self::itemJobPage($idx)) !== null)
                return '<a href="?ragnarok-job='.$j.'">'.$n.'</a>';
            return $n;
        };

        if ($mask == $all)
            $txt = 'All jobs';
        else if (!$mask)
            $txt = 'No job';
        else if (substr_count(decbin($mask), '1') > count(self::ITEM_JOB_NAMES) / 2)
        {
            $ex = [];
            foreach (self::ITEM_JOB_NAMES as $idx => $_)
                if (!($mask & (1 << $idx)))
                    $ex[] = $name($idx);
            $txt = 'All jobs except '.implode(', ', $ex);
        }
        else
        {
            $in = [];
            foreach (self::ITEM_JOB_NAMES as $idx => $_)
                if ($mask & (1 << $idx))
                    $in[] = $name($idx);
            $txt = implode(', ', $in);
        }

        $cm = (int)$ro['classMask'];
        if (($cm & 0x07) != 0x07)
        {
            $cls = [];
            if ($cm & self::ITEM_CLASS_NORMAL)
                $cls[] = 'normal';
            if ($cm & self::ITEM_CLASS_UPPER)
                $cls[] = 'transcendent';
            if ($cm & self::ITEM_CLASS_BABY)
                $cls[] = 'baby';
            $txt .= ' ('.($cls ? implode(' and ', $cls).' jobs only' : 'no job can use it').')';
        }

        if ($ro['gender'] == 1)
            $txt .= ', female only';
        else if ($ro['gender'] == 2)
            $txt .= ', male only';

        return $txt;
    }

    // the RO block of an item tooltip (after the name)
    public static function itemTooltip(array $ro) : string
    {
        $x   = '<div style="max-width: 330px; white-space: normal">';   // long job / bonus lines wrap instead of widening the tooltip
        $cat = (int)$ro['roCategory'];

        // type line: category | weapon level
        $x .= '<table width="100%"><tr><td>'.self::categoryName($cat).'</td>';
        if ($ro['roType'] == 'Weapon' && $ro['weaponLevel'])
            $x .= '<th>Weapon Level '.$ro['weaponLevel'].'</th>';
        else if ($ro['roType'] == 'Armor' && $ro['armorLevel'] > 1)
            $x .= '<th>Armor Level '.$ro['armorLevel'].'</th>';
        $x .= '</tr></table>';

        if ($ro['roType'] == 'Card' && $ro['locations'])
            $x .= 'Compounds on: '.implode(', ', self::locationNames($ro['locations'])).'<br />';
        else if ($ro['roType'] == 'Armor' && $ro['locations'] && count(self::locationNames($ro['locations'])) > 1)
            $x .= 'Worn on: '.implode(', ', self::locationNames($ro['locations'])).'<br />';

        if ($ro['baseAtk'])
            $x .= 'ATK '.$ro['baseAtk'].($ro['atkRange'] > 1 ? '<span class="q0"> (range '.$ro['atkRange'].')</span>' : '').'<br />';
        if ($ro['baseDef'])
            $x .= 'DEF '.$ro['baseDef'].'<br />';

        if ($ro['isEquip'] && $ro['roType'] != 'Card' && $ro['roType'] != 'Ammo')
            $x .= 'Slots: '.$ro['slots'].'<br />';

        if ((float)$ro['weight'] > 0)
            $x .= 'Weight: '.rtrim(rtrim(number_format((float)$ro['weight'], 1), '0'), '.').'<br />';

        // bonuses
        if ($ro['bonusLines'] && ($lines = json_decode($ro['bonusLines'], true)))
        {
            $shown = 0;
            foreach ($lines as $l)
            {
                $when = isset($l[2]) ? '<span class="q0">'.($l[2] == 'equip' ? 'When equipped: ' : 'When unequipped: ').'</span>' : '';
                if ($l[1])
                    $x .= '<span class="q2">'.$when.$l[0].'</span><br />';
                else if ($shown++ < 4)
                    $x .= '<span class="q0">'.$when.$l[0].'</span><br />';
            }

            if ($shown > 4)
                $x .= '<span class="q0">('.($shown - 4).' more script lines)</span><br />';
        }

        if ($ro['isEquip'] && $ro['roType'] != 'Card')
        {
            if ($ro['equipLevelMax'])
                $x .= 'Base level '.max(1, $ro['equipLevelMin']).' - '.$ro['equipLevelMax'].'<br />';
            $x .= 'Jobs: '.self::jobText($ro).'<br />';
            $x .= $ro['refineable'] ? '<span class="q1">Refineable</span><br />' : '<span class="q0">Cannot be refined</span><br />';
        }

        return $x.'</div>';
    }

    // markup lines for the item page infobox
    public static function itemInfobox(array $ro) : array
    {
        $box = [];
        $box[] = 'Ragnarok item: '.self::categoryName((int)$ro['roCategory']).' [span class=q0](#'.$ro['roId'].' '.$ro['aegisName'].')[/span]';
        if ($ro['buyZeny'])
            $box[] = 'Buy price: '.self::zeny((int)$ro['buyZeny'], false);
        if ($ro['sellZeny'])
            $box[] = 'Sell price: '.self::zeny((int)$ro['sellZeny'], false);
        if ($ro['cardEnchantId'])
            $box[] = 'Card enchantment: [url=?enchantment='.$ro['cardEnchantId'].']#'.$ro['cardEnchantId'].'[/url]';
        if ($ro['isRenewal'])
            $box[] = '[span class=q0]Post-renewal look (no stats)[/span]';

        return $box;
    }

    // extra listview fields (zeny flag for the price columns, RO stats for the filter columns)
    public static function listviewFields(array $ro) : array
    {
        $f = ['zeny' => 1];
        foreach (['str' => 'rostr', 'agi' => 'roagi', 'vit' => 'rovit', 'int' => 'roint', 'dex' => 'rodex', 'luk' => 'roluk', 'atk' => 'roatk',
                  'matk' => 'romatk', 'matkRate' => 'romatkrate', 'def' => 'rodef', 'mdef' => 'romdef', 'slots' => 'roslots', 'weaponLevel' => 'roweaponlevel',
                  'weight' => 'roweight', 'equipLevelMin' => 'roequiplevel', 'hit' => 'rohit', 'flee' => 'roflee', 'crit' => 'rocrit',
                  'maxhp' => 'romaxhp', 'maxsp' => 'romaxsp', 'aspdRate' => 'roaspd'] as $col => $key)
            if ((float)$ro[$col])
                $f[$key] = $col == 'weight' ? (float)$ro[$col] : (int)$ro[$col];

        return $f;
    }

    // RO info for a spell that is an RO skill level: [skill row, level, [jobIds]] or null
    public static function skillForSpell(int $spellId) : ?array
    {
        if (!self::enabled())
            return null;

        $ss = DB::Aowow()->selectRow('SELECT ss.`skillLevel`, s.* FROM ?_ragnarok_skill_spell ss JOIN ?_ragnarok_skill s ON s.`name` = ss.`skillName` WHERE ss.`spellId` = ?d', $spellId);
        if (!$ss)
            return null;

        $jobs = DB::Aowow()->selectCol('SELECT js.`jobId` FROM ?_ragnarok_job_skill js JOIN ?_ragnarok_job j ON j.`id` = js.`jobId` WHERE js.`skillName` = ?', $ss['name']);
        return [$ss, (int)$ss['skillLevel'], $jobs];
    }

    // WoW spell ids of a job's own skills (all levels); $passive: null both, true passive, false active
    public static function jobSpellIds(?int $jobId, ?bool $passive = null) : array
    {
        if (!self::enabled())
            return [];

        return DB::Aowow()->selectCol(
           'SELECT DISTINCT ss.`spellId`
            FROM   ?_ragnarok_job_skill js
            JOIN   ?_ragnarok_skill s        ON s.`name`       = js.`skillName`
            JOIN   ?_ragnarok_skill_spell ss ON ss.`skillName` = js.`skillName`
            WHERE  1 { AND js.`jobId` = ?d } { AND s.`passive` = ?d }',
            $jobId ?? DBSIMPLE_SKIP,
            $passive === null ? DBSIMPLE_SKIP : ($passive ? 1 : 0)
        );
    }


    /*
        Monster drops. An RO monster's creature_template has no lootid: mod-rowow rolls mob_db's Drops and MvpDrops (mod_rowow_db_mob, rAthena
        AegisName, Rate per 10000) itself, the rates adjusted by drops.conf (mod_rowow_db_battle_config), plus the converter's companion carriers
        (mod_rowow_companion_drop, at their own rate).  As ROWOW_FloorItem.cpp LoadFloorItemData / MobDropAdjust / MobGetDropRate / MobRollDrops and
        ROWOW_MobSkill.cpp's MVP reward do it.  Read from the world db when asked, as aowow reads the WoW loot templates.
        Left out: what the killer adds (LUK with drops_by_luk*, Bubble Gum, bAddMonsterDropItem): the chances are a player's without such bonuses.
    */

    private const DROP_KINDS = ['common', 'heal', 'use', 'equip', 'card', 'treasure'];

    private static function hasMobData() : bool
    {
        static $has = null;
        if ($has === null)
            $has = DB::World()->selectCell('SHOW TABLES LIKE ?', 'mod_rowow_db_mob') && DB::World()->selectCell('SHOW TABLES LIKE ?', 'mod_rowow_creature_mob') &&
                   DB::World()->selectCell('SHOW TABLES LIKE ?', 'mod_rowow_item');

        return $has;
    }

    // the drops.conf values the drops use, rAthena's defaults (ROWOW.h ROWOWBattleConfig) unless mod_rowow_db_battle_config sets them
    public static function dropConfig() : array
    {
        static $cfg = null;
        if ($cfg !== null)
            return $cfg;

        $cfg = array(
            'item_rate_mvp'          => 100, 'item_drop_mvp_min' => 1, 'item_drop_mvp_max' => 10000, 'item_drop_mvp_mode' => 0,
            'item_logarithmic_drops' => 0,   'drop_rate0item'    => 0, 'drop_rateincrease' => 0
        );
        foreach (self::DROP_KINDS as $k)
            $cfg += ['item_rate_'.$k => 100, 'item_rate_'.$k.'_boss' => 100, 'item_rate_'.$k.'_mvp' => 100, 'item_drop_'.$k.'_min' => 1, 'item_drop_'.$k.'_max' => 10000];

        if (DB::World()->selectCell('SHOW TABLES LIKE ?', 'mod_rowow_db_battle_config'))
        {
            foreach (DB::World()->select('SELECT `Key` AS "k", `Value` AS "v" FROM mod_rowow_db_battle_config WHERE `Key` IN (?a) ORDER BY `RowIndex`', array_keys($cfg)) as $r)
            {
                $v = trim((string)$r['v']);                 // ParseBattleConfigValue: yes / no, hexadecimal or decimal
                $cfg[$r['k']] = match ($v) { 'yes', 'on' => 1, 'no', 'off' => 0, default => intval($v, 0) };
            }
        }

        return $cfg;
    }

    // a short text on the drop rates, for listview notes
    public static function dropRateNote() : string
    {
        $cfg   = self::dropConfig();
        $rates = [$cfg['item_rate_mvp']];
        foreach (self::DROP_KINDS as $k)
            array_push($rates, $cfg['item_rate_'.$k], $cfg['item_rate_'.$k.'_boss'], $cfg['item_rate_'.$k.'_mvp']);

        if (count(array_unique($rates)) == 1 && $rates[0] == 100 && !$cfg['drop_rateincrease'])
            return 'Drop rates are the server\'s (drops.conf: 1x).';

        if (count(array_unique($rates)) == 1 && !$cfg['item_logarithmic_drops'] && !$cfg['drop_rateincrease'])
            return 'Drop rates are the server\'s (drops.conf: '.($rates[0] / 100).'x).';

        return 'Drop rates are the server\'s (drops.conf adjusted).';
    }

    // mob_drop_adjust
    private static function dropAdjust(int $base, int $adjust, int $min, int $max) : int
    {
        $rate = (float)$base;
        if (self::dropConfig()['item_logarithmic_drops'] && $adjust > 0 && $adjust != 100 && $base > 0)
            $rate = $rate * pow(5.0 - log10($rate), log($adjust / 100) / log(5.0)) + 0.5;
        else
            $rate = $rate * $adjust / 100;

        return (int)max($min, min($max, $rate));
    }

    // rAthena's map lists: "Item=Jellopy,Rate=7000;Item=Poring_Card,Rate=1,StealProtected=true;"
    private static function parseMapList(?string $cell) : array
    {
        $out = [];
        foreach (explode(';', (string)$cell) as $entry)
        {
            if ($entry === '')
                continue;

            $map = [];
            foreach (explode(',', $entry) as $pair)
            {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $map[trim($k)] = trim($v);
            }
            $out[] = $map;
        }

        return $out;
    }

    // [lower case AegisName => [WoW item id, RO item type]] of the converted items (ROWOW_Items.cpp LoadItemScriptData: item_db, then the post-renewal looks)
    private static function itemsByAegisName(array $names) : array
    {
        $names = array_values(array_unique(array_filter($names)));
        if (!$names)
            return [];

        $out = [];
        foreach (['mod_rowow_db_item', 'mod_rowow_db_item_renewal'] as $tbl)
        {
            if (!DB::World()->selectCell('SHOW TABLES LIKE ?', $tbl))
                continue;

            $rows = DB::World()->select('SELECT i.`AegisName` AS "aegis", i.`Type` AS "type", m.`ItemTemplateID` AS "itemId" FROM ?# i JOIN mod_rowow_item m ON m.`ItemID` = i.`Id` WHERE i.`AegisName` IN (?a) ORDER BY i.`RowIndex`', $tbl, $names);
            foreach ($rows as $r)
                $out[strtolower($r['aegis'])] = [(int)$r['itemId'], $r['type'] ?: 'Etc'];
        }

        return $out;
    }

    // MVP reward chances: one item unless item_drop_mvp_mode is 2; 0: the first in mob_db's order that succeeds, 1: the same in a random order
    private static function mvpChances(array $p, int $mode) : array
    {
        $n = count($p);
        if ($mode == 2 || $n < 2)
            return $p;

        $out = [];
        if ($mode != 1 || $n > 12)
        {
            $miss = 1.0;
            foreach ($p as $i => $x)
            {
                $out[$i] = $x * $miss;
                $miss   *= 1 - $x;
            }

            return $out;
        }

        // random order: an item is given when it succeeds and all shuffled before it failed; P(exactly the set S before it) = |S|! (n-1-|S|)! / n!
        $fact = [1];
        for ($i = 1; $i <= $n; $i++)
            $fact[$i] = $fact[$i - 1] * $i;

        foreach ($p as $i => $x)
        {
            $others = array_values(array_diff_key($p, [$i => 0]));
            $sum    = 0.0;
            for ($mask = 0; $mask < (1 << ($n - 1)); $mask++)
            {
                $miss = 1.0;
                $k    = 0;
                foreach ($others as $j => $y)
                {
                    if ($mask & (1 << $j))
                    {
                        $miss *= 1 - $y;
                        $k++;
                    }
                }
                $sum += $miss * $fact[$k] * $fact[$n - 1 - $k] / $fact[$n];
            }
            $out[$i] = $x * $sum;
        }

        return $out;
    }

    /*
        [mobId => ['isMvp' => bool, 'drops' => [row], 'mvp' => [row]]] for $mobIds (null: every monster)
        row: ['item' => WoW item id, 'percent' => 0 - 100, 'stealProtected' => bool, 'companion' => bool], in mob_db's order
        drops roll each on their own; of the MVP reward a player gets one item (see mvpChances), 'percent' is the chance to get this one
    */
    public static function mobDrops(?array $mobIds = null) : array
    {
        if (!self::hasMobData() || ($mobIds !== null && !$mobIds))
            return [];

        $cfg  = self::dropConfig();
        $mobs = DB::World()->select('SELECT `Id` AS ARRAY_KEY, `Class`, `Modes`, `RaceGroups`, `Drops`, `MvpDrops` FROM mod_rowow_db_mob WHERE `Id` IS NOT NULL { AND `Id` IN (?a) } ORDER BY `RowIndex`', $mobIds ? array_values($mobIds) : DBSIMPLE_SKIP);

        $lists = [];
        $names = [];
        foreach ($mobs as $mobId => $m)
        {
            $lists[$mobId] = [self::parseMapList($m['Drops']), self::parseMapList($m['MvpDrops'])];
            foreach ($lists[$mobId] as $list)
                foreach ($list as $d)
                    $names[] = $d['Item'] ?? '';
        }
        $items = self::itemsByAegisName($names);

        $companion = [];
        if (DB::World()->selectCell('SHOW TABLES LIKE ?', 'mod_rowow_companion_drop'))
            foreach (DB::World()->select('SELECT `MobID` AS "mobId", `ItemTemplateID` AS "itemId", `Rate` AS "rate" FROM mod_rowow_companion_drop WHERE 1 { AND `MobID` IN (?a) }', $mobIds ? array_values($mobIds) : DBSIMPLE_SKIP) as $c)
                $companion[(int)$c['mobId']][] = [(int)$c['itemId'], (int)$c['rate']];

        // parseDrops: the converted items with a rate
        $resolve = function (array $d) use ($items) : ?array
        {
            $item = $items[strtolower($d['Item'] ?? '')] ?? null;
            $rate = (int)($d['Rate'] ?? 0);
            return $item && $rate != 0 ? [$item[0], $item[1], $rate] : null;
        };

        $out = [];
        foreach ($mobs as $mobId => $m)
        {
            $modes = self::parseMapList($m['Modes'])[0] ?? [];
            $isMvp      = ($modes['Mvp'] ?? '') == 'true';
            $isBoss     = !$isMvp && $m['Class'] == 'Boss';
            $isTreasure = str_contains((string)$m['RaceGroups'], 'Treasure');
            $res        = ['isMvp' => $isMvp, 'drops' => [], 'mvp' => []];

            foreach ($lists[$mobId][0] as $d)
            {
                if (!($r = $resolve($d)))
                    continue;

                [$itemId, $type, $rate] = $r;
                if ($cfg['drop_rateincrease'] && $rate < 5000)
                    $rate++;

                // by the item's type, treasure boxes by their own
                $kind = $isTreasure ? 'treasure' : match ($type)
                {
                    'Healing'                     => 'heal',
                    'Usable', 'Cash'              => 'use',
                    'Weapon', 'Armor', 'Petarmor' => 'equip',
                    'Card'                        => 'card',
                    default                       => 'common'
                };
                $adjust = $cfg['item_rate_'.$kind.($isTreasure ? '' : ($isMvp ? '_mvp' : ($isBoss ? '_boss' : '')))];
                if (!$adjust)
                    continue;                               // a rate of 0 removes the item

                $rate = self::dropAdjust($rate, $adjust, $cfg['item_drop_'.$kind.'_min'], $cfg['item_drop_'.$kind.'_max']);

                // mob_getdroprate for a player without drop bonuses (drop_rate_cap only caps what bonuses add): at most 100%, at least 0.01% unless drop_rate0item
                $rate = max(min($rate, 10000), $cfg['drop_rate0item'] ? 0 : 1);

                $res['drops'][] = ['item' => $itemId, 'percent' => $rate / 100, 'stealProtected' => ($d['StealProtected'] ?? '') == 'true', 'companion' => false];
            }

            foreach ($companion[$mobId] ?? [] as [$itemId, $rate])
                $res['drops'][] = ['item' => $itemId, 'percent' => max(0, min($rate, 10000)) / 100, 'stealProtected' => false, 'companion' => true];

            if ($cfg['item_rate_mvp'])
            {
                $mvp = [];
                foreach ($lists[$mobId][1] as $d)
                {
                    if (!($r = $resolve($d)))
                        continue;

                    // mob_dead: 10000 needs no roll, a rate of 0 or less becomes 1 unless drop_rate0item
                    $rate = self::dropAdjust($r[2], $cfg['item_rate_mvp'], $cfg['item_drop_mvp_min'], $cfg['item_drop_mvp_max']);
                    if ($rate <= 0)
                        $rate = $cfg['drop_rate0item'] ? 0 : 1;
                    $mvp[] = [$r[0], min($rate, 10000) / 10000];
                }

                foreach (self::mvpChances(array_column($mvp, 1), (int)$cfg['item_drop_mvp_mode']) as $i => $chance)
                    $res['mvp'][] = ['item' => $mvp[$i][0], 'percent' => $chance * 100, 'stealProtected' => false, 'companion' => false];
            }

            $out[$mobId] = $res;
        }

        return $out;
    }

    // [creature template id => mob id]: of the given creatures / of the given monsters (null: all)
    public static function mobIdsOfCreatures(array $creatureIds) : array
    {
        if (!$creatureIds || !self::hasMobData())
            return [];

        return DB::World()->selectCol('SELECT `CreatureTemplateID` AS ARRAY_KEY, `MobID` FROM mod_rowow_creature_mob WHERE `CreatureTemplateID` IN (?a)', array_values($creatureIds));
    }

    public static function creaturesOfMobs(?array $mobIds = null) : array
    {
        if (($mobIds !== null && !$mobIds) || !self::hasMobData())
            return [];

        return DB::World()->selectCol('SELECT `CreatureTemplateID` AS ARRAY_KEY, `MobID` FROM mod_rowow_creature_mob WHERE 1 { AND `MobID` IN (?a) }', $mobIds ? array_values($mobIds) : DBSIMPLE_SKIP);
    }

    // creature template ids of the RO monsters that drop anything (drops or MVP reward)
    public static function creaturesWithDrops() : array
    {
        $drops = array_filter(self::mobDrops(), fn($m) => $m['drops'] || $m['mvp']);
        return array_keys(self::creaturesOfMobs(array_keys($drops)));
    }

    /*
        Monster skills.  An RO monster has no SmartAI and no creature_template spells: mod-rowow's mob AI uses mob_skill_db's rows (mod_rowow_db_mob_skill,
        one row a skill use: the monster's state, the skill and level, the chance per 10000, cast time and reuse delay in ms, its target and condition),
        casting each skill's WoW spell (mod_rowow_skill_spell by the skill's name and level, as ROWOW_SkillEngine.cpp GetSkillSpellID; the monster-only
        NPC_ skills have one spell for all their levels).
    */
    private const MOB_SKILL_STATES = array(
        'any'       => 'Any time',
        'idle'      => 'Idle',
        'walk'      => 'Walking',
        'loot'      => 'Looting',
        'dead'      => 'On death',
        'attack'    => 'In combat',
        'angry'     => 'Before it attacks',
        'chase'     => 'Chasing',
        'follow'    => 'Chasing',
        'anytarget' => 'With a target'
    );

    private static function mobSkillCondition(string $type, ?string $value) : string
    {
        $v = $value ?? '0';
        return match (strtolower($type))
        {
            'always'            => '',
            'onspawn', 'spawn'  => 'when it appears',
            'myhpltmaxrate'     => 'at '.$v.'% HP or less',
            'myhpinrate'        => 'at '.$v.'% HP or more',
            'mystatuson'        => 'while it has '.$v,
            'mystatusoff'       => 'while it doesn\'t have '.$v,
            'friendhpltmaxrate' => 'when an ally is at '.$v.'% HP or less',
            'friendhpinrate'    => 'when an ally is at '.$v.'% HP or more',
            'friendstatuson'    => 'when an ally has '.$v,
            'friendstatusoff'   => 'when an ally doesn\'t have '.$v,
            'attackpcgt'        => 'when more than '.$v.' players attack it',
            'attackpcge'        => 'when '.$v.' or more players attack it',
            'slavelt'           => 'with fewer than '.$v.' followers',
            'slavele'           => 'with '.$v.' followers or fewer',
            'closedattacked'    => 'when hit in melee',
            'longrangeattacked' => 'when hit from range',
            'skillused'         => 'when a skill ('.$v.') is used on it',
            'afterskill'        => 'after it uses skill '.$v,
            'casttargeted'      => 'when targeted by a cast',
            'rudeattacked'      => 'when attacked while it can\'t fight back',
            'mobnearbygt'       => 'with more than '.$v.' monsters near',
            'groundattacked'    => 'when hit by a ground skill',
            'damagedgt'         => 'when hit for more than '.$v,
            'alchemist'         => 'when summoned by an Alchemist',
            default             => $type.($value !== null ? ' '.$value : '')
        };
    }

    // the spell a monster's use of a skill at a level casts ([level => spell] of the skill): its level's, else the highest level's under it (a monster
    // skill past the levels players have), else its first
    private static function mobSkillSpell(array $levels, int $level) : int
    {
        $below = array_filter(array_keys($levels), fn($l) => $l <= $level);
        return $levels[$below ? max($below) : min(array_keys($levels))];
    }

    // creature template ids of the RO monsters whose skills cast a spell (the spell page's "Used by")
    public static function creaturesUsingSpell(int $spellId) : array
    {
        if (!self::hasMobData() || !DB::World()->selectCell('SHOW TABLES LIKE ?', 'mod_rowow_db_mob_skill'))
            return [];

        if (!($skill = DB::World()->selectCell('SELECT `SkillName` FROM mod_rowow_skill_spell WHERE `SpellID` = ?d', $spellId)))
            return [];

        $levels = DB::World()->selectCol('SELECT `SkillLevel` AS ARRAY_KEY, `SpellID` FROM mod_rowow_skill_spell WHERE `SkillName` = ?', $skill);
        $mobIds = [];
        foreach (DB::World()->select('SELECT ms.`MobID` AS "mob", ms.`SkillLevel` AS "level" FROM mod_rowow_db_mob_skill ms JOIN mod_rowow_db_skill s ON s.`Id` = ms.`SkillID` WHERE s.`Name` = ? AND ms.`MobID` IS NOT NULL', $skill) as $r)
            if (self::mobSkillSpell($levels, (int)$r['level']) == $spellId)
                $mobIds[(int)$r['mob']] = (int)$r['mob'];

        return $mobIds ? array_keys(self::creaturesOfMobs(array_values($mobIds))) : [];
    }

    // the skill uses of an RO creature, in mob_skill_db order: [['spell', 'skill', 'level', 'when', 'chance' (percent), 'castTime', 'delay' (seconds)]]
    public static function creatureSkills(int $creatureId) : array
    {
        if (!($mobId = self::mobIdsOfCreatures([$creatureId])[$creatureId] ?? 0) || !DB::World()->selectCell('SHOW TABLES LIKE ?', 'mod_rowow_db_mob_skill'))
            return [];

        $rows = DB::World()->select(
           'SELECT ms.`State` AS "state", ms.`SkillLevel` AS "level", ms.`Rate` AS "rate", ms.`CastTime` AS "castTime", ms.`Delay` AS "delay", ms.`Target` AS "target",
                   ms.`ConditionType` AS "cond", ms.`ConditionValue` AS "condValue", s.`Name` AS "skill"
            FROM   mod_rowow_db_mob_skill ms
            JOIN   mod_rowow_db_skill s ON s.`Id` = ms.`SkillID`
            WHERE  ms.`MobID` = ?d
            ORDER BY ms.`RowIndex`', $mobId);
        if (!$rows)
            return [];

        // [skill name => [level => spell]]
        $spells = [];
        foreach (DB::World()->select('SELECT `SkillName` AS "skill", `SkillLevel` AS "level", `SpellID` AS "spell" FROM mod_rowow_skill_spell WHERE `SkillName` IN (?a)', array_unique(array_column($rows, 'skill'))) as $s)
            $spells[$s['skill']][(int)$s['level']] = (int)$s['spell'];

        $out = [];
        foreach ($rows as $r)
        {
            $levels = $spells[$r['skill']] ?? [];
            if (!$levels)
                continue;

            $level = (int)$r['level'];
            $spell = self::mobSkillSpell($levels, $level);

            $when = self::MOB_SKILL_STATES[strtolower($r['state'] ?? 'any')] ?? ucfirst($r['state'] ?? '');
            if ($cond = self::mobSkillCondition($r['cond'] ?? 'always', $r['condValue']))
                $when .= ', '.$cond;
            if (in_array(strtolower($r['target'] ?? ''), ['self', 'friend', 'master']))
                $when .= ' (on '.(strtolower($r['target']) == 'self' ? 'itself' : (strtolower($r['target']) == 'friend' ? 'an ally' : 'its master')).')';

            $out[] = array(
                'spell'    => $spell,
                'skill'    => $r['skill'],
                'level'    => $level,
                'when'     => $when,
                'chance'   => round((int)$r['rate'] / 100, 2),
                'castTime' => round((int)$r['castTime'] / 1000, 1),
                'delay'    => round((int)$r['delay'] / 1000, 1)
            );
        }

        return $out;
    }

    // the drops of an RO creature (see mobDrops) or null
    public static function creatureDrops(int $creatureId) : ?array
    {
        if (!($mobId = self::mobIdsOfCreatures([$creatureId])[$creatureId] ?? 0))
            return null;

        return self::mobDrops([$mobId])[$mobId] ?? null;
    }

    // the RO creatures dropping a WoW item: ['drops' => [creatureId => percent], 'mvp' => [creatureId => percent]]
    public static function droppedBy(int $itemId) : array
    {
        $out = ['drops' => [], 'mvp' => []];
        if (!self::hasMobData())
            return $out;

        // the monsters naming it (a loose match, mobDrops has the last word), and those with its companion carrier
        $mobIds = [];
        if ($roIds = DB::World()->selectCol('SELECT `ItemID` FROM mod_rowow_item WHERE `ItemTemplateID` = ?d', $itemId))
        {
            foreach (['mod_rowow_db_item', 'mod_rowow_db_item_renewal'] as $tbl)
            {
                if (!DB::World()->selectCell('SHOW TABLES LIKE ?', $tbl))
                    continue;

                foreach (DB::World()->selectCol('SELECT DISTINCT `AegisName` FROM ?# WHERE `Id` IN (?a) AND `AegisName` IS NOT NULL', $tbl, $roIds) as $name)
                {
                    $like   = '%Item='.addcslashes($name, '\\%_').'%';
                    $mobIds = array_merge($mobIds, DB::World()->selectCol('SELECT `Id` FROM mod_rowow_db_mob WHERE `Id` IS NOT NULL AND (`Drops` LIKE ? OR `MvpDrops` LIKE ?)', $like, $like));
                }
            }
        }

        if (DB::World()->selectCell('SHOW TABLES LIKE ?', 'mod_rowow_companion_drop'))
            $mobIds = array_merge($mobIds, DB::World()->selectCol('SELECT `MobID` FROM mod_rowow_companion_drop WHERE `ItemTemplateID` = ?d', $itemId));

        if (!($mobIds = array_unique(array_map('intval', $mobIds))))
            return $out;

        $drops = self::mobDrops($mobIds);
        foreach (self::creaturesOfMobs(array_keys($drops)) as $creatureId => $mobId)
        {
            foreach (['drops', 'mvp'] as $kind)
            {
                foreach ($drops[$mobId][$kind] as $d)
                {
                    if ($d['item'] != $itemId)
                        continue;

                    $out[$kind][$creatureId] = ($out[$kind][$creatureId] ?? 0) + $d['percent'];
                }
            }
        }

        return $out;
    }
}

?>
