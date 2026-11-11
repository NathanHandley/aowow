<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');


// ROWOW - Ragnarok Online jobs (the classes of Midgard): ?ragnarok-jobs
// menuId 104: Ragnarok Jobs  g_initPath()
//  tabId   0: Database       g_initHeader()
class RagnarokjobsPage extends GenericPage
{
    protected $tpl           = 'list-page-generic';
    protected $path          = [0, 104];
    protected $tabId         = 0;
    protected $mode          = CACHE_TYPE_NONE;
    protected $lvTabs        = [];
    protected $extraHTML     = null;

    public function __construct($pageCall, $pageParam)
    {
        parent::__construct($pageCall, $pageParam);

        $this->name = 'Ragnarok Jobs';
    }

    protected function generateContent()
    {
        $jobs = Ragnarok::jobs();
        if (!$jobs)
        {
            $this->extraHTML = '<div class="pad"></div><p>No Ragnarok data. Run <code>php aowow --sql=ragnarok</code>.</p>';
            return;
        }

        $skillCounts = DB::Aowow()->selectCol('SELECT `jobId` AS ARRAY_KEY, COUNT(*) FROM ?_ragnarok_job_skill GROUP BY `jobId`');
        $children    = [];
        foreach ($jobs as $id => $j)
            if ($j['parentJobId'] >= 0)
                $children[$j['parentJobId']][] = $id;

        $h  = '<div class="pad"></div>';
        $h .= '<p>The jobs of Ragnarok Online as ROWOW plays them in Midgard (rAthena pre-renewal). A job inherits the skills of the jobs it changes from; '.
              'transcendent and baby jobs use the items of their normal job, limited by the item\'s classes.</p>';

        $tier = null;
        foreach ($jobs as $id => $j)
        {
            if ($tier !== $j['tier'])
            {
                if ($tier !== null)
                    $h .= '</tbody></table>';

                $tier = $j['tier'];
                $h .= '<h2 class="clear">'.Ragnarok::JOB_TIERS[$tier].'</h2>';
                $h .= '<table class="listview-mode-default" style="width: 100%"><thead><tr>'.
                      '<th style="text-align: left">Job</th><th style="text-align: left">Changes from</th><th style="text-align: left">Changes to</th>'.
                      '<th>Max base level</th><th>Max job level</th><th>Skills</th><th>Equipment</th></tr></thead><tbody>';
            }

            $next = array_map(fn($c) => Ragnarok::jobLink($c), $children[$id] ?? []);
            $h .= '<tr>'.
                  '<td style="text-align: left"><b>'.Ragnarok::jobLink($id).'</b></td>'.
                  '<td style="text-align: left">'.($j['parentJobId'] >= 0 ? Ragnarok::jobLink($j['parentJobId']) : '').'</td>'.
                  '<td style="text-align: left">'.implode(', ', $next).'</td>'.
                  '<td>'.($j['maxBaseLevel'] ?: '').'</td>'.
                  '<td>'.($j['maxJobLevel'] ?: '').'</td>'.
                  '<td>'.(!empty($skillCounts[$id]) && $j['tier'] != 6 ? '<a href="?spells=-16.'.$id.'">'.$skillCounts[$id].'</a>' : (!empty($skillCounts[$id]) ? $skillCounts[$id] : '')).'</td>'.
                  '<td>'.($j['itemJobIdx'] >= 0 ? '<a href="?items&filter=ubr=3.0.'.$id.'">Usable items</a>' : '').'</td>'.
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
