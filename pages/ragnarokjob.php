<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');


// ROWOW - one Ragnarok Online job: ?ragnarok-job=<rAthena job id> - its skill tree (own and inherited) and the equipment it may use
// menuId 104: Ragnarok Jobs  g_initPath()
//  tabId   0: Database       g_initHeader()
class RagnarokjobPage extends GenericPage
{
    protected $tpl           = 'list-page-generic';
    protected $path          = [0, 104];
    protected $tabId         = 0;
    protected $mode          = CACHE_TYPE_NONE;
    protected $lvTabs        = [];
    protected $extraHTML     = null;

    private   $jobId         = -1;
    private   $job           = null;

    public function __construct($pageCall, $id)
    {
        parent::__construct($pageCall, $id);

        $this->jobId = is_numeric($id) ? intVal($id) : -1;
        $this->job   = Ragnarok::jobs()[$this->jobId] ?? null;
        if (!$this->job)
            $this->notFound('Ragnarok Job', 'This Ragnarok job doesn\'t exist.');

        $this->name = $this->job['displayName'];
    }

    protected function generatePath()
    {
        $this->path[] = $this->jobId;
    }

    protected function generateTitle()
    {
        array_unshift($this->title, $this->name, 'Ragnarok Job');
    }

    // "Name=SM_BASH,Level=10;Name=SM_MAGNUM,Level=3;" -> "Bash 10, Magnum Break 3"
    private static function requiresText(string $req, array $skillNames) : string
    {
        $out = [];
        foreach (explode(';', $req) as $r)
            if (preg_match('/Name=(\w+),Level=(\d+)/', $r, $m))
                $out[] = ($skillNames[$m[1]] ?? $m[1]).' '.$m[2];

        return implode(', ', $out);
    }

    // one listview row per skill (its level 1 spell) of the jobs' own skill trees
    private function skillTab(array $jobIds, string $id, string $name, bool $withJob) : array
    {
        $tree = DB::Aowow()->select(
           'SELECT   js.*, s.`displayName`, s.`passive`, s.`spellId1`, s.`maxLevel` AS skillMaxLevel
            FROM     ?_ragnarok_job_skill js
            JOIN     ?_ragnarok_skill s ON s.`name` = js.`skillName`
            WHERE    js.`jobId` IN (?a)
            ORDER BY js.`sortOrder`',
            $jobIds
        );
        if (!$tree)
            return [];

        // the names as the WoW spells have them (the skill db's own differ a little: "Twohand Quicken")
        $skillNames = DB::Aowow()->selectCol('SELECT s.`name` AS ARRAY_KEY, IFNULL(sp.`name_loc0`, s.`displayName`) FROM ?_ragnarok_skill s LEFT JOIN ?_spell sp ON sp.`id` = s.`spellId1`');
        $bySpell    = [];
        $noSpell    = [];
        foreach ($tree as $t)
        {
            if ($t['spellId1'])
                $bySpell[$t['spellId1']] = $t;
            else
                $noSpell[] = $t['displayName'];
        }

        $data = [];
        if ($bySpell)
        {
            $spells = new SpellList(array(['s.id', array_keys($bySpell)], Cfg::get('SQL_LIMIT_NONE')));
            if (!$spells->error)
            {
                $this->extendGlobalData($spells->getJSGlobals(GLOBALINFO_SELF | GLOBALINFO_RELATED));
                $data = $spells->getListviewData();
                $order = 0;
                foreach ($bySpell as $spellId => $t)
                {
                    if (!isset($data[$spellId]))
                        continue;

                    $data[$spellId]['romaxlevel'] = (int)$t['maxLevel'];
                    $data[$spellId]['rotype']     = $t['passive'] ? 'Passive' : 'Active';
                    $data[$spellId]['roreq']      = self::requiresText($t['requires'], $skillNames).($t['jobLevel'] ? ($t['requires'] ? ', ' : '').'Job Lv '.$t['jobLevel'] : '');
                    $data[$spellId]['roorder']    = ++$order;
                    if ($withJob)
                        $data[$spellId]['rojob']  = Ragnarok::jobName((int)$t['jobId']);
                }
            }
        }

        if (!$data)
            return [];

        $extraCols = array(
            "\$Listview.funcBox.createSimpleCol('romaxlevel', 'Max Lv', '7%', 'romaxlevel')",
            "\$Listview.funcBox.createSimpleCol('rotype', 'Type', '8%', 'rotype')",
            "\$Listview.funcBox.createSimpleCol('roreq', 'Requires', '30%', 'roreq')"
        );
        if ($withJob)
            array_unshift($extraCols, "\$Listview.funcBox.createSimpleCol('rojob', 'Job', '12%', 'rojob')");

        $tab = array(
            'data'        => array_values($data),
            'id'          => $id,
            'name'        => $name,
            'extraCols'   => $extraCols,
            'hiddenCols'  => ['level', 'reagents', 'skill', 'classes', 'school', 'schools'],
            'sort'        => $withJob ? ['rojob', 'roorder'] : ['roorder']
        );

        if ($noSpell)
            $tab['note'] = 'Also in the tree (no WoW spell): '.implode(', ', array_unique($noSpell));

        return [SpellList::$brickFile, $tab];
    }

    protected function generateContent()
    {
        $j     = $this->job;
        $jobs  = Ragnarok::jobs();

        /* summary */
        $children = [];
        foreach ($jobs as $id => $o)
            if ($o['parentJobId'] == $this->jobId)
                $children[] = Ragnarok::jobLink($id);

        $inherit = array_filter(array_map('intval', explode(',', $j['inherit'])), fn($i) => isset($jobs[$i]) && $j['inherit'] !== '');

        $rows = [];
        $rows[] = ['Tier', Ragnarok::JOB_TIERS[$j['tier']]];
        if ($j['parentJobId'] >= 0)
            $rows[] = ['Changes from', Ragnarok::jobLink($j['parentJobId'])];
        if ($children)
            $rows[] = ['Changes to', implode(', ', $children)];
        if ($j['normalJobId'] >= 0)
            $rows[] = ['Baby of', Ragnarok::jobLink($j['normalJobId'])];
        if ($inherit)
            $rows[] = ['Inherits the skills of', implode(', ', array_map(fn($i) => Ragnarok::jobLink($i), $inherit))];
        if ($j['maxBaseLevel'])
            $rows[] = ['Max base level', $j['maxBaseLevel']];
        if ($j['maxJobLevel'])
            $rows[] = ['Max job level', $j['maxJobLevel']];
        if ($j['itemJobIdx'] >= 0)
        {
            $cls = $j['itemClass'] == Ragnarok::ITEM_CLASS_UPPER ? ' (transcendent)' : ($j['itemClass'] == Ragnarok::ITEM_CLASS_BABY ? ' (baby)' : '');
            $rows[] = ['Uses the items of', Ragnarok::ITEM_JOB_NAMES[$j['itemJobIdx']].$cls.' &ndash; <a href="?items&filter=ubro='.$this->jobId.'">all usable items</a>'];
        }
        if ($j['tier'] != 6)
            $rows[] = ['Skill spells', '<a href="?spells=-16.'.$this->jobId.'">all skill levels</a> (<a href="?spells=-16.'.$this->jobId.'.1">active</a>, <a href="?spells=-16.'.$this->jobId.'.2">passive</a>)'];
        $rows[] = ['rAthena', $j['name'].' (job id '.$this->jobId.')'];

        $h  = '<div class="pad"></div><table class="infobox" style="float: none; width: auto"><tbody>';
        foreach ($rows as [$k, $v])
            $h .= '<tr><th style="text-align: left; padding-right: 1em">'.$k.'</th><td>'.$v.'</td></tr>';
        $h .= '</tbody></table><div class="pad"></div>';
        $this->extraHTML = $h;

        /* tabs */
        if ($tab = $this->skillTab([$this->jobId], 'skills', 'Skills', false))
            $this->lvTabs[] = $tab;

        if ($inherit && ($tab = $this->skillTab($inherit, 'inherited-skills', 'Inherited skills', true)))
            $this->lvTabs[] = $tab;

        if ($j['itemJobIdx'] >= 0)
        {
            $items = new ItemList(array(
                ['ro.isEquip', 1],
                ['ro.isRenewal', 0],                        // not the ~4700 stat-less post-renewal looks
                [['ro.jobMask', 1 << $j['itemJobIdx'], '&'], 0, '!'],
                [['ro.classMask', $j['itemClass'], '&'], 0, '!'],
                Cfg::get('SQL_LIMIT_DEFAULT')
            ), ['calcTotal' => true]);

            if (!$items->error)
            {
                $this->extendGlobalData($items->getJSGlobals());
                $tab = array(
                    'data' => array_values($items->getListviewData()),
                    'id'   => 'items',
                    'name' => 'Usable equipment'
                );
                if ($items->getMatches() > Cfg::get('SQL_LIMIT_DEFAULT'))
                {
                    $tab['note']       = sprintf(Util::$filterResultString, '?items&filter=ubro='.$this->jobId);
                    $tab['_truncated'] = 1;
                }
                $this->lvTabs[] = [ItemList::$brickFile, $tab];
            }
        }
    }
}

?>
