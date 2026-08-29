<?php

// EQWOW - triggered reaction data (kill/combat/timer spawns, gossip reactions, quest reactions)
//
// EQWOW replaces most of EQ's Lua scripting with three world-db tables written by the converter and
// read by mod-everquest at runtime:
//   mod_everquest_creature_kill_spawn - "creature A dies / enters combat / evades / idles -> do X"
//   mod_everquest_gossip_reaction     - "player picks gossip option on creature A -> do X"
//   mod_everquest_quest_reaction      - "player turns in quest Q -> do X" (questgiver acts)
// None of them exist in a stock AzerothCore world db, so every read is guarded by a table check and
// the pages simply render nothing when the tables are absent.
//
// This component turns those rows into display-ready fragments for npc.php and quest.php so both
// ends of a reaction (the trigger and the thing it spawns) describe each other.

if (!defined('AOWOW_REVISION'))
    die('illegal access');


abstract class EQReactions
{
    // QuestReactionType (EQWOWConverter/Quests/Types/QuestReactionType.cs, EQ_QUEST_REACTION_* in EverQuest.h)
    public const REACT_UNKNOWN       = 0;
    public const REACT_ATTACKPLAYER  = 1;
    public const REACT_DESPAWN       = 2;
    public const REACT_EMOTE         = 3;
    public const REACT_SAY           = 4;
    public const REACT_SPAWN         = 5;
    public const REACT_SPAWNUNIQUE   = 6;
    public const REACT_YELL          = 7;
    public const REACT_KILLSPAWN     = 8;
    public const REACT_WALKTO        = 9;
    // CastSpell (10) never reaches these tables - the converter turns it into creature_text + SmartAI instead
    public const REACT_CASTSPELL     = 10;
    public const REACT_WALKGRID      = 11;
    public const REACT_SPAWNOBJECT   = 12;

    // EQ_KILLSPAWN_ACTION_* in EverQuest.h
    public const ACTION_SPAWN         = 0;
    public const ACTION_DESPAWN       = 1;
    public const ACTION_RESPAWNSELF   = 2;
    public const ACTION_RESPAWNTARGET = 3;
    public const ACTION_SAY           = 4;
    public const ACTION_EMOTE         = 5;
    public const ACTION_YELL          = 6;
    public const ACTION_ATTACKPLAYER  = 7;
    // 8 and 9 are only ever built in memory, when a walkgrid/spawnobject reaction is deferred to an arrival
    public const ACTION_WALKPATH      = 8;
    public const ACTION_SPAWNOBJECT   = 9;

    // EQ_KILLSPAWN_TRIGGER_* in EverQuest.h
    public const TRIGGER_DEATH    = 0;
    public const TRIGGER_COMBAT   = 1;
    public const TRIGGER_EVADE    = 2;
    public const TRIGGER_OOCTIMER = 3;

    private const TBL_KILLSPAWN = 'mod_everquest_creature_kill_spawn';
    private const TBL_GOSSIP    = 'mod_everquest_gossip_reaction';
    private const TBL_QUEST     = 'mod_everquest_quest_reaction';
    private const TBL_WAYPOINT  = 'mod_everquest_creature_waypoint';

    private static $tables     = [];
    private static $npcNames   = [];
    private static $qstNames   = [];
    private static $objNames   = [];
    private static $placements = [];
    private static $pathLens   = [];


    /**********/
    /* Shared */
    /**********/

    private static function hasTable(string $table) : bool
    {
        if (!isset(self::$tables[$table]))
            self::$tables[$table] = !!DB::World()->selectCell('SHOW TABLES LIKE "'.$table.'"');

        return self::$tables[$table];
    }

    // the walkgrid/spawnobject columns arrived later than the tables did - a world db from before that
    // converter run still works, it just has nothing to show for those reactions
    private static function hasColumn(string $table, string $column) : bool
    {
        $key = $table.'.'.$column;
        if (!isset(self::$tables[$key]))
            self::$tables[$key] = !!DB::World()->selectCell('SHOW COLUMNS FROM '.$table.' LIKE "'.$column.'"');

        return self::$tables[$key];
    }

    // one query for every creature name a page needs instead of one per link
    private static function loadNPCNames(array $ids) : void
    {
        $ids = array_diff(array_filter(array_map('intVal', $ids)), array_keys(self::$npcNames));
        if (!$ids)
            return;

        $rows = DB::Aowow()->select('SELECT id AS ARRAY_KEY, name_loc0, name_loc2, name_loc3, name_loc4, name_loc6, name_loc8 FROM ?_creature WHERE id IN (?a)', $ids);
        foreach ($ids as $id)
            self::$npcNames[$id] = isset($rows[$id]) ? Util::localizedString($rows[$id], 'name') : '';
    }

    private static function loadQuestNames(array $ids) : void
    {
        $ids = array_diff(array_filter(array_map('intVal', $ids)), array_keys(self::$qstNames));
        if (!$ids)
            return;

        $rows = DB::Aowow()->select('SELECT id AS ARRAY_KEY, name_loc0, name_loc2, name_loc3, name_loc4, name_loc6, name_loc8 FROM ?_quests WHERE id IN (?a)', $ids);
        foreach ($ids as $id)
            self::$qstNames[$id] = isset($rows[$id]) ? Util::localizedString($rows[$id], 'name') : '';
    }

    // plain names - map pin tooltips are plain text, they cannot carry links
    public static function npcName($id) : string
    {
        $id = intVal($id);
        if ($id <= 0)
            return Lang::npc('reactUnknownNpc');

        self::loadNPCNames([$id]);

        return self::$npcNames[$id] ?: (Util::ucFirst(Lang::game('npc')).' #'.$id);
    }

    public static function questName($id) : string
    {
        $id = intVal($id);
        if ($id <= 0)
            return Lang::npc('reactUnknownQuest');

        self::loadQuestNames([$id]);

        return self::$qstNames[$id] ?: (Util::ucFirst(Lang::game('quest')).' #'.$id);
    }

    public static function npcLink($id, string $selfLabel = '') : string
    {
        if (intVal($id) <= 0)
            return $selfLabel ?: Lang::npc('reactUnknownNpc');

        return '<a href="?npc='.intVal($id).'">'.Util::htmlEscape(self::npcName($id)).'</a>';
    }

    public static function questLink($id) : string
    {
        if (intVal($id) <= 0)
            return Lang::npc('reactUnknownQuest');

        return '<a href="?quest='.intVal($id).'">'.Util::htmlEscape(self::questName($id)).'</a>';
    }

    public static function objectName($id) : string
    {
        $id = intVal($id);
        if ($id <= 0)
            return Lang::npc('reactUnknownObject');

        if (!isset(self::$objNames[$id]))
            self::$objNames[$id] = GameObjectList::getName($id) ?: (Util::ucFirst(Lang::game('object')).' #'.$id);

        return self::$objNames[$id];
    }

    public static function objectLink($id) : string
    {
        if (intVal($id) <= 0)
            return Lang::npc('reactUnknownObject');

        return '<a href="?object='.intVal($id).'">'.Util::htmlEscape(self::objectName($id)).'</a>';
    }

    // walkto and walkgrid both hand every row behind them to the arrival queue
    private static function isWalkType(int $type) : bool
    {
        return $type == self::REACT_WALKTO || $type == self::REACT_WALKGRID;
    }

    // mod_everquest_creature_waypoint holds the reaction paths, keyed by WaypointID (MapID 0 = any map)
    private static function pathLength(int $pathListId) : int
    {
        if (!$pathListId)
            return 0;

        if (!isset(self::$pathLens[$pathListId]))
            self::$pathLens[$pathListId] = self::hasTable(self::TBL_WAYPOINT)
                ? (int)DB::World()->selectCell('SELECT COUNT(*) FROM '.self::TBL_WAYPOINT.' WHERE `WaypointID` = ?d', $pathListId)
                : 0;

        return self::$pathLens[$pathListId];
    }

    private static function quoted(string $text) : string
    {
        return '<span class="q1">&laquo;'.Util::htmlEscape(trim($text)).'&raquo;</span>';
    }

    private static function pct(float $chance) : string
    {
        return (round($chance, 2) == round($chance, 0) ? round($chance) : round($chance, 2)).'%';
    }

    private static function delayNote(int $ms) : string
    {
        return $ms > 0 ? sprintf(Lang::npc('reactAfterDelay'), Util::formatTime($ms, true)) : '';
    }


    /*****************************/
    /* kill / combat / ooc spawn */
    /*****************************/

    // describes one mod_everquest_creature_kill_spawn row from the point of view of its trigger creature
    private static function killSpawnAction(array $r, bool $namedTarget) : string
    {
        $target = $namedTarget ? self::npcLink($r['TargetCreatureTemplateID']) : Lang::npc('reactThisNpc');

        // identical rows at different corpse offsets were merged - say how many copies appear
        if (($r['_count'] ?? 1) > 1)
            $target = sprintf(Lang::npc('reactCopies'), $r['_count'], $target);

        switch ($r['ActionType'])
        {
            case self::ACTION_DESPAWN:
                if ($namedTarget && $r['TargetCreatureTemplateID'] == $r['TriggerCreatureTemplateID'])
                    return Lang::npc('reactDespawnsSelf');

                return sprintf(Lang::npc('reactDespawns'), $target);
            case self::ACTION_RESPAWNSELF:
                return Lang::npc('reactRespawnsSelf');
            case self::ACTION_RESPAWNTARGET:
                return sprintf(Lang::npc('reactRespawnsTarget'), $target);
            // say/emote/yell actions are only ever built in memory for deferred walkto steps, never stored here,
            // so there is no text column to print - keep a sane label in case that changes
            case self::ACTION_SAY:
                return Lang::npc('reactSaysSomething');
            case self::ACTION_EMOTE:
                return Lang::npc('reactEmotesSomething');
            case self::ACTION_YELL:
                return Lang::npc('reactYellsSomething');
            case self::ACTION_ATTACKPLAYER:
                return sprintf(Lang::npc('reactAttacks'), $target);
            case self::ACTION_WALKPATH:
                return Lang::npc('reactWalksPath');
            case self::ACTION_SPAWNOBJECT:
                return sprintf(Lang::npc('reactSpawnsObject'), Lang::npc('reactUnknownObject'));
            default:
                return sprintf($r['SpawnAtCorpse'] ? Lang::npc('reactSpawnsAtCorpse') : Lang::npc('reactSpawns'), $target);
        }
    }

    // $withDelay is false for ooctimer rows: there DelayMinMS is the countdown itself, already stated by the header
    private static function killSpawnNotes(array $r, bool $withDelay = true) : array
    {
        $notes = [];

        if ($r['Chance'] < 100)
            $notes[] = sprintf(Lang::npc('reactChance'), self::pct($r['Chance']));

        if ($withDelay && ($_ = self::delayNote(max($r['DelayMinMS'], 0))))
            $notes[] = $r['DelayMaxMS'] > $r['DelayMinMS'] ? sprintf(Lang::npc('reactAfterDelayRange'), Util::formatTime($r['DelayMinMS'], true), Util::formatTime($r['DelayMaxMS'], true)) : $_;

        if ($r['RespawnTimeSec'] > 0)
            $notes[] = sprintf(Lang::npc('reactRespawnTime'), Util::formatTime($r['RespawnTimeSec'] * 1000, true));

        if ($r['OnlyIfNotAliveCreatureTemplateID'] > 0)
            $notes[] = sprintf(Lang::npc('reactOnlyIfNotAlive'), self::npcLink($r['OnlyIfNotAliveCreatureTemplateID']));

        foreach ([['RequireDeadCreatureTemplateIDs', 'reactRequireDead'], ['RequireAliveCreatureTemplateIDs', 'reactRequireAlive']] as [$col, $str])
        {
            if (!trim($r[$col]))
                continue;

            $links = [];
            foreach (explode(',', $r[$col]) as $id)
                if ($id = intVal(trim($id)))
                    $links[] = self::npcLink($id);

            if ($links)
                $notes[] = sprintf(Lang::npc($str), implode(', ', $links));
        }

        if ($r['TriggerMinLevel'] > 0 && $r['TriggerMaxLevel'] > 0)
            $notes[] = sprintf(Lang::npc('reactLevelRange'), $r['TriggerMinLevel'], $r['TriggerMaxLevel']);
        else if ($r['TriggerMinLevel'] > 0)
            $notes[] = sprintf(Lang::npc('reactLevelMin'), $r['TriggerMinLevel']);
        else if ($r['TriggerMaxLevel'] > 0)
            $notes[] = sprintf(Lang::npc('reactLevelMax'), $r['TriggerMaxLevel']);

        if ($r['AddToHateList'])
            $notes[] = Lang::npc('reactAddToHate');

        return $notes;
    }

    private static function triggerText(int $type, int $delayMS, bool $selfIsSubject) : string
    {
        switch ($type)
        {
            case self::TRIGGER_COMBAT:
                return Lang::npc($selfIsSubject ? 'reactOnCombat' : 'reactOnCombatOther');
            case self::TRIGGER_EVADE:
                return Lang::npc($selfIsSubject ? 'reactOnEvade' : 'reactOnEvadeOther');
            case self::TRIGGER_OOCTIMER:
                return sprintf(Lang::npc($selfIsSubject ? 'reactOnOocTimer' : 'reactOnOocTimerOther'), Util::formatTime(max($delayMS, 0), true));
            default:
                return Lang::npc($selfIsSubject ? 'reactOnDeath' : 'reactOnDeathOther');
        }
    }

    // rows are grouped by trigger type, then by AltGroup (a weighted pick of one scenario out of several)
    private static function groupKillSpawnRows(array $rows, bool $namedTarget) : array
    {
        $byTrigger = [];
        foreach ($rows as $r)
            $byTrigger[$r['TriggerTypeID']][] = $r;

        ksort($byTrigger);

        $out = [];
        foreach ($byTrigger as $triggerType => $trRows)
        {
            $oocDelay = 0;
            if ($triggerType == self::TRIGGER_OOCTIMER)
                foreach ($trRows as $r)
                    $oocDelay = max($oocDelay, $r['DelayMinMS']);

            $plain = [];                                    // AltGroup 0 - always runs
            $alts  = [];                                    // AltGroup > 0 - one AltID wins, by weight
            foreach ($trRows as $r)
            {
                if ($r['AltGroup'] > 0)
                    $alts[$r['AltGroup']][$r['AltID']][] = $r;
                else
                    $plain[] = $r;
            }

            $withDelay = $triggerType != self::TRIGGER_OOCTIMER;

            $entries = [];
            foreach ($plain as $r)
                $entries[] = array(
                    'text'  => self::killSpawnAction($r, $namedTarget),
                    'notes' => self::killSpawnNotes($r, $withDelay),
                    'alts'  => []
                );

            foreach ($alts as $altIds)
            {
                $sumWeight = 0;
                foreach ($altIds as $altRows)
                    $sumWeight += $altRows[0]['AltWeight'];

                $branches = [];
                foreach ($altIds as $altRows)
                {
                    $lines = [];
                    foreach ($altRows as $r)
                        $lines[] = array('text' => self::killSpawnAction($r, $namedTarget), 'notes' => self::killSpawnNotes($r, $withDelay));

                    $branches[] = array(
                        'chance' => $sumWeight > 0 ? 100 * $altRows[0]['AltWeight'] / $sumWeight : 0,
                        'lines'  => $lines
                    );
                }

                usort($branches, function ($a, $b) { return $b['chance'] <=> $a['chance']; });

                $entries[] = array(
                    'text'  => Lang::npc('reactOneOf'),
                    'notes' => [],
                    'alts'  => $branches
                );
            }

            $out[] = array(
                'trigger'     => $triggerType,
                'triggerText' => self::triggerText($triggerType, $oocDelay, $namedTarget),
                'entries'     => $entries
            );
        }

        return $out;
    }

    // everything this NPC does when it dies / fights / evades / idles
    public static function getKillSpawnTriggers(int $npcId) : array
    {
        if (!self::hasTable(self::TBL_KILLSPAWN))
            return [];

        $rows = DB::World()->select('SELECT * FROM '.self::TBL_KILLSPAWN.' WHERE `TriggerCreatureTemplateID` = ?d ORDER BY `TriggerTypeID`, `AltGroup`, `AltID`, `ID`', $npcId);
        if (!$rows)
            return [];

        self::loadNPCNames(array_column($rows, 'TargetCreatureTemplateID'));

        // several rows can be the very same action at different offsets around the corpse (e.g. two adds);
        // collapse them into one line with a count so the list stays readable
        return self::groupKillSpawnRows(self::collapseDuplicates($rows), true);
    }

    // everything that spawns/despawns this NPC through a kill/combat/timer trigger
    public static function getKillSpawnSources(int $npcId) : array
    {
        if (!self::hasTable(self::TBL_KILLSPAWN))
            return [];

        $rows = DB::World()->select('SELECT * FROM '.self::TBL_KILLSPAWN.' WHERE `TargetCreatureTemplateID` = ?d AND `TriggerCreatureTemplateID` <> ?d ORDER BY `TriggerCreatureTemplateID`, `TriggerTypeID`, `AltGroup`, `AltID`, `ID`', $npcId, $npcId);
        if (!$rows)
            return [];

        self::loadNPCNames(array_column($rows, 'TriggerCreatureTemplateID'));

        $byTrigger = [];
        foreach (self::collapseDuplicates($rows) as $r)
            $byTrigger[$r['TriggerCreatureTemplateID']][] = $r;

        $out = [];
        foreach ($byTrigger as $triggerId => $trRows)
            $out[] = array(
                'npcId'  => $triggerId,
                'link'   => self::npcLink($triggerId),
                'groups' => self::groupKillSpawnRows($trRows, false)
            );

        return $out;
    }

    private static function collapseDuplicates(array $rows) : array
    {
        $seen = [];
        $out  = [];
        foreach ($rows as $r)
        {
            $key = implode('|', [$r['TriggerCreatureTemplateID'], $r['TriggerTypeID'], $r['ActionType'], $r['TargetCreatureTemplateID'], $r['Chance'],
                                 $r['AltGroup'], $r['AltID'], $r['SpawnAtCorpse'], $r['DelayMinMS'], $r['DelayMaxMS'], $r['OnlyIfNotAliveCreatureTemplateID'],
                                 $r['RequireDeadCreatureTemplateIDs'], $r['RequireAliveCreatureTemplateIDs'], $r['AddToHateList'], $r['TriggerMinLevel'],
                                 $r['TriggerMaxLevel'], $r['RespawnTimeSec'], $r['Comment']]);

            if (isset($seen[$key]))
            {
                $out[$seen[$key]]['_count']++;
                continue;
            }

            $r['_count'] = 1;
            $seen[$key]  = count($out);
            $out[]       = $r;
        }

        return $out;
    }


    /*******************/
    /* gossip reaction */
    /*******************/

    // several identical rows in a row are the same action at different spots (five skeletons, two adds, ...) -
    // fold them into one line carrying a count, but never across a row that differs, so walkto ordering survives
    private static function collapseReactionRows(array $rows, string $targetCol) : array
    {
        $out = [];
        foreach ($rows as $r)
        {
            $prev = $out ? array_key_last($out) : null;
            if ($prev !== null &&
                $out[$prev]['ReactionType']   == $r['ReactionType']   && $out[$prev][$targetCol]     == $r[$targetCol] &&
                $out[$prev]['SayText']        === $r['SayText']       && $out[$prev]['DelayInMS']    == $r['DelayInMS'] &&
                $out[$prev]['FiresOnArrival'] == $r['FiresOnArrival'])
            {
                $out[$prev]['_count']++;
                continue;
            }

            $r['_count'] = 1;
            $out[]       = $r;
        }

        return $out;
    }

    // describes one reaction row of a gossip option or a quest turn-in
    // $targetCol is CreatureTemplateID on quest rows, TargetCreatureTemplateID on gossip rows.
    // $namedTarget false means "the page we are on IS the target", so it is named "this NPC" instead.
    private static function reactionLine(array $r, string $targetCol, int $subjectId, bool $namedTarget, int $count = 1) : ?array
    {
        $type     = (int)$r['ReactionType'];
        $targetId = (int)$r[$targetCol];
        $sayText  = (string)($r['SayText'] ?? '');
        $delayMS  = (int)($r['DelayInMS'] ?? 0);

        $isSelf = $targetId && $subjectId && $targetId == $subjectId;
        $target = $namedTarget ? self::npcLink($targetId) : Lang::npc('reactThisNpc');

        if ($count > 1)
            $target = sprintf(Lang::npc('reactCopies'), $count, $target);

        $extraNotes = [];

        switch ($type)
        {
            case self::REACT_SAY:
                $text = sprintf(Lang::npc('reactSays'), self::quoted($sayText));
                break;
            case self::REACT_EMOTE:
                $text = sprintf(Lang::npc('reactEmotes'), self::quoted($sayText));
                break;
            case self::REACT_YELL:
                $text = sprintf(Lang::npc('reactYells'), self::quoted($sayText));
                break;
            case self::REACT_ATTACKPLAYER:
                $text = $isSelf ? Lang::npc('reactAttacksSelf') : sprintf(Lang::npc('reactAttacks'), $target);
                break;
            case self::REACT_DESPAWN:
                $text = $isSelf && $namedTarget ? Lang::npc('reactDespawnsSelf') : sprintf(Lang::npc('reactDespawns'), $target);
                break;
            case self::REACT_SPAWN:
                $text = sprintf(Lang::npc('reactSpawns'), $target);
                break;
            case self::REACT_SPAWNUNIQUE:
                $text = sprintf(Lang::npc('reactSpawnsUnique'), $target);
                break;
            case self::REACT_KILLSPAWN:
                $text = sprintf(Lang::npc('reactArmsKillSpawn'), $target);
                break;
            case self::REACT_WALKTO:
                $text = Lang::npc('reactWalksTo');
                break;
            case self::REACT_WALKGRID:
                // the EQ script sends the NPC along a fixed patrol grid; the row's Position is its last node
                $text = Lang::npc('reactWalksPath');
                if ($nodes = self::pathLength((int)($r['PathListID'] ?? 0)))
                    $extraNotes[] = sprintf(Lang::npc('reactPathNodes'), $nodes);
                if (!empty($r['MovementIsRun']))
                    $extraNotes[] = Lang::npc('reactPathRuns');
                break;
            case self::REACT_SPAWNOBJECT:
                // places a gameobject (a chest, a click-to-loot prop) rather than a creature
                $text = sprintf(Lang::npc('reactSpawnsObject'), self::objectLink($r['GameObjectEntryID'] ?? 0));
                if ($life = (int)($r['GameObjectLifetimeSec'] ?? 0))
                    $extraNotes[] = sprintf(Lang::npc('reactObjectLifetime'), Util::formatTime($life * 1000, true));
                break;
            default:
                return null;
        }

        $notes = [];
        if ($_ = self::delayNote($delayMS))
            $notes[] = $_;

        return ['type' => $type, 'text' => $text, 'notes' => array_merge($notes, $extraNotes), 'steps' => []];
    }

    // a walkto/walkgrid row swallows every row behind it in its group - those fire when the creature arrives
    private static function nestArrivalRows(array $lines) : array
    {
        $out    = [];
        $walker = null;
        foreach ($lines as [$line, $firesOnArrival])
        {
            if (!$line)
                continue;

            if ($firesOnArrival)
            {
                if ($walker !== null)                       // nest under the walkto row that owns it
                {
                    $out[$walker]['steps'][] = $line;
                    continue;
                }

                // the walkto row itself was filtered out (it names a different creature) - say so on the line
                $line['notes'][] = Lang::npc('reactOnArrival');
            }

            $out[] = $line;
            if (self::isWalkType($line['type']))
                $walker = array_key_last($out);
        }

        return $out;
    }

    // the gossip menu this NPC offers, with what each option does
    public static function getGossipMenu(int $npcId) : array
    {
        if (!self::hasTable(self::TBL_GOSSIP))
            return [];

        $rows = DB::World()->select('SELECT * FROM '.self::TBL_GOSSIP.' WHERE `GossipCreatureTemplateID` = ?d ORDER BY `OptionID`, `ID`', $npcId);
        if (!$rows)
            return [];

        self::loadNPCNames(array_column($rows, 'TargetCreatureTemplateID'));

        $greeting = '';
        if ($textId = $rows[0]['NpcTextID'])
            if ($_ = DB::World()->selectCell('SELECT `text0_0` FROM npc_text WHERE `ID` = ?d', $textId))
                $greeting = $_;

        $options = [];
        foreach ($rows as $r)
            $options[$r['OptionID']]['rows'][] = $r;

        $out = [];
        foreach ($options as $optionId => $o)
        {
            $label = '';
            $lines = [];
            $reqs  = [];
            foreach (self::collapseReactionRows($o['rows'], 'TargetCreatureTemplateID') as $r)
            {
                if (!$label && $r['OptionText'] !== '')
                    $label = $r['OptionText'];

                // an option can be gated - it only shows for players who meet these
                if (!empty($r['RequiredQuestID']))
                    $reqs[sprintf(Lang::npc('reactReqQuest'), self::questLink($r['RequiredQuestID']))] = true;

                if (!empty($r['RequiredNearDistance']))
                    $reqs[sprintf(Lang::npc('reactReqNear'), round($r['RequiredNearDistance']))] = true;

                $lines[] = [self::reactionLine($r, 'TargetCreatureTemplateID', $npcId, true, $r['_count']), !!$r['FiresOnArrival']];
            }

            $out[] = array(
                'optionId' => $optionId,
                'text'     => $label,
                'reqs'     => array_keys($reqs),
                'lines'    => self::nestArrivalRows($lines)
            );
        }

        return ['greeting' => $greeting, 'options' => $out];
    }

    // gossip options on OTHER NPCs that spawn/despawn/attack with this NPC
    public static function getGossipSources(int $npcId) : array
    {
        if (!self::hasTable(self::TBL_GOSSIP))
            return [];

        $rows = DB::World()->select('SELECT * FROM '.self::TBL_GOSSIP.' WHERE `TargetCreatureTemplateID` = ?d AND `GossipCreatureTemplateID` <> ?d ORDER BY `GossipCreatureTemplateID`, `OptionID`, `ID`', $npcId, $npcId);
        if (!$rows)
            return [];

        self::loadNPCNames(array_column($rows, 'GossipCreatureTemplateID'));

        $out = [];
        foreach ($rows as $r)
        {
            if (!($line = self::reactionLine($r, 'TargetCreatureTemplateID', $npcId, false)))
                continue;

            // the option text lives on the row that carries it - not necessarily on this one
            $label = $r['OptionText'];
            if ($label === '')
                $label = (string)DB::World()->selectCell('SELECT `OptionText` FROM '.self::TBL_GOSSIP.' WHERE `GossipCreatureTemplateID` = ?d AND `OptionID` = ?d AND `OptionText` <> "" LIMIT 1', $r['GossipCreatureTemplateID'], $r['OptionID']);

            $out[] = array(
                'npcId'  => $r['GossipCreatureTemplateID'],
                'link'   => self::npcLink($r['GossipCreatureTemplateID']),
                'option' => $label,
                'line'   => $line,
                'onWalk' => !!$r['FiresOnArrival']
            );
        }

        return $out;
    }

    // stock gossip_menu_option entries (WoW NPCs) - EQ NPCs build their menus in the mod instead
    public static function getStockGossip(int $menuId) : array
    {
        if ($menuId <= 0)
            return [];

        $rows = DB::World()->select('SELECT `OptionID`, `OptionText`, `OptionBroadcastTextID`, `OptionType`, `BoxText`, `BoxMoney` FROM gossip_menu_option WHERE `MenuID` = ?d ORDER BY `OptionID`', $menuId);
        if (!$rows)
            return [];

        $texts = [];
        if ($btIds = array_filter(array_column($rows, 'OptionBroadcastTextID')))
            $texts = DB::World()->selectCol('SELECT `ID` AS ARRAY_KEY, `MaleText` FROM broadcast_text WHERE `ID` IN (?a)', $btIds);

        $greeting = '';
        if ($textId = DB::World()->selectCell('SELECT `TextID` FROM gossip_menu WHERE `MenuID` = ?d ORDER BY `TextID` LIMIT 1', $menuId))
            $greeting = (string)DB::World()->selectCell('SELECT `text0_0` FROM npc_text WHERE `ID` = ?d', $textId);

        $options = [];
        foreach ($rows as $r)
        {
            $label = $r['OptionText'];
            if ($label === '' || $label === null)
                $label = $texts[$r['OptionBroadcastTextID']] ?? '';

            if ($label === '')
                continue;

            $options[] = array(
                'optionId' => $r['OptionID'],
                'text'     => $label,
                'reqs'     => [],
                'lines'    => []
            );
        }

        return $options ? ['greeting' => $greeting, 'options' => $options] : [];
    }


    /******************/
    /* quest reaction */
    /******************/

    // what happens when this quest is turned in
    public static function getQuestReactions(int $questId) : array
    {
        if (!self::hasTable(self::TBL_QUEST))
            return [];

        $rows = DB::World()->select('SELECT * FROM '.self::TBL_QUEST.' WHERE `QuestTemplateID` = ?d ORDER BY `ID`', $questId);
        if (!$rows)
            return [];

        self::loadNPCNames(array_merge(array_column($rows, 'CreatureTemplateID'), array_column($rows, 'QuestgiverCreatureTemplateID')));

        $byGiver = [];
        foreach ($rows as $r)
            $byGiver[$r['QuestgiverCreatureTemplateID']][] = $r;

        $out = [];
        foreach ($byGiver as $giverId => $gRows)
        {
            $lines = [];
            foreach (self::collapseReactionRows($gRows, 'CreatureTemplateID') as $r)
                $lines[] = [self::reactionLine($r, 'CreatureTemplateID', $giverId, true, $r['_count']), !!$r['FiresOnArrival']];

            $out[] = array(
                'npcId' => $giverId,
                'link'  => self::npcLink($giverId),
                'lines' => self::nestArrivalRows($lines)
            );
        }

        return $out;
    }

    // quests whose turn-in spawns/despawns this NPC, and quests this NPC reacts to as the questgiver
    public static function getQuestSources(int $npcId) : array
    {
        if (!self::hasTable(self::TBL_QUEST))
            return [];

        $rows = DB::World()->select('SELECT * FROM '.self::TBL_QUEST.' WHERE `CreatureTemplateID` = ?d OR `QuestgiverCreatureTemplateID` = ?d ORDER BY `QuestTemplateID`, `ID`', $npcId, $npcId);
        if (!$rows)
            return [];

        self::loadNPCNames(array_merge(array_column($rows, 'CreatureTemplateID'), array_column($rows, 'QuestgiverCreatureTemplateID')));
        self::loadQuestNames(array_column($rows, 'QuestTemplateID'));

        $byQuest = [];
        foreach ($rows as $r)
            $byQuest[$r['QuestTemplateID']][] = $r;

        $out = [];
        foreach ($byQuest as $questId => $qRows)
        {
            $qRows   = self::collapseReactionRows($qRows, 'CreatureTemplateID');
            $lines   = [];
            $isGiver = false;
            foreach ($qRows as $r)
            {
                // only rows that mention this NPC are interesting here - a questgiver's own page still wants
                // the full picture though, since "spawns X, then despawns" only reads correctly as a whole
                $isGiver = $isGiver || $r['QuestgiverCreatureTemplateID'] == $npcId;
                $lines[] = [self::reactionLine($r, 'CreatureTemplateID', $r['QuestgiverCreatureTemplateID'], true, $r['_count']), !!$r['FiresOnArrival']];
            }

            $giverId = 0;
            if (!$isGiver)                                  // this NPC is only a target: drop unrelated rows of the same quest
            {
                $lines = [];
                foreach ($qRows as $r)
                {
                    if ($r['CreatureTemplateID'] != $npcId || $r['ReactionType'] == self::REACT_SPAWNOBJECT)
                        continue;

                    $giverId = $giverId ?: $r['QuestgiverCreatureTemplateID'];
                    $lines[] = [self::reactionLine($r, 'CreatureTemplateID', $r['QuestgiverCreatureTemplateID'], false, $r['_count']), !!$r['FiresOnArrival']];
                }
            }

            if (!($lines = self::nestArrivalRows($lines)))
                continue;

            $out[] = array(
                'questId' => $questId,
                'link'    => self::questLink($questId),
                'giver'   => $giverId,
                'giverLk' => $giverId ? self::npcLink($giverId) : '',
                'isGiver' => $isGiver,
                'lines'   => $lines
            );
        }

        return $out;
    }


    /****************/
    /* gameobjects  */
    /****************/

    // quests and gossip options that place this gameobject - the object side of a spawnobject reaction
    public static function getObjectSources(int $objectId) : array
    {
        $out = [];

        if (self::hasTable(self::TBL_QUEST) && self::hasColumn(self::TBL_QUEST, 'GameObjectEntryID'))
        {
            $rows = DB::World()->select('SELECT * FROM '.self::TBL_QUEST.' WHERE `GameObjectEntryID` = ?d AND `ReactionType` = ?d ORDER BY `QuestTemplateID`, `ID`', $objectId, self::REACT_SPAWNOBJECT);
            foreach ($rows as $r)
            {
                $line = self::reactionLine($r, 'CreatureTemplateID', 0, true);
                if (!$line)
                    continue;

                $out[] = array(
                    'kind'   => 'quest',
                    'head'   => sprintf(Lang::npc('reactQuestTurnIn'), self::npcLink($r['QuestgiverCreatureTemplateID'])).' &ndash; '.self::questLink($r['QuestTemplateID']),
                    'line'   => $line,
                    'onWalk' => !empty($r['FiresOnArrival'])
                );
            }
        }

        if (self::hasTable(self::TBL_GOSSIP) && self::hasColumn(self::TBL_GOSSIP, 'GameObjectEntryID'))
        {
            $rows = DB::World()->select('SELECT * FROM '.self::TBL_GOSSIP.' WHERE `GameObjectEntryID` = ?d AND `ReactionType` = ?d ORDER BY `GossipCreatureTemplateID`, `OptionID`, `ID`', $objectId, self::REACT_SPAWNOBJECT);
            foreach ($rows as $r)
            {
                $line = self::reactionLine($r, 'TargetCreatureTemplateID', 0, true);
                if (!$line)
                    continue;

                $label = $r['OptionText'];
                if ($label === '')
                    $label = (string)DB::World()->selectCell('SELECT `OptionText` FROM '.self::TBL_GOSSIP.' WHERE `GossipCreatureTemplateID` = ?d AND `OptionID` = ?d AND `OptionText` <> "" LIMIT 1', $r['GossipCreatureTemplateID'], $r['OptionID']);

                $out[] = array(
                    'kind'   => 'gossip',
                    'head'   => sprintf(Lang::npc('gossipVia'), self::npcLink($r['GossipCreatureTemplateID']), '<span class="q1">&laquo;'.Util::htmlEscape($label).'&raquo;</span>'),
                    'line'   => $line,
                    'onWalk' => !empty($r['FiresOnArrival'])
                );
            }
        }

        return $out;
    }

    // same idea as getReactionSpawnLocations, for gameobjects placed by a spawnobject reaction
    public static function getObjectSpawnLocations(int $objectId) : array
    {
        $points = [];
        $zones  = [];

        if (self::hasTable(self::TBL_QUEST) && self::hasColumn(self::TBL_QUEST, 'GameObjectEntryID'))
            foreach (DB::World()->select('SELECT * FROM '.self::TBL_QUEST.' WHERE `GameObjectEntryID` = ?d AND `ReactionType` = ?d', $objectId, self::REACT_SPAWNOBJECT) as $r)
                self::addReactionPlace($points, $zones, $r, $r['QuestgiverCreatureTemplateID'], sprintf(Lang::npc('reactPinQuest'), self::questName($r['QuestTemplateID'])), false, 0, self::questArea($r['QuestTemplateID']));

        if (self::hasTable(self::TBL_GOSSIP) && self::hasColumn(self::TBL_GOSSIP, 'GameObjectEntryID'))
            foreach (DB::World()->select('SELECT * FROM '.self::TBL_GOSSIP.' WHERE `GameObjectEntryID` = ?d AND `ReactionType` = ?d', $objectId, self::REACT_SPAWNOBJECT) as $r)
                self::addReactionPlace($points, $zones, $r, $r['GossipCreatureTemplateID'], sprintf(Lang::npc('reactPinGossip'), self::npcName($r['GossipCreatureTemplateID'])), true, 0);

        return ['points' => self::mergePoints($points), 'zones' => self::flattenZones($zones)];
    }


    /*******************/
    /* spawn locations */
    /*******************/

    // every map a creature template is actually placed on, with the zone it sits in there
    private static function creaturePlacements(int $creatureId) : array
    {
        if (!isset(self::$placements[$creatureId]))
            self::$placements[$creatureId] = DB::World()->select('SELECT DISTINCT `map`, `zoneId` FROM creature WHERE `id` = ?d', $creatureId) ?: [];

        return self::$placements[$creatureId];
    }

    // Where a creature can actually be found: its own placements, or - when it is script-only - the placements
    // of whatever spawns it. EQ chains these (Bzzazzt dies -> Bazzzazzt spawns, that dies -> Bizazzzt spawns),
    // so without walking the chain the tail of it resolves to nothing at all.
    private static function effectivePlacements(int $creatureId, array $seen = []) : array
    {
        if ($places = self::creaturePlacements($creatureId))
            return $places;

        if (isset($seen[$creatureId]) || count($seen) > 4)   // cycle guard + depth cap
            return [];

        $seen[$creatureId] = true;

        $triggers = [];
        if (self::hasTable(self::TBL_KILLSPAWN))
            $triggers = DB::World()->selectCol('SELECT DISTINCT `TriggerCreatureTemplateID` FROM '.self::TBL_KILLSPAWN.' WHERE `TargetCreatureTemplateID` = ?d AND `ActionType` = ?d', $creatureId, self::ACTION_SPAWN) ?: [];

        if (self::hasTable(self::TBL_GOSSIP))
            $triggers = array_merge($triggers, DB::World()->selectCol('SELECT DISTINCT `GossipCreatureTemplateID` FROM '.self::TBL_GOSSIP.' WHERE `TargetCreatureTemplateID` = ?d AND `ReactionType` IN (?a)', $creatureId, [self::REACT_SPAWN, self::REACT_SPAWNUNIQUE]) ?: []);

        if (self::hasTable(self::TBL_QUEST))
            $triggers = array_merge($triggers, DB::World()->selectCol('SELECT DISTINCT `QuestgiverCreatureTemplateID` FROM '.self::TBL_QUEST.' WHERE `CreatureTemplateID` = ?d AND `ReactionType` IN (?a)', $creatureId, [self::REACT_SPAWN, self::REACT_SPAWNUNIQUE, self::REACT_KILLSPAWN]) ?: []);

        $out = $known = [];
        foreach (array_unique($triggers) as $triggerId)
        {
            foreach (self::effectivePlacements($triggerId, $seen) as $pl)
            {
                $key = $pl['map'].'_'.$pl['zoneId'];
                if (isset($known[$key]))
                    continue;

                $known[$key] = true;
                $out[]       = $pl;
            }
        }

        return $out;
    }

    // An EQ zone map holds exactly one area, so a bare MapID is enough to name the zone. Continent maps
    // (and anything else holding several areas) stay unresolved rather than being guessed at.
    private static function areaForMap(int $mapId) : int
    {
        if (!$mapId)
            return 0;

        $areas = DB::Aowow()->selectCol('SELECT DISTINCT `areaId` FROM ?_worldmaparea WHERE `mapId` = ?d', $mapId);

        return count($areas) == 1 ? (int)$areas[0] : 0;
    }

    private static function mapForArea(int $areaId) : int
    {
        // lowest mapId is the base zone map; the converter emits extra rows for dungeon/raid variants
        return $areaId ? (int)DB::Aowow()->selectCell('SELECT `mapId` FROM ?_worldmaparea WHERE `areaId` = ?d ORDER BY `mapId` LIMIT 1', $areaId) : 0;
    }

    // EQ quests carry their zone in zoneOrSort (WoW quests use negative values for sort categories)
    private static function questArea(int $questId) : int
    {
        $area = (int)DB::Aowow()->selectCell('SELECT `zoneOrSort` FROM ?_quests WHERE `id` = ?d', $questId);

        return $area > 0 ? $area : 0;
    }

    private static function mapPoint(int $mapId, float $x, float $y, string $label) : ?array
    {
        if (!$x && !$y)
            return null;

        if (!($points = Game::worldPosToZonePos($mapId, $x, $y)))
            return null;

        $p = $points[0];                                    // ordered multifloor first, then nearest to map center

        return ['areaId' => $p['areaId'], 'floor' => $p['floor'], 'posX' => $p['posX'], 'posY' => $p['posY'], 'labels' => [$label]];
    }

    // a row behind a walkto resolves its npc-relative position against where the walker stopped
    private static function walkDestination(array $r, bool $isGossip) : ?array
    {
        $walkTypes = [self::REACT_WALKTO, self::REACT_WALKGRID];

        if ($isGossip)
            $row = DB::World()->selectRow('SELECT `PositionX`, `PositionY` FROM '.self::TBL_GOSSIP.' WHERE `GossipCreatureTemplateID` = ?d AND `OptionID` = ?d AND `ReactionType` IN (?a) AND `FiresOnArrival` = 0 AND `ID` < ?d ORDER BY `ID` DESC LIMIT 1',
                $r['GossipCreatureTemplateID'], $r['OptionID'], $walkTypes, $r['ID']);
        else
            $row = DB::World()->selectRow('SELECT `PositionX`, `PositionY` FROM '.self::TBL_QUEST.' WHERE `QuestTemplateID` = ?d AND `ReactionType` IN (?a) AND `FiresOnArrival` = 0 AND `ID` < ?d ORDER BY `ID` DESC LIMIT 1',
                $r['QuestTemplateID'], $walkTypes, $r['ID']);

        return $row ? [(float)$row['PositionX'], (float)$row['PositionY']] : null;
    }

    // resolves one gossip/quest reaction row into map pins, or into a bare zone when the spot is not fixed
    private static function addReactionPlace(array &$points, array &$zones, array $r, int $triggerId, string $label, bool $isGossip, int $npcId, int $fallbackAreaId = 0) : void
    {
        $places = self::effectivePlacements($triggerId, [$npcId => true]);

        // a script-only trigger that nothing else places either - fall back to the zone the quest belongs to
        if (!$places && $fallbackAreaId && ($fallbackMap = self::mapForArea($fallbackAreaId)))
            $places = [['map' => $fallbackMap, 'zoneId' => $fallbackAreaId]];

        // anchored on the player - lands wherever the turn-in or the conversation happened
        if ($r['UsePlayerX'] || $r['UsePlayerY'])
        {
            foreach ($places as $pl)
                $zones[$pl['zoneId']][$label] = true;

            return;
        }

        $x = (float)$r['PositionX'];
        $y = (float)$r['PositionY'];

        if ($r['UseNpcX'] || $r['UseNpcY'])
        {
            if ($r['FiresOnArrival'])                       // "at my feet" after the walk = the walkto destination
            {
                if ($walk = self::walkDestination($r, $isGossip))
                    [$x, $y] = $walk;
            }
            else                                            // "at my feet" right now = wherever the trigger stands
            {
                $own = DB::Aowow()->select('SELECT `areaId`, `floor`, `posX`, `posY` FROM ?_spawns WHERE `type` = ?d AND `typeId` = ?d AND `posX` > 0 AND `posY` > 0', Type::NPC, $triggerId);
                foreach ($own as $s)
                    $points[] = ['areaId' => $s['areaId'], 'floor' => $s['floor'], 'posX' => $s['posX'], 'posY' => $s['posY'], 'labels' => [$label]];

                if (!$own)                                  // the trigger is script-only too - fall back to its zones
                    foreach ($places as $pl)
                        $zones[$pl['zoneId']][$label] = true;

                return;
            }
        }

        if (!$x && !$y)
        {
            foreach ($places as $pl)
                $zones[$pl['zoneId']][$label] = true;

            return;
        }

        // the gossip and quest tables carry no map of their own - it is whichever map the trigger is placed on
        $pinned = false;
        foreach ($places as $pl)
        {
            if (!($p = self::mapPoint($pl['map'], $x, $y, $label)))
                continue;

            $points[] = $p;
            $pinned   = true;
        }

        // the coordinates fall outside every map rect we tried - still name the zone
        if (!$pinned)
            foreach ($places as $pl)
                $zones[$pl['zoneId']][$label] = true;
    }

    // Where a triggered spawn actually appears. These creatures usually have no `creature` row at all, so
    // aowow's spawn table knows nothing about them and their page would otherwise read "location unknown".
    // Fixed positions become map pins; a position taken from a corpse or from the player varies per trigger,
    // so those are only reported by zone.
    public static function getReactionSpawnLocations(int $npcId) : array
    {
        $points = [];
        $zones  = [];

        // kill / combat / evade / ooctimer spawns are the only ones carrying their own MapID
        if (self::hasTable(self::TBL_KILLSPAWN))
        {
            foreach (DB::World()->select('SELECT * FROM '.self::TBL_KILLSPAWN.' WHERE `TargetCreatureTemplateID` = ?d AND `ActionType` = ?d', $npcId, self::ACTION_SPAWN) as $r)
            {
                $label = sprintf(Lang::npc('reactPinKill'), self::npcName($r['TriggerCreatureTemplateID']));

                if ($r['SpawnAtCorpse'])                    // Position* are offsets from a corpse that can be anywhere
                {
                    $placed = false;
                    foreach (self::effectivePlacements($r['TriggerCreatureTemplateID'], [$npcId => true]) as $pl)
                    {
                        if ($r['MapID'] && $pl['map'] != $r['MapID'])
                            continue;

                        $zones[$pl['zoneId']][$label] = true;
                        $placed = true;
                    }

                    // nothing places the trigger either, but the row still names the map it happens on
                    if (!$placed && ($areaId = self::areaForMap($r['MapID'])))
                        $zones[$areaId][$label] = true;

                    continue;
                }

                if ($p = self::mapPoint($r['MapID'], $r['PositionX'], $r['PositionY'], $label))
                    $points[] = $p;
            }
        }

        if (self::hasTable(self::TBL_GOSSIP))
            foreach (DB::World()->select('SELECT * FROM '.self::TBL_GOSSIP.' WHERE `TargetCreatureTemplateID` = ?d AND `ReactionType` IN (?a)', $npcId, [self::REACT_SPAWN, self::REACT_SPAWNUNIQUE]) as $r)
                self::addReactionPlace($points, $zones, $r, $r['GossipCreatureTemplateID'], sprintf(Lang::npc('reactPinGossip'), self::npcName($r['GossipCreatureTemplateID'])), true, $npcId);

        if (self::hasTable(self::TBL_QUEST))
            foreach (DB::World()->select('SELECT * FROM '.self::TBL_QUEST.' WHERE `CreatureTemplateID` = ?d AND `ReactionType` IN (?a)', $npcId, [self::REACT_SPAWN, self::REACT_SPAWNUNIQUE, self::REACT_KILLSPAWN]) as $r)
                self::addReactionPlace($points, $zones, $r, $r['QuestgiverCreatureTemplateID'], sprintf(Lang::npc('reactPinQuest'), self::questName($r['QuestTemplateID'])), false, $npcId, self::questArea($r['QuestTemplateID']));

        return ['points' => self::mergePoints($points), 'zones' => self::flattenZones($zones)];
    }

    // the same spot is reached by several rows more often than not (repeat quests, N copies at one point)
    private static function mergePoints(array $points) : array
    {
        $merged = [];
        foreach ($points as $p)
        {
            $key = $p['areaId'].'_'.$p['floor'].'_'.round($p['posX'], 1).'_'.round($p['posY'], 1);
            if (isset($merged[$key]))
                $merged[$key]['labels'] = array_values(array_unique(array_merge($merged[$key]['labels'], $p['labels'])));
            else
                $merged[$key] = $p;
        }

        return array_values($merged);
    }

    private static function flattenZones(array $zones) : array
    {
        foreach ($zones as $areaId => $labels)
            $zones[$areaId] = array_keys($labels);

        return $zones;
    }
}

?>
