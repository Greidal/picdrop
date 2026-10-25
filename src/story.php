<?php
// Summary of the guest's evening as a 1080×1920 image (Instagram story), rendered in the browser.
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

$pageTitle = "Dein Abend – " . $eventName;
require __DIR__ . '/lib/header.php';
require_once __DIR__ . '/lib/guest_nav.php';
renderGuestCommon($eventId);
?>

<style>
    .container {
        max-width: 560px;
        text-align: center;
    }

    #story {
        width: 100%;
        max-width: 360px;
        aspect-ratio: 9 / 16;
        border-radius: 18px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.7);
        background: #1a1a1a;
    }

    .story-actions {
        display: flex;
        gap: 10px;
        justify-content: center;
        margin: 20px 0;
        flex-wrap: wrap;
    }

    .story-actions .btn {
        width: auto;
        font-size: 1rem;
    }
</style>

<div class="container">
    <h1 style="font-size:1.5rem;">📲 Dein Abend</h1>
    <canvas id="story" width="1080" height="1920" aria-label="Story-Vorschau"></canvas>
    <div class="story-actions">
        <button type="button" class="btn btn-primary" id="btn-share" onclick="share()">📤 Teilen</button>
        <button type="button" class="btn btn-secondary" onclick="download()">⬇️ Bild speichern</button>
    </div>
    <p class="muted" style="font-size:0.85rem;">Auf dem Handy: „Teilen“ → Instagram → Story.<br>Alternativ Bild speichern und in Instagram als Story hochladen.</p>
    <a href="pegel.php?event=<?php echo e(urlencode($eventId)); ?>" class="btn btn-secondary btn-small">🔙 Zurück zu Mein Pegel</a>
</div>

<script>
    const EVENT_NAME = <?php echo json_encode($eventName); ?>;
    const canvas = document.getElementById('story');
    const ctx = canvas.getContext('2d');
    const W = 1080, H = 1920;

    function loadImage(src) {
        return new Promise((resolve) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = () => resolve(null);
            img.src = src;
        });
    }

    function roundRect(x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }

    /** Draws an image cropped to fill the box ("object-fit: cover"). */
    function drawCover(img, x, y, w, h, r, rotate = 0) {
        ctx.save();
        ctx.translate(x + w / 2, y + h / 2);
        ctx.rotate(rotate);
        roundRect(-w / 2 - 10, -h / 2 - 10, w + 20, h + 20, r + 6);
        ctx.fillStyle = '#fff';
        ctx.shadowColor = 'rgba(0,0,0,0.5)';
        ctx.shadowBlur = 30;
        ctx.fill();
        ctx.shadowBlur = 0;
        roundRect(-w / 2, -h / 2, w, h, r);
        ctx.clip();
        const scale = Math.max(w / img.width, h / img.height);
        const iw = img.width * scale, ih = img.height * scale;
        ctx.drawImage(img, -iw / 2, -ih / 2, iw, ih);
        ctx.restore();
    }

    function fitText(text, maxWidth, size, weight = 800) {
        do {
            ctx.font = `${weight} ${size}px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif`;
            size -= 4;
        } while (ctx.measureText(text).width > maxWidth && size > 20);
    }

    const fmt = (n, d = 1) => Number(n).toLocaleString('de-DE', { maximumFractionDigits: d });

    async function render() {
        let state, photos;
        try {
            [state, photos] = await Promise.all([Guest.api('state'), Guest.api('photos')]);
        } catch (e) {
            Guest.toast(e.message, true);
            return;
        }
        const s = state.stats;
        const bac = state.bac;

        // background
        const g = ctx.createLinearGradient(0, 0, W, H);
        g.addColorStop(0, '#2b0a3d');
        g.addColorStop(0.5, '#a1004a');
        g.addColorStop(1, '#ff5e3a');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, W, H);
        ctx.fillStyle = 'rgba(0,0,0,0.18)';
        for (let i = 0; i < 40; i++) {
            ctx.beginPath();
            ctx.arc((i * 263) % W, (i * 491) % H, 6 + (i % 5) * 4, 0, Math.PI * 2);
            ctx.fill();
        }

        // header
        ctx.textAlign = 'center';
        ctx.fillStyle = 'rgba(255,255,255,0.75)';
        ctx.font = '600 40px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        const dateRef = state.history.length ? state.history[state.history.length - 1].ts * 1000 : Date.now();
        ctx.fillText('MEIN ABEND · ' + new Date(dateRef).toLocaleDateString('de-DE', { day: '2-digit', month: 'long', year: 'numeric' }).toUpperCase(), W / 2, 150);
        ctx.fillStyle = '#fff';
        fitText(EVENT_NAME, W - 140, 96);
        ctx.fillText(EVENT_NAME, W / 2, 260);
        const name = Guest.name() || state.profile.name;
        if (name) {
            ctx.font = '500 48px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
            ctx.fillStyle = 'rgba(255,255,255,0.85)';
            ctx.fillText('mit ' + name + (state.profile.team ? ' · Team ' + state.profile.team : ''), W / 2, 330);
        }

        // photo collage
        const imgs = (await Promise.all(photos.photos.slice(0, 3).map(loadImage))).filter(Boolean);
        const collageTop = 400;
        if (imgs.length === 1) {
            drawCover(imgs[0], 190, collageTop, 700, 620, 24, -0.03);
        } else if (imgs.length === 2) {
            drawCover(imgs[0], 90, collageTop + 20, 470, 580, 24, -0.06);
            drawCover(imgs[1], 520, collageTop + 50, 470, 580, 24, 0.05);
        } else if (imgs.length >= 3) {
            drawCover(imgs[0], 60, collageTop + 60, 400, 520, 22, -0.08);
            drawCover(imgs[2], 620, collageTop + 70, 400, 520, 22, 0.08);
            drawCover(imgs[1], 340, collageTop, 400, 560, 22, 0.01);
        } else {
            ctx.font = '220px serif';
            ctx.fillText('🍻', W / 2, collageTop + 380);
        }

        // stats grid
        const top = 1110;
        const tiles = [
            ['🍺', s.count, 'Getränke'],
            ['📈', fmt(bac.peak ? bac.peak.value : 0, 2) + ' ‰', 'Höchster Pegel'],
            ['📸', s.photos, 'Fotos'],
            ['🧪', fmt(s.grams, 0) + ' g', 'reiner Alkohol'],
            ['❤️', s.favorite ? s.favorite.name : '–', 'Lieblingsdrink'],
            ['🏆', s.rank ? '#' + s.rank : '–', 'Platz im Ranking'],
        ];
        tiles.forEach(([icon, value, label], i) => {
            const col = i % 3, row = Math.floor(i / 3);
            const x = 60 + col * 330, y = top + row * 290;
            roundRect(x, y, 300, 260, 30);
            ctx.fillStyle = 'rgba(0,0,0,0.28)';
            ctx.fill();
            ctx.fillStyle = '#fff';
            ctx.font = '64px serif';
            ctx.fillText(icon, x + 150, y + 85);
            fitText(String(value), 270, 64);
            ctx.fillText(String(value), x + 150, y + 170);
            ctx.font = '500 32px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
            ctx.fillStyle = 'rgba(255,255,255,0.75)';
            ctx.fillText(label, x + 150, y + 220);
        });

        // badges
        const earned = state.badges.filter((b) => b.earned);
        if (earned.length) {
            ctx.fillStyle = 'rgba(255,255,255,0.8)';
            ctx.font = '600 34px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
            ctx.fillText(`${earned.length} ABZEICHEN`, W / 2, 1720);
            ctx.font = '80px serif';
            const shown = earned.slice(0, 8);
            shown.forEach((b, i) => {
                ctx.fillText(b.icon, W / 2 + (i - (shown.length - 1) / 2) * 110, 1815);
            });
        }

        ctx.font = '600 30px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        ctx.fillStyle = 'rgba(255,255,255,0.55)';
        ctx.fillText('📸 PicDrop', W / 2, 1890);
    }

    function storyBlob() {
        return new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
    }

    async function share() {
        const blob = await storyBlob();
        const file = new File([blob], 'mein-abend.png', { type: 'image/png' });
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
            try {
                await navigator.share({ files: [file], title: EVENT_NAME });
            } catch (e) {}
            return;
        }
        Guest.toast('Teilen wird hier nicht unterstützt – das Bild wird gespeichert.');
        download();
    }

    async function download() {
        const blob = await storyBlob();
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'mein-abend.png';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    }

    render();
</script>
</body>

</html>
