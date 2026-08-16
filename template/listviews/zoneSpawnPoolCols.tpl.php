var _ = [
    {
        id: 'pool',
        after: 'location',
        name: '<?=Lang::npc('spawnPoolCol'); ?>',
        width: '8%',
        value: 'eqPool',
        compute: function(npc, td)
        {
            var s = $WH.ce('span');
            s.title = npc.eqPoolNote;
            s.style.borderBottom = '1px dotted';
            s.style.cursor = 'help';
            $WH.ae(s, $WH.ct('#' + npc.eqPool));
            $WH.ae(td, s);
        }
    },
    {
        id: 'chance',
        after: 'pool',
        name: '<?=Lang::npc('spawnPoolChanceCol'); ?>',
        width: '8%',
        value: 'eqChance',
        compute: function(npc, td)
        {
            $WH.ae(td, $WH.ct((npc.eqApprox ? '~' : '') + npc.eqChance + '%'));
        }
    }
];
