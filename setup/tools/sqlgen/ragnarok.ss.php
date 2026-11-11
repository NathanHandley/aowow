<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');

if (!CLI)
    die('not in cli mode');


/*
    ROWOW: compiles Ragnarok Online data from the mod_rowow_* tables that the converter deploys into the world database.

    aowow_ragnarok_item        - the RO side of every RO item (rAthena pre-renewal item_db = mod_rowow_db_item, as mod-rowow
                                 uses it; mod_rowow_db_item_renewal only for the stat-less post-renewal looks it doesn't have):
                                 type, zeny prices, weight, ATK/DEF, slots, levels, usable jobs, refineable, the scripts and
                                 their bonuses (summed per stat for the filters, and as readable lines for the tooltips)
    aowow_ragnarok_job         - the jobs mod-rowow knows (ROWOW_Identity.cpp PreRenewalJobNames), with tier, parent job,
                                 item job index (ROWOWItemJobNames) and max levels (mod_rowow_db_job)
    aowow_ragnarok_skill       - RO skills (mod_rowow_db_skill) with their WoW spells (mod_rowow_skill_spell)
    aowow_ragnarok_job_skill   - the skill tree (mod_rowow_db_skill_tree) by job id
    aowow_ragnarok_skill_spell - WoW spell id -> RO skill and skill level

    Prices: the converter writes item_template BuyPrice / SellPrice as zeny * RO_ITEM_COPPER_PER_ZENY (1), and mod-rowow's
    Zeny is the player's money, so a zeny is a copper.  buyZeny / sellZeny follow RoItemTemplate.BuyPrice / SellPrice.
*/

CLISetup::registerSetup("sql", new class extends SetupScript
{
    protected $info = array(
        'ragnarok' => [[], CLISetup::ARGV_PARAM, 'Compiles Ragnarok Online item, job and skill data from the ROWOW mod tables in the world db.']
    );

    protected $worldDependency = ['mod_rowow_item', 'mod_rowow_db_item', 'mod_rowow_db_skill', 'mod_rowow_db_skill_tree', 'mod_rowow_skill_spell'];

    // ROWOW_Status.cpp ROWOWItemJobNames - bit index = item job index
    public const ITEM_JOB_NAMES = ['Novice', 'Swordman', 'Mage', 'Archer', 'Acolyte', 'Merchant', 'Thief', 'Knight', 'Priest', 'Wizard', 'Blacksmith', 'Hunter',
        'Assassin', 'Crusader', 'Monk', 'Sage', 'Rogue', 'Alchemist', 'BardDancer', 'Taekwon', 'StarGladiator', 'SoulLinker', 'Gunslinger', 'Ninja', 'SuperNovice'];

    // ROWOW_Identity.cpp PreRenewalJobNames: [jobId, name, item job name], in display order (by tier)
    private const JOBS = array(
        [0, 'Novice', 'Novice'], [1, 'Swordman', 'Swordman'], [2, 'Mage', 'Mage'], [3, 'Archer', 'Archer'], [4, 'Acolyte', 'Acolyte'], [5, 'Merchant', 'Merchant'],
        [6, 'Thief', 'Thief'], [7, 'Knight', 'Knight'], [8, 'Priest', 'Priest'], [9, 'Wizard', 'Wizard'], [10, 'Blacksmith', 'Blacksmith'], [11, 'Hunter', 'Hunter'],
        [12, 'Assassin', 'Assassin'], [14, 'Crusader', 'Crusader'], [15, 'Monk', 'Monk'], [16, 'Sage', 'Sage'], [17, 'Rogue', 'Rogue'], [18, 'Alchemist', 'Alchemist'],
        [19, 'Bard', 'BardDancer'], [20, 'Dancer', 'BardDancer'],
        [4001, 'Novice_High', 'Novice'], [4002, 'Swordman_High', 'Swordman'], [4003, 'Mage_High', 'Mage'], [4004, 'Archer_High', 'Archer'],
        [4005, 'Acolyte_High', 'Acolyte'], [4006, 'Merchant_High', 'Merchant'], [4007, 'Thief_High', 'Thief'], [4008, 'Lord_Knight', 'Knight'],
        [4009, 'High_Priest', 'Priest'], [4010, 'High_Wizard', 'Wizard'], [4011, 'Whitesmith', 'Blacksmith'], [4012, 'Sniper', 'Hunter'],
        [4013, 'Assassin_Cross', 'Assassin'], [4015, 'Paladin', 'Crusader'], [4016, 'Champion', 'Monk'], [4017, 'Professor', 'Sage'], [4018, 'Stalker', 'Rogue'],
        [4019, 'Creator', 'Alchemist'], [4020, 'Clown', 'BardDancer'], [4021, 'Gypsy', 'BardDancer'], [4046, 'Taekwon', 'Taekwon'],
        [4047, 'Star_Gladiator', 'StarGladiator'], [4049, 'Soul_Linker', 'SoulLinker'],
        [23, 'Super_Novice', 'SuperNovice'], [24, 'Gunslinger', 'Gunslinger'], [25, 'Ninja', 'Ninja'], [4023, 'Baby', 'Novice'], [4024, 'Baby_Swordman', 'Swordman'],
        [4025, 'Baby_Mage', 'Mage'], [4026, 'Baby_Archer', 'Archer'], [4027, 'Baby_Acolyte', 'Acolyte'], [4028, 'Baby_Merchant', 'Merchant'],
        [4029, 'Baby_Thief', 'Thief'], [4030, 'Baby_Knight', 'Knight'], [4031, 'Baby_Priest', 'Priest'], [4032, 'Baby_Wizard', 'Wizard'],
        [4033, 'Baby_Blacksmith', 'Blacksmith'], [4034, 'Baby_Hunter', 'Hunter'], [4035, 'Baby_Assassin', 'Assassin'], [4037, 'Baby_Crusader', 'Crusader'],
        [4038, 'Baby_Monk', 'Monk'], [4039, 'Baby_Sage', 'Sage'], [4040, 'Baby_Rogue', 'Rogue'], [4041, 'Baby_Alchemist', 'Alchemist'],
        [4042, 'Baby_Bard', 'BardDancer'], [4043, 'Baby_Dancer', 'BardDancer'], [4045, 'Super_Baby', 'SuperNovice']
    );

    // second job -> first job (ROWOW_Identity.cpp SecondJobFirstJobIDs)
    private const SECOND_JOB_FIRST_JOB = [7 => 1, 14 => 1, 8 => 4, 15 => 4, 9 => 2, 16 => 2, 10 => 5, 18 => 5, 11 => 3, 19 => 3, 20 => 3, 12 => 6, 17 => 6,
        4047 => 4046, 4049 => 4046];

    // item classes (ROWOW_ITEM_CLASS_*)
    private const ITEM_CLASS_NORMAL = 0x01;
    private const ITEM_CLASS_UPPER  = 0x02;
    private const ITEM_CLASS_BABY   = 0x04;
    private const ITEM_CLASS_ALL    = 0xFF;

    // RO item categories (Lang::ragnarok('itemCat') and the rocat filter)
    private const WEAPON_CATS = ['Dagger' => 1, '1hSword' => 2, '2hSword' => 3, '1hSpear' => 4, '2hSpear' => 5, '1hAxe' => 6, '2hAxe' => 7, 'Mace' => 8,
        'Staff' => 9, 'Bow' => 10, 'Knuckle' => 11, 'Musical' => 12, 'Whip' => 13, 'Book' => 14, 'Katar' => 15, 'Revolver' => 16, 'Rifle' => 17,
        'Gatling' => 18, 'Shotgun' => 19, 'Grenade' => 20, 'Huuma' => 21, '2hStaff' => 22];
    private const TYPE_CATS   = ['Card' => 40, 'Healing' => 41, 'Usable' => 42, 'Delayconsume' => 43, 'Ammo' => 44, 'Petegg' => 45, 'Petarmor' => 46, 'Etc' => 47, 'Cash' => 48];

    private $skills      = [];                              // skill name => [displayName, [level => spellId]]
    private $skillsById  = [];                              // skill id   => skill name
    private $roItemToWow = [];                              // RO item id => WoW item id
    private $aegisToRo   = [];                              // RO AegisName => RO item id

    public function generate(array $ids = []) : bool
    {
        DB::Aowow()->query('DROP TABLE IF EXISTS ?_ragnarok_item');
        DB::Aowow()->query(
           'CREATE TABLE ?_ragnarok_item (
                `id`            INT UNSIGNED NOT NULL,
                `roId`          INT UNSIGNED NOT NULL,
                `aegisName`     VARCHAR(64) NOT NULL DEFAULT "",
                `roType`        VARCHAR(24) NOT NULL DEFAULT "",
                `roSubType`     VARCHAR(24) NOT NULL DEFAULT "",
                `roCategory`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `isRenewal`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `buyZeny`       INT UNSIGNED NOT NULL DEFAULT 0,
                `sellZeny`      INT UNSIGNED NOT NULL DEFAULT 0,
                `weight`        DECIMAL(8,1) NOT NULL DEFAULT 0,
                `baseAtk`       SMALLINT NOT NULL DEFAULT 0,
                `baseDef`       SMALLINT NOT NULL DEFAULT 0,
                `atk`           SMALLINT NOT NULL DEFAULT 0,
                `matk`          SMALLINT NOT NULL DEFAULT 0,
                `matkRate`      SMALLINT NOT NULL DEFAULT 0,
                `def`           SMALLINT NOT NULL DEFAULT 0,
                `mdef`          SMALLINT NOT NULL DEFAULT 0,
                `atkRange`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `slots`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `weaponLevel`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `armorLevel`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `equipLevelMin` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `equipLevelMax` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `refineable`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `isEquip`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `jobMask`       INT UNSIGNED NOT NULL DEFAULT 0,
                `classMask`     TINYINT UNSIGNED NOT NULL DEFAULT 255,
                `gender`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `locations`     VARCHAR(255) NOT NULL DEFAULT "",
                `str`           SMALLINT NOT NULL DEFAULT 0,
                `agi`           SMALLINT NOT NULL DEFAULT 0,
                `vit`           SMALLINT NOT NULL DEFAULT 0,
                `int`           SMALLINT NOT NULL DEFAULT 0,
                `dex`           SMALLINT NOT NULL DEFAULT 0,
                `luk`           SMALLINT NOT NULL DEFAULT 0,
                `hit`           SMALLINT NOT NULL DEFAULT 0,
                `flee`          SMALLINT NOT NULL DEFAULT 0,
                `crit`          SMALLINT NOT NULL DEFAULT 0,
                `maxhp`         SMALLINT NOT NULL DEFAULT 0,
                `maxsp`         SMALLINT NOT NULL DEFAULT 0,
                `aspdRate`      SMALLINT NOT NULL DEFAULT 0,
                `cardEnchantId` INT UNSIGNED NOT NULL DEFAULT 0,
                `bonusLines`    MEDIUMTEXT NULL,
                `script`        TEXT NULL,
                `equipScript`   TEXT NULL,
                `unequipScript` TEXT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_roid` (`roId`),
                INDEX `idx_cat` (`roCategory`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        DB::Aowow()->query('DROP TABLE IF EXISTS ?_ragnarok_job');
        DB::Aowow()->query(
           'CREATE TABLE ?_ragnarok_job (
                `id`           SMALLINT UNSIGNED NOT NULL,
                `name`         VARCHAR(32) NOT NULL,
                `displayName`  VARCHAR(48) NOT NULL,
                `tier`         TINYINT UNSIGNED NOT NULL,
                `parentJobId`  SMALLINT NOT NULL DEFAULT -1,
                `normalJobId`  SMALLINT NOT NULL DEFAULT -1,
                `itemJobIdx`   TINYINT NOT NULL DEFAULT -1,
                `itemClass`    TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `maxJobLevel`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `maxBaseLevel` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `inherit`      VARCHAR(255) NOT NULL DEFAULT "",
                `sortOrder`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        DB::Aowow()->query('DROP TABLE IF EXISTS ?_ragnarok_skill');
        DB::Aowow()->query(
           'CREATE TABLE ?_ragnarok_skill (
                `id`          SMALLINT UNSIGNED NOT NULL,
                `name`        VARCHAR(64) NOT NULL,
                `displayName` VARCHAR(96) NOT NULL,
                `maxLevel`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `skillType`   VARCHAR(16) NOT NULL DEFAULT "",
                `targetType`  VARCHAR(16) NOT NULL DEFAULT "",
                `passive`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `spellId1`    INT UNSIGNED NOT NULL DEFAULT 0,
                `spellIdMax`  INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        DB::Aowow()->query('DROP TABLE IF EXISTS ?_ragnarok_job_skill');
        DB::Aowow()->query(
           'CREATE TABLE ?_ragnarok_job_skill (
                `jobId`     SMALLINT UNSIGNED NOT NULL,
                `skillName` VARCHAR(64) NOT NULL,
                `sortOrder` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `maxLevel`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `jobLevel`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `requires`  VARCHAR(512) NOT NULL DEFAULT "",
                `exclude`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`jobId`, `skillName`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        DB::Aowow()->query('DROP TABLE IF EXISTS ?_ragnarok_skill_spell');
        DB::Aowow()->query(
           'CREATE TABLE ?_ragnarok_skill_spell (
                `spellId`    INT UNSIGNED NOT NULL,
                `skillName`  VARCHAR(64) NOT NULL,
                `skillLevel` TINYINT UNSIGNED NOT NULL,
                PRIMARY KEY (`spellId`),
                INDEX `idx_skill` (`skillName`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        if (!DB::World()->selectCell('SHOW TABLES LIKE ?', 'mod_rowow_item'))
        {
            CLI::write('[ragnarok] mod_rowow_item not found in world db - ROWOW data not deployed? Skipping.', CLI::LOG_WARN);
            return true;
        }

        $this->generateSkills();
        $this->generateJobs();
        $this->generateItems();

        return true;
    }

    /*********/
    /* skills */
    /*********/

    private function generateSkills() : void
    {
        $spells = DB::World()->select('SELECT `SkillName`, `SkillLevel`, `SpellID` FROM mod_rowow_skill_spell ORDER BY `SkillName`, `SkillLevel`');
        $rows   = [];
        $byName = [];
        foreach ($spells as $s)
        {
            $rows[] = [$s['SpellID'], $s['SkillName'], $s['SkillLevel']];
            $byName[$s['SkillName']][(int)$s['SkillLevel']] = (int)$s['SpellID'];
        }

        foreach (array_chunk($rows, 1000) as $chunk)
            DB::Aowow()->query('INSERT IGNORE INTO ?_ragnarok_skill_spell VALUES (?a)', $chunk);

        $skills = DB::World()->select('SELECT `Id`, `Name`, `Description`, `MaxLevel`, `Type`, `TargetType` FROM mod_rowow_db_skill WHERE `Id` IS NOT NULL ORDER BY `RowIndex`');
        $rows   = [];
        foreach ($skills as $sk)
        {
            $name   = $sk['Name'];
            $levels = $byName[$name] ?? [];
            $this->skills[$name] = [$sk['Description'] ?: $name, $levels];
            $this->skillsById[(int)$sk['Id']] = $name;

            // rAthena: a skill without a target type can't be used - it's passive
            $rows[$sk['Id']] = [$sk['Id'], $name, $sk['Description'] ?: $name, (int)$sk['MaxLevel'], (string)$sk['Type'], (string)$sk['TargetType'],
                empty($sk['TargetType']) ? 1 : 0, $levels ? reset($levels) : 0, $levels ? end($levels) : 0];
        }

        foreach (array_chunk($rows, 500) as $chunk)
            DB::Aowow()->query('INSERT IGNORE INTO ?_ragnarok_skill VALUES (?a)', $chunk);

        CLI::write('[ragnarok] '.count($rows).' skills, '.count($spells).' skill level spells', CLI::LOG_BLANK, true, true);
    }

    /********/
    /* jobs */
    /********/

    private static function jobKey(string $name) : string
    {
        return strtolower(str_replace('_', '', $name));    // rAthena matches Super_Novice as Supernovice
    }

    private static function jobDisplayName(string $name) : string
    {
        if (preg_match('/^(\w+)_High$/', $name, $m))
            return 'High '.str_replace('_', ' ', $m[1]);

        return str_replace('_', ' ', $name);
    }

    private function generateJobs() : void
    {
        $byKey = [];
        foreach (self::JOBS as [$id, $name, $_])
            $byKey[self::jobKey($name)] = $id;

        // max job / base levels
        $maxJob = $maxBase = [];
        foreach (DB::World()->select('SELECT `Jobs`, `MaxJobLevel`, `MaxBaseLevel` FROM mod_rowow_db_job WHERE `MaxJobLevel` IS NOT NULL OR `MaxBaseLevel` IS NOT NULL ORDER BY `RowIndex`') as $r)
        {
            foreach (self::parseMap($r['Jobs']) as $k => $v)
            {
                if ($v != 'true' || !isset($byKey[self::jobKey($k)]))
                    continue;

                if ($r['MaxJobLevel'] !== null)
                    $maxJob[$byKey[self::jobKey($k)]] = (int)$r['MaxJobLevel'];
                if ($r['MaxBaseLevel'] !== null)
                    $maxBase[$byKey[self::jobKey($k)]] = (int)$r['MaxBaseLevel'];
            }
        }

        // skill tree
        $inherit  = [];
        $treeRows = [];
        foreach (DB::World()->select('SELECT `RowIndex`, `Job`, `Inherit`, `Tree_Name`, `Tree_MaxLevel`, `Tree_JobLevel`, `Tree_Requires`, `Tree_Exclude` FROM mod_rowow_db_skill_tree ORDER BY `RowIndex`') as $t)
        {
            $jobId = $byKey[self::jobKey($t['Job'])] ?? null;
            if ($jobId === null)                            // Knight2 (Peco), Gangsi, .. - not jobs of ROWOW
                continue;

            if ($t['Inherit'] && !isset($inherit[$jobId]))
                $inherit[$jobId] = implode(',', array_filter(array_map(fn($k) => $byKey[self::jobKey($k)] ?? null, array_keys(array_filter(self::parseMap($t['Inherit']), fn($v) => $v == 'true'))), fn($v) => $v !== null));

            if ($t['Tree_Name'])
                $treeRows[$jobId.':'.$t['Tree_Name']] = [$jobId, $t['Tree_Name'], $t['RowIndex'], (int)$t['Tree_MaxLevel'], (int)$t['Tree_JobLevel'], (string)$t['Tree_Requires'], $t['Tree_Exclude'] == 'true' ? 1 : 0];
        }

        foreach (array_chunk(array_values($treeRows), 500) as $chunk)
            DB::Aowow()->query('INSERT IGNORE INTO ?_ragnarok_job_skill VALUES (?a)', $chunk);

        $rows  = [];
        $order = 0;
        foreach (self::JOBS as [$id, $name, $itemJobName])
        {
            $normal = -1;
            if ($id >= 4023 && $id <= 4045)
            {
                $tier   = 6;                                // Baby jobs: their normal job's skills and items
                $normal = $id == 4045 ? 23 : $id - 4023;    // ROWOW_Identity.cpp BabyJobNormalJobIDs
                $parent = $id == 4023 ? -1 : (in_array($id, [4024, 4025, 4026, 4027, 4028, 4029, 4045]) ? 4023 : (4023 + (self::SECOND_JOB_FIRST_JOB[$normal] ?? 0)));
            }
            else if ($id == 0)
                [$tier, $parent] = [0, -1];
            else if ($id <= 6)
                [$tier, $parent] = [1, 0];
            else if ($id <= 20)
                [$tier, $parent] = [2, self::SECOND_JOB_FIRST_JOB[$id]];
            else if ($id <= 25)
                [$tier, $parent] = [5, 0];
            else if ($id == 4001)
                [$tier, $parent] = [3, 0];
            else if ($id <= 4007)
                [$tier, $parent] = [3, 4001];
            else if ($id <= 4022)
                [$tier, $parent] = [4, 4001 + self::SECOND_JOB_FIRST_JOB[$id - 4001]];
            else                                            // Taekwon line
                [$tier, $parent] = [5, self::SECOND_JOB_FIRST_JOB[$id] ?? 0];

            $itemClass = $tier == 6 ? self::ITEM_CLASS_BABY : (in_array($tier, [3, 4]) ? self::ITEM_CLASS_UPPER : self::ITEM_CLASS_NORMAL);
            $itemIdx   = array_search($itemJobName, self::ITEM_JOB_NAMES);

            $rows[] = [$id, $name, self::jobDisplayName($name), $tier, $parent, $normal, $itemIdx === false ? -1 : $itemIdx, $itemClass,
                $maxJob[$id] ?? 0, $maxBase[$id] ?? 0, $inherit[$id] ?? '', ++$order];
        }

        DB::Aowow()->query('INSERT INTO ?_ragnarok_job VALUES (?a)', $rows);

        CLI::write('[ragnarok] '.count($rows).' jobs, '.count($treeRows).' skill tree entries', CLI::LOG_BLANK, true, true);
    }

    /*********/
    /* items */
    /*********/

    // "Key=Value,Key=Value" (RoWorldDataTable / ROWOWDataTable::ParseMap)
    public static function parseMap(?string $s) : array
    {
        $res = [];
        foreach (preg_split('/[,;]/', (string)$s, -1, PREG_SPLIT_NO_EMPTY) as $pair)
        {
            $kv = explode('=', $pair, 2);
            $res[trim($kv[0])] = isset($kv[1]) ? trim($kv[1]) : 'true';
        }

        return $res;
    }

    private function generateItems() : void
    {
        $map = DB::World()->selectCol('SELECT `ItemID` AS ARRAY_KEY, `ItemTemplateID` FROM mod_rowow_item');
        $this->roItemToWow = $map;

        $cards = [];
        if (DB::World()->selectCell('SHOW TABLES LIKE ?', 'mod_rowow_card'))
            $cards = DB::World()->selectCol('SELECT `ItemID` AS ARRAY_KEY, `EnchantmentID` FROM mod_rowow_card');

        $items = [];
        foreach (DB::World()->select('SELECT * FROM mod_rowow_db_item WHERE `Id` IS NOT NULL ORDER BY `RowIndex`') as $r)
            $items[(int)$r['Id']] = $r;

        // the post-renewal looks pre-renewal doesn't have (stat-less)
        if (DB::World()->selectCell('SHOW TABLES LIKE ?', 'mod_rowow_db_item_renewal'))
            foreach (DB::World()->select('SELECT * FROM mod_rowow_db_item_renewal WHERE `Id` IS NOT NULL ORDER BY `RowIndex`') as $r)
                if (!isset($items[(int)$r['Id']]))
                    $items[(int)$r['Id']] = $r + ['isRenewal' => 1];

        foreach ($items as $roId => $r)
            if ($r['AegisName'])
                $this->aegisToRo[$r['AegisName']] = $roId;

        $rows = [];
        foreach ($map as $roId => $wowId)
        {
            if (!isset($items[$roId]))
                continue;

            $r    = $items[$roId];
            $type = $r['Type'] ?: 'Etc';
            $sub  = $r['SubType'] ?? '';

            // jobs / classes default to all (ROWOW_Status.cpp LoadStatusData)
            $jobMask = (1 << count(self::ITEM_JOB_NAMES)) - 1;
            if ($jobs = self::parseMap($r['Jobs'] ?? null))
            {
                $jobMask = 0;
                foreach ($jobs as $k => $v)
                {
                    if ($k == 'All')
                        $jobMask = $v == 'true' ? (1 << count(self::ITEM_JOB_NAMES)) - 1 : 0;
                    else if (($idx = array_search($k, self::ITEM_JOB_NAMES)) !== false)
                        $jobMask = $v == 'true' ? $jobMask | (1 << $idx) : $jobMask & ~(1 << $idx);
                }
            }

            $classMask = self::ITEM_CLASS_ALL;
            if ($classes = self::parseMap($r['Classes'] ?? null))
            {
                $classMask = 0;
                $names = ['Normal' => self::ITEM_CLASS_NORMAL, 'Upper' => self::ITEM_CLASS_UPPER, 'Baby' => self::ITEM_CLASS_BABY, 'All_Upper' => self::ITEM_CLASS_UPPER, 'All_Baby' => self::ITEM_CLASS_BABY];
                foreach ($classes as $k => $v)
                {
                    if ($k == 'All')
                        $classMask = $v == 'true' ? self::ITEM_CLASS_ALL : 0;
                    else if (isset($names[$k]))
                        $classMask = $v == 'true' ? $classMask | $names[$k] : $classMask & ~$names[$k];
                }
            }

            $locs = array_keys(array_filter(self::parseMap($r['Locations'] ?? null), fn($v) => $v == 'true'));

            // category
            $cat = 47;
            if ($type == 'Weapon')
                $cat = self::WEAPON_CATS[$sub] ?? 0;
            else if ($type == 'Armor')
            {
                $l = implode(',', $locs);
                $cat = match (true)
                {
                    str_contains($l, 'Costume')      => 38,
                    in_array('Head_Top', $locs)      => 30,
                    in_array('Head_Mid', $locs)      => 31,
                    in_array('Head_Low', $locs)      => 32,
                    in_array('Armor', $locs)         => 33,
                    in_array('Left_Hand', $locs)     => 34,
                    in_array('Garment', $locs)       => 35,
                    in_array('Shoes', $locs)         => 36,
                    str_contains($l, 'Accessory')    => 37,
                    default                          => 39
                };
            }
            else
                $cat = self::TYPE_CATS[$type] ?? 47;

            // prices (RoItemTemplate.BuyPrice / SellPrice: Buy and Sell default to each other)
            $buy  = isset($r['Buy'])  && $r['Buy']  !== null ? (int)$r['Buy']  : -1;
            $sell = isset($r['Sell']) && $r['Sell'] !== null ? (int)$r['Sell'] : -1;
            $buyZ  = $buy  >= 0 ? $buy  : ($sell >= 0 ? $sell * 2 : 0);
            $sellZ = $sell >= 0 ? $sell : ($buy  >= 0 ? intdiv($buy, 2) : 0);

            // script bonuses
            $stats = array_fill_keys(['str', 'agi', 'vit', 'int', 'dex', 'luk', 'hit', 'flee', 'crit', 'maxhp', 'maxsp', 'aspdRate', 'matk', 'matkRate', 'def', 'mdef', 'atk'], 0);
            $lines = [];
            foreach (['Script' => '', 'EquipScript' => 'equip', 'UnEquipScript' => 'unequip'] as $col => $when)
                if (!empty($r[$col]))
                    foreach ($this->translateScript($r[$col], $stats) as $line)
                        $lines[] = $when ? [$line[0], $line[1], $when] : $line;

            $isEquip = in_array($type, ['Weapon', 'Armor', 'Ammo']) ? 1 : 0;   // what the Jobs / Classes / level checks apply to

            $rows[] = array(
                $wowId, $roId, (string)$r['AegisName'], $type, (string)$sub, $cat, empty($r['isRenewal']) ? 0 : 1,
                $buyZ, $sellZ, round(((int)($r['Weight'] ?? 0)) / 10, 1), (int)($r['Attack'] ?? 0), (int)($r['Defense'] ?? 0),
                (int)($r['Attack'] ?? 0) + $stats['atk'], $stats['matk'], $stats['matkRate'], (int)($r['Defense'] ?? 0) + $stats['def'], $stats['mdef'],
                (int)($r['Range'] ?? 0), (int)($r['Slots'] ?? 0), (int)($r['WeaponLevel'] ?? 0), (int)($r['ArmorLevel'] ?? 0),
                (int)($r['EquipLevelMin'] ?? 0), (int)($r['EquipLevelMax'] ?? 0), ($r['Refineable'] ?? '') == 'true' ? 1 : 0, $isEquip,
                $jobMask, $classMask, ($r['Gender'] ?? '') == 'Female' ? 1 : (($r['Gender'] ?? '') == 'Male' ? 2 : 0), implode(',', $locs),
                $stats['str'], $stats['agi'], $stats['vit'], $stats['int'], $stats['dex'], $stats['luk'],
                $stats['hit'], $stats['flee'], $stats['crit'], $stats['maxhp'], $stats['maxsp'], $stats['aspdRate'],
                (int)($cards[$roId] ?? 0), $lines ? json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                $r['Script'] ?? null, $r['EquipScript'] ?? null, $r['UnEquipScript'] ?? null
            );
        }

        foreach (array_chunk($rows, 500) as $chunk)
            DB::Aowow()->query('INSERT IGNORE INTO ?_ragnarok_item VALUES (?a)', $chunk);

        CLI::write('[ragnarok] '.count($rows).' items', CLI::LOG_BLANK, true, true);
    }

    /***********/
    /* scripts */
    /***********/

    // splits a script into top level statements; a block statement (if / for / { }) stays whole
    private static function statements(string $script) : array
    {
        $script = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $script);
        $out    = [];
        $cur    = '';
        $depth  = 0;
        $inStr  = false;
        $len    = strlen($script);
        for ($i = 0; $i < $len; $i++)
        {
            $c = $script[$i];
            if ($c == '"' && ($i == 0 || $script[$i - 1] != '\\'))
                $inStr = !$inStr;

            if (!$inStr)
            {
                if ($c == '{')
                    $depth++;
                else if ($c == '}')
                {
                    $depth--;
                    if ($depth <= 0)
                    {
                        $cur .= $c;
                        // "if (..) { .. } else { .. }" stays one statement
                        if (!preg_match('/^\s*else\b/', substr($script, $i + 1)))
                        {
                            $out[] = trim($cur);
                            $cur   = '';
                        }
                        $depth = 0;
                        continue;
                    }
                }
                else if ($c == ';' && $depth == 0)
                {
                    $out[] = trim($cur);
                    $cur   = '';
                    continue;
                }
            }

            $cur .= $c;
        }

        if (trim($cur) !== '')
            $out[] = trim($cur);

        return array_values(array_filter($out, fn($s) => $s !== ''));
    }

    private static function args(string $s) : array
    {
        $out   = [];
        $cur   = '';
        $depth = 0;
        $inStr = false;
        for ($i = 0; $i < strlen($s); $i++)
        {
            $c = $s[$i];
            if ($c == '"')
                $inStr = !$inStr;
            else if (!$inStr && $c == '(')
                $depth++;
            else if (!$inStr && $c == ')')
                $depth--;

            if ($c == ',' && !$inStr && !$depth)
            {
                $out[] = trim($cur);
                $cur   = '';
                continue;
            }
            $cur .= $c;
        }
        $out[] = trim($cur);

        return $out;
    }

    private static function num(string $v) : ?int
    {
        return preg_match('/^-?\d+$/', trim($v)) ? (int)$v : null;
    }

    private static function signed(int $v) : string
    {
        return ($v >= 0 ? '+' : '').$v;
    }

    private static function pct(int $v, int $div) : string
    {
        return rtrim(rtrim(number_format($v / $div, 2, '.', ''), '0'), '.');
    }

    // RC_DemiHuman -> Demi-Human, Ele_Fire -> Fire, Size_Small -> Small, Eff_DPoison -> Deadly Poison, Class_Boss -> Boss
    private static function constName(string $c) : string
    {
        static $special = array(
            'RC_DemiHuman' => 'Demi-Human', 'RC_All' => 'all races', 'RC_Player' => 'Player', 'RC_NonBoss' => 'non-Boss',
            'Ele_All' => 'all elements', 'Size_All' => 'all sizes', 'Class_All' => 'all monsters', 'Class_Normal' => 'normal monsters', 'Class_Boss' => 'Boss monsters',
            'Eff_DPoison' => 'Deadly Poison', 'Eff_Stone' => 'Stone Curse', 'Eff_Freeze' => 'Frozen'
        );
        if (isset($special[$c]))
            return $special[$c];

        $c = preg_replace('/^(RC2?|Ele|Size|Class|Eff|SC)_/i', '', $c);
        return trim(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', str_replace('_', ' ', $c)));
    }

    // a skill reference ("KN_BOWLINGBASH", 62 or KN_BOWLINGBASH) -> link to its WoW spell at the level
    private function skillLink(string $ref, int $level = 0) : string
    {
        $ref  = trim($ref, " \"");
        $name = ctype_digit($ref) ? ($this->skillsById[(int)$ref] ?? $ref) : $ref;
        if (!isset($this->skills[$name]))
            return htmlspecialchars($name);

        [$display, $levels] = $this->skills[$name];
        $spellId = $levels[$level] ?? ($levels ? reset($levels) : 0);

        return $spellId ? '<a href="?spell='.$spellId.'">'.htmlspecialchars($display).'</a>' : htmlspecialchars($display);
    }

    private function itemLink(string $ref) : string
    {
        $ref = trim($ref, " \"");
        if (isset($this->aegisToRo[$ref]))
            $ref = (string)$this->aegisToRo[$ref];
        if (ctype_digit($ref) && isset($this->roItemToWow[(int)$ref]))
        {
            $wowId = $this->roItemToWow[(int)$ref];
            $name  = DB::World()->selectCell('SELECT `name` FROM item_template WHERE `entry` = ?d', $wowId) ?: 'item #'.$ref;
            return '<a href="?item='.$wowId.'">'.htmlspecialchars($name).'</a>';
        }

        return htmlspecialchars($ref);
    }

    /*
        returns [[html, translated (1|0)], ..] and sums the unconditional stat bonuses into $stats
        untranslated statements are returned as their (escaped) source text
    */
    private function translateScript(string $script, array &$stats) : array
    {
        static $simple = array(                             // bonus bX,n => [label, unit, stat column]
            'bStr'        => ['STR',   '',  'str'],   'bAgi'     => ['AGI',  '', 'agi'],   'bVit'  => ['VIT', '', 'vit'],
            'bInt'        => ['INT',   '',  'int'],   'bDex'     => ['DEX',  '', 'dex'],   'bLuk'  => ['LUK', '', 'luk'],
            'bMaxHP'      => ['MaxHP', '',  'maxhp'], 'bMaxSP'   => ['MaxSP','', 'maxsp'],
            'bMaxHPrate'  => ['MaxHP', '%', null],    'bMaxHPRate' => ['MaxHP', '%', null], 'bMaxSPrate' => ['MaxSP', '%', null], 'bMaxSPRate' => ['MaxSP', '%', null],
            'bBaseAtk'    => ['ATK',   '',  'atk'],   'bAtk'     => ['ATK',  '', 'atk'],    'bAtkRate' => ['ATK', '%', null],
            'bMatk'       => ['MATK',  '',  'matk'],  'bMatkRate' => ['MATK', '%', 'matkRate'], 'bMAtkRate' => ['MATK', '%', 'matkRate'],
            'bDef'        => ['DEF',   '',  'def'],   'bDefRate' => ['DEF',  '%', null],   'bDef2' => ['VIT DEF', '', null], 'bDef2Rate' => ['VIT DEF', '%', null],
            'bMdef'       => ['MDEF',  '',  'mdef'],  'bMdefRate' => ['MDEF', '%', null],  'bMdef2' => ['INT MDEF', '', null], 'bMdef2Rate' => ['INT MDEF', '%', null],
            'bHit'        => ['HIT',   '',  'hit'],   'bHitRate' => ['HIT',  '%', null],
            'bFlee'       => ['FLEE',  '',  'flee'],  'bFleeRate' => ['FLEE', '%', null],  'bFlee2' => ['Perfect Dodge', '', null], 'bFlee2Rate' => ['Perfect Dodge', '%', null],
            'bCritical'   => ['CRIT',  '',  'crit'],  'bCriticalRate' => ['CRIT', '%', null],
            'bAspd'       => ['ASPD',  '',  null],    'bAspdRate' => ['ASPD', '%', 'aspdRate'],
            'bSpeedRate'  => ['Movement speed', '%', null],    'bSpeedAddRate' => ['Movement speed', '%', null],
            'bCastrate'   => ['Variable cast time', '%', null], 'bCastRate' => ['Variable cast time', '%', null],
            'bDelayRate'  => ['After-cast delay', '%', null],   'bUseSPrate' => ['SP consumption', '%', null],
            'bHPrecovRate' => ['Natural HP recovery', '%', null], 'bSPrecovRate' => ['Natural SP recovery', '%', null],
            'bHealPower'  => ['Heal effectiveness', '%', null],  'bHealPower2' => ['Heal received', '%', null],
            'bCritAtkRate' => ['Critical damage', '%', null],   'bLongAtkRate' => ['Ranged physical damage', '%', null],
            'bSplashRange' => ['Splash range', '', null],       'bPerfectHitRate' => ['Perfect hit chance', '%', null],
        );
        static $flags = array(
            'bUnbreakableWeapon' => 'Weapon cannot be broken', 'bUnbreakableArmor' => 'Armor cannot be broken', 'bUnbreakableHelm' => 'Headgear cannot be broken',
            'bUnbreakableShield' => 'Shield cannot be broken', 'bUnbreakableGarment' => 'Garment cannot be broken', 'bUnbreakableShoes' => 'Footgear cannot be broken',
            'bNoKnockback' => 'Cannot be knocked back', 'bNoCastCancel' => 'Casting cannot be interrupted', 'bNoGemStone' => 'Skills need no gemstones',
            'bNoWalkDelay' => 'No delay after being hit', 'bIntravision' => 'Can see hidden enemies', 'bNoSizeFix' => 'No weapon size penalty',
            'bInfiniteEndure' => 'Endure effect', 'bRestartFullRecover' => 'Revives with full HP and SP', 'bNoMagicDamage' => 'Immune to magic damage',
            'bNoWeaponDamage' => 'Immune to physical damage', 'bNoRegen' => 'No natural regeneration', 'bUnstripable' => 'Equipment cannot be stripped',
        );

        $out = [];
        foreach (self::statements($script) as $st)
        {
            $html = null;

            if (preg_match('/^(bonus[2-5]?)\s+(\w+)\s*(?:,(.*))?$/s', $st, $m))
            {
                $kind = $m[1];
                $bn   = $m[2];
                $a    = isset($m[3]) ? self::args($m[3]) : [];
                $n0   = isset($a[0]) ? self::num($a[0]) : null;
                $nl   = $a ? self::num(end($a)) : null;

                if ($kind == 'bonus')
                {
                    if (isset($simple[$bn]) && $n0 !== null)
                    {
                        [$label, $unit, $col] = $simple[$bn];
                        $html = $label.' '.self::signed($n0).$unit;
                        if ($col)
                            $stats[$col] += $n0;
                    }
                    else if ($bn == 'bAllStats' && $n0 !== null)
                    {
                        $html = 'All Stats '.self::signed($n0);
                        foreach (['str', 'agi', 'vit', 'int', 'dex', 'luk'] as $s)
                            $stats[$s] += $n0;
                    }
                    else if ($bn == 'bAgiVit' && $n0 !== null)
                    {
                        $html = 'AGI and VIT '.self::signed($n0);
                        $stats['agi'] += $n0;
                        $stats['vit'] += $n0;
                    }
                    else if ($bn == 'bAgiDexStr' && $n0 !== null)
                    {
                        $html = 'STR, AGI and DEX '.self::signed($n0);
                        foreach (['str', 'agi', 'dex'] as $s)
                            $stats[$s] += $n0;
                    }
                    else if (isset($flags[$bn]))
                        $html = $flags[$bn];
                    else if ($bn == 'bAtkEle' && isset($a[0]))
                        $html = 'Weapon element: '.self::constName($a[0]);
                    else if ($bn == 'bDefEle' && isset($a[0]))
                        $html = 'Armor element: '.self::constName($a[0]);
                    else if ($bn == 'bIgnoreDefRace' && isset($a[0]))
                        $html = 'Ignores the DEF of '.self::constName($a[0]);
                    else if ($bn == 'bIgnoreDefClass' && isset($a[0]))
                        $html = 'Ignores the DEF of '.self::constName($a[0]);
                    else if ($bn == 'bIgnoreMdefRace' && isset($a[0]))
                        $html = 'Ignores the MDEF of '.self::constName($a[0]);
                    else if ($bn == 'bLongAtkDef' && $n0 !== null)
                        $html = $n0.'% less damage from ranged attacks';
                    else if ($bn == 'bNearAtkDef' && $n0 !== null)
                        $html = $n0.'% less damage from melee attacks';
                    else if ($bn == 'bMagicAtkDef' && $n0 !== null)
                        $html = $n0.'% less damage from magic';
                    else if ($bn == 'bShortWeaponDamageReturn' && $n0 !== null)
                        $html = 'Reflects '.$n0.'% of melee physical damage';
                    else if ($bn == 'bLongWeaponDamageReturn' && $n0 !== null)
                        $html = 'Reflects '.$n0.'% of ranged physical damage';
                    else if ($bn == 'bMagicDamageReturn' && $n0 !== null)
                        $html = $n0.'% chance to reflect magic';
                    else if (in_array($bn, ['bHPGainValue', 'bSPGainValue']) && $n0 !== null)
                        $html = 'Gains '.$n0.' '.($bn == 'bHPGainValue' ? 'HP' : 'SP').' for each monster killed';
                    else if (in_array($bn, ['bSPDrainValue', 'bHPDrainValue']) && $n0 !== null)
                        $html = 'Drains '.$n0.' '.($bn == 'bHPDrainValue' ? 'HP' : 'SP').' with each attack';
                    else if (in_array($bn, ['bBreakWeaponRate', 'bBreakArmorRate']) && $n0 !== null)
                        $html = self::pct($n0, 100).'% chance to break the target\'s '.($bn == 'bBreakWeaponRate' ? 'weapon' : 'armor');
                    else if ($bn == 'bClassChange' && $n0 !== null)
                        $html = self::pct($n0, 100).'% chance to transform the target';
                    else if ($bn == 'bDefRatioAtkClass' && isset($a[0]))
                        $html = 'Damage against '.self::constName($a[0]).' increases with their DEF';
                }
                else if ($kind == 'bonus2' && count($a) >= 2)
                {
                    $x = $a[0];
                    switch ($bn)
                    {
                        case 'bAddRace':  case 'bAddRace2': case 'bAddClass': case 'bAddEle': case 'bAddSize':
                            if ($nl !== null)
                                $html = self::signed($nl).'% physical damage against '.self::constName($x).($bn == 'bAddEle' ? ' element' : ($bn == 'bAddSize' ? ' size' : ''));
                            break;
                        case 'bMagicAddRace': case 'bMagicAddClass': case 'bMagicAddEle': case 'bMagicAddSize':
                            if ($nl !== null)
                                $html = self::signed($nl).'% magic damage against '.self::constName($x).($bn == 'bMagicAddEle' ? ' element' : ($bn == 'bMagicAddSize' ? ' size' : ''));
                            break;
                        case 'bSubRace':  case 'bSubRace2': case 'bSubClass': case 'bSubEle': case 'bSubSize':
                            if ($nl !== null)
                                $html = $nl.'% less damage from '.self::constName($x).($bn == 'bSubEle' ? ' element' : ($bn == 'bSubSize' ? ' size' : ''));
                            break;
                        case 'bIgnoreDefRaceRate': case 'bIgnoreDefClassRate':
                            if ($nl !== null)
                                $html = 'Ignores '.$nl.'% of the DEF of '.self::constName($x);
                            break;
                        case 'bIgnoreMdefRaceRate': case 'bIgnoreMdefClassRate':
                            if ($nl !== null)
                                $html = 'Ignores '.$nl.'% of the MDEF of '.self::constName($x);
                            break;
                        case 'bResEff':
                            if ($nl !== null)
                                $html = self::pct($nl, 100).'% resistance to '.self::constName($x);
                            break;
                        case 'bAddEff': case 'bAddEff2': case 'bAddEffWhenHit':
                            if ($nl !== null)
                                $html = self::pct($nl, 100).'% chance to inflict '.self::constName($x).($bn == 'bAddEffWhenHit' ? ' on the attacker when hit' : ($bn == 'bAddEff2' ? ' on yourself when attacking' : ' when attacking'));
                            break;
                        case 'bComaRace': case 'bComaClass':
                            if ($nl !== null)
                                $html = self::pct($nl, 100).'% chance to inflict Coma on '.self::constName($x);
                            break;
                        case 'bSkillAtk':
                            if ($nl !== null)
                                $html = 'Damage of '.$this->skillLink($x).' '.self::signed($nl).'%';
                            break;
                        case 'bCastrate': case 'bCastRate':
                            if ($nl !== null)
                                $html = 'Cast time of '.$this->skillLink($x).' '.self::signed($nl).'%';
                            break;
                        case 'bSkillHeal':
                            if ($nl !== null)
                                $html = 'Healing of '.$this->skillLink($x).' '.self::signed($nl).'%';
                            break;
                        case 'bCriticalAddRace':
                            if ($nl !== null)
                                $html = 'CRIT '.self::signed($nl).' against '.self::constName($x);
                            break;
                        case 'bExpAddRace': case 'bExpAddClass':
                            if ($nl !== null)
                                $html = self::signed($nl).'% experience from '.self::constName($x);
                            break;
                        case 'bSPGainRace':
                            if ($nl !== null)
                                $html = 'Gains '.$nl.' SP for each '.self::constName($x).' killed';
                            break;
                        case 'bHPDrainRate': case 'bSPDrainRate':
                            if (($r = self::num($a[0])) !== null && $nl !== null)
                                $html = self::pct($r, 10).'% chance to gain '.$nl.'% of the damage dealt as '.($bn == 'bHPDrainRate' ? 'HP' : 'SP');
                            break;
                        case 'bHPRegenRate': case 'bSPRegenRate': case 'bHPLossRate': case 'bSPLossRate':
                            if (($v = self::num($a[0])) !== null && $nl !== null)
                                $html = (str_contains($bn, 'Loss') ? 'Loses ' : 'Regenerates ').$v.' '.($bn[1] == 'H' ? 'HP' : 'SP').' every '.self::pct($nl, 1000).' seconds';
                            break;
                        case 'bAddMonsterDropItem':
                            if ($nl !== null)
                                $html = self::pct($nl, 100).'% chance to get '.$this->itemLink($x).' from monsters killed';
                            break;
                        case 'bAddItemHealRate': case 'bAddItemGroupHealRate':
                            if ($nl !== null)
                                $html = 'Healing from '.($bn == 'bAddItemHealRate' ? $this->itemLink($x) : self::constName($x)).' '.self::signed($nl).'%';
                            break;
                        case 'bGetZenyNum':
                            if (($v = self::num($a[0])) !== null && $nl !== null)
                                $html = $nl.'% chance to get up to '.$v.' zeny from monsters killed';
                            break;
                    }
                }
                else if (in_array($kind, ['bonus3', 'bonus4', 'bonus5']) && count($a) >= 3)
                {
                    if (in_array($bn, ['bAutoSpell', 'bAutoSpellWhenHit']) && ($lv = self::num($a[1])) !== null && ($r = self::num($a[2])) !== null)
                        $html = self::pct($r, 10).'% chance to cast Lv '.$lv.' '.$this->skillLink($a[0], $lv).($bn == 'bAutoSpellWhenHit' ? ' when hit' : ' when attacking');
                    else if ($bn == 'bAutoSpellOnSkill' && count($a) >= 4 && ($lv = self::num($a[2])) !== null && ($r = self::num($a[3])) !== null)
                        $html = self::pct($r, 10).'% chance to cast Lv '.$lv.' '.$this->skillLink($a[1], $lv).' when using '.$this->skillLink($a[0]);
                    else if ($bn == 'bAddMonsterDropItem' && ($r = self::num(end($a))) !== null)
                        $html = self::pct($r, 100).'% chance to get '.$this->itemLink($a[0]).' from '.self::constName($a[1]).' killed';
                    else if (in_array($bn, ['bAddEff', 'bAddEffWhenHit']) && ($r = self::num($a[1])) !== null)
                        $html = self::pct($r, 100).'% chance to inflict '.self::constName($a[0]).($bn == 'bAddEffWhenHit' ? ' on the attacker when hit' : ' when attacking');
                    else if ($bn == 'bAddEffOnSkill' && ($r = self::num($a[2])) !== null)
                        $html = self::pct($r, 100).'% chance to inflict '.self::constName($a[1]).' with '.$this->skillLink($a[0]);
                }
            }
            else if (preg_match('/^skill\s+([^,]+),\s*(-?\d+)/', $st, $m))
                $html = 'Enables Lv '.$m[2].' '.$this->skillLink($m[1], (int)$m[2]);
            else if (preg_match('/^itemskill\s+([^,]+),\s*(-?\d+)/', $st, $m))
                $html = 'Casts Lv '.$m[2].' '.$this->skillLink($m[1], (int)$m[2]);
            else if (preg_match('/^getitem\s+([^,]+),\s*(\d+)\s*$/', $st, $m))
                $html = 'Gives '.($m[2] > 1 ? $m[2].' x ' : '').$this->itemLink($m[1]);
            else if (preg_match('/^rentitem\s+([^,]+),\s*(\d+)\s*$/', $st, $m))
                $html = 'Rents '.$this->itemLink($m[1]).' for '.($m[2] % 3600 ? ($m[2] % 60 ? $m[2].' seconds' : ($m[2] / 60).' minutes') : ($m[2] / 3600).' hours');
            else if (preg_match('/^getgroupitem\s+(\w+)/', $st, $m))
                $html = 'Gives a random item of the group '.self::constName(preg_replace('/^IG_/', '', $m[1]));
            else if (preg_match('/^(itemheal|percentheal|heal)\s+(.+)$/s', $st, $m))
            {
                $a   = self::args($m[2]);
                $fmt = function ($v) { return preg_match('/^rand\((\d+),\s*(\d+)\)$/', trim($v), $r) ? $r[1].' - '.$r[2] : (self::num($v) !== null ? trim($v) : null); };
                $hp  = isset($a[0]) ? $fmt($a[0]) : null;
                $sp  = isset($a[1]) ? $fmt($a[1]) : null;
                $pct = $m[1] == 'percentheal' ? '%' : '';
                if ($hp !== null && $sp !== null)
                {
                    $parts = [];
                    if ($hp !== '0')
                        $parts[] = $hp.$pct.' HP';
                    if ($sp !== '0')
                        $parts[] = $sp.$pct.' SP';
                    $html = 'Restores '.($parts ? implode(' and ', $parts) : 'nothing');
                }
            }
            else if (preg_match('/^(sc_start\d?|sc_end)\s+(SC_\w+)(?:,\s*(\d+))?/', $st, $m))
            {
                if ($m[1] == 'sc_end')
                    $html = 'Cures '.self::constName($m[2]);
                else if (isset($m[3]))
                    $html = 'Applies '.self::constName($m[2]).' for '.self::pct((int)$m[3], 1000).' seconds';
            }
            else if (preg_match('/^autobonus\d?\s+/', $st))
                $html = null;                               // shown as source text

            if ($html !== null)
                $out[] = [$html, 1];
            else
            {
                $src = preg_replace('/\s+/', ' ', $st);
                $out[] = [htmlspecialchars(mb_strlen($src) > 160 ? mb_substr($src, 0, 157).'...' : $src), 0];
            }
        }

        return $out;
    }
});

?>
