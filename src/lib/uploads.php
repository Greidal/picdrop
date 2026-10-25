<?php
require_once __DIR__ . '/images.php';
require_once __DIR__ . '/metadata.php';
require_once __DIR__ . '/drinks.php';

/**
 * Processes a single guest upload for an event: device ban, drink check-in,
 * content validation, optional GPS removal, database row and image variants.
 *
 * Used by the classic form post and by the multi-photo upload (one request per photo).
 *
 * @param array $file  one entry of $_FILES
 * @param array $input uploader, drink_id, device_uuid, optional volume_ml/abv (from $_POST)
 * @param bool $useCaptureTime date photos by their EXIF capture time instead of the upload time
 * @return array{ok: bool, status: int, message: string, upload_id?: int}
 */
function handleGuestUpload(mysqli $conn, string $eventId, array $file, array $input, bool $stripLocation, bool $useCaptureTime = false): array
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
    // Drink photos are always "now"; the capture time is only meant for photos added later.
    $takenAt = $useCaptureTime && !$drinkId ? photoCaptureTime($storedPath) : null;
    $takenAt ??= time();
    $stmt = $conn->prepare("INSERT INTO uploads (event_id, device_uuid, filename, uploader_name, drink_id, timestamp) VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?))");
    $stmt->bind_param("ssssii", $eventId, $deviceParam, $fileName, $uploader, $drinkId, $takenAt);
    $stmt->execute();
    $uploadId = (int) $conn->insert_id;

    if ($drinkId && ($input['checkin_self'] ?? '1') !== '0') {
        $volume = isset($input['volume_ml']) && $input['volume_ml'] !== '' ? (int) $input['volume_ml'] : null;
        $abv = isset($input['abv']) && $input['abv'] !== '' ? (float) $input['abv'] : null;
        drinkCheckin($conn, $eventId, $deviceParam ?? '', $uploader, $drinkId, $volume, $abv, true, [], $uploadId);
    }

    createAllImageVariants($eventId, $fileName);

    $result = uploadResult(true, 200, $drinkId ? "Prost! 🍻 Check-in erledigt!" : "Bild ist auf der Leinwand! 🥳");
    $result['upload_id'] = $uploadId;
    return $result;
}

/** Capture time from the EXIF data (JPEG), interpreted in APP_TIMEZONE; null if missing or implausible. */
function photoCaptureTime(string $path): ?int
{
    if (!function_exists('exif_read_data')) {
        return null;
    }
    $exif = @exif_read_data($path, 'EXIF');
    $raw = is_array($exif) ? ($exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null) : null;
    if (!is_string($raw)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('Y:m:d H:i:s', trim($raw), new DateTimeZone(APP_TIMEZONE));
    if ($date === false) {
        return null;
    }
    $ts = $date->getTimestamp();
    // Wrong camera clocks: nothing from the future or before digital cameras were common.
    return $ts > time() + 300 || $ts < 946684800 ? null : $ts;
}

/** @return array{ok: bool, status: int, message: string} */
function uploadResult(bool $ok, int $status, string $message): array
{
    return ['ok' => $ok, 'status' => $status, 'message' => $message];
}
