<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/uploads.php';

$eventId = $_GET['event'] ?? '';
$eventName = getEventOrDie($conn, $eventId);

// load event settings: show the "bar" (drink) button, strip location data from photos
$stmt2 = $conn->prepare("SELECT setting_show_bar, setting_strip_location FROM events WHERE uuid = ?");
$stmt2->bind_param("s", $eventId);
$stmt2->execute();
$row = $stmt2->get_result()->fetch_assoc();
$showBar = isset($row['setting_show_bar']) ? boolval($row['setting_show_bar']) : true;
$stripLocation = !empty($row['setting_strip_location']);

$prefilledName = "";
if (isLoggedIn()) {
    $prefilledName = $_SESSION['username'] ?? '';
}

$stmt = $conn->prepare("SELECT * FROM drinks WHERE event_uuid = ?");
$stmt->bind_param("s", $eventId);
$stmt->execute();
$drinks = $stmt->get_result();

$flash = getFlashMessage();
if ($flash) {
    $msg = $flash['text'];
    $msgClass = $flash['type'];
} else {
    $msg = "";
    $msgClass = "";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // The multi-photo upload sends one photo per request and expects JSON.
    $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

    if (empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // Request exceeded post_max_size: PHP dropped the whole body.
        $result = uploadResult(false, 413, "Das Bild ist zu groß.");
    } else {
        $result = handleGuestUpload($conn, $eventId, $_FILES['image'] ?? [], $_POST, $stripLocation);
    }

    if ($wantsJson) {
        http_response_code($result['status']);
        header('Content-Type: application/json');
        echo json_encode(['ok' => $result['ok'], 'message' => $result['message']]);
        exit;
    }

    if ($result['ok']) {
        setFlashMessage($result['message'], "success");
        header("Location: index.php?event=" . urlencode($eventId));
        exit;
    }
    if ($result['status'] === 403) {
        http_response_code(403);
        die(e($result['message']));
    }
    $msg = $result['message'];
    $msgClass = "error";
}

$pageTitle = $eventName;
require __DIR__ . '/lib/header.php';
?>

<style>
    .drink-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
        max-width: 400px;
        width: 100%;
        margin: 20px auto;
    }

    .drink-card {
        background: #1a1a1a;
        border: 2px solid #333;
        border-radius: 15px;
        height: 120px;
        position: relative;
        overflow: hidden;
        cursor: pointer;
        transition: 0.2s;
    }

    .drink-card.selected {
        border-color: #00ff88;
        box-shadow: 0 0 15px rgba(0, 255, 136, 0.3);
        transform: scale(1.02);
    }

    .drink-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        filter: brightness(0.6);
        transition: 0.3s;
    }

    .drink-card.selected .drink-img {
        filter: brightness(1);
    }

    .drink-name {
        position: absolute;
        bottom: 0;
        width: 100%;
        background: rgba(0, 0, 0, 0.7);
        text-align: center;
        padding: 5px 0;
        font-weight: bold;
        font-size: 0.9rem;
    }

    #upload-box {
        max-width: 300px;
        margin: 15px auto 0;
    }

    .upload-progress {
        height: 8px;
        background: #222;
        border-radius: 4px;
        overflow: hidden;
    }

    #upload-bar {
        height: 100%;
        width: 0;
        background: var(--success);
        transition: width 0.2s;
    }

    #upload-errors {
        text-align: left;
        color: #ff8888;
        font-size: 0.85rem;
        padding-left: 20px;
    }

    .uploading label.btn {
        pointer-events: none;
        opacity: 0.5;
    }

    #emoji-bar {
        position: fixed;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: rgba(20, 20, 20, 0.95);
        padding: 8px 15px;
        border-radius: 50px;
        display: flex;
        gap: 10px;
        align-items: center;
        z-index: 1000;
        border: 1px solid #444;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(10px);
    }

    .quick-btn {
        background: transparent;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        padding: 0 5px;
        transition: transform 0.1s;
    }

    .quick-btn:active {
        transform: scale(1.3);
    }

    #emoji-form {
        display: flex;
        gap: 5px;
        padding-left: 10px;
        border-left: 1px solid #444;
    }

    #emoji-input {
        background: #333;
        border: 1px solid #555;
        color: white;
        border-radius: 20px;
        width: 80px;
        padding: 5px 10px;
        font-size: 1.2rem;
        text-align: center;
        margin: 0;
    }

    #emoji-input:focus {
        border-color: #ff0055;
        outline: none;
    }

    .send-btn {
        background: #ff0055;
        border: none;
        border-radius: 50%;
        width: 35px;
        height: 35px;
        color: white;
        font-size: 1rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
    }
</style>

<div class="container text-center">
    <?php echo renderMessage($msg, $msgClass); ?>

    <input type="text" id="uploader-name" placeholder="Dein Name (optional)"
        value="<?php echo e($prefilledName); ?>"
        style="text-align:center; font-size:1.2rem; max-width: 300px; border: 2px solid #333;">

    <div id="view-main">
        <h1><?php echo e($eventName); ?></h1>
        <p style="color:#888;">Das Bild landet direkt auf der Leinwand.</p>

        <form method="post" enctype="multipart/form-data" id="form-cam">
            <input type="hidden" name="uploader" class="hidden-uploader">
            <input type="hidden" name="device_uuid" class="hidden-device-id">

            <label for="inp-cam" class="btn btn-primary">Kamera öffnen 📸</label>
            <input id="inp-cam" type="file" name="image" accept="image/*" capture="environment" class="hidden"
                onchange="submitForm('form-cam', this)">
        </form>

        <form method="post" enctype="multipart/form-data" id="form-gal">
            <input type="hidden" name="uploader" class="hidden-uploader">
            <input type="hidden" name="device_uuid" class="hidden-device-id">

            <label for="inp-gal" class="btn btn-secondary"
                style="margin-top:15px; display:block; margin-left:auto; margin-right:auto; max-width:300px;">Aus
                Galerie wählen 🖼️</label>
            <input id="inp-gal" type="file" name="image" accept="image/*" multiple class="hidden"
                onchange="uploadPhotos(this)">
            <p style="color:#666; font-size:0.85rem; margin-top:8px;">Du kannst auch mehrere Fotos auf einmal auswählen.</p>
        </form>

        <div id="upload-box" class="hidden" aria-live="polite">
            <div id="upload-status" class="msg"></div>
            <div class="upload-progress"><div id="upload-bar"></div></div>
            <ul id="upload-errors"></ul>
            <button type="button" id="upload-retry" class="btn btn-secondary btn-small hidden" onclick="retryFailed()">🔁 Fehlgeschlagene erneut hochladen</button>
        </div>

        <?php if ($showBar): ?>
            <div style="margin-top: 40px;">
                <button onclick="toggleView()" class="btn btn-small"
                    style="background:#222; border:1px solid #444; color:#888;">🍸 Getränk einchecken</button>
            </div>
        <?php endif; ?>
    </div>

    <div id="view-bar" class="hidden">
        <h1>Was trinkst du?</h1>
        <p style="color:#888; font-size:0.9rem;">(Name für Leaderboard notwendig)</p>

        <?php if ($drinks->num_rows > 0): ?>
            <div class="drink-grid">
                <?php while ($d = $drinks->fetch_assoc()): ?>
                    <div class="drink-card" onclick="selectDrink(<?php echo (int) $d['id']; ?>, this)">
                        <img src="<?php echo e($d['image_path']); ?>" class="drink-img">
                        <div class="drink-name"><?php echo e($d['name']); ?></div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <p>Für dieses Event wurde noch keine Bar konfiguriert.</p>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="form-bar" class="hidden">
            <input type="hidden" name="uploader" class="hidden-uploader">
            <input type="hidden" name="drink_id" id="selected-drink-id">
            <input type="hidden" name="device_uuid" class="hidden-device-id">

            <label for="inp-bar-cam" class="btn btn-primary" style="margin-top:20px;">📸 Beweisfoto machen</label>
            <input id="inp-bar-cam" type="file" name="image" accept="image/*" capture="environment" class="hidden"
                onchange="submitForm('form-bar', this)">
        </form>

        <div style="margin-top: 30px;">
            <button onclick="toggleView()" class="btn btn-secondary btn-small">🔙 Zurück</button>
        </div>
    </div>
</div>

<div id="emoji-bar">
    <button type="button" class="quick-btn" onclick="sendReaction('❤️')">❤️</button>
    <button type="button" class="quick-btn" onclick="sendReaction('🔥')">🔥</button>
    <button type="button" class="quick-btn" onclick="sendReaction('🍻')">🍻</button>
    <form id="emoji-form" onsubmit="handleCustomEmoji(event)">
        <input type="text" id="emoji-input" placeholder="Emoji..." maxlength="5" autocomplete="off">
        <button type="submit" class="send-btn">🚀</button>
    </form>
</div>

<script>
    function getDeviceId() {
        let uuid = localStorage.getItem('fotobox_device_uuid');
        if (!uuid) {
            uuid = 'dev_' + Date.now().toString(36) + Math.random().toString(36).substr(2);
            localStorage.setItem('fotobox_device_uuid', uuid);
        }
        return uuid;
    }

    const deviceId = getDeviceId();
    document.querySelectorAll('.hidden-device-id').forEach(el => el.value = deviceId);

    const nameInput = document.getElementById('uploader-name');
    if (nameInput.value.trim() === "") {
        const storedName = localStorage.getItem('party_user');
        if (storedName) nameInput.value = storedName;
    } else {
        localStorage.setItem('party_user', nameInput.value);
    }

    nameInput.addEventListener('input', () => {
        localStorage.setItem('party_user', nameInput.value);
        nameInput.style.borderColor = "#333";
    });

    function submitForm(formId, inputEl) {
        if (inputEl.files.length === 0) return;

        if (formId === 'form-bar') {
            if (nameInput.value.trim() === "") {
                alert("Wer trinkt das? Bitte gib oben deinen Namen ein!");
                inputEl.value = "";
                nameInput.focus();
                nameInput.style.borderColor = "#ff0055";
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
                return;
            }
        }

        const form = document.getElementById(formId);
        form.querySelector('.hidden-uploader').value = nameInput.value;

        const label = form.querySelector('label');
        label.innerText = "⏳ Wird hochgeladen...";
        label.style.opacity = "0.7";

        form.submit();
    }

    // Multi-photo upload: one request per photo, sequentially, with progress.
    const MAX_PHOTOS_PER_SELECTION = 30;
    const UPLOAD_URL = 'index.php?event=' + encodeURIComponent(<?php echo json_encode($eventId); ?>);
    let failedFiles = [];
    let uploading = false;

    function uploadOne(file, onProgress) {
        return new Promise((resolve) => {
            const data = new FormData();
            data.append('image', file);
            data.append('uploader', nameInput.value);
            data.append('device_uuid', deviceId);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', UPLOAD_URL);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) onProgress(e.loaded / e.total);
            };
            xhr.onload = () => {
                let body = null;
                try {
                    body = JSON.parse(xhr.responseText);
                } catch (e) {}
                const fallback = xhr.status === 413 ? 'Das Bild ist zu groß.' : 'Upload fehlgeschlagen.';
                resolve({
                    ok: xhr.status === 200 && !!body && body.ok === true,
                    status: xhr.status,
                    message: (body && body.message) || fallback
                });
            };
            xhr.onerror = () => resolve({ ok: false, status: 0, message: 'Keine Verbindung – bitte erneut versuchen.' });
            xhr.send(data);
        });
    }

    function uploadPhotos(input) {
        let files = Array.from(input.files);
        input.value = '';
        if (files.length === 0 || uploading) return;

        const skipped = Math.max(0, files.length - MAX_PHOTOS_PER_SELECTION);
        runUploads(files.slice(0, MAX_PHOTOS_PER_SELECTION), skipped);
    }

    function retryFailed() {
        runUploads(failedFiles.slice(), 0);
    }

    async function runUploads(files, skipped) {
        const box = document.getElementById('upload-box');
        const status = document.getElementById('upload-status');
        const bar = document.getElementById('upload-bar');
        const errors = document.getElementById('upload-errors');
        const retry = document.getElementById('upload-retry');

        document.querySelectorAll('.container > .msg').forEach((el) => el.remove());
        box.classList.remove('hidden');
        retry.classList.add('hidden');
        errors.replaceChildren();
        status.className = 'msg';
        bar.style.width = '0';
        failedFiles = [];
        uploading = true;
        document.body.classList.add('uploading');

        let done = 0;
        let ok = 0;
        let blocked = false;
        for (const file of files) {
            status.textContent = `⏳ Foto ${done + 1} von ${files.length} wird hochgeladen…`;
            const result = await uploadOne(file, (p) => {
                bar.style.width = ((done + p) / files.length * 100) + '%';
            });
            done++;
            bar.style.width = (done / files.length * 100) + '%';

            if (result.ok) {
                ok++;
                continue;
            }
            if (result.status === 403) {
                // device is blocked: no point in trying the rest
                blocked = true;
                status.textContent = result.message;
                break;
            }
            failedFiles.push(file);
            const li = document.createElement('li');
            li.textContent = `${file.name}: ${result.message}`;
            errors.appendChild(li);
        }

        uploading = false;
        document.body.classList.remove('uploading');
        if (blocked) {
            status.className = 'msg error';
            return;
        }

        let text;
        if (failedFiles.length === 0) {
            text = ok === 1 ? 'Bild ist auf der Leinwand! 🥳' : `${ok} Bilder sind auf der Leinwand! 🥳`;
        } else {
            text = `${ok} von ${files.length} Bildern hochgeladen, ${failedFiles.length} fehlgeschlagen.`;
            retry.classList.remove('hidden');
        }
        if (skipped > 0) {
            text += ` ${skipped} weitere wurden nicht hochgeladen (max. ${MAX_PHOTOS_PER_SELECTION} pro Auswahl).`;
        }
        status.textContent = text;
        status.className = failedFiles.length === 0 && skipped === 0 ? 'msg success' : 'msg error';
    }

    window.addEventListener('beforeunload', (e) => {
        if (uploading) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    function toggleView() {
        const main = document.getElementById('view-main');
        const bar = document.getElementById('view-bar');

        main.classList.toggle('hidden');
        bar.classList.toggle('hidden');

        if (!bar.classList.contains('hidden')) {
            nameInput.placeholder = "Dein Name (Name für Leaderboard notwendig)";
            if (nameInput.value.trim() === "") nameInput.style.borderColor = "#ff0055";
        } else {
            nameInput.placeholder = "Dein Name (optional)";
            nameInput.style.borderColor = "#333";
        }
    }

    function selectDrink(id, el) {
        document.querySelectorAll('.drink-card').forEach(c => c.classList.remove('selected'));
        el.classList.add('selected');
        document.getElementById('selected-drink-id').value = id;
        document.getElementById('form-bar').classList.remove('hidden');
        el.scrollIntoView({
            behavior: "smooth",
            block: "center"
        });
    }

    function sendReaction(emoji) {
        if (event && event.target.classList.contains('quick-btn')) {
            const btn = event.target;
            btn.style.transform = "scale(1.5)";
            setTimeout(() => btn.style.transform = "scale(1)", 150);
        }
        const formData = new FormData();
        formData.append('emoji', emoji);
        fetch('reaction_api.php?action=send&event=' + encodeURIComponent(<?php echo json_encode($eventId); ?>), {
            method: 'POST',
            body: formData
        });
    }

    function handleCustomEmoji(e) {
        e.preventDefault();
        const input = document.getElementById('emoji-input');
        const val = input.value.trim();
        if (val) {
            sendReaction(val);
            input.value = "";
            input.focus();
        }
    }
</script>
</body>

</html>