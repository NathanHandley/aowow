/*
    ROWOW - Ragnarok character builder (?rocalc)

    Data: g_ro_builder, built by setup/tools/filegen/robuilder.ss.php (php aowow --build=robuilder) from mod-rowow's tables.
    The rules are mod-rowow's (a port of pre-renewal rAthena); each formula names the mod-rowow function it follows
    (E:\MW\eqwow-live\azerothcore-wotlk\Source\modules\mod-rowow\src).

    Build link: ?rocalc#<base level>/<job>.<job level>-<job>.<job level>.../<str>.<agi>.<vit>.<int>.<dex>.<luk>/<skill>:<level>,.../<options>
    options: w<weapon index> (ASPD and ATK weapon), r (riding a Peco), n (Super Novice that never died)
*/

var
    rb_data          = null,
    rb_state         = null,
    rb_hash          = null,
    rb_skillIdsByKey = {},
    rb_changesByFrom = {},
    rb_treeIdsByJob  = {},
    rb_message       = '',
    rb_el            = {};

var RB_STATS  = ['STR', 'AGI', 'VIT', 'INT', 'DEX', 'LUK'];
var RB_RANGED = { Bow: 1, Musical: 1, Whip: 1, Revolver: 1, Rifle: 1, Gatling: 1, Shotgun: 1, Grenade: 1 }; // IsRangedWeaponType
var RB_GUNS   = { Revolver: 1, Rifle: 1, Gatling: 1, Shotgun: 1, Grenade: 1 };
var RB_PATHS  = [[0, 'Normal'], [4001, 'Transcendent (reborn)'], [4023, 'Baby (adopted)']];
var RB_FLAG_QUEST = 1, RB_FLAG_WEDDING = 2, RB_FLAG_SPIRIT = 4;

function rb_init() {
    if (typeof g_ro_builder == 'undefined') {
        $WH.ae($WH.ge('rb-itself'), $WH.ct('The Ragnarok character builder data is missing: run "php aowow --build=robuilder".'));
        return;
    }

    rb_data = g_ro_builder;

    for (var id in rb_data.s)
        rb_skillIdsByKey[rb_data.s[id].k] = parseInt(id);

    for (var i = 0; i < rb_data.x.length; ++i) {
        var c = rb_data.x[i];
        (rb_changesByFrom[c[0]] = rb_changesByFrom[c[0]] || []).push(c);
    }

    for (var jobId in rb_data.j) {
        var ids = {}, t = rb_data.j[jobId].t;
        for (var i = 0; i < t.length; ++i)
            ids[t[i][0]] = i;
        rb_treeIdsByJob[jobId] = ids;
    }

    rb_buildSkeleton();

    rb_state = rb_defaultState();
    if (!rb_readHash())
        rb_refresh();

    $WH.aE(window, 'hashchange', rb_readHash);
}

/* ---------- state ---------- */

function rb_defaultState() {
    return { path: [[0, 1]], base: 1, stats: [1, 1, 1, 1, 1, 1], skills: {}, w: 'Fist', r: 0, n: 0 };
}

function rb_job(jobId) {
    return rb_data.j[jobId];
}

function rb_finalJobId() {
    return rb_state.path[rb_state.path.length - 1][0];
}

function rb_finalJob() {
    return rb_job(rb_finalJobId());
}

function rb_jobLevel() {
    return rb_state.path[rb_state.path.length - 1][1];
}

function rb_change(from, to) {
    var c = rb_changesByFrom[from] || [];
    for (var i = 0; i < c.length; ++i)
        if (c[i][1] == to)
            return c[i];

    return null;
}

// the job level a stage must reach: the next job change's need (the job change NPCs), else 1
function rb_stageMinLevel(i) {
    var p = rb_state.path;
    if (i < p.length - 1) {
        var c = rb_change(p[i][0], p[i + 1][0]);
        return c ? c[2] : 1;
    }

    return 1;
}

// the base level the job changes need (Super Novice: 45)
function rb_minBase() {
    var p = rb_state.path, min = 1;
    for (var i = 0; i < p.length - 1; ++i) {
        var c = rb_change(p[i][0], p[i + 1][0]);
        if (c && c[3] > min)
            min = c[3];
    }

    return min;
}

// puts the state back inside the rules after the path or levels changed
function rb_normalize() {
    var
        s  = rb_state,
        p  = s.path,
        fj = rb_finalJob();

    for (var i = 0; i < p.length; ++i) {
        var job = rb_job(p[i][0]);
        p[i][1] = Math.max(rb_stageMinLevel(i), Math.min(job.mj, p[i][1] || 1));
    }

    s.base = Math.max(rb_minBase(), Math.min(fj.mb, s.base || 1));

    var maxParam = rb_maxParameter();
    for (var i = 0; i < 6; ++i)
        s.stats[i] = Math.max(1, Math.min(maxParam, s.stats[i] || 1));

    // skills: only the final job's tree, up to its max levels; the Novice's Basic Skill is 9 once the Novice changed job
    var tree = rb_finalJob().t, ids = rb_treeIdsByJob[rb_finalJobId()];
    for (var id in s.skills) {
        var skill = rb_data.s[id];
        if (ids[id] === undefined || !skill || (skill.f & (RB_FLAG_WEDDING | RB_FLAG_SPIRIT)) || s.skills[id] <= 0)
            delete s.skills[id];
        else
            s.skills[id] = Math.min(s.skills[id], tree[ids[id]][1]);
    }

    var basic = rb_skillIdsByKey['NV_BASIC'];
    if (p.length > 1 && basic && ids[basic] !== undefined)
        s.skills[basic] = tree[ids[basic]][1];

    if (!fj.a[s.w])
        s.w = 'Fist';
    if (!fj.a[s.w])
        for (var w in fj.a) {
            s.w = w;
            break;
        }

    if (!rb_level('KN_RIDING'))
        s.r = 0;
}

/* ---------- status points (ROWOW_Status.cpp) ---------- */

// PCMaxParameter: Babies max_baby_parameter, transcendent jobs max_trans_parameter, else max_parameter
function rb_maxParameter() {
    var fj = rb_finalJob(), c = rb_data.c;
    if (fj.y)
        return c.max_baby_parameter;

    return fj.u ? c.max_trans_parameter : c.max_parameter;
}

// GetStatusPointCost: raising a stat from this value by one, pre-renewal
function rb_statCost(low) {
    return 1 + Math.floor((low + 9) / 10);
}

// statpoint.yml's total at the base level, and transcendent_status_points for a transcendent job (PCResetState, PCJobChange)
function rb_statPointsTotal() {
    var p = rb_data.p, base = Math.min(rb_state.base, p.length - 1);
    return p[base] + (rb_finalJob().u ? Math.max(0, rb_data.c.transcendent_status_points) : 0);
}

function rb_statPointsSpent() {
    var spent = 0;
    for (var i = 0; i < 6; ++i)
        for (var v = 1; v < rb_state.stats[i]; ++v)
            spent += rb_statCost(v);

    return spent;
}

/* ---------- skill points (ROWOW_Skills.cpp) ---------- */

// a skill point with each job level past 1 (PCCheckJobLevelUp); job changes keep the skill points (PCJobChange)
function rb_skillPointsTotal() {
    var total = 0, p = rb_state.path;
    for (var i = 0; i < p.length; ++i)
        total += p[i][1] - 1;

    return total;
}

// PCCalcSkillPoint: quest skills (unless quest_skill_learn) and wedding skills cost no points
function rb_isCounted(skillId) {
    var f = rb_data.s[skillId].f;
    if (f & RB_FLAG_WEDDING)
        return false;
    if ((f & RB_FLAG_QUEST) && !rb_data.c.quest_skill_learn)
        return false;

    return true;
}

function rb_skillPointsSpent(skills) {
    var spent = 0;
    for (var id in skills)
        if (rb_isCounted(id))
            spent += skills[id];

    return spent;
}

// the stage of the path whose tree first has the skill: 0 Novice, 1 first job, 2 second job
function rb_tier(skillId) {
    var p = rb_state.path;
    for (var i = 0; i < p.length; ++i)
        if (rb_treeIdsByJob[p[i][0]][skillId] !== undefined)
            return i;

    return p.length - 1;
}

// GetSkillUpLimitPoints: the points used before the first job's skills open (the Novice's max job level less 1), and before a second
// job's (those and the first job's: the job level it changed at less 1).  null for none
function rb_limits() {
    var
        p  = rb_state.path,
        fj = rb_finalJob(),
        r  = [null, null];

    if (!rb_data.c.player_skillup_limit)
        return r;

    r[0] = rb_job(0).mj - 1;
    if (fj.f > 0 && p.length >= 3)
        r[1] = r[0] + p[p.length - 2][1] - 1;

    return r;
}

// PCCalcSkillTreeNormalizeJob: the first points go to the Novice's tree, the next to the first job's: a set of skill levels is reachable
// when its earlier jobs' skills hold at least the points the later trees waited for
function rb_tierCheck(skills) {
    var
        lim   = rb_limits(),
        spent = rb_skillPointsSpent(skills),
        sum   = [0, 0, 0];

    for (var id in skills)
        if (rb_isCounted(id))
            sum[Math.min(2, rb_tier(id))] += skills[id];

    if (lim[0] !== null && sum[0] < Math.min(lim[0], spent))
        return 'Spend the Novice\'s ' + lim[0] + ' skill points first.';
    if (lim[1] !== null && sum[0] + sum[1] < Math.min(lim[1], spent))
        return 'Spend the ' + lim[1] + ' skill points of the Novice and ' + rb_job(rb_state.path[1][0]).n + ' first.';

    return null;
}

function rb_treeEntry(skillId) {
    var i = rb_treeIdsByJob[rb_finalJobId()][skillId];
    return i === undefined ? null : rb_finalJob().t[i];
}

function rb_level(key) {
    var id = rb_skillIdsByKey[key];
    return id && rb_treeEntry(id) ? (rb_state.skills[id] || 0) : 0;
}

function rb_has(key) {
    var id = rb_skillIdsByKey[key];
    return !!(id && rb_treeEntry(id));
}

function rb_skillName(skillId) {
    return rb_data.s[skillId] ? rb_data.s[skillId].n : '#' + skillId;
}

// PCSkillUp with PCCalcSkillTree's needs (prerequisite levels and job level)
function rb_canAdd(skillId) {
    var
        e     = rb_treeEntry(skillId),
        s     = rb_state,
        level = s.skills[skillId] || 0;

    if (!e)
        return 'Not in this job\'s skill tree.';
    if (level >= e[1])
        return 'Already at its maximum level.';
    if (rb_isCounted(skillId) && rb_skillPointsSpent(s.skills) >= rb_skillPointsTotal())
        return 'No skill points left.';
    if (e[2] && rb_jobLevel() < e[2])
        return 'Needs job level ' + e[2] + '.';

    var missing = [];
    for (var i = 0; i < e[3].length; ++i)
        if ((s.skills[e[3][i][0]] || 0) < e[3][i][1])
            missing.push(rb_skillName(e[3][i][0]) + ' ' + e[3][i][1]);

    if (missing.length)
        return 'Needs ' + missing.join(', ') + '.';

    if (rb_isCounted(skillId)) {
        var next = rb_copy(s.skills);
        next[skillId] = level + 1;
        var err = rb_tierCheck(next);
        if (err)
            return err;
    }

    return null;
}

function rb_canRemove(skillId) {
    var
        s     = rb_state,
        level = s.skills[skillId] || 0;

    if (!level)
        return 'Not learned.';
    if (skillId == rb_skillIdsByKey['NV_BASIC'] && s.path.length > 1)
        return 'Basic Skill 9 is needed to change job.';

    var tree = rb_finalJob().t, needers = [];
    for (var i = 0; i < tree.length; ++i) {
        var e = tree[i];
        if (!s.skills[e[0]])
            continue;

        for (var j = 0; j < e[3].length; ++j)
            if (e[3][j][0] == skillId && e[3][j][1] > level - 1)
                needers.push(rb_skillName(e[0]));
    }

    if (needers.length)
        return 'Needed by ' + needers.join(', ') + '.';

    if (rb_isCounted(skillId)) {
        var next = rb_copy(s.skills);
        if (level > 1)
            next[skillId] = level - 1;
        else
            delete next[skillId];

        if (rb_tierCheck(next) && !rb_tierCheck(s.skills))
            return 'The later jobs\' skills need these points to stay spent.';
    }

    return null;
}

/* ---------- derived status (ROWOW_Status.cpp CalculateIdentityStatus, no gear and no statuses) ---------- */

function rb_status() {
    var
        s     = rb_state,
        c     = rb_data.c,
        fj    = rb_finalJob(),
        jl    = rb_jobLevel(),
        base  = s.base,
        st    = [],
        bonus = [0, 0, 0, 0, 0, 0],
        skill = [0, 0, 0, 0, 0, 0],
        r     = {};

    // job bonuses (BonusStatsByJobLevel), the passive skills, then the base stats
    if (fj.b.length && jl >= 1 && jl <= fj.b.length)
        bonus = fj.b[jl - 1];

    if (rb_level('BS_HILTBINDING') > 0)
        skill[0]++;
    if (rb_level('SA_DRAGONOLOGY') > 0)
        skill[3] += Math.floor((rb_level('SA_DRAGONOLOGY') + 1) / 2);
    if (rb_level('AC_OWL') > 0)
        skill[4] += rb_level('AC_OWL');
    if ((rb_finalJobId() == 23 || rb_finalJobId() == 4045) && jl >= 70 && s.n)
        for (var i = 0; i < 6; ++i)
            skill[i] += 10;

    for (var i = 0; i < 6; ++i)
        st[i] = Math.max(0, bonus[i] + skill[i] + s.stats[i]);

    r.bonus = [];
    for (var i = 0; i < 6; ++i)
        r.bonus[i] = bonus[i] + skill[i];
    r.stats = st;

    var STR = st[0], AGI = st[1], VIT = st[2], INT = st[3], DEX = st[4], LUK = st[5];

    // StatusCalcMaxHPPC
    var hp = 1;
    if (fj.hp.length) {
        var dmax = fj.hp[Math.min(Math.max(base, 1) - 1, fj.hp.length - 1)];
        if (VIT > 0)
            dmax *= (1.0 + VIT * 0.01);
        if (fj.u)
            dmax *= 1.25;
        dmax += rb_level('CR_TRUST') * 200;
        hp = Math.max(1, Math.floor(dmax));
    }
    if (c.hp_rate != 100)
        hp = Math.floor(c.hp_rate * (hp / 100));
    hp = Math.max(1, Math.min(hp, base < 100 ? c.max_hp_lv99 : (base < 151 ? c.max_hp_lv150 : c.max_hp)));
    r.hp = hp;

    // StatusCalcMaxSPPC
    var sp = 1;
    if (fj.sp.length) {
        var dmax = fj.sp[Math.min(Math.max(base, 1) - 1, fj.sp.length - 1)];
        if (INT > 0)
            dmax *= (1.0 + INT * 0.01);
        if (fj.u)
            dmax *= 1.25;
        dmax += rb_level('SL_KAINA') * 30;
        var rate = rb_level('HP_MEDITATIO') + 2 * rb_level('HW_SOULDRAIN');
        dmax += rb_trunc(dmax * Math.max(-100, rate) / 100);
        sp = Math.max(1, Math.floor(dmax));
    }
    if (c.sp_rate != 100)
        sp = Math.floor(c.sp_rate * (sp / 100));
    r.sp = Math.max(1, Math.min(sp, c.max_sp));

    // StatusBaseAtk (enable_baseatk has players): ranged weapons swap STR and DEX; Hilt Binding +4
    r.atk = 0;
    if (c.enable_baseatk & 1) {
        var str = STR, dex = DEX;
        if (RB_RANGED[s.w]) {
            str = DEX;
            dex = STR;
        }
        var dstr = Math.floor(str / 10);
        r.atk = Math.max(0, Math.min(65535, str + dstr * dstr + Math.floor(dex / 5) + Math.floor(LUK / 5)));
    }
    if (rb_level('BS_HILTBINDING') > 0)
        r.atk += 4;

    // StatusCalcMisc
    r.matkMin = INT + Math.floor(INT / 7) * Math.floor(INT / 7);
    r.matkMax = INT + Math.floor(INT / 5) * Math.floor(INT / 5);
    r.hit     = Math.max(1, base + DEX);
    r.flee    = Math.max(1, base + AGI);
    r.def2    = VIT;
    r.mdef2   = INT;                                        // the window shows MDef2 less VIT / 2
    r.crit    = (c.enable_critical & 1) ? Math.floor(Math.max(1, 10 + rb_trunc(LUK * 10 / 3)) / 10) : 0;
    r.flee2   = (c.enable_perfect_flee & 1) ? Math.floor((LUK + 10) / 10) : 0;

    // passive skills: HIT (Weapon Research, Vulture's Eye, Single Action and Snake Eye with guns), FLEE (Improve Dodge, more for a
    // second job of the Thief line; Dodge)
    r.hit += rb_level('BS_WEAPONRESEARCH') * 2 + rb_level('AC_VULTURE');
    if (RB_GUNS[s.w])
        r.hit += 2 * rb_level('GS_SINGLEACTION') + rb_level('GS_SNAKEEYE');
    r.flee += rb_level('TF_MISS') * (fj.f == 6 ? 4 : 3);
    r.flee += Math.floor(rb_level('MO_DODGE') * 3 / 2);

    // StatusBaseAmotionPC, the passive ASPD rates, then StatusCalcBLMain's cap
    r.aspd = null;
    if (fj.a[s.w]) {
        var amotion = fj.a[s.w];
        amotion -= rb_trunc(amotion * (4 * AGI + DEX) / 1000);
        var aspdRate = 1000;
        if (s.w == 'Book')
            aspdRate -= 5 * rb_level('SA_ADVANCEDBOOK');
        if (jl >= fj.mj)
            aspdRate -= 30 * rb_level('SG_DEVIL');
        if (RB_GUNS[s.w])
            aspdRate -= Math.floor((rb_level('GS_SINGLEACTION') + 1) / 2) * 10 * (rb_level('GS_SINGLEACTION') > 0 ? 1 : 0);
        if (s.r)
            aspdRate += 500 - 100 * rb_level('KN_CAVALIERMASTERY');
        amotion = rb_trunc(amotion * Math.max(0, aspdRate) / 1000);
        amotion = Math.max(2000 - c.max_aspd * 10, Math.min(4000, amotion));
        r.aspd = Math.floor((2000 - amotion) / 10);
    }

    // StatusCalcRegen (per natural regen tick), with Meditatio's SP
    r.hpRegen = Math.floor(VIT / 5) + Math.max(1, Math.floor(r.hp / 200));
    var spRegen = 1 + Math.floor(INT / 6) + Math.floor(r.sp / 100);
    if (INT >= 120)
        spRegen += Math.floor((INT - 120) / 2) + 4;
    if (rb_level('HP_MEDITATIO') > 0)
        spRegen = Math.max(1, rb_trunc(spRegen * (100 + 3 * rb_level('HP_MEDITATIO')) / 100));
    r.spRegen = spRegen;

    // the cast time scale (ROWOW_SkillEngine.cpp: castrate_dex_scale, casting_rate)
    var scale = c.castrate_dex_scale - DEX;
    r.cast = scale > 0 ? Math.round(1000 * scale / c.castrate_dex_scale * (c.casting_rate || 100) / 100) / 10 : 0;

    return r;
}

function rb_trunc(x) {
    return x < 0 ? Math.ceil(x) : Math.floor(x);
}

function rb_copy(o) {
    var c = {};
    for (var k in o)
        c[k] = o[k];

    return c;
}

/* ---------- link ---------- */

function rb_getHash() {
    var s = rb_state, p = [], sk = [], ids = [];

    for (var i = 0; i < s.path.length; ++i)
        p.push(s.path[i][0] + '.' + s.path[i][1]);

    for (var id in s.skills)
        ids.push(parseInt(id));
    ids.sort(function(a, b) { return a - b; });
    for (var i = 0; i < ids.length; ++i)
        sk.push(ids[i] + ':' + s.skills[ids[i]]);

    var opt = '';
    for (var i = 0; i < rb_data.w.length; ++i)
        if (rb_data.w[i][0] == s.w && i > 0)
            opt += 'w' + i;
    if (s.r)
        opt += 'r';
    if (s.n)
        opt += 'n';

    return s.base + '/' + p.join('-') + '/' + s.stats.join('.') + '/' + sk.join(',') + (opt ? '/' + opt : '');
}

function rb_readHash() {
    var hash = location.hash ? decodeURIComponent(location.hash.substr(1)) : '';
    if (!hash || hash == rb_hash)
        return false;

    var
        parts = hash.split('/'),
        s     = rb_defaultState(),
        path  = [];

    s.base = parseInt(parts[0]) || 1;

    var stages = (parts[1] || '').split('-');
    for (var i = 0; i < stages.length; ++i) {
        var _ = stages[i].split('.'), jobId = parseInt(_[0]), lvl = parseInt(_[1]) || 1;
        if (!rb_data.j[jobId])
            break;
        if (i == 0 && !rb_isStart(jobId))
            break;
        if (i > 0 && !rb_change(path[i - 1][0], jobId))
            break;
        path.push([jobId, lvl]);
    }
    if (path.length)
        s.path = path;

    var stats = (parts[2] || '').split('.');
    for (var i = 0; i < 6; ++i)
        s.stats[i] = parseInt(stats[i]) || 1;

    var skills = (parts[3] || '').split(',');
    for (var i = 0; i < skills.length; ++i) {
        var _ = skills[i].split(':'), id = parseInt(_[0]), lvl = parseInt(_[1]);
        if (rb_data.s[id] && lvl > 0)
            s.skills[id] = lvl;
    }

    var opt = parts[4] || '', m;
    if (m = opt.match(/w(\d+)/))
        if (rb_data.w[m[1]])
            s.w = rb_data.w[m[1]][0];
    s.r = opt.indexOf('r') != -1 ? 1 : 0;
    s.n = opt.indexOf('n') != -1 ? 1 : 0;

    rb_state = s;
    rb_refresh();

    return true;
}

function rb_isStart(jobId) {
    for (var i = 0; i < rb_data.r.length; ++i)
        if (rb_data.r[i] == jobId)
            return true;

    return false;
}

/* ---------- page ---------- */

function rb_buildSkeleton() {
    var
        root = $WH.ge('rb-itself'),
        _;

    root.className = 'rb';

    _ = $WH.ce('div');
    _.className = 'rb-intro';
    _.innerHTML = 'Plan a Ragnarok Online character as MergedWorlds\' Midgard plays it (pre-renewal rules): pick the job path and the job level each job ' +
        'reached, the base level, then spend the status and skill points. The link at the bottom keeps the build.';
    $WH.ae(root, _);

    var left = $WH.ce('div');
    left.className = 'rb-left';
    $WH.ae(root, left);

    var right = $WH.ce('div');
    right.className = 'rb-right';
    $WH.ae(root, right);

    rb_el.path    = rb_box(left, 'Job path');
    rb_el.stats   = rb_box(left, 'Stats');
    rb_el.derived = rb_box(left, 'Status (no gear)');
    rb_el.skills  = rb_box(right, 'Skills');

    _ = $WH.ce('div');
    _.className = 'clear';
    $WH.ae(root, _);

    var bottom = $WH.ce('div');
    bottom.className = 'rb-bottom';
    $WH.ae(root, bottom);

    $WH.ae(bottom, $WH.ct('Link to this build: '));
    rb_el.link = $WH.ce('a');
    rb_el.link.className = 'rb-link';
    $WH.ae(bottom, rb_el.link);

    $WH.ae(bottom, $WH.ct(' | '));
    $WH.ae(bottom, rb_action('Reset stats', function() {
        rb_state.stats = [1, 1, 1, 1, 1, 1];
        rb_refresh();
    }));
    $WH.ae(bottom, $WH.ct(' | '));
    $WH.ae(bottom, rb_action('Reset skills', function() {
        rb_state.skills = {};
        rb_refresh();
    }));
    $WH.ae(bottom, $WH.ct(' | '));
    $WH.ae(bottom, rb_action('Start over', function() {
        rb_state = rb_defaultState();
        rb_refresh();
    }));
}

function rb_box(parent, title) {
    var
        box = $WH.ce('div'),
        h   = $WH.ce('h3'),
        div = $WH.ce('div');

    box.className = 'rb-box';
    $WH.ae(h, $WH.ct(title));
    $WH.ae(box, h);
    $WH.ae(box, div);
    $WH.ae(parent, box);

    return div;
}

function rb_action(text, fn, title) {
    var a = $WH.ce('a');
    a.href = 'javascript:;';
    a.onclick = fn;
    if (title)
        a.title = title;
    $WH.ae(a, $WH.ct(text));

    return a;
}

function rb_select(options, value, onchange) {
    var sel = $WH.ce('select');
    for (var i = 0; i < options.length; ++i) {
        var o = $WH.ce('option');
        o.value = options[i][0];
        $WH.ae(o, $WH.ct(options[i][1]));
        if (options[i][0] == value)
            o.selected = true;
        $WH.ae(sel, o);
    }
    sel.onchange = function() { onchange(this.value); };

    return sel;
}

function rb_levelOptions(min, max) {
    var o = [];
    for (var i = min; i <= max; ++i)
        o.push([i, i]);

    return o;
}

function rb_refresh() {
    rb_normalize();

    rb_drawPath();
    rb_drawStats();
    rb_drawDerived();
    rb_drawSkills();

    rb_hash = rb_getHash();
    location.replace('#' + rb_hash);
    rb_el.link.href = location.href;
    $WH.ee(rb_el.link);
    $WH.ae(rb_el.link, $WH.ct(location.href));

    var fj = rb_finalJob();
    document.title = fj.n + ' ' + rb_state.base + '/' + rb_jobLevel() + ' - ' + document.title.replace(/^.* \d+\/\d+ - /, '');

    PageTemplate.set({ breadcrumb: [1, 9] });
}

function rb_drawPath() {
    var
        div = rb_el.path,
        p   = rb_state.path,
        t   = $WH.ce('table'),
        tr, td;

    $WH.ee(div);
    t.className = 'rb-path';

    // path type: the first job of the path
    tr = $WH.ce('tr');
    td = $WH.ce('th');
    $WH.ae(td, $WH.ct('Path'));
    $WH.ae(tr, td);
    td = $WH.ce('td');
    td.colSpan = 2;
    $WH.ae(td, rb_select(RB_PATHS, p[0][0], function(v) {
        rb_state.path = [[parseInt(v), 1]];
        rb_state.skills = {};
        rb_refresh();
    }));
    $WH.ae(tr, td);
    $WH.ae(t, tr);

    for (var i = 0; i < p.length; ++i) {
        tr = $WH.ce('tr');

        td = $WH.ce('th');
        $WH.ae(td, $WH.ct(i == 0 ? 'Job' : 'then'));
        $WH.ae(tr, td);

        td = $WH.ce('td');
        if (i == 0)
            $WH.ae(td, $WH.ct(rb_job(p[0][0]).n));
        else {
            var opts = [], ch = rb_changesByFrom[p[i - 1][0]] || [];
            for (var j = 0; j < ch.length; ++j)
                opts.push([ch[j][1], rb_job(ch[j][1]).n]);
            $WH.ae(td, rb_select(opts, p[i][0], (function(idx) {
                return function(v) {
                    rb_state.path = rb_state.path.slice(0, idx);
                    rb_state.path.push([parseInt(v), 1]);
                    rb_refresh();
                };
            })(i)));
        }
        $WH.ae(tr, td);

        td = $WH.ce('td');
        $WH.ae(td, $WH.ct('Job Lv '));
        $WH.ae(td, rb_select(rb_levelOptions(rb_stageMinLevel(i), rb_job(p[i][0]).mj), p[i][1], (function(idx) {
            return function(v) {
                rb_state.path[idx][1] = parseInt(v);
                rb_refresh();
            };
        })(i)));
        if (i < p.length - 1) {
            var c = rb_change(p[i][0], p[i + 1][0]);
            if (c && c[2] > 1) {
                var n = $WH.ce('small');
                n.className = 'q0';
                $WH.ae(n, $WH.ct(' (' + (c[2] == rb_job(p[i][0]).mj ? 'job change at ' : 'job change from ') + c[2] + ')'));
                $WH.ae(td, n);
            }
        }
        $WH.ae(tr, td);
        $WH.ae(t, tr);
    }

    // the next job change, if any
    var next = rb_changesByFrom[p[p.length - 1][0]] || [];
    if (next.length) {
        tr = $WH.ce('tr');
        td = $WH.ce('th');
        $WH.ae(td, $WH.ct('then'));
        $WH.ae(tr, td);
        td = $WH.ce('td');
        td.colSpan = 2;
        var opts = [[-1, '(stay ' + rb_job(p[p.length - 1][0]).n + ')']];
        for (var j = 0; j < next.length; ++j)
            opts.push([next[j][1], rb_job(next[j][1]).n + ' (job ' + next[j][2] + (next[j][3] ? ', base ' + next[j][3] : '') + ')']);
        $WH.ae(td, rb_select(opts, -1, function(v) {
            v = parseInt(v);
            if (v < 0)
                return;
            var last = rb_state.path[rb_state.path.length - 1], c = rb_change(last[0], v);
            last[1] = Math.max(last[1], c[2]);
            rb_state.path.push([v, 1]);
            if (c[3])
                rb_state.base = Math.max(rb_state.base, c[3]);
            rb_refresh();
        }));
        $WH.ae(tr, td);
        $WH.ae(t, tr);
    }

    tr = $WH.ce('tr');
    td = $WH.ce('th');
    $WH.ae(td, $WH.ct('Base Lv'));
    $WH.ae(tr, td);
    td = $WH.ce('td');
    td.colSpan = 2;
    $WH.ae(td, rb_select(rb_levelOptions(rb_minBase(), rb_finalJob().mb), rb_state.base, function(v) {
        rb_state.base = parseInt(v);
        rb_refresh();
    }));
    $WH.ae(tr, td);
    $WH.ae(t, tr);

    $WH.ae(div, t);

    var notes = $WH.ce('div');
    notes.className = 'rb-note q0';
    var fj = rb_finalJob(), txt = [];
    if (p[0][0] == 4001)
        txt.push('Rebirth needs base 99 and job 50 in a second job of the same line; it resets the skills and gives ' + (rb_data.p[1] + rb_data.c.transcendent_status_points) + ' status points at base 1.');
    if (fj.y)
        txt.push('Baby jobs cap each stat at ' + rb_data.c.max_baby_parameter + '.');
    if (txt.length) {
        $WH.ae(notes, $WH.ct(txt.join(' ')));
        $WH.ae(div, notes);
    }
}

function rb_drawStats() {
    var
        div      = rb_el.stats,
        s        = rb_state,
        st       = rb_status(),
        total    = rb_statPointsTotal(),
        spent    = rb_statPointsSpent(),
        left     = total - spent,
        maxParam = rb_maxParameter(),
        t        = $WH.ce('table'),
        tr, td;

    $WH.ee(div);
    t.className = 'rb-stats';

    tr = $WH.ce('tr');
    var heads = ['', '', 'Base', '', 'Bonus', 'Total', 'Next'];
    for (var i = 0; i < heads.length; ++i) {
        td = $WH.ce('th');
        $WH.ae(td, $WH.ct(heads[i]));
        $WH.ae(tr, td);
    }
    $WH.ae(t, tr);

    for (var i = 0; i < 6; ++i) {
        var v = s.stats[i], cost = v < maxParam ? rb_statCost(v) : 0;
        tr = $WH.ce('tr');

        td = $WH.ce('th');
        $WH.ae(td, $WH.ct(RB_STATS[i]));
        $WH.ae(tr, td);

        td = $WH.ce('td');
        var minus = rb_action('\u2212', (function(idx) {
            return function(e) {
                e = $WH.$E(e);
                rb_state.stats[idx] = Math.max(1, rb_state.stats[idx] - (e.shiftKey ? 10 : 1));
                rb_refresh();
            };
        })(i), 'Lower (shift: by 10)');
        minus.className = 'rb-btn' + (v > 1 ? '' : ' rb-off');
        $WH.ae(td, minus);
        $WH.ae(tr, td);

        td = $WH.ce('td');
        var inp = $WH.ce('input');
        inp.type = 'text';
        inp.size = 3;
        inp.value = v;
        inp.onchange = (function(idx) {
            return function() {
                rb_setStat(idx, parseInt(this.value) || 1);
            };
        })(i);
        $WH.ae(td, inp);
        $WH.ae(tr, td);

        td = $WH.ce('td');
        var plus = rb_action('+', (function(idx) {
            return function(e) {
                e = $WH.$E(e);
                rb_setStat(idx, rb_state.stats[idx] + (e.shiftKey ? 10 : 1));
            };
        })(i), 'Raise (shift: by 10)');
        plus.className = 'rb-btn' + (cost && cost <= left ? '' : ' rb-off');
        $WH.ae(td, plus);
        $WH.ae(tr, td);

        td = $WH.ce('td');
        td.className = 'q2';
        if (st.bonus[i])
            $WH.ae(td, $WH.ct((st.bonus[i] > 0 ? '+' : '') + st.bonus[i]));
        $WH.ae(tr, td);

        td = $WH.ce('td');
        $WH.ae(td, $WH.ct(st.stats[i]));
        $WH.ae(tr, td);

        td = $WH.ce('td');
        td.className = 'q0';
        $WH.ae(td, $WH.ct(cost ? cost : 'max'));
        $WH.ae(tr, td);

        $WH.ae(t, tr);
    }
    $WH.ae(div, t);

    var p = $WH.ce('div');
    p.className = 'rb-points' + (left < 0 ? ' q10' : '');
    $WH.ae(p, $WH.ct('Status points: ' + left + ' left of ' + total + ' (max ' + maxParam + ' per stat)'));
    $WH.ae(div, p);

    var n = $WH.ce('div');
    n.className = 'rb-note q0';
    $WH.ae(n, $WH.ct('Bonus: ' + rb_finalJob().n + ' job level ' + rb_jobLevel() + ' bonus and passive skills. Next: the points the next raise costs.'));
    $WH.ae(div, n);
}

// raises or lowers a stat to the value, as far as the points and the stat cap allow (PCStatusUp)
function rb_setStat(idx, value) {
    var
        s        = rb_state,
        maxParam = rb_maxParameter();

    value = Math.max(1, Math.min(maxParam, value));
    if (value > s.stats[idx]) {
        var left = rb_statPointsTotal() - rb_statPointsSpent(), v = s.stats[idx];
        while (v < value && rb_statCost(v) <= left) {
            left -= rb_statCost(v);
            v++;
        }
        value = v;
    }
    s.stats[idx] = value;
    rb_refresh();
}

function rb_drawDerived() {
    var
        div = rb_el.derived,
        s   = rb_state,
        st  = rb_status(),
        fj  = rb_finalJob(),
        t   = $WH.ce('table'),
        rows;

    $WH.ee(div);
    t.className = 'rb-derived';

    rows = [
        ['Max HP', st.hp, 'Max SP', st.sp],
        ['ATK', st.atk + ' + weapon', 'MATK', st.matkMin + ' ~ ' + st.matkMax],
        ['HIT', st.hit, 'FLEE', st.flee + ' + ' + st.flee2],
        ['Critical', st.crit, 'ASPD', st.aspd === null ? 'n/a' : st.aspd],
        ['DEF', '0 + ' + st.def2, 'MDEF', '0 + ' + st.mdef2],
        ['HP regen', st.hpRegen + ' / tick', 'SP regen', st.spRegen + ' / tick'],
        ['Cast time', st.cast + '%', '', '']
    ];

    for (var i = 0; i < rows.length; ++i) {
        var tr = $WH.ce('tr');
        for (var j = 0; j < 4; ++j) {
            var td = $WH.ce(j % 2 ? 'td' : 'th');
            $WH.ae(td, $WH.ct(rows[i][j]));
            $WH.ae(tr, td);
        }
        $WH.ae(t, tr);
    }
    $WH.ae(div, t);

    // the weapon held, for the ASPD and the base ATK (bows, instruments, whips and guns use DEX)
    var w = $WH.ce('div'), opts = [];
    w.className = 'rb-weapon';
    for (var i = 0; i < rb_data.w.length; ++i)
        if (fj.a[rb_data.w[i][0]])
            opts.push([rb_data.w[i][0], rb_data.w[i][1]]);
    $WH.ae(w, $WH.ct('Weapon: '));
    $WH.ae(w, rb_select(opts, s.w, function(v) {
        rb_state.w = v;
        rb_refresh();
    }));

    if (rb_level('KN_RIDING'))
        rb_checkbox(w, 'riding a Peco', s.r, function(v) { rb_state.r = v; });
    if ((rb_finalJobId() == 23 || rb_finalJobId() == 4045) && rb_jobLevel() >= 70)
        rb_checkbox(w, 'never died (+10 all stats)', s.n, function(v) { rb_state.n = v; });
    $WH.ae(div, w);

    var n = $WH.ce('div');
    n.className = 'rb-note q0';
    $WH.ae(n, $WH.ct('As mod-rowow computes them without gear, cards or buffs. FLEE shows FLEE + perfect dodge; DEF and MDEF show gear + soft. Cast time is the share of a skill\'s variable cast time left.'));
    $WH.ae(div, n);
}

function rb_checkbox(parent, label, value, set) {
    var
        l = $WH.ce('label'),
        c = $WH.ce('input');

    c.type = 'checkbox';
    c.checked = !!value;
    c.onclick = function() {
        set(this.checked ? 1 : 0);
        rb_refresh();
    };
    $WH.ae(l, $WH.ct(' '));
    $WH.ae(l, c);
    $WH.ae(l, $WH.ct(label));
    $WH.ae(parent, l);
}

function rb_drawSkills() {
    var
        div   = rb_el.skills,
        s     = rb_state,
        p     = s.path,
        tree  = rb_finalJob().t,
        total = rb_skillPointsTotal(),
        spent = rb_skillPointsSpent(s.skills),
        lim   = rb_limits(),
        sum   = [0, 0, 0];

    $WH.ee(div);

    for (var id in s.skills)
        if (rb_isCounted(id))
            sum[Math.min(2, rb_tier(id))] += s.skills[id];

    var pts = $WH.ce('div');
    pts.className = 'rb-points' + (spent > total ? ' q10' : '');
    $WH.ae(pts, $WH.ct('Skill points: ' + (total - spent) + ' left of ' + total));
    $WH.ae(div, pts);

    var tierErr = rb_tierCheck(s.skills);
    if (tierErr) {
        var e = $WH.ce('div');
        e.className = 'rb-points q10';
        $WH.ae(e, $WH.ct(tierErr));
        $WH.ae(div, e);
    }

    if (rb_message) {
        var m = $WH.ce('div');
        m.className = 'rb-points q10';
        $WH.ae(m, $WH.ct(rb_message));
        $WH.ae(div, m);
        rb_message = '';
    }

    // one group per job of the path: the skills its tree adds, by the job whose own tree has them
    for (var tier = 0; tier < p.length; ++tier) {
        var
            stageJob = rb_job(p[tier][0]),
            byOrigin = {},
            origins  = [];

        for (var i = 0; i < tree.length; ++i) {
            var e = tree[i], skill = rb_data.s[e[0]];
            if (!skill || (skill.f & (RB_FLAG_WEDDING | RB_FLAG_SPIRIT)))
                continue;
            if (Math.min(rb_tier(e[0]), p.length - 1) != tier)
                continue;
            if (!byOrigin[e[4]]) {
                byOrigin[e[4]] = [];
                origins.push(e[4]);
            }
            byOrigin[e[4]].push(e);
        }

        if (!origins.length)
            continue;

        var h = $WH.ce('h4');
        $WH.ae(h, $WH.ct(stageJob.n + ' (job level ' + p[tier][1] + ': ' + (p[tier][1] - 1) + ' points)'));
        var used = $WH.ce('small');
        used.className = 'q0';
        $WH.ae(used, $WH.ct(' \u2014 ' + sum[Math.min(2, tier)] + ' spent here'));
        $WH.ae(h, used);
        $WH.ae(div, h);

        for (var o = 0; o < origins.length; ++o) {
            if (origins.length > 1) {
                var sub = $WH.ce('div');
                sub.className = 'rb-origin';
                $WH.ae(sub, $WH.ct(rb_job(origins[o]).n));
                $WH.ae(div, sub);
            }

            var list = $WH.ce('div');
            list.className = 'rb-skills';
            for (var i = 0; i < byOrigin[origins[o]].length; ++i)
                $WH.ae(list, rb_skillRow(byOrigin[origins[o]][i]));
            $WH.ae(div, list);
        }
    }

    var n = $WH.ce('div');
    n.className = 'rb-note q0';
    $WH.ae(n, $WH.ct('Left click + / \u2212 (shift: all the way). Quest skills come from their quests and cost no skill points. Like the game, a job\'s skills open once the earlier jobs\' points are spent.'));
    $WH.ae(div, n);
}

function rb_skillRow(e) {
    var
        s       = rb_state,
        skillId = e[0],
        skill   = rb_data.s[skillId],
        level   = s.skills[skillId] || 0,
        row     = $WH.ce('div'),
        spellId = skill.l ? (skill.l[Math.max(level, 1) - 1] || skill.l[0]) : 0,
        url     = spellId ? '?spell=' + spellId : null,
        addErr  = rb_canAdd(skillId),
        remErr  = rb_canRemove(skillId),
        _;

    row.className = 'rb-skill' + (level ? ' rb-learned' : '') + (!level && addErr ? ' rb-locked' : '');

    var icon = Icon.create(skill.i || 'inv_misc_questionmark', 1, null, url);
    $WH.ae(row, icon);
    Icon.setNumQty(icon, level ? String(level) : null);

    var info = $WH.ce('div');
    info.className = 'rb-skill-info';

    var name = $WH.ce(url ? 'a' : 'span');
    if (url)
        name.href = url;
    $WH.ae(name, $WH.ct(skill.n));
    $WH.ae(info, name);

    _ = $WH.ce('span');
    _.className = 'rb-lvl';
    $WH.ae(_, $WH.ct(' ' + level + '/' + e[1]));
    $WH.ae(info, _);

    if (skill.f & RB_FLAG_QUEST) {
        _ = $WH.ce('span');
        _.className = 'q0';
        $WH.ae(_, $WH.ct(' (quest)'));
        $WH.ae(info, _);
    }

    $WH.ae(info, $WH.ce('br'));

    var minus = rb_action('\u2212', function(ev) {
        ev = $WH.$E(ev);
        rb_changeSkill(skillId, ev.shiftKey ? -99 : -1);
    }, remErr || 'Lower');
    minus.className = 'rb-btn' + (remErr ? ' rb-off' : '');
    $WH.ae(info, minus);

    var plus = rb_action('+', function(ev) {
        ev = $WH.$E(ev);
        rb_changeSkill(skillId, ev.shiftKey ? 99 : 1);
    }, addErr || 'Raise');
    plus.className = 'rb-btn' + (addErr ? ' rb-off' : '');
    $WH.ae(info, plus);

    // what it needs
    var needs = [];
    for (var i = 0; i < e[3].length; ++i)
        needs.push([rb_skillName(e[3][i][0]) + ' ' + e[3][i][1], (s.skills[e[3][i][0]] || 0) >= e[3][i][1]]);
    if (e[2])
        needs.push(['Job Lv ' + e[2], rb_jobLevel() >= e[2]]);

    if (needs.length) {
        var req = $WH.ce('small');
        req.className = 'rb-req';
        req.title = 'Needs: ' + needs.map(function(x) { return x[0]; }).join(', ');
        $WH.ae(req, $WH.ct(' Needs: '));
        for (var i = 0; i < needs.length; ++i) {
            _ = $WH.ce('span');
            _.className = needs[i][1] ? 'q2' : 'q10';
            $WH.ae(_, $WH.ct(needs[i][0]));
            $WH.ae(req, _);
            if (i < needs.length - 1)
                $WH.ae(req, $WH.ct(', '));
        }
        $WH.ae(info, req);
    }

    $WH.ae(row, info);

    return row;
}

// raises or lowers a skill by up to |delta| levels, one level at a time while the rules allow it
function rb_changeSkill(skillId, delta) {
    var s = rb_state, err = null, moved = 0;

    while (delta > 0) {
        if (err = rb_canAdd(skillId))
            break;
        s.skills[skillId] = (s.skills[skillId] || 0) + 1;
        delta--;
        moved++;
    }

    while (delta < 0) {
        if (err = rb_canRemove(skillId))
            break;
        if (s.skills[skillId] > 1)
            s.skills[skillId]--;
        else
            delete s.skills[skillId];
        delta++;
        moved++;
    }

    if (!moved && err)
        rb_message = rb_skillName(skillId) + ': ' + err;

    rb_refresh();
}
