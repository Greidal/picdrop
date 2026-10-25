<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/images.php';
requireLogin();
$uuid = $_GET['event'] ?? '';
checkEventAccess($conn, $uuid);
$eventName = getEventOrDie($conn, $uuid);

$stmt = $conn->prepare("
    SELECT u.filename, u.uploader_name, u.timestamp, d.name as drink_name 
    FROM uploads u 
    LEFT JOIN drinks d ON u.drink_id = d.id 
    WHERE u.event_id = ? 
    ORDER BY u.timestamp DESC
");
$stmt->bind_param("s", $uuid);
$stmt->execute();
$result = $stmt->get_result();

$images = [];
$jsImages = [];
while ($row = $result->fetch_assoc()) {
    $images[] = $row;
}

$pageTitle = "Galerie - " . $eventName;
require __DIR__ . '/lib/header.php';
?>

<style>
    .gallery-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 10px;
        margin-top: 20px;
    }

    .gallery-item {
        position: relative;
        aspect-ratio: 1;
        cursor: pointer;
        overflow: hidden;
        border-radius: 8px;
        border: 1px solid #333;
        transition: transform 0.2s;
    }

    .gallery-item:hover {
        transform: scale(1.02);
        border-color: var(--primary);
    }

    .gallery-item img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .lightbox {
        position: fixed;
        z-index: 1000;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.95);
        display: none;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        backdrop-filter: blur(5px);
    }

    .lightbox.active {
        display: flex;
    }

    .lightbox-img-container {
        position: relative;
        max-width: 90%;
        max-height: 80vh;
    }

    .lightbox-img {
        max-width: 100%;
        max-height: 80vh;
        border-radius: 5px;
        box-shadow: 0 0 20px rgba(0, 0, 0, 0.8);
    }

    .lb-btn {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        background: rgba(255, 255, 255, 0.1);
        color: white;
        border: none;
        font-size: 2rem;
        padding: 10px 20px;
        cursor: pointer;
        border-radius: 5px;
        transition: 0.2s;
        user-select: none;
    }

    .lb-btn:hover {
        background: var(--primary);
    }

    .lb-prev {
        left: 20px;
    }

    .lb-next {
        right: 20px;
    }

    .lb-close {
        position: absolute;
        top: 20px;
        right: 20px;
        background: transparent;
        color: #888;
        font-size: 2rem;
        border: none;
        cursor: pointer;
    }

    .lb-close:hover {
        color: white;
    }

    .lb-info {
        margin-top: 15px;
        text-align: center;
        color: #ccc;
        width: 100%;
        max-width: 600px;
    }

    .lb-title {
        font-size: 1.2rem;
        font-weight: bold;
        color: white;
    }

    .lb-meta {
        font-size: 0.9rem;
        color: #888;
        margin-top: 5px;
    }

    .lb-download {
        display: inline-block;
        margin-top: 15px;
        padding: 8px 15px;
        border: 1px solid #444;
        border-radius: 20px;
        color: #eee;
        font-size: 0.9rem;
        transition: 0.2s;
    }

    .lb-download:hover {
        background: var(--primary);
        border-color: var(--primary);
        color: white;
    }

    .gallery-head {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .gallery-head h1 {
        margin: 0;
        flex: 1;
        min-width: 0;
        font-size: 1.6rem;
    }

    .gallery-tools {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-top: 15px;
        color: var(--text-muted);
        font-size: 0.9rem;
    }

    .select-mark {
        position: absolute;
        top: 6px;
        left: 6px;
        width: 26px;
        height: 26px;
        border-radius: 50%;
        border: 2px solid rgba(255, 255, 255, 0.85);
        background: rgba(0, 0, 0, 0.35);
        display: none;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 0.8rem;
        z-index: 2;
    }

    .selecting .select-mark {
        display: flex;
    }

    .selecting .gallery-item:hover {
        transform: none;
    }

    .gallery-item.selected {
        border-color: var(--primary);
        box-shadow: inset 0 0 0 3px var(--primary);
    }

    .gallery-item.selected img {
        transform: scale(0.92);
        border-radius: 6px;
    }

    .gallery-item img {
        transition: transform 0.15s;
    }

    .gallery-item.selected .select-mark {
        background: var(--primary);
        border-color: var(--primary);
    }

    .gallery-item.selected .select-mark::after {
        content: "\2713";
        font-weight: bold;
    }

    .gallery-item.removing {
        transform: scale(0);
        transition: transform 0.3s;
    }

    #select-bar {
        position: fixed;
        left: 50%;
        bottom: 16px;
        transform: translateX(-50%);
        width: min(560px, calc(100% - 24px));
        box-sizing: border-box;
        background: rgba(26, 26, 26, 0.97);
        border: 1px solid #444;
        border-radius: 16px;
        padding: 8px 8px 8px 16px;
        display: flex;
        align-items: center;
        gap: 4px;
        z-index: 900;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(10px);
    }

    #select-count {
        flex: 1;
        font-weight: bold;
        white-space: nowrap;
    }

    #select-bar .icon-link:disabled {
        opacity: 0.35;
        pointer-events: none;
    }

    #select-bar .icon-link.danger:hover {
        color: #ff4444;
    }

    body.selecting-active .container {
        padding-bottom: 90px;
    }

    @media (max-width: 600px) {
        .gallery-grid {
            grid-template-columns: repeat(3, 1fr);
            gap: 4px;
        }

        .gallery-item {
            border-radius: 4px;
        }

        .lb-btn {
            padding: 8px 12px;
            font-size: 1.5rem;
        }

        .lb-prev {
            left: 6px;
        }

        .lb-next {
            right: 6px;
        }
    }
</style>

<div class="container">
    <div class="gallery-head">
        <a href="admin.php" class="icon-btn" title="Zurück zum Dashboard" aria-label="Zurück zum Dashboard"><i class="fa fa-arrow-left"></i></a>
        <h1>Galerie: <?php echo e($eventName); ?></h1>
    </div>

    <?php if (empty($images)): ?>
        <p style="text-align:center; margin-top:50px; color:#666;">Noch keine Fotos vorhanden.</p>
    <?php else: ?>
        <div class="gallery-tools">
            <span><span id="photo-count"><?php echo count($images); ?></span> Fotos</span>
            <button type="button" id="select-toggle" class="btn btn-secondary btn-small" onclick="toggleSelectMode()"><i class="fa fa-check-square"></i> Auswählen</button>
        </div>
        <div class="gallery-grid" id="gallery-grid">
            <?php
            foreach ($images as $index => $img):
                $fullPath = "uploads/$uuid/" . rawurlencode($img['filename']);

                $user = $img['uploader_name'] ?: '(Gast)';
                $drink = $img['drink_name'] ? "trinkt " . $img['drink_name'] : "";
                $date = date("d.m.Y H:i", strtotime($img['timestamp']));

                $jsImages[] = [
                    'file' => $img['filename'],
                    'src' => imageVariantUrl($uuid, $img['filename'], 'display'),
                    'original' => $fullPath,
                    'user' => $user,
                    'drink' => $drink,
                    'date' => $date
                ];
            ?>
                <div class="gallery-item" id="img-card-<?php echo $index; ?>" data-index="<?php echo $index; ?>">
                    <span class="select-mark" aria-hidden="true"></span>
                    <img src="<?php echo e(imageVariantUrl($uuid, $img['filename'], 'thumb')); ?>" loading="lazy" decoding="async" alt="Foto">
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div id="lightbox" class="lightbox" onclick="if(event.target === this) closeLightbox()">
    <button class="lb-close" onclick="closeLightbox()">&times;</button>

    <button class="lb-btn lb-prev" onclick="changeSlide(-1)">&#10094;</button>
    <button class="lb-btn lb-next" onclick="changeSlide(1)">&#10095;</button>

    <div class="lightbox-img-container">
        <img id="lb-image" class="lightbox-img" src="">
    </div>

    <div class="lb-info">
        <div id="lb-text" class="lb-title"></div>
        <div id="lb-meta" class="lb-meta"></div>
        <a id="lb-download" href="#" download class="lb-download">⬇️ Original herunterladen</a>
    </div>
</div>

<div id="select-bar" class="hidden" role="toolbar" aria-label="Auswahl">
    <span id="select-count">0 ausgewählt</span>
    <button type="button" class="icon-link" onclick="selectAll()" aria-label="Alle"><i class="fa fa-check-double"></i><span>Alle</span></button>
    <button type="button" class="icon-link" id="btn-download" onclick="downloadSelected()" disabled aria-label="Download"><i class="fa fa-download"></i><span>Download</span></button>
    <button type="button" class="icon-link danger" id="btn-delete" onclick="deleteSelected()" disabled aria-label="Löschen"><i class="fa fa-trash"></i><span>Löschen</span></button>
    <button type="button" class="icon-link" onclick="toggleSelectMode(false)" aria-label="Fertig"><i class="fa fa-xmark"></i><span>Fertig</span></button>
</div>

<form id="zip-form" method="post" action="download_zip.php?event=<?php echo e(urlencode($uuid)); ?>" class="hidden">
    <?php echo csrfField(); ?>
</form>

<script>
    const images = <?php echo json_encode($jsImages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    let currentIndex = 0;

    const lb = document.getElementById('lightbox');
    const lbImg = document.getElementById('lb-image');
    const lbText = document.getElementById('lb-text');
    const lbMeta = document.getElementById('lb-meta');
    const lbDown = document.getElementById('lb-download');

    function openLightbox(index) {
        if (images[index].deleted) return;

        currentIndex = index;
        updateLightbox();
        lb.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        lb.classList.remove('active');
        document.body.style.overflow = '';
    }

    function changeSlide(direction) {
        let nextIndex = currentIndex;
        let found = false;
        let attempts = 0;

        while (attempts < images.length) {
            nextIndex += direction;

            if (nextIndex >= images.length) nextIndex = 0;
            if (nextIndex < 0) nextIndex = images.length - 1;

            if (!images[nextIndex].deleted) {
                currentIndex = nextIndex;
                found = true;
                break;
            }

            attempts++;
        }

        if (found) {
            updateLightbox();
        } else {
            closeLightbox();
        }
    }

    function updateLightbox() {
        const imgData = images[currentIndex];

        lbImg.src = imgData.src;

        let title = imgData.user;
        if (imgData.drink) title += " " + imgData.drink;

        lbText.innerText = title;
        lbMeta.innerText = imgData.date + " Uhr";

        lbDown.href = imgData.original;
    }

    // --- Selection mode: select photos, then download or delete them together ---
    const grid = document.getElementById('gallery-grid');
    const selected = new Set();
    let selectMode = false;

    function toggleSelectMode(force) {
        selectMode = typeof force === 'boolean' ? force : !selectMode;
        if (!selectMode) {
            selected.clear();
            document.querySelectorAll('.gallery-item.selected').forEach((el) => el.classList.remove('selected'));
        }
        grid?.classList.toggle('selecting', selectMode);
        document.body.classList.toggle('selecting-active', selectMode);
        document.getElementById('select-bar').classList.toggle('hidden', !selectMode);
        updateSelectBar();
    }

    function toggleItem(index) {
        const card = document.getElementById('img-card-' + index);
        if (selected.has(index)) {
            selected.delete(index);
        } else {
            selected.add(index);
        }
        card.classList.toggle('selected', selected.has(index));
        updateSelectBar();
    }

    function selectAll() {
        const all = images.map((img, i) => i).filter((i) => !images[i].deleted);
        const allSelected = all.every((i) => selected.has(i));
        all.forEach((i) => {
            if (allSelected) selected.delete(i);
            else selected.add(i);
            document.getElementById('img-card-' + i).classList.toggle('selected', !allSelected);
        });
        updateSelectBar();
    }

    function updateSelectBar() {
        document.getElementById('select-count').textContent = selected.size + ' ausgewählt';
        document.getElementById('btn-download').disabled = selected.size === 0;
        document.getElementById('btn-delete').disabled = selected.size === 0;
    }

    grid?.addEventListener('click', (e) => {
        const card = e.target.closest('.gallery-item');
        if (!card) return;
        if (suppressClick) {
            suppressClick = false;
            return;
        }
        const index = Number(card.dataset.index);
        if (selectMode) toggleItem(index);
        else openLightbox(index);
    });

    // Long press starts the selection mode (phones have no hover/right click).
    let pressTimer = null;
    let suppressClick = false;
    grid?.addEventListener('pointerdown', (e) => {
        const card = e.target.closest('.gallery-item');
        if (!card || selectMode) return;
        pressTimer = setTimeout(() => {
            suppressClick = true;
            toggleSelectMode(true);
            toggleItem(Number(card.dataset.index));
            if (navigator.vibrate) navigator.vibrate(20);
        }, 500);
    });
    ['pointerup', 'pointerleave', 'pointercancel', 'pointermove'].forEach((type) =>
        grid?.addEventListener(type, (e) => {
            if (type === 'pointermove' && Math.abs(e.movementX) + Math.abs(e.movementY) < 4) return;
            clearTimeout(pressTimer);
        }));
    grid?.addEventListener('contextmenu', (e) => {
        if (e.target.closest('.gallery-item')) e.preventDefault();
    });

    function downloadSelected() {
        const files = [...selected].map((i) => images[i]);
        if (files.length === 1) {
            const a = document.createElement('a');
            a.href = files[0].original;
            a.download = files[0].file;
            document.body.appendChild(a);
            a.click();
            a.remove();
            return;
        }
        const form = document.getElementById('zip-form');
        form.querySelectorAll('input[name="files[]"]').forEach((el) => el.remove());
        files.forEach((img) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'files[]';
            input.value = img.file;
            form.appendChild(input);
        });
        form.submit();
    }

    async function deleteSelected() {
        const indexes = [...selected];
        const label = indexes.length === 1 ? 'dieses Foto' : `diese ${indexes.length} Fotos`;
        if (!confirm(`Willst du ${label} wirklich löschen? Das kann nicht rückgängig gemacht werden.`)) return;

        const formData = new FormData();
        formData.append('event_uuid', <?php echo json_encode($uuid); ?>);
        formData.append('csrf_token', <?php echo json_encode(csrfToken()); ?>);
        indexes.forEach((i) => formData.append('filenames[]', images[i].file));

        try {
            const response = await fetch('delete_image.php', { method: 'POST', body: formData });
            if (!response.ok) throw new Error(response.status);
        } catch (e) {
            alert("Fehler beim Löschen.");
            return;
        }

        indexes.forEach((i) => {
            images[i].deleted = true;
            const card = document.getElementById('img-card-' + i);
            card.classList.add('removing');
            setTimeout(() => card.remove(), 300);
        });
        const left = images.filter((img) => !img.deleted).length;
        document.getElementById('photo-count').textContent = left;
        toggleSelectMode(false);
        if (left === 0) setTimeout(() => location.reload(), 350);
    }

    document.addEventListener('keydown', function(e) {
        if (!lb.classList.contains('active')) return;

        if (e.key === 'ArrowLeft') changeSlide(-1);
        if (e.key === 'ArrowRight') changeSlide(1);
        if (e.key === 'Escape') closeLightbox();
    });
</script>
</body>

</html>