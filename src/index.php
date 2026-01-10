<?php
require_once 'auth.php';
require_once 'db.php';

$eventId = $_GET['event'] ?? '';
$eventName = getEventOrDie($conn, $eventId);

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
    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $error = $_FILES['image']['error'];

        if ($error === UPLOAD_ERR_OK) {
            $uploader = htmlspecialchars(trim($_POST['uploader'] ?? ''));
            $drinkId = !empty($_POST['drink_id']) ? intval($_POST['drink_id']) : null;

            if ($drinkId && empty($uploader)) {
                $msg = "Wer trinkt das? Bitte Namen angeben!";
                $msgClass = "error";
            } else {
                $uploadDir = 'uploads/' . $eventId . '/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $fileName = time() . '_' . uniqid() . '.jpg';
                $targetFile = $uploadDir . $fileName;

                if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
                    $stmt = $conn->prepare("INSERT INTO uploads (event_id, filename, uploader_name, drink_id) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("sssi", $eventId, $fileName, $uploader, $drinkId);

                    if ($stmt->execute()) {
                        $txt = $drinkId ? "Prost! 🍻 Check-in erledigt!" : "Bild ist auf der Leinwand! 🥳";
                        setFlashMessage($txt, "success");

                        header("Location: index.php?event=" . $eventId);
                        exit;
                    } else {
                        $msg = "Datenbank-Fehler.";
                        $msgClass = "error";
                    }
                } else {
                    $msg = "Fehler beim Speichern.";
                    $msgClass = "error";
                }
            }
        } else {
            $msg = "Upload Fehler Code: " . $error;
            $msgClass = "error";
        }
    }
}

$pageTitle = $eventName;
require 'header.php';
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
</style>

<div class="container text-center">
    <?php if ($msg): ?><div class="msg <?php echo $msgClass; ?>"><?php echo $msg; ?></div><?php endif; ?>

    <input type="text" id="uploader-name"
        placeholder="Dein Name (optional)"
        value="<?php echo htmlspecialchars($prefilledName); ?>"
        style="text-align:center; font-size:1.2rem; max-width: 300px; border: 2px solid #333;">

    <div id="view-main">
        <h1><?php echo htmlspecialchars($eventName); ?></h1>
        <p style="color:#888;">Das Bild landet direkt auf der Leinwand.</p>

        <form method="post" enctype="multipart/form-data" id="form-cam">
            <input type="hidden" name="uploader" class="hidden-uploader">
            <label for="inp-cam" class="btn btn-primary">Kamera öffnen 📸</label>
            <input id="inp-cam" type="file" name="image" accept="image/*" capture="environment" class="hidden" onchange="submitForm('form-cam', this)">
        </form>

        <form method="post" enctype="multipart/form-data" id="form-gal">
            <input type="hidden" name="uploader" class="hidden-uploader">
            <label for="inp-gal" class="btn btn-secondary" style="margin-top:15px; display:block; margin-left:auto; margin-right:auto; max-width:300px;">Aus Galerie wählen 🖼️</label>
            <input id="inp-gal" type="file" name="image" accept="image/*" class="hidden" onchange="submitForm('form-gal', this)">
        </form>

        <div style="margin-top: 40px;">
            <button onclick="toggleView()" class="btn btn-small" style="background:#222; border:1px solid #444; color:#888;">🍸 Getränk einchecken</button>
        </div>
    </div>

    <div id="view-bar" class="hidden">
        <h1>Was trinkst du?</h1>
        <p style="color:#888; font-size:0.9rem;">(Name für Leaderboard notwendig)</p>

        <?php if ($drinks->num_rows > 0): ?>
            <div class="drink-grid">
                <?php while ($d = $drinks->fetch_assoc()): ?>
                    <div class="drink-card" onclick="selectDrink(<?php echo $d['id']; ?>, this)">
                        <img src="<?php echo htmlspecialchars($d['image_path']); ?>" class="drink-img">
                        <div class="drink-name"><?php echo htmlspecialchars($d['name']); ?></div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <p>Für dieses Event wurde noch keine Bar konfiguriert.</p>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="form-bar" class="hidden">
            <input type="hidden" name="uploader" class="hidden-uploader">
            <input type="hidden" name="drink_id" id="selected-drink-id">

            <label for="inp-bar-cam" class="btn btn-primary" style="margin-top:20px;">📸 Beweisfoto machen</label>
            <input id="inp-bar-cam" type="file" name="image" accept="image/*" capture="environment" class="hidden" onchange="submitForm('form-bar', this)">
        </form>

        <div style="margin-top: 30px;">
            <button onclick="toggleView()" class="btn btn-secondary btn-small">🔙 Zurück</button>
        </div>
    </div>
</div>

<script>
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
</script>
</body>

</html>