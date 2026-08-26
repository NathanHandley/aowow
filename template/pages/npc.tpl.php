<?php $this->brick('header'); ?>

    <div class="main" id="main">
        <div class="main-precontents" id="main-precontents"></div>
        <div class="main-contents" id="main-contents">

<?php
    $this->brick('announcement');

    $this->brick('pageTemplate');

    $this->brick('infobox');
?>

            <div class="text">
<?php $this->brick('redButtons'); ?>

                <h1><?=$this->name.($this->subname ? ' &lt;'.$this->subname.'&gt;' : null); ?></h1>

<?php
    $this->brick('article');

if ($this->accessory):
    echo '                <div>'.Lang::npc('accessoryFor').' ';
    echo Lang::concat($this->accessory, true, function ($v, $k) { return '<a href="?npc='.$v[0].'">'.$v[1].'</a>'; });
    echo ".</div>\n";
endif;

if ($this->placeholder):
    echo '                <div>'.Lang::npc('difficultyPH', $this->placeholder)."</div>\n";
?>
                <div class="pad"></div>
<?php
elseif (!empty($this->map)):
    $this->brick('mapper');
else:
    echo '                '.Lang::npc('unkPosition')."\n";
endif;

if ($this->quotes[0]):
?>
                <h3><a class="disclosure-off" onclick="return g_disclose($WH.ge('quotes-generic'), this)"><?=Lang::npc('quotes').'&nbsp;('.$this->quotes[1]; ?>)</a></h3>
                <div id="quotes-generic" style="display: none"><ul>
<?php
    foreach ($this->quotes[0] as $group):
        if (count($group) > 1 && count($this->quotes[0]) > 1):
            echo "<ul>\n";
        endif;

        echo '<li>';

        $last = end($group);
        foreach ($group as $itr):
            echo sprintf(sprintf($itr['text'], $itr['prefix']), $this->name);
            echo ($itr == $last) ? null : "</li>\n<li>";
        endforeach;

        echo "</li>\n";

        if (count($group) > 1 && count($this->quotes[0]) > 1):
            echo "</ul>\n";
        endif;

    endforeach;
?>
                </ul></div>
<?php
endif;

if ($this->reputation):
?>
                <h3><?=Lang::main('gains'); ?></h3>
<?php
    echo Lang::npc('gainsDesc').Lang::main('colon');

    foreach ($this->reputation as $set):
        if (count($this->reputation) > 1):
            echo '<ul><li><span class="rep-difficulty">'.$set[0].'</span></li>';
        endif;

        echo '<ul>';

        foreach ($set[1] as $itr):
            if ($itr['qty'][1] && User::isInGroup(U_GROUP_EMPLOYEE))
                $qty = intVal($itr['qty'][0]) . sprintf(Util::$dfnString, Lang::faction('customRewRate'), ($itr['qty'][1] > 0 ? '+' : '').intVal($itr['qty'][1]));
            else
                $qty = intVal(array_sum($itr['qty']));

            echo '<li><div'.($itr['qty'][0] < 0 ? ' class="reputation-negative-amount"' : null).'><span>'.$qty.'</span> '.Lang::npc('repWith') .
                ' <a href="?faction='.$itr['id'].'">'.$itr['name'].'</a>'.($itr['cap'] && $itr['qty'][0] > 0 ? '&nbsp;('.sprintf(Lang::npc('stopsAt'), $itr['cap']).')' : null).'</div></li>';
        endforeach;

        echo '</ul>';

        if (count($this->reputation) > 1):
            echo '</ul>';
        endif;
    endforeach;
endif;

// EQWOW begin - spawn pools with spawn rates
if ($this->spawnPools):
?>
                <h3><?=Lang::npc('spawnPools'); ?></h3>
<?php
    echo '                '.Lang::npc('spawnPoolsDesc').Lang::main('colon')."\n";

    foreach ($this->spawnPools as $pool):
        $head = '<a href="?zone='.$pool['areaId'].'">'.$pool['zone'].'</a> &ndash; '.sprintf(Lang::npc('spawnPoolPoints'), $pool['points']);
        if ($pool['mode'] == 'weighted' && $pool['points'] > 1)
            $head .= ', '.Lang::npc('spawnPoolPerPoint');
        else if ($pool['mode'] != 'weighted')
            $head .= ', '.sprintf(Lang::npc('spawnPoolLimit'), $pool['limit']);
        if ($pool['mode'] == 'cycle')
            $head .= ' ('.sprintf(Lang::npc('spawnPoolCycle'), Util::formatTime($pool['cycleRespawn'] * 1000, true)).')';

        echo '                <ul><li><div>'.$head.'</div><ul>'."\n";
        foreach ($pool['members'] as $m):
            $pct  = ($m['approx'] ? '~' : null).round($m['chance'], 1).'%';
            $line = '<span>'.$pct.'</span> &ndash; <a href="?npc='.$m['npcId'].'">'.$m['name'].'</a>';
            if ($pool['mode'] == 'capped' && count($pool['members']) > 1)
                $line .= ' ('.sprintf(Lang::npc('spawnPoolShare'), $m['points'], $pool['points']).')';
            echo '                    <li><div>'.($m['self'] ? '<b>'.$line.'</b>' : $line).'</div></li>'."\n";
        endforeach;
        echo '                </ul></li></ul>'."\n";
    endforeach;
endif;
// EQWOW end

// EQWOW begin - triggered reactions (kill / gossip / quest spawns) and the gossip menu
$eqNotes = function (array $notes)
{
    return $notes ? ' <small class="q0">('.implode('; ', $notes).')</small>' : '';
};

// one action line, plus the steps a walkto defers to its arrival
$eqLine = function (array $line, string $indent) use ($eqNotes)
{
    $out = $indent.'<li><div>'.$line['text'].$eqNotes($line['notes'])."</div>\n";
    if (!empty($line['steps']))
    {
        $out .= $indent."    <ul>\n";
        foreach ($line['steps'] as $step)
            $out .= $indent.'        <li><div>'.$step['text'].$eqNotes($step['notes'])."</div></li>\n";
        $out .= $indent."    </ul>\n";
    }

    return $out.$indent."</li>\n";
};

// a trigger group ("When killed" -> actions), used for both directions of a kill spawn
$eqTriggerGroup = function (array $group, string $indent, ?string $prefix = null) use ($eqLine, $eqNotes)
{
    $head = $prefix === null ? $group['triggerText'] : sprintf($prefix, $group['triggerText']);
    $out  = $indent.'<li><div>'.$head.Lang::main('colon')."</div>\n".$indent."    <ul>\n";
    foreach ($group['entries'] as $entry)
    {
        if (!$entry['alts'])
        {
            $out .= $eqLine($entry, $indent.'        ');
            continue;
        }

        $out .= $indent.'        <li><div>'.$entry['text'].Lang::main('colon')."</div>\n".$indent."            <ul>\n";
        foreach ($entry['alts'] as $alt)
        {
            $out .= $indent.'                <li><div>'.round($alt['chance'], 1)."%</div>\n".$indent."                    <ul>\n";
            foreach ($alt['lines'] as $line)
                $out .= $indent.'                        <li><div>'.$line['text'].$eqNotes($line['notes'])."</div></li>\n";
            $out .= $indent."                    </ul>\n".$indent."                </li>\n";
        }
        $out .= $indent."            </ul>\n".$indent."        </li>\n";
    }

    return $out.$indent."    </ul>\n".$indent."</li>\n";
};

$eqTriggers    = $this->killSpawns['triggers']    ?? [];
$eqTriggeredBy = $this->killSpawns['triggeredBy'] ?? [];

// quest rows split by direction: this NPC as the questgiver reacting, vs. this NPC being spawned by someone else's turn-in
$eqGiverQuests = array_filter($this->questReact, function ($qr) { return  $qr['isGiver']; });
$eqQuestSrc    = array_filter($this->questReact, function ($qr) { return !$qr['isGiver']; });

if ($eqTriggers || $eqTriggeredBy || $this->gossipSrc || $eqQuestSrc):
?>
                <h3><?=Lang::npc('reactions'); ?></h3>
<?php
    if ($eqTriggers):
        echo '                '.Lang::npc('reactTriggers').Lang::main('colon')."\n                <ul>\n";
        foreach ($eqTriggers as $group)
            echo $eqTriggerGroup($group, '                    ');
        echo "                </ul>\n";
    endif;

    if ($eqTriggeredBy || $this->gossipSrc || $eqQuestSrc):
        echo '                '.Lang::npc('reactTriggeredBy').Lang::main('colon')."\n                <ul>\n";

        foreach ($eqTriggeredBy as $src):
            echo '                    <li><div>'.$src['link']."</div>\n                        <ul>\n";
            foreach ($src['groups'] as $group)
                echo $eqTriggerGroup($group, '                            ', '%s');
            echo "                        </ul>\n                    </li>\n";
        endforeach;

        foreach ($this->gossipSrc as $src):
            $head = sprintf(Lang::npc('gossipVia'), $src['link'], '<span class="q1">&laquo;'.Util::htmlEscape($src['option']).'&raquo;</span>');
            if ($src['onWalk'])
                $head .= ' <small class="q0">('.Lang::npc('reactOnArrival').')</small>';

            echo '                    <li><div>'.$head."</div>\n                        <ul>\n";
            echo $eqLine($src['line'], '                            ');
            echo "                        </ul>\n                    </li>\n";
        endforeach;

        foreach ($eqQuestSrc as $qr):
            echo '                    <li><div>'.sprintf(Lang::npc('reactQuestTurnIn'), $qr['giverLk']).' &ndash; '.$qr['link']."</div>\n                        <ul>\n";
            foreach ($qr['lines'] as $line)
                echo $eqLine($line, '                            ');
            echo "                        </ul>\n                    </li>\n";
        endforeach;

        echo "                </ul>\n";
    endif;
endif;

// quests this NPC itself hands out that make it react
if ($eqGiverQuests):
?>
                <h3><?=Lang::npc('reactQuests'); ?></h3>
<?php
    echo '                '.Lang::npc('reactQuestsGiver').Lang::main('colon')."\n                <ul>\n";
    foreach ($eqGiverQuests as $qr):
        echo '                    <li><div>'.$qr['link']."</div>\n                        <ul>\n";
        foreach ($qr['lines'] as $line)
            echo $eqLine($line, '                            ');
        echo "                        </ul>\n                    </li>\n";
    endforeach;
    echo "                </ul>\n";
endif;

if (!empty($this->gossip['options'])):
?>
                <h3><?=Lang::npc('gossip'); ?></h3>
<?php
    echo '                '.Lang::npc('gossipDesc').Lang::main('colon')."\n";
    if (!empty($this->gossip['greeting']))
        echo '                <div class="q1">&laquo;'.Util::htmlEscape($this->gossip['greeting'])."&raquo;</div>\n";

    echo "                <ul>\n";
    foreach ($this->gossip['options'] as $opt):
        echo '                    <li><div><span class="q4">'.Util::htmlEscape($opt['text'])."</span></div>\n";
        if ($opt['lines']):
            echo "                        <ul>\n";
            foreach ($opt['lines'] as $line)
                echo $eqLine($line, '                            ');
            echo "                        </ul>\n";
        endif;
        echo "                    </li>\n";
    endforeach;
    echo "                </ul>\n";
endif;
// EQWOW end

if (isset($this->smartAI)):
?>
    <div id="text-generic" class="left"></div>
    <script type="text/javascript">//<![CDATA[
        Markup.printHtml("<?=$this->smartAI; ?>", "text-generic", {
            allow: Markup.CLASS_ADMIN,
            dbpage: true
        });
    //]]></script>

    <div class="pad2"></div>
<?php
endif;
?>
                <h2 class="clear"><?=Lang::main('related'); ?></h2>
            </div>

<?php
$this->brick('lvTabs', ['relTabs' => true]);

$this->brick('contribute');
?>

            <div class="clear"></div>
        </div><!-- main-contents -->
    </div><!-- main -->

<?php $this->brick('footer'); ?>
