<?php
require_once 'auth.php';
requireLogin();
$eventId = $_GET['event'] ?? '';
checkEventAccess($conn, $eventId);
$eventName = getEventOrDie($conn, $eventId);

$pageTitle = "Leaderboard: $eventName";
require 'header.php';
?>

<div class="container text-center">
    <h1>🏆 Hall of Fame 🏆</h1>
    <p style="color:#888; margin-bottom:30px;">
        <span id="loading-indicator">Lade Daten...</span><br>
        <small>Tippe auf einen Namen für Details</small>
    </p>

    <div class="grid" style="grid-template-columns: 1fr 1fr; text-align:left; gap:20px;">

        <div class="card">
            <h3 style="color:var(--primary)">Top Trinker</h3>
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
        eventId: "<?php echo htmlspecialchars($eventId); ?>",
        pollInterval: 5000
    };

    let globalDetails = {};

    async function updateLeaderboard() {
        try {
            const response = await fetch(`get_leaderboard_data.php?event=${CONFIG.eventId}`);
            if (!response.ok) throw new Error("API response was not ok");

            const data = await response.json();

            globalDetails = data.details;

            let userHtml = '';
            if (data.users.length === 0) {
                userHtml = '<p style="color:#666; font-style:italic;">Noch keine Daten.</p>';
            } else {
                data.users.forEach((u, index) => {
                    const rank = index + 1;
                    const nameEscaped = u.uploader_name.replace(/'/g, "\\'");

                    userHtml += `
                        <div class="flex-between" 
                             style="padding:12px 10px; border-bottom:1px solid #333; cursor:pointer; transition:0.2s;" 
                             onmouseover="this.style.background='#222'" 
                             onmouseout="this.style.background='transparent'"
                             onclick="openModal('${nameEscaped}')">
                            
                            <div>
                                <span style="font-weight:bold; color:#666; margin-right:10px;">#${rank}</span>
                                <span style="font-size:1.1rem;">${escapeHtml(u.uploader_name)}</span>
                            </div>
                            <span style="background:var(--primary); color:white; padding:2px 10px; border-radius:10px; font-weight:bold; box-shadow:0 0 5px var(--primary-glow);">
                                ${u.count} 🍻
                            </span>
                        </div>
                    `;
                });
            }
            document.getElementById('user-list').innerHTML = userHtml;

            let drinkHtml = '';
            if (data.drinks.length === 0) {
                drinkHtml = '<p style="color:#666; font-style:italic;">Noch keine Daten.</p>';
            } else {
                data.drinks.forEach(d => {
                    drinkHtml += `
                        <div class="flex-between" style="padding:10px 0; border-bottom:1px solid #333;">
                            <div style="display:flex; align-items:center;">
                                <img src="${escapeHtml(d.image_path)}" class="avatar">
                                ${escapeHtml(d.name)}
                            </div>
                            <strong style="color:var(--success); font-size:1.2rem;">${d.count}</strong>
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

    function openModal(name) {
        document.getElementById('mTitle').innerText = name;
        const list = document.getElementById('mList');
        list.innerHTML = "";

        const details = globalDetails[name] || [];

        if (details.length === 0) {
            list.innerHTML = "<p class='text-center'>Keine Daten.</p>";
        } else {
            details.forEach(d => {
                list.innerHTML += `
                    <div class="flex-between" style="padding:10px; border-bottom:1px solid #333;">
                        <div style="display:flex; align-items:center;">
                            <img src="${escapeHtml(d.img)}" class="avatar" style="width:30px; height:30px;">
                            <span>${escapeHtml(d.drink)}</span>
                        </div>
                        <strong style="color:var(--success)">${d.qty}x</strong>
                    </div>
                `;
            });
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
        return text
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