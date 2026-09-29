<?php

// EQWOW - open world raid boss scaling
//
// mod-everquest makes the open world copy of an EQ raid boss / raid mini boss stronger than its instanced
// copy (see EverQuestMod::IsEQWorldBossTierCreature and the EverQuest.CreatureWorldBoss.* settings in
// mod-everquest's conf). Both copies share one creature_template entry, so the difference only exists at
// runtime and nothing in the world db describes it. The three multipliers are mirrored here as aowow
// config values (eqwow_worldboss_*, defaults = the mod's defaults) and must be kept in step with the
// server's Everquest.conf by hand.
//
//   HealthMultiplier - max health
//   DamageMultiplier - white melee/ranged damage and all spell damage (breaths, nukes, DoTs, procs)
//   LootMultiplier   - the whole EQ loot table is rolled this many times per kill (coin is not)

if (!defined('AOWOW_REVISION'))
    die('illegal access');


abstract class EQWorldBoss
{
    // EQ_CREATURE_DIFFICULTY_* in EverQuest.h
    public const DIFFICULTY_RAIDBOSS     = 2;
    public const DIFFICULTY_RAIDMINIBOSS = 3;

    // mod-everquest defaults (conf.dist "Creature World Boss Settings"), used if the config rows are missing
    public const DEFAULT_HEALTH_MULTIPLIER = 2.5;
    public const DEFAULT_DAMAGE_MULTIPLIER = 1.5;
    public const DEFAULT_LOOT_MULTIPLIER   = 2;

    private const TBL_CREATURE = 'mod_everquest_creature';

    private static $hasTable = null;

    // the config rows come from setup/updates, which the EQWOW update scripts never apply - fall back to
    // the mod defaults instead of letting Cfg::get() raise on an unknown key
    private static function cfg(string $key, $default)
    {
        return Cfg::has($key) ? Cfg::get($key) : $default;
    }

    public static function healthMultiplier() : float
    {
        return max(0.0, floatVal(self::cfg('EQWOW_WBOSS_HEALTH_MULT', self::DEFAULT_HEALTH_MULTIPLIER)));
    }

    public static function damageMultiplier() : float
    {
        return max(0.0, floatVal(self::cfg('EQWOW_WBOSS_DAMAGE_MULT', self::DEFAULT_DAMAGE_MULTIPLIER)));
    }

    // the mod treats anything <= 1 as "roll once"
    public static function lootMultiplier() : int
    {
        return max(1, intVal(self::cfg('EQWOW_WBOSS_LOOT_MULT', self::DEFAULT_LOOT_MULTIPLIER)));
    }

    public static function getDifficultyType(int $npcId) : int
    {
        if (self::$hasTable === null)
            self::$hasTable = !!DB::World()->selectCell('SHOW TABLES LIKE "'.self::TBL_CREATURE.'"');

        if (!self::$hasTable)
            return 0;

        return (int)DB::World()->selectCell('SELECT `DifficultyType` FROM '.self::TBL_CREATURE.' WHERE `CreatureTemplateID` = ?d', $npcId);
    }

    // The mod scales a raid boss / mini boss only while it stands on a map that is not instanceable. EQ open
    // world maps (750-872) are the ones ?_zones lists as MAP_TYPE_ZONE; the dungeon/raid instance copies
    // (901-949) are not in ?_zones at all. Triggered bosses have no `creature` row, so fall back to where
    // their kill/gossip/quest reactions put them.
    public static function hasWorldVersion(int $npcId) : bool
    {
        if (!in_array(self::getDifficultyType($npcId), [self::DIFFICULTY_RAIDBOSS, self::DIFFICULTY_RAIDMINIBOSS]))
            return false;

        if ($maps = DB::World()->selectCol('SELECT DISTINCT `map` FROM creature WHERE `id` = ?d', $npcId))
            return !!DB::Aowow()->selectCell('SELECT 1 FROM ?_zones WHERE `type` = ?d AND `mapId` IN (?a) LIMIT 1', MAP_TYPE_ZONE, $maps);

        $loc     = EQReactions::getReactionSpawnLocations($npcId);
        $areaIds = array_merge(array_column($loc['points'], 'areaId'), array_keys($loc['zones']));
        if ($areaIds = array_filter($areaIds))
            return !!DB::Aowow()->selectCell('SELECT 1 FROM ?_zones WHERE `type` = ?d AND `id` IN (?a) LIMIT 1', MAP_TYPE_ZONE, $areaIds);

        return false;
    }

    // 2.5 -> "2.5", 2.0 -> "2"
    public static function formatMultiplier(float $value) : string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    // same rounding as EverQuestMod::ApplyWorldBossHealthMultiplier (truncating uint32 cast)
    public static function scaleHealth(int $health) : int
    {
        return (int)min(2000000000, max(1, floor($health * self::healthMultiplier())));
    }

    // same rounding as EverQuestMod::ApplyWorldBossMeleeDamageMultiplier
    public static function scaleDamage(float $damage) : int
    {
        return (int)min(2000000000, max(0, round($damage * self::damageMultiplier())));
    }

    // Each extra pass is a complete, independent roll of the table pooled into the same corpse, so an item
    // that drops with chance p per pass drops at least once with 1 - (1 - p)^n, and up to n times the
    // per-pass stack. Guaranteed items (100%) simply multiply their stack. The mod stops adding bonus copies
    // once the corpse would exceed its 18 loot slots, which only ever trims a handful of the biggest tables.
    public static function scaleLootRows(array $rows) : array
    {
        $n = self::lootMultiplier();
        if ($n <= 1)
            return $rows;

        foreach ($rows as &$row)
        {
            $p = min(1.0, max(0.0, $row['percent'] / 100));

            if (isset($row['stack']))
            {
                if ($p >= 1.0)
                    $row['stack'] = [$row['stack'][0] * $n, $row['stack'][1] * $n];
                else
                    $row['stack'] = [$row['stack'][0], $row['stack'][1] * $n];
            }

            // per-pass count distribution no longer applies
            unset($row['pctstack']);

            if ($row['percent'] > 0)
                $row['percent'] = round((1 - pow(1 - $p, $n)) * 100, 3);
        }

        return $rows;
    }
}

?>
