<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');

if (!CLI)
    die('not in cli mode');


// ROWOW - Ragnarok character builder (?rocalc)
// Compiles mod-rowow's pre-renewal job and skill data into datasets/ro-builder (loaded by ?data=ro-builder).
// The rules mirror mod-rowow (E:\MW\eqwow-live\azerothcore-wotlk\Source\modules\mod-rowow\src):
//   jobs (PreRenewalJobNames, SecondJobFirstJobIDs, BabyJobNormalJobIDs)    ROWOW_Identity.cpp
//   max levels, base HP/SP by level                                       ROWOW_Identity.cpp LoadIdentityData
//   job bonus stats, attack motion by weapon                              ROWOW_Status.cpp LoadStatusData
//   skill trees and their inheritance                                     ROWOW_Skills.cpp LoadSkillData
//   status points by base level, battle config                            mod_rowow_db_statpoint, mod_rowow_db_battle_config
//
// g_ro_builder
//   c - battle config values the builder uses
//   p - total status points by base level (index = level)
//   w - weapon types [key, name]
//   j - jobs by id: n name, k db name, mb max base level, mj max job level, u transcendent, y baby, f first job (2nd jobs) or -1,
//       hp/sp base by base level (index = level - 1), b stat bonus totals by job level (index = job level - 1), a attack motion by weapon key,
//       t skill tree [skillId, maxLevel, jobLevel, [[needSkillId, needLevel], ...], originJobId]
//   s - skills by id: k const name, n name, m max level, f flags (1 quest, 2 wedding, 4 spirit), l spell ids by level, i icon
//   x - job changes: [fromJob, toJob, fromJobLevelNeeded, baseLevelNeeded]
//   r - path starts [jobId, ...]

CLISetup::registerSetup("build", new class extends SetupScript
{
    protected $info = array(
        'robuilder' => [[], CLISetup::ARGV_PARAM, 'Compiles ROWOW job and skill data to file for the Ragnarok character builder.']
    );

    protected $setupAfter   = [['spell', 'icons'], []];
    protected $requiredDirs = ['datasets/'];

    // ROWOW_Identity.cpp PreRenewalJobNames: [jobId, name]
    private const JOBS = array(
        [0, 'Novice'], [1, 'Swordman'], [2, 'Mage'], [3, 'Archer'], [4, 'Acolyte'], [5, 'Merchant'], [6, 'Thief'], [7, 'Knight'], [8, 'Priest'],
        [9, 'Wizard'], [10, 'Blacksmith'], [11, 'Hunter'], [12, 'Assassin'], [14, 'Crusader'], [15, 'Monk'], [16, 'Sage'], [17, 'Rogue'],
        [18, 'Alchemist'], [19, 'Bard'], [20, 'Dancer'], [23, 'Super_Novice'], [24, 'Gunslinger'], [25, 'Ninja'],
        [4001, 'Novice_High'], [4002, 'Swordman_High'], [4003, 'Mage_High'], [4004, 'Archer_High'], [4005, 'Acolyte_High'], [4006, 'Merchant_High'],
        [4007, 'Thief_High'], [4008, 'Lord_Knight'], [4009, 'High_Priest'], [4010, 'High_Wizard'], [4011, 'Whitesmith'], [4012, 'Sniper'],
        [4013, 'Assassin_Cross'], [4015, 'Paladin'], [4016, 'Champion'], [4017, 'Professor'], [4018, 'Stalker'], [4019, 'Creator'], [4020, 'Clown'],
        [4021, 'Gypsy'], [4046, 'Taekwon'], [4047, 'Star_Gladiator'], [4049, 'Soul_Linker'], [4023, 'Baby'], [4024, 'Baby_Swordman'],
        [4025, 'Baby_Mage'], [4026, 'Baby_Archer'], [4027, 'Baby_Acolyte'], [4028, 'Baby_Merchant'], [4029, 'Baby_Thief'], [4030, 'Baby_Knight'],
        [4031, 'Baby_Priest'], [4032, 'Baby_Wizard'], [4033, 'Baby_Blacksmith'], [4034, 'Baby_Hunter'], [4035, 'Baby_Assassin'], [4037, 'Baby_Crusader'],
        [4038, 'Baby_Monk'], [4039, 'Baby_Sage'], [4040, 'Baby_Rogue'], [4041, 'Baby_Alchemist'], [4042, 'Baby_Bard'], [4043, 'Baby_Dancer'],
        [4045, 'Super_Baby']
    );

    // ROWOW_Identity.cpp BabyJobNormalJobIDs
    private const BABY_JOBS = array(
        4023 => 0, 4024 => 1, 4025 => 2, 4026 => 3, 4027 => 4, 4028 => 5, 4029 => 6, 4030 => 7, 4031 => 8, 4032 => 9, 4033 => 10,
        4034 => 11, 4035 => 12, 4037 => 14, 4038 => 15, 4039 => 16, 4040 => 17, 4041 => 18, 4042 => 19, 4043 => 20, 4045 => 23
    );

    // ROWOW_Identity.cpp SecondJobFirstJobIDs
    private const SECOND_JOBS = array(
        7 => 1, 14 => 1, 8 => 4, 15 => 4, 9 => 2, 16 => 2, 10 => 5, 18 => 5, 11 => 3, 19 => 3, 20 => 3, 12 => 6, 17 => 6,
        4008 => 1, 4015 => 1, 4009 => 4, 4016 => 4, 4010 => 2, 4017 => 2, 4011 => 5, 4019 => 5, 4012 => 3, 4020 => 3, 4021 => 3,
        4013 => 6, 4018 => 6, 4047 => 4046, 4049 => 4046
    );

    // ROWOW.h ROWOW_WEAPON_TYPE_* (job_aspd.yml keys) and their display names
    private const WEAPONS = array(
        ['Fist', 'Bare Hands'], ['Dagger', 'Dagger'], ['1hSword', 'One-Handed Sword'], ['2hSword', 'Two-Handed Sword'], ['1hSpear', 'One-Handed Spear'],
        ['2hSpear', 'Two-Handed Spear'], ['1hAxe', 'One-Handed Axe'], ['2hAxe', 'Two-Handed Axe'], ['Mace', 'Mace'], ['2hMace', 'Two-Handed Mace'],
        ['Staff', 'Staff'], ['Bow', 'Bow'], ['Knuckle', 'Knuckle'], ['Musical', 'Musical Instrument'], ['Whip', 'Whip'], ['Book', 'Book'],
        ['Katar', 'Katar'], ['Revolver', 'Revolver'], ['Rifle', 'Rifle'], ['Gatling', 'Gatling Gun'], ['Shotgun', 'Shotgun'], ['Grenade', 'Grenade Launcher'],
        ['Huuma', 'Huuma Shuriken'], ['2hStaff', 'Two-Handed Staff']
    );

    // the battle config values the builder reads (ROWOW_Status.cpp LoadStatusData), with mod-rowow's defaults
    private const CONFIG = array(
        'max_parameter'              => 99,
        'max_trans_parameter'        => 99,
        'max_baby_parameter'         => 80,
        'transcendent_status_points' => 52,
        'player_skillup_limit'       => 1,
        'player_skillfree'           => 0,
        'quest_skill_learn'          => 0,
        'hp_rate'                    => 100,
        'sp_rate'                    => 100,
        'max_hp_lv99'                => 330000,
        'max_hp_lv150'               => 660000,
        'max_hp'                     => 1100000,
        'max_sp'                     => 1000000,
        'max_aspd'                   => 190,
        'castrate_dex_scale'         => 150,
        'enable_baseatk'             => 9,
        'enable_critical'            => 17,
        'enable_perfect_flee'        => 1
    );

    public function generate() : bool
    {
        if (!$this->success)
            return false;

        // job names as rAthena matches them: upper case, no underscores (GetJobNameKey)
        $jobIdsByKey = [];
        $jobs        = [];
        foreach (self::JOBS as [$jobId, $name])
        {
            $jobIdsByKey[$this->nameKey($name)] = $jobId;
            $jobs[$jobId] = array(
                'n'  => $this->displayName($name),
                'k'  => $name,
                'mb' => 99,
                'mj' => 10,
                'u'  => $jobId >= 4001 && $jobId <= 4022 ? 1 : 0,
                'y'  => isset(self::BABY_JOBS[$jobId]) ? 1 : 0,
                'f'  => self::SECOND_JOBS[$jobId] ?? -1,
                'hp' => [],
                'sp' => [],
                'b'  => [],
                'a'  => new stdClass(),
                't'  => []
            );
        }

        // a Baby job's first job is its normal job's
        foreach (self::BABY_JOBS as $babyId => $normalId)
            $jobs[$babyId]['f'] = self::SECOND_JOBS[$normalId] ?? -1;

        // job table: rows in rAthena's load order, a later row wins
        $jobRows = DB::World()->select('SELECT * FROM mod_rowow_db_job ORDER BY `RowIndex`');
        if (!$jobRows)
        {
            CLI::write('[robuilder] mod_rowow_db_job is empty or missing - is the world DB the ROWOW one?', CLI::LOG_ERROR);
            return false;
        }

        foreach ($jobRows as $row)
        {
            foreach ($this->parseMap($row['Jobs']) as $jobName => $on)
            {
                if ($on != 'true' || !isset($jobIdsByKey[$this->nameKey($jobName)]))
                    continue;

                $job = &$jobs[$jobIdsByKey[$this->nameKey($jobName)]];
                if ($row['MaxBaseLevel'] !== null)
                    $job['mb'] = (int)$row['MaxBaseLevel'];
                if ($row['MaxJobLevel'] !== null)
                    $job['mj'] = (int)$row['MaxJobLevel'];
                if ($_ = $this->valuesByLevel($row['BaseHp'], 'Hp'))
                    $job['hp'] = $_;
                if ($_ = $this->valuesByLevel($row['BaseSp'], 'Sp'))
                    $job['sp'] = $_;
                unset($job);
            }
        }

        // stat bonuses and attack motion (LoadStatusData runs after the max levels are known)
        $statNames = ['Str', 'Agi', 'Vit', 'Int', 'Dex', 'Luk'];
        foreach ($jobRows as $row)
        {
            if (!$row['BonusStats'] && !$row['BaseASPD'])
                continue;

            foreach ($this->parseMap($row['Jobs']) as $jobName => $on)
            {
                if ($on != 'true' || !isset($jobIdsByKey[$this->nameKey($jobName)]))
                    continue;

                $job = &$jobs[$jobIdsByKey[$this->nameKey($jobName)]];
                if ($row['BonusStats'])
                {
                    $bonus = array_fill(0, $job['mj'], [0, 0, 0, 0, 0, 0]);
                    foreach ($this->parseMapList($row['BonusStats']) as $entry)
                    {
                        $lvl = (int)($entry['Level'] ?? 0);
                        if ($lvl < 1 || $lvl > $job['mj'])
                            continue;

                        foreach ($statNames as $i => $s)
                            if (isset($entry[$s]))
                                $bonus[$lvl - 1][$i] += (int)$entry[$s];
                    }

                    for ($i = 1; $i < count($bonus); $i++)
                        for ($s = 0; $s < 6; $s++)
                            $bonus[$i][$s] += $bonus[$i - 1][$s];

                    $job['b'] = $bonus;
                }

                if ($row['BaseASPD'])
                {
                    $aspd = (array)$job['a'];
                    foreach ($this->parseMap($row['BaseASPD']) as $weapon => $amotion)
                        foreach (self::WEAPONS as [$wKey, ])
                            if (strcasecmp($wKey, $weapon) == 0)
                                $aspd[$wKey] = (int)$amotion;

                    $job['a'] = (object)$aspd;
                }
                unset($job);
            }
        }

        // skills
        $skills    = [];
        $skillKeys = [];
        foreach (DB::World()->select('SELECT `Id`, `Name`, `Description`, `MaxLevel`, `Flags` FROM mod_rowow_db_skill') as $s)
        {
            $flags = 0;
            $f     = $this->parseMap($s['Flags']);
            if (($f['IsQuest'] ?? '') == 'true')
                $flags |= 1;
            if (($f['IsWedding'] ?? '') == 'true')
                $flags |= 2;
            if (($f['IsSpirit'] ?? '') == 'true')
                $flags |= 4;

            $skillKeys[strtoupper($s['Name'])] = (int)$s['Id'];
            $skills[(int)$s['Id']] = ['k' => $s['Name'], 'n' => $s['Description'] ?: $s['Name'], 'm' => (int)$s['MaxLevel'], 'f' => $flags];
        }

        // skill trees: each job's own (SkillTreeDatabase::parseBodyNode), then those it inherits (loadingFinished)
        $own      = [];                                     // jobId => [skillId => entry]
        $inherits = [];                                     // jobId => [jobId, ..]
        foreach (DB::World()->select('SELECT * FROM mod_rowow_db_skill_tree ORDER BY `RowIndex`') as $row)
        {
            $jobKey = $this->nameKey($row['Job']);
            if (!isset($jobIdsByKey[$jobKey]))
                continue;

            $jobId = $jobIdsByKey[$jobKey];
            $own[$jobId]      ??= [];
            $inherits[$jobId] ??= [];

            foreach ($this->parseMap($row['Inherit']) as $iName => $on)
            {
                if (!isset($jobIdsByKey[$this->nameKey($iName)]))
                    continue;

                $iId = $jobIdsByKey[$this->nameKey($iName)];
                $pos = array_search($iId, $inherits[$jobId]);
                if ($on != 'true')
                {
                    if ($pos !== false)
                        array_splice($inherits[$jobId], $pos, 1);
                }
                else if ($pos === false)
                    $inherits[$jobId][] = $iId;
            }

            if (!$row['Tree_Name'])
                continue;

            $skillId = $skillKeys[strtoupper($row['Tree_Name'])] ?? 0;
            if (!$skillId)
            {
                CLI::write('[robuilder] skill '.$row['Tree_Name'].' of job '.$row['Job'].' is not in mod_rowow_db_skill', CLI::LOG_WARN);
                continue;
            }

            $exists = isset($own[$jobId][$skillId]);
            $entry  = $own[$jobId][$skillId] ?? ['m' => 0, 'j' => 0, 'r' => [], 'x' => false, 'o' => $jobId];

            $entry['m'] = min((int)$row['Tree_MaxLevel'], $skills[$skillId]['m']);
            if ($row['Tree_JobLevel'] !== null)
                $entry['j'] = min((int)$row['Tree_JobLevel'], $jobs[$jobId]['mj']);

            foreach ($this->parseMapList($row['Tree_Requires']) as $req)
            {
                $needId = $skillKeys[strtoupper($req['Name'] ?? '')] ?? 0;
                if (!$needId)
                    continue;

                $needLvl = min((int)($req['Level'] ?? 0), $skills[$needId]['m']);
                $found   = false;
                foreach ($entry['r'] as $k => [$nId, ])
                {
                    if ($nId != $needId)
                        continue;

                    $found = true;
                    if ($needLvl == 0)
                        unset($entry['r'][$k]);
                    else
                        $entry['r'][$k][1] = $needLvl;
                }

                if (!$found && $needLvl)
                    $entry['r'][] = [$needId, $needLvl];
            }
            $entry['r'] = array_values($entry['r']);

            if ($row['Tree_Exclude'] !== null && $row['Tree_Exclude'] !== '')
                $entry['x'] = $row['Tree_Exclude'] == 'true';
            else if (!$exists)
                $entry['x'] = false;

            $own[$jobId][$skillId] = $entry;
        }

        // inheritance: the inherited jobs' own trees (as loaded), the last inherited job winning, levels capped to this job's maximums
        foreach ($own as $jobId => $tree)
        {
            $merged = $tree;
            $inh    = [];
            foreach ($inherits[$jobId] as $iId)
            {
                foreach ($own[$iId] ?? [] as $skillId => $entry)
                {
                    if ($entry['x'] || isset($tree[$skillId]))
                        continue;

                    $entry['j'] = min($entry['j'], $jobs[$jobId]['mj']);
                    $inh[$skillId] = $entry;
                }
            }

            foreach ($inh as $skillId => $entry)
                if (!isset($merged[$skillId]))
                    $merged[$skillId] = $entry;

            foreach ($merged as $skillId => $entry)
            {
                if (!$entry['m'])
                    continue;

                $jobs[$jobId]['t'][] = [$skillId, $entry['m'], $entry['j'], $entry['r'], $entry['o']];
            }
        }

        // spells by level (mod_rowow_skill_spell) and their icons
        $spellIds = [];
        foreach (DB::World()->select('SELECT `SkillName`, `SkillLevel`, `SpellID` FROM mod_rowow_skill_spell ORDER BY `SkillName`, `SkillLevel`') as $ss)
        {
            $skillId = $skillKeys[strtoupper($ss['SkillName'])] ?? 0;
            if (!$skillId || $ss['SkillLevel'] < 1)
                continue;

            $skills[$skillId]['l'][(int)$ss['SkillLevel'] - 1] = (int)$ss['SpellID'];
            $spellIds[] = (int)$ss['SpellID'];
        }

        $icons = $this->loadIcons($spellIds);
        $usedSkills = [];
        foreach ($jobs as $job)
            foreach ($job['t'] as [$skillId, ])
                $usedSkills[$skillId] = 1;

        $nIcons = 0;
        foreach ($skills as $skillId => &$s)
        {
            if (!isset($usedSkills[$skillId]))
            {
                unset($skills[$skillId]);
                continue;
            }

            if (!empty($s['l']))
            {
                ksort($s['l']);
                $lvls = [];
                for ($i = 0; $i < $s['m']; $i++)
                    $lvls[$i] = $s['l'][$i] ?? 0;
                $s['l'] = $lvls;

                foreach ($s['l'] as $spellId)
                {
                    if ($spellId && !empty($icons[$spellId]))
                    {
                        $s['i'] = $icons[$spellId];
                        $nIcons++;
                        break;
                    }
                }
            }
        }
        unset($s);

        if (!$nIcons)
            CLI::write('[robuilder] no skill icons found (aowow_spell/aowow_icons or dbc_spell/dbc_spellicon empty?) - rebuild after the spell setup', CLI::LOG_WARN);

        // job changes: [from, to, job level needed, base level needed] (the job change NPC scripts in mod_rowow_npc_script:
        // Novice: Basic Skill 9 (job 10); second jobs: job 40 (knight.txt, LordKnight.txt, StarGladiator.txt, SoulLinker.txt);
        // Super Novice: base 45 (supernovice.txt); rebirth: base 99, job 50 (valkyrie.txt))
        $changes = [];
        foreach ([1, 2, 3, 4, 5, 6, 24, 25, 4046] as $to)
            $changes[] = [0, $to, 10, 0];
        $changes[] = [0, 23, 10, 45];
        foreach ([4002, 4003, 4004, 4005, 4006, 4007] as $to)
            $changes[] = [4001, $to, 10, 0];
        foreach ([4024, 4025, 4026, 4027, 4028, 4029] as $to)
            $changes[] = [4023, $to, 10, 0];
        $changes[] = [4023, 4045, 10, 45];
        foreach (self::SECOND_JOBS as $second => $first)
        {
            if ($second >= 4001 && $second <= 4022)        // transcendent second jobs come from the transcendent first jobs
                $first = $first + 4001;
            $changes[] = [$first, $second, 40, 0];
        }
        foreach (self::BABY_JOBS as $baby => $normal)
            if (isset(self::SECOND_JOBS[$normal]))
                $changes[] = [array_search(self::SECOND_JOBS[$normal], self::BABY_JOBS), $baby, 40, 0];

        // battle config
        $config = self::CONFIG;
        foreach (DB::World()->select('SELECT `Key`, `Value` FROM mod_rowow_db_battle_config') as $bc)
            if (array_key_exists($bc['Key'], $config) && $bc['Value'] !== null && $bc['Value'] !== '')
                $config[$bc['Key']] = $this->configValue($bc['Value']);

        // status points by base level
        $statPoints = [0];
        foreach (DB::World()->select('SELECT `Level`, `Points` FROM mod_rowow_db_statpoint ORDER BY `Level`') as $sp)
            $statPoints[(int)$sp['Level']] = (int)$sp['Points'];
        for ($i = 1; $i < count($statPoints); $i++)
            $statPoints[$i] ??= $statPoints[$i - 1];
        ksort($statPoints);

        $data = array(
            'c' => $config,
            'p' => array_values($statPoints),
            'w' => self::WEAPONS,
            'j' => $jobs,
            's' => $skills,
            'x' => $changes,
            'r' => [0, 4001, 4023]
        );

        CLI::write('[robuilder] '.count($jobs).' jobs, '.count($skills).' skills ('.$nIcons.' with icons), '.count($changes).' job changes');

        return CLISetup::writeFile('datasets/ro-builder', 'var g_ro_builder = '.Util::toJSON($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).';');
    }

    private function loadIcons(array $spellIds) : array
    {
        if (!$spellIds)
            return [];

        $icons = [];
        try
        {
            $icons = DB::Aowow()->selectCol('SELECT s.`id` AS ARRAY_KEY, ic.`name` FROM ?_spell s JOIN ?_icons ic ON ic.`id` = s.`iconId` WHERE s.`id` IN (?a)', $spellIds) ?: [];
        }
        catch (Throwable $e) { }

        if ($icons)
            return $icons;

        try
        {
            if (DB::Aowow()->selectCell('SHOW TABLES LIKE "dbc_spell"') && DB::Aowow()->selectCell('SHOW TABLES LIKE "dbc_spellicon"'))
                $icons = DB::Aowow()->selectCol('SELECT s.`id` AS ARRAY_KEY, LOWER(SUBSTRING_INDEX(si.`iconPath`, "\\\\", -1)) FROM dbc_spell s JOIN dbc_spellicon si ON si.`id` = s.`iconId` WHERE s.`id` IN (?a)', $spellIds) ?: [];
        }
        catch (Throwable $e) { }

        return $icons;
    }

    private function nameKey(string $name) : string
    {
        return strtoupper(str_replace('_', '', $name));
    }

    private function displayName(string $name) : string
    {
        if (preg_match('/^(\w+)_High$/', $name, $m))
            return 'High '.$m[1];
        if ($name == 'Baby')
            return 'Baby Novice';

        return str_replace('_', ' ', $name);
    }

    private function configValue(string $text) : int
    {
        if ($text == 'yes' || $text == 'on')
            return 1;
        if ($text == 'no' || $text == 'off')
            return 0;

        return intval($text, 0);
    }

    // "Key=value,Key2=value2" (ROWOWDataTable::ParseMap)
    private function parseMap(?string $cell) : array
    {
        $result = [];
        foreach (explode(',', $cell ?? '') as $pair)
        {
            if (!strpos($pair, '='))
                continue;

            [$k, $v] = explode('=', $pair, 2);
            $result[trim($k)] = trim($v);
        }

        return $result;
    }

    // "Level=1,Hp=40;Level=2,Hp=45;" (ROWOWDataTable::ParseMapList)
    private function parseMapList(?string $cell) : array
    {
        $result = [];
        foreach (explode(';', $cell ?? '') as $entry)
            if ($_ = $this->parseMap($entry))
                $result[] = $_;

        return $result;
    }

    // values by level, index = level - 1, a missing level repeating the one before (ReadValuesByLevel)
    private function valuesByLevel(?string $cell, string $field) : array
    {
        $byLevel = [];
        foreach ($this->parseMapList($cell) as $entry)
            if (isset($entry['Level'], $entry[$field]) && $entry['Level'] > 0)
                $byLevel[(int)$entry['Level']] = (int)$entry[$field];

        if (!$byLevel)
            return [];

        $result = [];
        $last   = 0;
        for ($i = 1; $i <= max(array_keys($byLevel)); $i++)
            $result[] = $last = $byLevel[$i] ?? $last;

        return $result;
    }
});

?>
