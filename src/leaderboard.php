<?php
require_once __DIR__ . '/lib/auth.php';
requireLogin();
$eventId = $_GET['event'] ?? '';
checkEventAccess($conn, $eventId);
$eventName = getEventOrDie($conn, $eventId);

$pageTitle = "Leaderboard: $eventName";
require __DIR__ . '/lib/header.php';
?>

<style>
    .board-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        text-align: left;
        gap: 20px;
    }

    .seg {
        display: inline-flex;
        background: #222;
        border-radius: 10px;
        padding: 3px;
        margin-bottom: 14px;
    }

    .seg button {
        background: none;
        border: none;
        color: #999;
        padding: 7px 16px;
        border-radius: 8px;
        font-weight: bold;
        cursor: pointer;
    }

    .seg button.active {
        background: #3a3a3a;
        color: white;
    }

    .lb-row {
        padding: 12px 10px;
        border-bottom: 1px solid #333;
        cursor: pointer;
        transition: 0.2s;
        gap: 10px;
    }

    .lb-row:hover {
        background: #222;
    }

    .lb-row small {
        display: block;
        color: #888;
        margin-left: 34px;
    }

    .lb-bac {
        color: #ffb000;
        font-size: 0.85rem;
        margin-right: 8px;
        white-space: nowrap;
    }

    .lb-pts {
        background: var(--primary);
        color: white;
        padding: 2px 10px;
        border-radius: 10px;
        font-weight: bold;
        box-shadow: 0 0 5px var(--primary-glow);
        white-space: nowrap;
    }

    @media (max-width: 700px) {
        .board-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="container text-center" style="max-width:960px;">
    <h1>🏆 Hall of Fame 🏆</h1>
    <p style="color:#888; margin-bottom:20px;">
        <span id="loading-indicator">Lade Daten...</span><br>
        <small>Tippe auf einen Namen für Details</small>
    </p>

    <div class="board-grid">
        <div class="card">
            <div class="flex-between" style="flex-wrap:wrap; gap:10px;">
                <h3 style="color:var(--primary); margin:0 0 14px;">Top Trinker</h3>
                <div class="seg" role="tablist">
                    <button type="button" class="active" data-board="users" role="tab">Einzeln</button>
                    <button type="button" data-board="teams" role="tab">Teams</button>
                </div>
            </div>
            <div id="user-list"></div>
        </div>

        <div class="card">
            <h3 style="color:var(--success)">Beliebteste Drinks</h3>
            <div id="drink-list"></div>
        </div>
    </div>
</div>

<div id="detailModal" class="modal-overlay" onclick="closeModal(event)">
    <div class="modal-content">
        <h2 id="mTitle" style="color:var(--primary); margin-bottom:20px;">Name</h2>
        <div id="mList" style="text-align:left;"></div>
        <button onclick="document.getElementById('detailModal').style.display='none'" class="btn btn-secondary btn-small" style="margin-top:20px;">Schließen</button>
    </div>
</div>

<script>
    const CONFIG = {
        eventId: <?php echo json_encode($eventId); ?>,
        pollInterval: 5000
    };

    let data = { users: [], teams: [], drinks: [] };
    let boardTab = 'users';

    const fmt = (n, d = 1) => Number(n).toLocaleString('de-DE', { maximumFractionDigits: d });
    const avatar = (img, size = 40) => img
        ? `<img src="${escapeHtml(img)}" class="avatar" style="width:${size}px; height:${size}px;">`
        : `<span class="avatar" style="width:${size}px; height:${size}px; display:inline-flex; align-items:center; justify-content:center; background:#000;">🍹</span>`;

    async function updateLeaderboard() {
        try {
            const response = await fetch(`get_leaderboard_data.php?event=${CONFIG.eventId}`);
            if (!response.ok) throw new Error("API response was not ok");
            data = await response.json();
            renderBoard();

            let drinkHtml = '';
            if (data.drinks.length === 0) {
                drinkHtml = '<p style="color:#666; font-style:italic;">Noch keine Daten.</p>';
            } else {
                data.drinks.forEach(d => {
                    drinkHtml += `
                        <div class="flex-between" style="padding:10px 0; border-bottom:1px solid #333;">
                            <div style="display:flex; align-items:center;">
                                ${avatar(d.image_path)}
                                ${escapeHtml(d.name)}
                            </div>
                            <strong style="color:var(--success); font-size:1.2rem;">${d.count}x</strong>
                        </div>
                    `;
                });
            }
            document.getElementById('drink-list').innerHTML = drinkHtml;
            document.getElementById('loading-indicator').style.display = 'none';
        } catch (e) {
            console.error("Fehler beim Update:", e);
        }
    }

    function renderBoard() {
        const rows = boardTab === 'teams' ? data.teams : data.users;
        if (rows.length === 0) {
            document.getElementById('user-list').innerHTML = `<p style="color:#666; font-style:italic;">${boardTab === 'teams' ? 'Noch keine Teams.' : 'Noch keine Daten.'}</p>`;
            return;
        }
        let html = '';
        rows.forEach((r, index) => {
            const name = boardTab === 'teams' ? r.name : r.uploader_name;
            const points = boardTab === 'teams' ? r.points : r.count;
            const sub = boardTab === 'teams'
                ? `${r.members} Mitglieder · Ø ${fmt(r.avg_points)} Pkt · Ø ${fmt(r.avg_bac, 2)} ‰`
                : `${r.drinks} Getränke${r.team ? ' · 👥 ' + escapeHtml(r.team) : ''}`;
            const bac = boardTab === 'users' && r.bac > 0 ? `<span class="lb-bac">${fmt(r.bac, 2)} ‰</span>` : '';
            html += `
                <div class="lb-row flex-between" onclick="openModal(${index})">
                    <div style="min-width:0;">
                        <span style="font-weight:bold; color:#666; margin-right:10px; display:inline-block; width:24px;">#${index + 1}</span>
                        <span style="font-size:1.1rem;">${escapeHtml(name)}</span>
                        <small>${sub}</small>
                    </div>
                    <div>${bac}<span class="lb-pts">${fmt(points)} <small style="font-size:0.8em; display:inline; margin:0; color:#fff;">Pkt</small></span></div>
                </div>`;
        });
        document.getElementById('user-list').innerHTML = html;
    }

    document.querySelectorAll('.seg button').forEach((b) => b.addEventListener('click', () => {
        boardTab = b.dataset.board;
        document.querySelectorAll('.seg button').forEach((x) => x.classList.toggle('active', x === b));
        renderBoard();
    }));

    function openModal(index) {
        const list = document.getElementById('mList');
        list.innerHTML = "";
        if (boardTab === 'teams') {
            const team = data.teams[index];
            document.getElementById('mTitle').innerText = 'Team ' + team.name;
            const members = data.users.filter((u) => u.team === team.name);
            members.forEach((u) => {
                list.innerHTML += `
                    <div class="flex-between" style="padding:10px; border-bottom:1px solid #333;">
                        <span>${escapeHtml(u.uploader_name)}</span>
                        <strong style="color:var(--success)">${fmt(u.count)} Pkt</strong>
                    </div>`;
            });
            if (members.length === 0) list.innerHTML = "<p class='text-center'>Keine Daten.</p>";
        } else {
            const user = data.users[index];
            document.getElementById('mTitle').innerText = user.uploader_name;
            (user.details || []).forEach(d => {
                list.innerHTML += `
                    <div class="flex-between" style="padding:10px; border-bottom:1px solid #333;">
                        <div style="display:flex; align-items:center;">
                            ${avatar(d.img, 30)}
                            <span>${escapeHtml(d.drink)}</span>
                        </div>
                        <strong style="color:var(--success)">${d.qty}x</strong>
                    </div>
                `;
            });
            if (!user.details || user.details.length === 0) list.innerHTML = "<p class='text-center'>Keine Daten.</p>";
        }
        document.getElementById('detailModal').style.display = 'flex';
    }

    function closeModal(e) {
        if (e.target.classList.contains('modal-overlay')) {
            e.target.style.display = 'none';
        }
    }

    function escapeHtml(text) {
        if (!text) return text;
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    updateLeaderboard();
    setInterval(updateLeaderboard, CONFIG.pollInterval);
</script>
</body>

</html>