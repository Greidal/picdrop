<?php
require_once 'auth.php';
requireLogin();
$eventId = $_GET['event'] ?? '';
checkEventAccess($conn, $eventId);
$eventName = getEventOrDie($conn, $eventId);

$resUsers = $conn->query("
    SELECT uploader_name, COUNT(*) as count 
    FROM uploads 
    WHERE event_id = '$eventId' AND drink_id IS NOT NULL AND uploader_name != '' 
    GROUP BY uploader_name ORDER BY count DESC LIMIT 30
");

$resDetails = $conn->query("
    SELECT u.uploader_name, d.name, d.image_path, COUNT(*) as qty
    FROM uploads u
    JOIN drinks d ON u.drink_id = d.id
    WHERE u.event_id = '$eventId' AND u.uploader_name != ''
    GROUP BY u.uploader_name, d.name
    ORDER BY u.uploader_name, qty DESC
");

$userDetails = [];
while ($row = $resDetails->fetch_assoc()) {
    $userDetails[$row['uploader_name']][] = [
        'drink' => $row['name'],
        'img' => $row['image_path'],
        'qty' => $row['qty']
    ];
}

$resDrinks = $conn->query("
    SELECT d.name, d.image_path, COUNT(*) as count 
    FROM uploads u JOIN drinks d ON u.drink_id = d.id 
    WHERE u.event_id = '$eventId' 
    GROUP BY d.id ORDER BY count DESC
");

$pageTitle = "Leaderboard: $eventName";
require 'header.php';
?>

<div class="container text-center">
    <h1>🏆 Hall of Fame 🏆</h1>
    <p style="color:#888; margin-bottom:30px;">Tippe auf einen Namen für Details</p>

    <div class="grid" style="grid-template-columns: 1fr 1fr; text-align:left; gap:20px;">

        <div class="card">
            <h3 style="color:var(--primary)">Top Trinker</h3>
            <?php
            $rank = 1;
            while ($u = $resUsers->fetch_assoc()):
                $name = htmlspecialchars($u['uploader_name']);
                $safeDetails = isset($userDetails[$u['uploader_name']]) ? json_encode($userDetails[$u['uploader_name']]) : '[]';
            ?>
                <div class="flex-between"
                    style="padding:12px 10px; border-bottom:1px solid #333; cursor:pointer; transition:0.2s;"
                    onmouseover="this.style.background='#222'"
                    onmouseout="this.style.background='transparent'"
                    onclick='openModal("<?php echo $name; ?>", <?php echo $safeDetails; ?>)'>

                    <div>
                        <span style="font-weight:bold; color:#666; margin-right:10px;">#<?php echo $rank++; ?></span>
                        <span style="font-size:1.1rem;"><?php echo $name; ?></span>
                    </div>
                    <span style="background:var(--primary); color:white; padding:2px 10px; border-radius:10px; font-weight:bold; box-shadow:0 0 5px var(--primary-glow);">
                        <?php echo $u['count']; ?> 🍻
                    </span>
                </div>
            <?php endwhile; ?>
        </div>

        <div class="card">
            <h3 style="color:var(--success)">Beliebteste Drinks</h3>
            <?php while ($d = $resDrinks->fetch_assoc()): ?>
                <div class="flex-between" style="padding:10px 0; border-bottom:1px solid #333;">
                    <div style="display:flex; align-items:center;">
                        <img src="<?php echo htmlspecialchars($d['image_path']); ?>" class="avatar">
                        <?php echo htmlspecialchars($d['name']); ?>
                    </div>
                    <strong style="color:var(--success); font-size:1.2rem;"><?php echo $d['count']; ?></strong>
                </div>
            <?php endwhile; ?>
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
    function openModal(name, details) {
        document.getElementById('mTitle').innerText = name;
        const list = document.getElementById('mList');
        list.innerHTML = "";

        if (details.length === 0) {
            list.innerHTML = "<p class='text-center'>Keine Daten.</p>";
        } else {
            details.forEach(d => {
                list.innerHTML += `
                    <div class="flex-between" style="padding:10px; border-bottom:1px solid #333;">
                        <div style="display:flex; align-items:center;">
                            <img src="${d.img}" class="avatar" style="width:30px; height:30px;">
                            <span>${d.drink}</span>
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
</script>
</body>

</html>