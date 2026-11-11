<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');


// MergedWorlds - one EverQuest class: ?everquest-class=<EQ class id 1-14> - the WoW classes it belongs to (mod_everquest_classmap),
// the items it may use (?_everquest_item.eqClassMask, as the item filter's "Usable by" EverQuest rows) and its spells (?_everquest_spell_learn)
// menuId 105: EverQuest Classes  g_initPath()
//  tabId   0: Database           g_initHeader()
class EverquestclassPage extends GenericPage
{
    protected $tpl           = 'list-page-generic';
    protected $path          = [0, 105];
    protected $tabId         = 0;
    protected $mode          = CACHE_TYPE_NONE;
    protected $lvTabs        = [];
    protected $extraHTML     = null;

    private   $classId       = 0;

    public function __construct($pageCall, $id)
    {
        parent::__construct($pageCall, $id);

        $this->classId = is_numeric($id) ? intVal($id) : 0;
        if ($this->classId < 1 || $this->classId > 14 || !Lang::game('eqcl', $this->classId))
            $this->notFound(Lang::game('eqClassPage', 'class'), Lang::game('eqClassPage', 'notFound'));

        $this->name = Lang::game('eqcl', $this->classId);
    }

    // [primary => [eqClass => [wowClass, ..]], second => [..], eligible => [..]] from mod_everquest_classmap (empty without the mod tables)
    public static function classMap() : array
    {
        static $map = null;
        if ($map !== null)
            return $map;

        $map = ['primary' => [], 'second' => [], 'eligible' => []];
        if (!DB::World()->selectCell('SHOW TABLES LIKE "mod_everquest_classmap"'))
            return $map;

        foreach (DB::World()->select('SELECT `wowclass`, `eqclass_base`, `eqclass_defaultsecond`, `eqclass_eligiblesecond_mask` FROM mod_everquest_classmap ORDER BY `wowclass`') as $r)
        {
            $map['primary'][$r['eqclass_base']][]          = (int)$r['wowclass'];
            $map['second'][$r['eqclass_defaultsecond']][]  = (int)$r['wowclass'];
            for ($i = 1; $i <= 14; $i++)
                if ($r['eqclass_eligiblesecond_mask'] & (1 << ($i - 1)))
                    $map['eligible'][$i][] = (int)$r['wowclass'];
        }

        return $map;
    }

    public static function classLink(int $eqClass) : string
    {
        return '<a href="?everquest-class='.$eqClass.'">'.Util::htmlEscape(Lang::game('eqcl', $eqClass)).'</a>';
    }

    public static function wowClassLinks(array $wowClasses) : string
    {
        return implode(', ', array_map(fn($c) => '<a href="?class='.$c.'" class="c'.$c.'">'.Util::htmlEscape(Lang::game('cl', $c)).'</a>', $wowClasses));
    }

    protected function generatePath()
    {
        $this->path[] = $this->classId;
    }

    protected function generateTitle()
    {
        array_unshift($this->title, $this->name, Lang::game('eqClassPage', 'class'));
    }

    protected function generateContent()
    {
        $p   = Lang::game('eqClassPage');
        $map = self::classMap();
        $id  = $this->classId;

        /* summary */
        $rows   = [];
        $rows[] = [$p['id'], $id];
        foreach (['primary' => 'primaryOf', 'second' => 'secondOf', 'eligible' => 'eligibleOf'] as $k => $label)
            $rows[] = [$p[$label], self::wowClassLinks($map[$k][$id] ?? []) ?: '<span class="q0">'.$p['none'].'</span>'];
        $rows[] = [$p['items'], '<a href="?items&filter=ubr=1.0.'.$id.'">'.$p['allItems'].'</a>'];
        $rows[] = [$p['spells'], '<a href="?spells=-15.'.$id.'">'.$p['allSpells'].'</a>'];

        $h  = '<div class="pad"></div><table class="infobox" style="float: none; width: auto"><tbody>';
        foreach ($rows as [$k, $v])
            $h .= '<tr><th style="text-align: left; padding-right: 1em">'.$k.'</th><td>'.$v.'</td></tr>';
        $h .= '</tbody></table><div class="pad"></div>';
        $this->extraHTML = $h;

        /* usable items (as the item filter's "Usable by" EverQuest row) */
        $itemIds = DB::Aowow()->selectCol('SELECT `id` FROM ?_everquest_item WHERE `eqClassMask` & ?d', 1 << ($id - 1));
        if ($itemIds)
        {
            $conditions = [['i.id', $itemIds], Cfg::get('SQL_LIMIT_DEFAULT')];
            if (!User::isInGroup(U_GROUP_EMPLOYEE))
                $conditions[] = [['cuFlags', CUSTOM_EXCLUDE_FOR_LISTVIEW, '&'], 0];

            $items = new ItemList($conditions, ['calcTotal' => true]);
            if (!$items->error)
            {
                $this->extendGlobalData($items->getJSGlobals());
                $tab = array(
                    'data' => array_values($items->getListviewData()),
                    'id'   => 'items',
                    'name' => $p['items']
                );
                if ($items->getMatches() > Cfg::get('SQL_LIMIT_DEFAULT'))
                {
                    $tab['note']       = sprintf(Util::$filterResultString, '?items&filter=ubr=1.0.'.$id);
                    $tab['_truncated'] = 1;
                }
                $this->lvTabs[] = [ItemList::$brickFile, $tab];
            }
        }

        /* spells, with the class' learn level (from spell scrolls) as level, as ?spells=-15.<id> shows them */
        $eqLearn = DB::Aowow()->selectCol('SELECT `spellId` AS ARRAY_KEY, `learnLevel` FROM ?_everquest_spell_learn WHERE `eqClass` = ?d', $id);
        if ($eqLearn)
        {
            $spells = new SpellList(array(['s.id', array_keys($eqLearn)], Cfg::get('SQL_LIMIT_DEFAULT')), ['calcTotal' => true]);
            if (!$spells->error)
            {
                $this->extendGlobalData($spells->getJSGlobals(GLOBALINFO_SELF | GLOBALINFO_RELATED));
                $data = $spells->getListviewData();
                foreach ($data as $spellId => &$row)
                    if (isset($eqLearn[$spellId]))
                        $row['level'] = $eqLearn[$spellId];
                unset($row);

                $tab = array(
                    'data'        => array_values($data),
                    'id'          => 'spells',
                    'name'        => $p['spells'],
                    'visibleCols' => ['level', 'schools'],
                    'hiddenCols'  => ['skill']
                );
                if ($spells->getMatches() > Cfg::get('SQL_LIMIT_DEFAULT'))
                {
                    $tab['note']       = sprintf(Util::$filterResultString, '?spells=-15.'.$id);
                    $tab['_truncated'] = 1;
                }
                $this->lvTabs[] = [SpellList::$brickFile, $tab];
            }
        }
    }
}

?>
