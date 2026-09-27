<?php
require_once __DIR__ . '/images.php';
require_once __DIR__ . '/metadata.php';

/**
 * Processes a single guest upload for an event: device ban, drink check-in,
 * content validation, optional GPS removal, database row and image variants.
 *
 * Used by the classic form post and by the multi-photo upload (one request per photo).
 *
 * @param array $file  one entry of $_FILES
 * @param array $input uploader, drink_id, device_uuid (from $_POST)
 * @return array{ok: bool, status: int, message: string}
 */
function handleGuestUpload(mysqli $conn, string $eventId, array $file, array $input, bool $stripLocation): array
{
    $deviceUuid = mb_substr(trim((string) ($input['device_uuid'] ?? '')), 0, 64);
    if ($deviceUuid !== '') {
        $banCheck = $conn->prepare("SELECT id FROM blocked_devices WHERE event_uuid = ? AND device_uuid = ?");
        $banCheck->bind_param("ss", $eventId, $deviceUuid);
        $banCheck->execute();
        if ($banCheck->get_result()->num_rows > 0) {
            return uploadResult(false, 403, "⛔ Dein Gerät wurde für dieses Event gesperrt. Wende dich an den Organisator der Veranstaltung.");
        }
    }

    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return uploadResult(false, 400, "Kein Bild ausgewählt.");
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return uploadResult(false, 413, "Das Bild ist zu groß.");
    }
    if ($error !== UPLOAD_ERR_OK) {
        return uploadResult(false, 400, "Upload Fehler Code: " . $error);
    }

    $uploader = mb_substr(trim((string) ($input['uploader'] ?? '')), 0, 100);
    $drinkId = !empty($input['drink_id']) ? intval($input['drink_id']) : null;

    if ($drinkId) {
        // Only accept drinks that belong to this event.
        $drinkCheck = $conn->prepare("SELECT id FROM drinks WHERE id = ? AND event_uuid = ?");
        $drinkCheck->bind_param("is", $drinkId, $eventId);
        $drinkCheck->execute();
        if ($drinkCheck->get_result()->num_rows === 0) {
            $drinkId = null;
        }
    }
    if ($drinkId && $uploader === '') {
        return uploadResult(false, 400, "Wer trinkt das? Bitte Namen angeben!");
    }

    $storedPath = storeUploadedImage($file, dirname(__DIR__) . '/uploads/' . $eventId);
    if ($storedPath === null) {
        return uploadResult(false, 415, "Das ist leider kein gültiges Bild (erlaubt: JPG, PNG, WebP, GIF, HEIC).");
    }

    $fileName = basename($storedPath);
    if ($stripLocation && stripLocationMetadata($storedPath) === METADATA_FAILED) {
        error_log("PicDrop: could not strip location data from $storedPath");
    }
    $deviceParam = $deviceUuid !== '' ? $deviceUuid : null;
    $stmt = $conn->prepare("INSERT INTO uploads (event_id, device_uuid, filename, uploader_name, drink_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssi", $eventId, $deviceParam, $fileName, $uploader, $drinkId);
    $stmt->execute();

    createAllImageVariants($eventId, $fileName);

    return uploadResult(true, 200, $drinkId ? "Prost! 🍻 Check-in erledigt!" : "Bild ist auf der Leinwand! 🥳");
}

/** @return array{ok: bool, status: int, message: string} */
function uploadResult(bool $ok, int $status, string $message): array
{
    return ['ok' => $ok, 'status' => $status, 'message' => $message];
}
