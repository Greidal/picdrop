<?php
// "Mein Pegel": personal BAC estimate, stats, history, badges and ranking for guests (drink mode).
require_once __DIR__ . '/lib/auth.php';

$eventId = $_GET['event'] ?? '';
$eventName = getEventOrDie($conn, $eventId);

$stmt = $conn->prepare("SELECT setting_show_bar FROM events WHERE uuid = ?");
$stmt->bind_param("s", $eventId);
$stmt->execute();
if (empty($stmt->get_result()->fetch_assoc()['setting_show_bar'])) {
    header("Location: index.php?event=" . urlencode($eventId));
    exit;
}

$pageTitle = "Mein Pegel – " . $eventName;
require __DIR__ . '/lib/header.php';
require_once __DIR__ . '/lib/guest_nav.php';
renderGuestCommon($eventId);
?>

<style>
    .container {
        max-width: 560px;
    }

    .hero {
        text-align: center;
        padding: 22px 16px 18px;
    }

    .hero-label {
        color: var(--text-muted);
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    #bac-now {
        font-size: 3.6rem;
        font-weight: 800;
        line-height: 1.1;
        margin: 4px 0;
        font-variant-numeric: tabular-nums;
    }

    #bac-sub {
        color: #aaa;
        font-size: 0.9rem;
        min-height: 1.2em;
    }

    .tiles {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-top: 16px;
    }

    .tile {
        background: #222;
        border-radius: 12px;
        padding: 10px 12px;
        text-align: left;
    }

    .tile .k {
        font-size: 0.75rem;
        color: var(--text-muted);
    }

    .tile .v {
        font-size: 1.25rem;
        font-weight: bold;
        margin-top: 2px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .card h3 {
        margin-bottom: 12px;
        font-size: 1.1rem;
        font-weight: 600;
    }

    #chart {
        width: 100%;
        height: 210px;
        display: block;
        touch-action: pan-y;
    }

    #chart text {
        fill: #777;
        font-size: 11px;
    }

    .chart-legend {
        display: flex;
        gap: 14px;
        font-size: 0.75rem;
        color: #888;
        margin-top: 6px;
        flex-wrap: wrap;
    }

    .history {
        list-style: none;
        padding: 0;
        margin: 14px 0 0;
    }

    .history li {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 0;
        border-top: 1px solid #262626;
    }

    .history .time {
        color: #888;
        font-size: 0.85rem;
        width: 42px;
        flex-shrink: 0;
        font-variant-numeric: tabular-nums;
    }

    .history .thumb {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        object-fit: cover;
        background: #000;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .history .what {
        flex: 1;
        min-width: 0;
    }

    .history .what small {
        display: block;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
    }

    .stats-grid .tile .v {
        font-size: 1.1rem;
    }

    .badges {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(92px, 1fr));
        gap: 8px;
    }

    .badge {
        background: #222;
        border-radius: 12px;
        padding: 10px 6px;
        text-align: center;
        border: 1px solid #2c2c2c;
    }

    .badge .icon {
        font-size: 1.8rem;
    }

    .badge .t {
        overflow-wrap: anywhere;
        font-size: 0.78rem;
        font-weight: bold;
        margin-top: 4px;
    }

    .badge .d {
        font-size: 0.66rem;
        color: #888;
        margin-top: 2px;
        line-height: 1.25;
    }

    .badge.locked {
        opacity: 0.35;
        filter: grayscale(1);
    }

    .badge.earned {
        border-color: rgba(255, 0, 85, 0.5);
        box-shadow: 0 0 10px rgba(255, 0, 85, 0.15);
    }

    .seg {
        display: flex;
        background: #222;
        border-radius: 10px;
        padding: 3px;
        margin-bottom: 10px;
    }

    .seg button {
        flex: 1;
        background: none;
        border: none;
        color: #999;
        padding: 8px;
        border-radius: 8px;
        font-weight: bold;
        cursor: pointer;
    }

    .seg button.active {
        background: #3a3a3a;
        color: white;
    }

    .rank-row {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 6px;
        border-bottom: 1px solid #262626;
        border-radius: 8px;
    }

    .rank-row.me {
        background: rgba(255, 0, 85, 0.12);
    }

    .rank-row .pos {
        width: 28px;
        color: #777;
        font-weight: bold;
    }

    .rank-row .nm {
        flex: 1;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .rank-row .pts {
        background: var(--primary);
        border-radius: 10px;
        padding: 1px 9px;
        font-weight: bold;
        font-size: 0.9rem;
    }

    .story-card {
        display: flex;
        align-items: center;
        gap: 14px;
        background: linear-gradient(135deg, #ff0055 0%, #7b2ff7 100%);
        border: none;
        color: white;
    }

    .story-card:hover {
        color: white;
    }

    .story-card .big {
        font-size: 2rem;
    }

    .profile-row {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 14px;
    }

    .profile-row > label:first-child {
        width: 80px;
        flex-shrink: 0;
    }

    .profile-row input,
    .profile-row select {
        margin: 0;
    }

    .sex-toggle {
        display: flex;
        gap: 6px;
    }

    .sex-toggle button {
        background: #222;
        border: 1px solid #3a3a3a;
        color: #ccc;
        border-radius: 20px;
        padding: 8px 14px;
        cursor: pointer;
    }

    .sex-toggle button.active {
        border-color: var(--primary);
        color: white;
        background: rgba(255, 0, 85, 0.15);
    }

    .disclaimer {
        font-size: 0.75rem;
        color: #777;
        text-align: center;
        margin: 4px 10px 30px;
        line-height: 1.4;
    }

    .empty-state {
        text-align: center;
        color: #888;
        padding: 10px 0;
    }
</style>

<div class="container">
    <div style="display:flex; gap:10px; margin-bottom:14px;">
        <a href="index.php?event=<?php echo e(urlencode($eventId)); ?>" class="btn btn-secondary btn-small">🔙 Zurück</a>
        <a href="index.php?event=<?php echo e(urlencode($eventId)); ?>#drink" class="btn btn-secondary btn-small">🍺 Getränk einchecken</a>
    </div>
    <div class="card hero">
        <div class="hero-label">Geschätzter Pegel</div>
        <div id="bac-now">–</div>
        <div id="bac-sub">Lade …</div>
        <div class="tiles">
            <div class="tile">
                <div class="k">🚗 Unter 0,5 ‰</div>
                <div class="v" id="t-limit">–</div>
            </div>
            <div class="tile">
                <div class="k">💧 Wieder nüchtern</div>
                <div class="v" id="t-sober">–</div>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>📈 Verlauf & Prognose</h3>
        <svg id="chart" viewBox="0 0 520 210" role="img" aria-label="Promille-Verlauf"></svg>
        <div class="chart-legend">
            <span><span style="color:var(--primary)">━</span> bisher</span>
            <span><span style="color:var(--primary)">┅</span> Prognose</span>
            <span><span style="color:#ffb000">┅</span> 0,5 ‰</span>
        </div>
        <ul class="history" id="history"></ul>
    </div>

    <div class="card">
        <h3>📊 Statistik</h3>
        <div class="stats-grid" id="stats"></div>
    </div>

    <div class="card">
        <h3>🏅 Abzeichen <small id="badge-count"></small></h3>
        <div class="badges" id="badges"></div>
    </div>

    <div class="card">
        <h3>🏆 Ranking</h3>
        <div class="seg" role="tablist">
            <button type="button" class="active" data-board="people" role="tab">Einzeln</button>
            <button type="button" data-board="teams" role="tab">Teams</button>
        </div>
        <div id="board"></div>
    </div>

    <a class="card story-card" href="story.php?event=<?php echo e(urlencode($eventId)); ?>">
        <span class="big">📲</span>
        <span><strong>Dein Abend als Insta-Story</strong><br><small style="color:#fde;">Statistiken & Fotos als Bild zum Teilen</small></span>
    </a>

    <div class="card">
        <h3>⚙️ Für die Berechnung</h3>
        <div class="profile-row">
            <label for="p-name">Name</label>
            <input type="text" id="p-name" maxlength="100" placeholder="Dein Name">
        </div>
        <div class="profile-row">
            <label for="p-weight">Gewicht</label>
            <input type="range" id="p-weight" min="40" max="150" step="1">
            <output id="p-weight-out" style="width:64px; flex-shrink:0; text-align:right; font-weight:bold; white-space:nowrap;"></output>
        </div>
        <div class="profile-row">
            <label>Geschlecht</label>
            <div class="sex-toggle" id="p-sex">
                <button type="button" data-sex="m">♂ männlich</button>
                <button type="button" data-sex="f">♀ weiblich</button>
            </div>
        </div>
        <div class="profile-row">
            <label for="p-team">Team</label>
            <select id="p-team"></select>
        </div>
        <div class="profile-row hidden" id="new-team-row">
            <label for="p-new-team">Neues Team</label>
            <input type="text" id="p-new-team" maxlength="40" placeholder="Teamname">
            <button type="button" class="btn btn-primary btn-inline" onclick="createTeam()">Gründen</button>
        </div>
    </div>

    <p class="disclaimer">Alle Werte sind grobe Schätzungen nach der Widmark-Formel und können stark abweichen.
        Verwende sie niemals, um zu entscheiden, ob du fahren kannst – im Zweifel: Taxi, Öffis oder Schlafen. 🚕</p>
</div>

<script>
    let state = null;
    let boardTab = 'people';

    async function load(action = 'state', fields = {}) {
        try {
            state = await Guest.api(action, fields);
            render();
        } catch (e) {
            Guest.toast(e.message, true);
        }
    }

    function el(tag, attrs = {}, text = null) {
        const node = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, v);
        if (text !== null) node.textContent = text;
        return node;
    }

    function bacColor(v) {
        if (v >= 1.1) return '#ff4444';
        if (v >= 0.5) return '#ffb000';
        if (v > 0) return '#00ff88';
        return '#ddd';
    }

    function relTime(unix) {
        const min = Math.round((unix - Date.now() / 1000) / 60);
        if (min < 60) return `in ${min} min`;
        return `in ${Math.floor(min / 60)} h ${String(min % 60).padStart(2, '0')} min`;
    }

    function render() {
        const b = state.bac;
        const now = document.getElementById('bac-now');
        now.textContent = Guest.formatBac(b.current);
        now.style.color = bacColor(b.current);

        let sub = 'Noch nichts eingecheckt – Prost! 🍻';
        if (b.peak) {
            const peakFuture = b.peak.ts > state.now;
            sub = (peakFuture ? 'Höchstwert erwartet ' : 'Höchstwert ') + Guest.formatBac(b.peak.value) + ' um ' + Guest.formatTime(b.peak.ts);
        }
        document.getElementById('bac-sub').textContent = sub;

        document.getElementById('t-limit').textContent = b.below_limit_at ? Guest.formatTime(b.below_limit_at) + ' Uhr' : (b.current < state.limit && !(b.peak && b.peak.value >= state.limit && b.peak.ts > state.now) ? '✓ jetzt' : '–');
        document.getElementById('t-sober').textContent = b.sober_at ? Guest.formatTime(b.sober_at) + ' Uhr' : (b.current === 0 ? '✓ jetzt' : '–');
        document.getElementById('t-limit').title = b.below_limit_at ? relTime(b.below_limit_at) : '';
        document.getElementById('t-sober').title = b.sober_at ? relTime(b.sober_at) : '';

        drawChart();
        renderHistory();
        renderStats();
        renderBadges();
        renderBoard();
        renderProfile();
    }

    function drawChart() {
        const svg = document.getElementById('chart');
        svg.replaceChildren();
        const series = state.bac.series;
        // Draw in CSS pixels so the labels aren't stretched on narrow screens.
        const W = Math.max(280, Math.round(svg.getBoundingClientRect().width)), H = 210, L = 34, R = 8, T = 10, B = 24;
        svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
        const NS = 'http://www.w3.org/2000/svg';
        const add = (tag, attrs, text) => {
            const node = document.createElementNS(NS, tag);
            for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, v);
            if (text) node.textContent = text;
            svg.appendChild(node);
            return node;
        };

        if (series.length < 2) {
            add('text', { x: W / 2, y: H / 2, 'text-anchor': 'middle' }, 'Noch keine Daten');
            return;
        }
        const t0 = series[0][0];
        const t1 = Math.max(series[series.length - 1][0], state.now + 1800);
        const maxV = Math.max(0.8, ...series.map((p) => p[1])) * 1.15;
        const x = (t) => L + (t - t0) / (t1 - t0) * (W - L - R);
        const y = (v) => T + (1 - v / maxV) * (H - T - B);

        // grid + y labels
        const step = maxV > 2 ? 0.5 : (maxV > 1 ? 0.25 : 0.2);
        for (let v = 0; v <= maxV; v += step) {
            add('line', { x1: L, x2: W - R, y1: y(v), y2: y(v), stroke: '#262626' });
            add('text', { x: L - 5, y: y(v) + 4, 'text-anchor': 'end' }, v.toLocaleString('de-DE', { maximumFractionDigits: 2 }));
        }
        // x labels every full hour
        const hour = 3600;
        for (let t = Math.ceil(t0 / hour) * hour; t <= t1; t += hour * Math.ceil((t1 - t0) / hour / 7)) {
            add('text', { x: x(t), y: H - 6, 'text-anchor': 'middle' }, Guest.formatTime(t));
        }
        // 0.5 limit
        add('line', { x1: L, x2: W - R, y1: y(state.limit), y2: y(state.limit), stroke: '#ffb000', 'stroke-dasharray': '4 4', 'stroke-width': 1 });

        const past = series.filter((p) => p[0] <= state.now);
        const future = series.filter((p) => p[0] >= state.now);
        const path = (pts) => pts.map((p, i) => (i ? 'L' : 'M') + x(p[0]).toFixed(1) + ' ' + y(p[1]).toFixed(1)).join(' ');
        if (past.length > 1) {
            add('path', { d: path(past) + ` L${x(past[past.length - 1][0])} ${y(0)} L${x(past[0][0])} ${y(0)} Z`, fill: 'rgba(255,0,85,0.15)' });
            add('path', { d: path(past), fill: 'none', stroke: '#ff0055', 'stroke-width': 2.5, 'stroke-linejoin': 'round' });
        }
        if (future.length > 1) {
            add('path', { d: path(future), fill: 'none', stroke: '#ff0055', 'stroke-width': 2, 'stroke-dasharray': '5 5', opacity: 0.7 });
        }
        // now marker
        if (state.now >= t0) {
            add('line', { x1: x(state.now), x2: x(state.now), y1: T, y2: H - B, stroke: '#666', 'stroke-dasharray': '2 3' });
            add('circle', { cx: x(state.now), cy: y(state.bac.current), r: 5, fill: bacColor(state.bac.current), stroke: '#111', 'stroke-width': 2 });
            add('text', { x: Math.min(x(state.now) + 4, W - 30), y: T + 10 }, 'jetzt');
        }
        // drink markers
        state.history.forEach((d) => {
            if (d.ts >= t0) add('circle', { cx: x(d.ts), cy: H - B, r: 3, fill: '#00ff88' });
        });
    }

    function renderHistory() {
        const list = document.getElementById('history');
        list.replaceChildren();
        if (state.history.length === 0) {
            list.appendChild(el('li', { class: 'empty-state' }, 'Hier erscheinen deine Getränke.'));
            return;
        }
        state.history.forEach((d) => {
            const li = el('li');
            li.appendChild(el('span', { class: 'time' }, Guest.formatTime(d.ts)));
            if (d.img) {
                li.appendChild(el('img', { class: 'thumb', src: d.img, alt: '' }));
            } else {
                li.appendChild(el('span', { class: 'thumb' }, '🍹'));
            }
            const what = el('span', { class: 'what' }, d.drink + (d.has_photo ? ' 📸' : ''));
            let meta = `${d.volume_ml} ml · ${d.abv.toLocaleString('de-DE')} % · ${d.grams.toLocaleString('de-DE')} g`;
            if (d.from) meta += ` · 🎁 von ${d.from}`;
            what.appendChild(el('small', {}, meta));
            li.appendChild(what);
            const del = el('button', { class: 'icon-btn danger', type: 'button', title: 'Eintrag löschen', 'aria-label': 'Eintrag löschen' });
            del.innerHTML = '<i class="fa fa-trash"></i>';
            del.addEventListener('click', async () => {
                if (!confirm(`„${d.drink}“ um ${Guest.formatTime(d.ts)} löschen?`)) return;
                await Guest.api('delete_checkin', { id: d.id }).catch((e) => Guest.toast(e.message, true));
                load();
            });
            li.appendChild(del);
            list.appendChild(li);
        });
    }

    function renderStats() {
        const s = state.stats;
        const fmt = (n, d = 1) => Number(n).toLocaleString('de-DE', { maximumFractionDigits: d });
        const tiles = [
            ['🍺 Getränke', s.count],
            ['🧪 Reiner Alkohol', fmt(s.grams, 0) + ' g'],
            ['🥤 Menge', fmt(s.volume_l, 2) + ' l'],
            ['⏱️ Tempo', s.per_hour !== null ? fmt(s.per_hour) + ' / h' : '–'],
            ['⌛ Dauer', s.duration_min >= 60 ? Math.floor(s.duration_min / 60) + ' h ' + (s.duration_min % 60) + ' min' : s.duration_min + ' min'],
            ['❤️ Liebling', s.favorite ? s.favorite.name : '–'],
            ['⭐ Punkte', fmt(s.points)],
            ['🏆 Platz', s.rank ? '#' + s.rank : '–'],
            ['🤝 Team-Platz', s.team_rank ? '#' + s.team_rank : '–'],
            ['📸 Fotos', s.photos],
            ['🎁 Runden', s.rounds_given],
            ['🍻 Eingeladen', s.received_rounds + '×'],
        ];
        const box = document.getElementById('stats');
        box.replaceChildren();
        tiles.forEach(([k, v]) => {
            const t = el('div', { class: 'tile' });
            t.appendChild(el('div', { class: 'k' }, k));
            t.appendChild(el('div', { class: 'v', title: String(v) }, String(v)));
            box.appendChild(t);
        });
    }

    function renderBadges() {
        const box = document.getElementById('badges');
        box.replaceChildren();
        const earned = state.badges.filter((b) => b.earned).length;
        document.getElementById('badge-count').textContent = `${earned}/${state.badges.length}`;
        [...state.badges].sort((a, b) => b.earned - a.earned).forEach((b) => {
            const card = el('div', { class: 'badge ' + (b.earned ? 'earned' : 'locked') });
            card.appendChild(el('div', { class: 'icon' }, b.icon));
            card.appendChild(el('div', { class: 't' }, b.title));
            card.appendChild(el('div', { class: 'd' }, b.desc));
            box.appendChild(card);
        });
    }

    function renderBoard() {
        const box = document.getElementById('board');
        box.replaceChildren();
        const rows = state.board[boardTab];
        if (rows.length === 0) {
            box.appendChild(el('p', { class: 'empty-state' }, boardTab === 'teams' ? 'Noch keine Teams am Start.' : 'Noch keine Daten.'));
            return;
        }
        rows.forEach((r, i) => {
            const row = el('div', { class: 'rank-row' + (r.me ? ' me' : '') });
            row.appendChild(el('span', { class: 'pos' }, ['🥇', '🥈', '🥉'][i] || '#' + (i + 1)));
            const name = el('span', { class: 'nm' }, r.name);
            const detail = boardTab === 'teams' ? `${r.members} Mitglieder · Ø ${r.avg_points.toLocaleString('de-DE')} Pkt` : (r.team ? `👥 ${r.team} · ${r.drinks} Getränke` : `${r.drinks} Getränke`);
            name.appendChild(el('small', { style: 'display:block' }, detail));
            row.appendChild(name);
            row.appendChild(el('span', { class: 'pts' }, Number(r.points).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' Pkt'));
            box.appendChild(row);
        });
    }

    document.querySelectorAll('.seg button').forEach((b) => b.addEventListener('click', () => {
        boardTab = b.dataset.board;
        document.querySelectorAll('.seg button').forEach((x) => x.classList.toggle('active', x === b));
        renderBoard();
    }));

    // --- Profile (weight/sex/team/name) ---
    const nameField = document.getElementById('p-name');
    const weightField = document.getElementById('p-weight');
    const teamField = document.getElementById('p-team');

    function renderProfile() {
        const p = state.profile;
        if (document.activeElement !== nameField) nameField.value = Guest.name() || p.name;
        if (document.activeElement !== weightField) weightField.value = p.weight_kg;
        document.getElementById('p-weight-out').textContent = Math.round(weightField.value) + ' kg';
        document.querySelectorAll('#p-sex button').forEach((b) => b.classList.toggle('active', b.dataset.sex === p.sex));

        teamField.replaceChildren(el('option', { value: '' }, '– kein Team –'));
        state.teams.forEach((t) => {
            const o = el('option', { value: t.id }, `${t.name} (${t.members})`);
            if (t.id === p.team_id) o.selected = true;
            teamField.appendChild(o);
        });
        teamField.appendChild(el('option', { value: 'new' }, '➕ Neues Team gründen …'));
    }

    nameField.addEventListener('change', () => {
        const name = nameField.value.trim();
        Guest.setName(name);
        if (name) load('profile', { name });
    });
    weightField.addEventListener('input', () => {
        document.getElementById('p-weight-out').textContent = Math.round(weightField.value) + ' kg';
    });
    weightField.addEventListener('change', () => load('profile', { weight_kg: weightField.value }));
    document.getElementById('p-sex').addEventListener('click', (e) => {
        const b = e.target.closest('button');
        if (b) load('profile', { sex: b.dataset.sex });
    });
    teamField.addEventListener('change', () => {
        const isNew = teamField.value === 'new';
        document.getElementById('new-team-row').classList.toggle('hidden', !isNew);
        if (isNew) {
            document.getElementById('p-new-team').focus();
            return;
        }
        load('profile', { team_id: teamField.value || '0' }).then(() => Guest.toast(teamField.value ? 'Willkommen im Team! 🤝' : 'Team verlassen'));
    });

    async function createTeam() {
        const name = document.getElementById('p-new-team').value.trim();
        if (!name) return;
        await load('profile', { new_team: name });
        document.getElementById('new-team-row').classList.add('hidden');
        document.getElementById('p-new-team').value = '';
        Guest.toast('Team „' + name + '“ gegründet! 🤝');
    }

    window.addEventListener('resize', () => state && drawChart());
    load();
    // keep the curve moving while the page is open
    setInterval(() => {
        if (!document.hidden && document.activeElement?.tagName !== 'INPUT') load();
    }, 60000);
</script>
</body>

</html>
