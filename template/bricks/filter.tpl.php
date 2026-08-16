            <script type="text/javascript">//<![CDATA[
<?php
// EQWOW begin - LANG.fidropdowns.zone / .faction in the static locale js only contain stock WoW
// entries; append the EverQuest zones and factions as an own optgroup ([null, label] starts a group)
$eqZones    = array_map(null, array_keys(Game::eqFilterZones()),    array_values(Game::eqFilterZones()));
$eqFactions = array_map(null, array_keys(Game::eqFilterFactions()), array_values(Game::eqFilterFactions()));
echo "                (function (z, f) {\n";
echo "                    if (z.length) { LANG.fidropdowns.zone.push([null, 'EverQuest']); LANG.fidropdowns.zone.push.apply(LANG.fidropdowns.zone, z); }\n";
echo "                    if (f.length) { LANG.fidropdowns.faction.push([null, 'EverQuest']); LANG.fidropdowns.faction.push.apply(LANG.fidropdowns.faction, f); }\n";
echo "                })(".Util::toJSON($eqZones).", ".Util::toJSON($eqFactions).");\n";
// EQWOW end
?>
<?php if (isset($this->region) && isset($this->realm)): ?>
                pr_setRegionRealm($WH.ge('fi').firstChild, '<?=$this->region; ?>', '<?=$this->realm; ?>');
                pr_onChangeRace();
<?php
endif;

if (!empty($fi['init'])):
    echo "                fi_init('".$fi['init']."');\n";
elseif (!empty($fi['type'])):
    echo "                var fi_type = '".$fi['type']."'\n";
endif;

if (!empty($fi['sc'])):
    echo '                fi_setCriteria('.Util::toJSON($fi['sc']['cr'] ?: []).', '.Util::toJSON($fi['sc']['crs'] ?: []).', '.Util::toJSON($fi['sc']['crv'] ?: []).");\n";
endif;
if (!empty($fi['sw'])):
    echo '                fi_setWeights('.Util::toJSON($fi['sw']).", 0, 1, 1);\n";
endif;
if (!empty($fi['ec'])):
    echo '                fi_extraCols = '.Util::toJSON($fi['ec']).";\n";
endif;
?>
            //]]></script>
