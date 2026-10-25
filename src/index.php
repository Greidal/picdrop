<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/uploads.php';
require_once __DIR__ . '/lib/drinks.php';

$eventId = $_GET['event'] ?? '';
// The bare domain is for organizers: send them to the dashboard (login if needed).
// Guests always arrive with ?event=… from the QR code.
if ($eventId === '') {
    header("Location: admin.php");
    exit;
}
$eventName = getEventOrDie($conn, $eventId);

// load event settings: drink mode, strip location data from photos, date photos by capture time
$stmt2 = $conn->prepare("SELECT setting_show_bar, setting_strip_location, setting_use_capture_time FROM events WHERE uuid = ?");
$stmt2->bind_param("s", $eventId);
$stmt2->execute();
$row = $stmt2->get_result()->fetch_assoc();
$showBar = isset($row['setting_show_bar']) ? boolval($row['setting_show_bar']) : true;
$stripLocation = !empty($row['setting_strip_location']);
$useCaptureTime = !empty($row['setting_use_capture_time']);

$prefilledName = "";
if (isLoggedIn()) {
    $prefilledName = $_SESSION['username'] ?? '';
}

$stmt = $conn->prepare("SELECT id, name, image_path, volume_ml, abv FROM drinks WHERE event_uuid = ? ORDER BY id");
$stmt->bind_param("s", $eventId);
$stmt->execute();
$drinks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

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
        $result = handleGuestUpload($conn, $eventId, $_FILES['image'] ?? [], $_POST, $stripLocation, $useCaptureTime);
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
        gap: 12px;
        max-width: 420px;
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
        padding: 0;
        color: inherit;
        font: inherit;
        width: 100%;
    }

    .drink-card:active {
        transform: scale(0.97);
    }

    .drink-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        filter: brightness(0.75);
    }

    .drink-emoji {
        font-size: 3rem;
        display: flex;
        align-items: center;
        justify-content: center;
        height: 80%;
    }

    .drink-name {
        position: absolute;
        bottom: 0;
        width: 100%;
        background: rgba(0, 0, 0, 0.72);
        text-align: center;
        padding: 5px 0;
        font-weight: bold;
        font-size: 0.9rem;
        line-height: 1.2;
    }

    .drink-name small {
        display: block;
        font-weight: normal;
        font-size: 0.72rem;
        color: #aaa;
    }

    /* Check-in sheet */
    .sheet-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.7);
        z-index: 1500;
        display: flex;
        align-items: flex-end;
        justify-content: center;
        backdrop-filter: blur(4px);
    }

    .sheet {
        background: #1a1a1a;
        width: 100%;
        max-width: 520px;
        max-height: 92vh;
        overflow-y: auto;
        border-radius: 20px 20px 0 0;
        border: 1px solid #333;
        border-bottom: none;
        padding: 10px 20px calc(20px + env(safe-area-inset-bottom));
        box-sizing: border-box;
        text-align: left;
        animation: sheetUp 0.22s ease-out;
    }

    @keyframes sheetUp {
        from {
            transform: translateY(40px);
            opacity: 0;
        }
    }

    .sheet-grip {
        width: 40px;
        height: 4px;
        background: #444;
        border-radius: 2px;
        margin: 0 auto 12px;
    }

    .sheet h2 {
        margin: 0 0 4px;
        font-weight: 600;
    }

    .sheet-label {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        margin: 16px 0 6px;
        font-size: 0.9rem;
        color: #aaa;
    }

    .sheet-label output {
        color: white;
        font-weight: bold;
        font-size: 1.05rem;
    }

    .containers {
        display: flex;
        gap: 6px;
        overflow-x: auto;
        padding-bottom: 4px;
        scrollbar-width: none;
    }

    .containers button {
        flex-shrink: 0;
        background: #222;
        border: 1px solid #3a3a3a;
        color: #ddd;
        border-radius: 12px;
        padding: 6px 10px;
        font-size: 0.8rem;
        cursor: pointer;
        line-height: 1.25;
    }

    .containers button b {
        display: block;
        font-size: 0.95rem;
    }

    .containers button.active {
        border-color: var(--primary);
        background: rgba(255, 0, 85, 0.15);
    }

    .people-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .people-chips button {
        background: #222;
        border: 1px solid #3a3a3a;
        color: #ddd;
        border-radius: 20px;
        padding: 7px 12px;
        font-size: 0.88rem;
        cursor: pointer;
    }

    .people-chips button.selected {
        border-color: var(--success);
        background: rgba(0, 255, 136, 0.12);
        color: white;
    }

    .people-chips button.selected::before {
        content: "✓ ";
        color: var(--success);
    }

    .sheet-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-top: 22px;
    }

    .sheet-actions .btn {
        max-width: none;
        width: 100%;
        font-size: 1rem;
        padding: 14px 10px;
        box-sizing: border-box;
    }

    .grams-hint {
        font-size: 0.8rem;
        color: #888;
        margin-top: 6px;
    }

    .drink-links {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
        margin: 40px auto 0;
        /* stay clear of the fixed emoji bar */
        padding-bottom: 90px;
    }

    .drink-links .btn {
        width: 100%;
        max-width: 300px;
        box-sizing: border-box;
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

<?php
require_once __DIR__ . '/lib/guest_nav.php';
renderGuestCommon($eventId);
?>

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
            <div class="drink-links">
                <a href="#drink" class="btn btn-secondary">🍺 Getränk einchecken</a>
                <a href="pegel.php?event=<?php echo e(urlencode($eventId)); ?>" class="btn btn-secondary">📈 Mein Pegel</a>
            </div>
        <?php endif; ?>

    </div>

    <?php if ($showBar): ?>
        <div id="view-bar" class="hidden">
            <h1>Was trinkst du?</h1>
            <p style="color:#888; font-size:0.9rem;">Antippen, Menge wählen, einchecken – auch für deine Runde.</p>

            <?php if ($drinks): ?>
                <div class="drink-grid">
                    <?php foreach ($drinks as $d): ?>
                        <button type="button" class="drink-card" aria-label="<?php echo e($d['name']); ?> einchecken" data-id="<?php echo (int) $d['id']; ?>" data-name="<?php echo e($d['name']); ?>"
                            data-ml="<?php echo (int) $d['volume_ml']; ?>" data-abv="<?php echo e((float) $d['abv']); ?>" onclick="openCheckin(this.dataset)">
                            <?php if ($d['image_path']): ?>
                                <img src="<?php echo e($d['image_path']); ?>" class="drink-img" alt="">
                            <?php else: ?>
                                <div class="drink-emoji">🍹</div>
                            <?php endif; ?>
                            <div class="drink-name"><?php echo e($d['name']); ?>
                                <small><?php echo (int) $d['volume_ml']; ?> ml · <?php echo e(str_replace('.', ',', (string) (float) $d['abv'])); ?> %</small>
                            </div>
                        </button>
                    <?php endforeach; ?>
                </div>
                <a href="pegel.php?event=<?php echo e(urlencode($eventId)); ?>" class="btn btn-secondary btn-small" style="margin-top:6px;">📈 Mein Pegel</a>
            <?php else: ?>
                <p>Für dieses Event wurde noch keine Getränkekarte angelegt.</p>
            <?php endif; ?>
            <div style="margin-top: 24px;">
                <a href="#foto" class="btn btn-secondary btn-small">🔙 Zurück</a>
            </div>
        </div>

        <div id="checkin-sheet" class="sheet-backdrop hidden" onclick="if (event.target === this) closeCheckin()">
            <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="ci-title">
                <div class="sheet-grip"></div>
                <h2 id="ci-title">Getränk</h2>
                <div class="grams-hint" id="ci-grams"></div>

                <div class="sheet-label">Gefäß</div>
                <div class="containers" id="ci-containers">
                    <?php foreach (DRINK_CONTAINERS as [$label, $ml]): ?>
                        <button type="button" data-ml="<?php echo (int) $ml; ?>"><b><?php echo e($ml >= 1000 ? str_replace('.', ',', (string) ($ml / 1000)) . ' l' : $ml . ' ml'); ?></b><?php echo e($label); ?></button>
                    <?php endforeach; ?>
                </div>

                <label class="sheet-label" for="ci-ml">Menge <output id="ci-ml-out"></output></label>
                <input type="range" id="ci-ml" min="10" max="1500" step="10">

                <label class="sheet-label" for="ci-abv">Alkoholgehalt <output id="ci-abv-out"></output></label>
                <input type="range" id="ci-abv" min="0" max="80" step="0.5">

                <div class="sheet-label">Für wen? <small>mehrere = Runde 🎁</small></div>
                <div class="people-chips" id="ci-people">
                    <button type="button" class="selected" data-self="1">Ich</button>
                </div>

                <div class="sheet-actions">
                    <button type="button" class="btn btn-secondary" id="ci-photo-btn" onclick="document.getElementById('ci-photo').click()">📸 Mit Foto</button>
                    <button type="button" class="btn btn-primary" id="ci-submit" onclick="submitCheckin()">✅ Einchecken</button>
                </div>
                <input type="file" id="ci-photo" accept="image/*" capture="environment" class="hidden" onchange="submitCheckin(this.files[0])">
                <button type="button" class="btn btn-secondary btn-small" style="margin:16px auto 0; display:block;" onclick="closeCheckin()">Abbrechen</button>
            </div>
        </div>
    <?php endif; ?>
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
    const deviceId = Guest.deviceId();
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

    // --- Views: photos / drink check-in (switched via #foto / #drink) ---
    function showView() {
        const bar = document.getElementById('view-bar');
        if (!bar) return;
        const drink = location.hash === '#drink';
        document.getElementById('view-main').classList.toggle('hidden', drink);
        bar.classList.toggle('hidden', !drink);
        document.getElementById('emoji-bar').classList.toggle('hidden', drink);
        nameInput.placeholder = drink ? "Dein Name (für Ranking & Pegel nötig)" : "Dein Name (optional)";
    }
    window.addEventListener('hashchange', showView);
    showView();

    // --- Check-in sheet ---
    const ci = {
        drink: null,
        ml: document.getElementById('ci-ml'),
        abv: document.getElementById('ci-abv'),
        guestsLoaded: false,
        busy: false
    };

    function formatMl(ml) {
        return ml >= 1000 ? (ml / 1000).toLocaleString('de-DE') + ' l' : ml + ' ml';
    }

    function updateCheckinLabels() {
        const ml = Number(ci.ml.value);
        const abv = Number(ci.abv.value);
        document.getElementById('ci-ml-out').textContent = formatMl(ml);
        document.getElementById('ci-abv-out').textContent = abv.toLocaleString('de-DE', { minimumFractionDigits: 1 }) + ' %';
        const grams = ml * abv / 100 * 0.789;
        document.getElementById('ci-grams').textContent = grams > 0 ? `≈ ${grams.toLocaleString('de-DE', { maximumFractionDigits: 1 })} g reiner Alkohol` : 'alkoholfrei';
        document.querySelectorAll('#ci-containers button').forEach((b) => b.classList.toggle('active', Number(b.dataset.ml) === ml));
    }

    function openCheckin(drink) {
        if (!ensureName()) return;
        ci.drink = drink;
        document.getElementById('ci-title').textContent = drink.name;
        ci.ml.value = drink.ml;
        ci.abv.value = drink.abv;
        updateCheckinLabels();
        document.getElementById('checkin-sheet').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        loadGuests();
    }

    function closeCheckin() {
        document.getElementById('checkin-sheet').classList.add('hidden');
        document.body.style.overflow = '';
    }

    function ensureName() {
        if (nameInput.value.trim() !== '') return true;
        Guest.toast('Wer trinkt? Bitte gib oben deinen Namen ein!', true);
        nameInput.focus();
        nameInput.style.borderColor = "#ff0055";
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return false;
    }

    async function loadGuests() {
        if (ci.guestsLoaded) return;
        try {
            const data = await Guest.api('guests');
            const box = document.getElementById('ci-people');
            data.guests.forEach((g) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.dataset.ref = g.ref;
                b.textContent = g.name;
                box.appendChild(b);
            });
            ci.guestsLoaded = true;
        } catch (e) {}
    }

    if (ci.ml) {
        ci.ml.addEventListener('input', updateCheckinLabels);
        ci.abv.addEventListener('input', updateCheckinLabels);
        document.getElementById('ci-containers').addEventListener('click', (e) => {
            const b = e.target.closest('button');
            if (!b) return;
            ci.ml.value = b.dataset.ml;
            updateCheckinLabels();
        });
        document.getElementById('ci-people').addEventListener('click', (e) => {
            const b = e.target.closest('button');
            if (b) b.classList.toggle('selected');
        });
    }

    async function submitCheckin(photo) {
        if (ci.busy || !ensureName()) return;
        const chips = [...document.querySelectorAll('#ci-people button.selected')];
        const self = chips.some((b) => b.dataset.self);
        const round = chips.filter((b) => b.dataset.ref).map((b) => b.dataset.ref);
        if (!self && round.length === 0) {
            Guest.toast('Für wen ist das Getränk? Wähle mindestens eine Person.', true);
            return;
        }

        const data = new FormData();
        data.append('name', nameInput.value.trim());
        if (photo) data.append('image', photo);
        ci.busy = true;
        const submit = document.getElementById(photo ? 'ci-photo-btn' : 'ci-submit');
        const label = submit.textContent;
        submit.textContent = '⏳ …';
        try {
            const res = await Guest.api('checkin', {
                drink_id: ci.drink.id,
                volume_ml: ci.ml.value,
                abv: ci.abv.value,
                self: self ? '1' : '0',
                round
            }, data);
            closeCheckin();
            Guest.toast(res.message);
        } catch (e) {
            Guest.toast(e.message, true);
        } finally {
            ci.busy = false;
            submit.textContent = label;
            document.getElementById('ci-photo').value = '';
        }
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