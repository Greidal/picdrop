<?php
require_once 'auth.php';
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
while ($row = $result->fetch_assoc()) {
    $images[] = $row;
}

$pageTitle = "Galerie - " . $eventName;
require 'header.php';
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
</style>

<div class="container">
    <div class="flex-between">
        <h1>Galerie: <?php echo htmlspecialchars($eventName); ?></h1>
        <a href="admin.php" class="btn btn-secondary btn-small">🔙 Dashboard</a>
    </div>

    <?php if (empty($images)): ?>
        <p style="text-align:center; margin-top:50px; color:#666;">Noch keine Fotos vorhanden.</p>
    <?php else: ?>
        <div class="gallery-grid">
            <?php
            $jsImages = [];
            foreach ($images as $index => $img):
                $fullPath = "uploads/$uuid/" . $img['filename'];

                $user = $img['uploader_name'] ?: '(Gast)';
                $drink = $img['drink_name'] ? "trinkt " . $img['drink_name'] : "";
                $date = date("d.m.Y H:i", strtotime($img['timestamp']));

                $jsImages[] = [
                    'src' => $fullPath,
                    'user' => $user,
                    'drink' => $drink,
                    'date' => $date
                ];
            ?>
                <div class="gallery-item" onclick="openLightbox(<?php echo $index; ?>)">
                    <img src="<?php echo $fullPath; ?>" loading="lazy" alt="Foto">
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

<script>
    const images = <?php echo json_encode($jsImages); ?>;
    let currentIndex = 0;

    const lb = document.getElementById('lightbox');
    const lbImg = document.getElementById('lb-image');
    const lbText = document.getElementById('lb-text');
    const lbMeta = document.getElementById('lb-meta');
    const lbDown = document.getElementById('lb-download');

    function openLightbox(index) {
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
        currentIndex += direction;

        if (currentIndex >= images.length) currentIndex = 0;
        if (currentIndex < 0) currentIndex = images.length - 1;

        updateLightbox();
    }

    function updateLightbox() {
        const imgData = images[currentIndex];

        lbImg.src = imgData.src;

        let title = imgData.user;
        if (imgData.drink) title += " " + imgData.drink;

        lbText.innerText = title;
        lbMeta.innerText = imgData.date + " Uhr";

        lbDown.href = imgData.src;
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