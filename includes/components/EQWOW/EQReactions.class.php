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

    // EQ_KILLSPAWN_ACTION_* in EverQuest.h
    public const ACTION_SPAWN         = 0;
    public const ACTION_DESPAWN       = 1;
    public const ACTION_RESPAWNSELF   = 2;
    public const ACTION_RESPAWNTARGET = 3;
    public const ACTION_SAY           = 4;
    public const ACTION_EMOTE         = 5;
    public const ACTION_YELL          = 6;
    public const ACTION_ATTACKPLAYER  = 7;

    // EQ_KILLSPAWN_TRIGGER_* in EverQuest.h
    public const TRIGGER_DEATH    = 0;
    public const TRIGGER_COMBAT   = 1;
    public const TRIGGER_EVADE    = 2;
    public const TRIGGER_OOCTIMER = 3;

    private const TBL_KILLSPAWN = 'mod_everquest_creature_kill_spawn';
    private const TBL_GOSSIP    = 'mod_everquest_gossip_reaction';
    private const TBL_QUEST     = 'mod_everquest_quest_reaction';

    private static $tables    = [];
    private static $npcNames  = [];
    private static $qstNames  = [];


    /**********/
    /* Shared */
    /**********/

    private static function hasTable(string $table) : bool
    {
        if (!isset(self::$tables[$table]))
            self::$tables[$table] = !!DB::World()->selectCell('SHOW TABLES LIKE "'.$table.'"');

        return self::$tables[$table];
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

    public static function npcLink($id, string $selfLabel = '') : string
    {
        $id = intVal($id);
        if ($id <= 0)
            return $selfLabel ?: Lang::npc('reactUnknownNpc');

        self::loadNPCNames([$id]);
        $name = self::$npcNames[$id] ?: (Util::ucFirst(Lang::game('npc')).' #'.$id);

        return '<a href="?npc='.$id.'">'.Util::htmlEscape($name).'</a>';
    }

    public static function questLink($id) : string
    {
        $id = intVal($id);
        if ($id <= 0)
            return Lang::npc('reactUnknownQuest');

        self::loadQuestNames([$id]);
        $name = self::$qstNames[$id] ?: (Util::ucFirst(Lang::game('quest')).' #'.$id);

        return '<a href="?quest='.$id.'">'.Util::htmlEscape($name).'</a>';
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
    private static function reactionLine(int $type, $targetId, string $sayText, int $delayMS, int $subjectId, bool $namedTarget, int $count = 1) : ?array
    {
        $isSelf = $targetId && $subjectId && $targetId == $subjectId;
        $target = $namedTarget ? self::npcLink($targetId) : Lang::npc('reactThisNpc');

        if ($count > 1)
            $target = sprintf(Lang::npc('reactCopies'), $count, $target);

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
            default:
                return null;
        }

        $notes = [];
        if ($_ = self::delayNote($delayMS))
            $notes[] = $_;

        return ['type' => $type, 'text' => $text, 'notes' => $notes, 'steps' => []];
    }

    // a walkto row swallows every row behind it in its group - those fire when the creature arrives
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
            if ($line['type'] == self::REACT_WALKTO)
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
            foreach (self::collapseReactionRows($o['rows'], 'TargetCreatureTemplateID') as $r)
            {
                if (!$label && $r['OptionText'] !== '')
                    $label = $r['OptionText'];

                $lines[] = [self::reactionLine($r['ReactionType'], $r['TargetCreatureTemplateID'], $r['SayText'], $r['DelayInMS'], $npcId, true, $r['_count']), !!$r['FiresOnArrival']];
            }

            $out[] = array(
                'optionId' => $optionId,
                'text'     => $label,
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
            if (!($line = self::reactionLine($r['ReactionType'], $r['TargetCreatureTemplateID'], $r['SayText'], $r['DelayInMS'], $npcId, false)))
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
                $lines[] = [self::reactionLine($r['ReactionType'], $r['CreatureTemplateID'], $r['SayText'], $r['DelayInMS'], $giverId, true, $r['_count']), !!$r['FiresOnArrival']];

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
                $lines[] = [self::reactionLine($r['ReactionType'], $r['CreatureTemplateID'], $r['SayText'], $r['DelayInMS'], $r['QuestgiverCreatureTemplateID'], true, $r['_count']), !!$r['FiresOnArrival']];
            }

            $giverId = 0;
            if (!$isGiver)                                  // this NPC is only a target: drop unrelated rows of the same quest
            {
                $lines = [];
                foreach ($qRows as $r)
                {
                    if ($r['CreatureTemplateID'] != $npcId)
                        continue;

                    $giverId = $giverId ?: $r['QuestgiverCreatureTemplateID'];
                    $lines[] = [self::reactionLine($r['ReactionType'], $r['CreatureTemplateID'], $r['SayText'], $r['DelayInMS'], $r['QuestgiverCreatureTemplateID'], false, $r['_count']), !!$r['FiresOnArrival']];
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
}

?>
