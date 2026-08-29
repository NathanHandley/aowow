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

                <h1><?=$this->name; ?></h1>

<?php
$this->brick('article');

if ($this->relBoss):
    echo "                <div>".sprintf(Lang::gameObject('npcLootPH'), $this->name, $this->relBoss[0], $this->relBoss[1])."</div>\n";
    echo '                <div class="pad"></div>';
endif;

if (!empty($this->map)):
    $this->brick('mapper');
// EQWOW - an object only placed by a reaction still has a zone to report
elseif (!$this->eqSpawnZones):
    echo Lang::gameObject('unkPosition');
endif;

// EQWOW begin - placements whose position varies, and the quests/gossip that place this object
if ($this->eqSpawnZones):
    echo '                <div>'.Lang::gameObject('reactSpawnDynamic').' ';
    echo Lang::concat($this->eqSpawnZones, true, function ($v, $k) { return '<a href="?zone='.$k.'">'.$v.'</a>'; });
    echo ".</div>\n";
endif;

if ($this->objectSrc):
?>
                <h3><?=Lang::gameObject('placedBy'); ?></h3>
<?php
    echo "                <ul>\n";
    foreach ($this->objectSrc as $src):
        $head = $src['head'];
        if ($src['onWalk'])
            $head .= ' <small class="q0">('.Lang::npc('reactOnArrival').')</small>';

        $notes = $src['line']['notes'] ? ' <small class="q0">('.implode('; ', $src['line']['notes']).')</small>' : '';

        echo '                    <li><div>'.$head."</div>\n";
        echo '                        <ul><li><div>'.$src['line']['text'].$notes."</div></li></ul>\n";
        echo "                    </li>\n";
    endforeach;
    echo "                </ul>\n";
endif;
// EQWOW end

$this->brick('book');

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
