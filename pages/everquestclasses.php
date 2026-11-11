<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');


// MergedWorlds - the EverQuest classes: ?everquest-classes (EQ class ids 1-14 as mod-everquest numbers them, Lang::game('eqcl'))
// menuId 105: EverQuest Classes  g_initPath()
//  tabId   0: Database           g_initHeader()
class EverquestclassesPage extends GenericPage
{
    protected $tpl           = 'list-page-generic';
    protected $path          = [0, 105];
    protected $tabId         = 0;
    protected $mode          = CACHE_TYPE_NONE;
    protected $lvTabs        = [];
    protected $extraHTML     = null;

    public function __construct($pageCall, $pageParam)
    {
        parent::__construct($pageCall, $pageParam);

        $this->name = Lang::game('eqClasses');
    }

    protected function generateContent()
    {
        $classMap   = EverquestclassPage::classMap();
        $itemCounts = [];
        $spellCount = DB::Aowow()->selectCol('SELECT `eqClass` AS ARRAY_KEY, COUNT(*) FROM ?_everquest_spell_learn GROUP BY `eqClass`');
        // as many as the item list behind the link finds
        foreach (DB::Aowow()->selectCol('SELECT e.`eqClassMask` FROM ?_everquest_item e JOIN ?_items i ON i.`id` = e.`id` WHERE (i.`cuFlags` & ?d) = 0', CUSTOM_EXCLUDE_FOR_LISTVIEW) as $mask)
            for ($i = 1; $i <= 14; $i++)
                if ($mask & (1 << ($i - 1)))
                    $itemCounts[$i] = ($itemCounts[$i] ?? 0) + 1;

        $p  = Lang::game('eqClassPage');
        $h  = '<div class="pad"></div><p>'.$p['intro'].'</p><div class="pad"></div>';
        $h .= '<table class="listview-mode-default" style="width: 100%"><thead><tr>'.
              '<th style="text-align: left">'.$p['class'].'</th><th style="text-align: left">'.$p['primaryOf'].'</th>'.
              '<th style="text-align: left">'.$p['eligibleOf'].'</th><th>'.$p['items'].'</th><th>'.$p['spells'].'</th></tr></thead><tbody>';

        foreach (Lang::game('eqcl') as $id => $name)
        {
            if (!$name)
                continue;

            $h .= '<tr>'.
                  '<td style="text-align: left"><b>'.EverquestclassPage::classLink($id).'</b></td>'.
                  '<td style="text-align: left">'.EverquestclassPage::wowClassLinks($classMap['primary'][$id] ?? []).'</td>'.
                  '<td style="text-align: left">'.EverquestclassPage::wowClassLinks($classMap['eligible'][$id] ?? []).'</td>'.
                  '<td>'.(!empty($itemCounts[$id]) ? '<a href="?items&filter=ubr=1.0.'.$id.'">'.$itemCounts[$id].'</a>' : '').'</td>'.
                  '<td>'.(!empty($spellCount[$id]) ? '<a href="?spells=-15.'.$id.'">'.$spellCount[$id].'</a>' : '').'</td>'.
                  '</tr>';
        }
        $h .= '</tbody></table>';

        $this->extraHTML = $h;
    }

    protected function generateTitle()
    {
        array_unshift($this->title, $this->name);
    }

    protected function generatePath() {}
}

?>
