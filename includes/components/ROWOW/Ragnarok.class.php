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
}

?>
