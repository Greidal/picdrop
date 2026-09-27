<?php
/*
 * Lossless removal of location data from uploaded photos.
 *
 * The image data itself is never re-encoded:
 * - JPEG: the GPS IFD inside the EXIF block is emptied (all other EXIF data such
 *   as orientation and capture time is kept); XMP blocks are dropped.
 * - PNG:  eXIf chunks and XMP text chunks are dropped.
 * - WebP: EXIF and XMP chunks are dropped.
 * Other formats (e.g. HEIC) are left untouched and reported as unsupported.
 */

const METADATA_STRIPPED = 'stripped';
const METADATA_UNSUPPORTED = 'unsupported';
const METADATA_FAILED = 'failed';

/** Removes location metadata from the file in place. Returns one of the METADATA_* constants. */
function stripLocationMetadata(string $path): string
{
    $data = @file_get_contents($path);
    if ($data === false) {
        return METADATA_FAILED;
    }

    if (str_starts_with($data, "\xFF\xD8")) {
        $result = stripJpegLocation($data);
    } elseif (str_starts_with($data, "\x89PNG\r\n\x1A\n")) {
        $result = stripPngLocation($data);
    } elseif (substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') {
        $result = stripWebpLocation($data);
    } else {
        return METADATA_UNSUPPORTED;
    }

    if ($result === null) {
        return METADATA_FAILED;
    }
    if ($result !== $data && !writeFileAtomically($path, $result)) {
        return METADATA_FAILED;
    }
    return METADATA_STRIPPED;
}

/** Strips location data from all photos of an event. Returns [stripped, unsupported, failed] counts. */
function stripLocationFromEvent(mysqli $conn, string $eventUuid): array
{
    $counts = [METADATA_STRIPPED => 0, METADATA_UNSUPPORTED => 0, METADATA_FAILED => 0];
    $stmt = $conn->prepare("SELECT filename FROM uploads WHERE event_id = ?");
    $stmt->bind_param("s", $eventUuid);
    $stmt->execute();
    foreach ($stmt->get_result() as $row) {
        $path = dirname(__DIR__) . "/uploads/$eventUuid/" . basename($row['filename']);
        if (is_file($path)) {
            $counts[stripLocationMetadata($path)]++;
        }
    }
    return $counts;
}

function writeFileAtomically(string $path, string $contents): bool
{
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $contents) !== strlen($contents)) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, fileperms($path) & 0777);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** @return string|null the cleaned JPEG, or null if the file structure is invalid */
function stripJpegLocation(string $data): ?string
{
    $out = "\xFF\xD8";
    $pos = 2;
    $len = strlen($data);

    while ($pos + 4 <= $len) {
        if ($data[$pos] !== "\xFF") {
            return null;
        }
        $marker = ord($data[$pos + 1]);

        // Start of scan: the rest is entropy-coded image data, copy verbatim.
        if ($marker === 0xDA) {
            return $out . substr($data, $pos);
        }
        // Markers without a length field.
        if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
            $out .= substr($data, $pos, 2);
            $pos += 2;
            continue;
        }

        $segLen = unpack('n', $data, $pos + 2)[1];
        if ($segLen < 2 || $pos + 2 + $segLen > $len) {
            return null;
        }
        $segment = substr($data, $pos, 2 + $segLen);
        $payload = substr($segment, 4);

        if ($marker === 0xE1 && str_starts_with($payload, "Exif\0\0")) {
            $tiff = clearExifGps(substr($payload, 6));
            if ($tiff === null) {
                return null;
            }
            $segment = substr($segment, 0, 10) . $tiff;
        } elseif ($marker === 0xE1 && (
            str_starts_with($payload, "http://ns.adobe.com/xap/1.0/\0")
            || str_starts_with($payload, "http://ns.adobe.com/xmp/extension/\0")
        )) {
            $segment = ''; // drop XMP (may contain exif:GPS* properties)
        }

        $out .= $segment;
        $pos += 2 + $segLen;
    }

    return null; // no image data found
}

/**
 * Empties the GPS IFD of a TIFF/EXIF structure without moving any offsets:
 * the entry count is set to 0 and all GPS entries and their values are zeroed.
 */
function clearExifGps(string $tiff): ?string
{
    $len = strlen($tiff);
    if ($len < 8) {
        return null;
    }
    $order = substr($tiff, 0, 2);
    if ($order !== 'II' && $order !== 'MM') {
        return null;
    }
    $u16 = $order === 'II' ? 'v' : 'n';
    $u32 = $order === 'II' ? 'V' : 'N';
    $read16 = static fn (int $o): ?int => $o + 2 <= $len ? unpack($u16, $tiff, $o)[1] : null;
    $read32 = static fn (int $o): ?int => $o + 4 <= $len ? unpack($u32, $tiff, $o)[1] : null;

    $ifd0 = $read32(4);
    $count = $ifd0 === null ? null : $read16($ifd0);
    if ($count === null || $ifd0 + 2 + $count * 12 > $len) {
        return null;
    }

    $gpsOffset = null;
    for ($i = 0; $i < $count; $i++) {
        $entry = $ifd0 + 2 + $i * 12;
        if ($read16($entry) === 0x8825) {
            $gpsOffset = $read32($entry + 8);
            break;
        }
    }
    if ($gpsOffset === null) {
        return $tiff; // no GPS data
    }

    $gpsCount = $read16($gpsOffset);
    if ($gpsCount === null || $gpsOffset + 2 + $gpsCount * 12 > $len) {
        return null;
    }

    // Byte size per TIFF field type.
    $typeSizes = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8];
    for ($i = 0; $i < $gpsCount; $i++) {
        $entry = $gpsOffset + 2 + $i * 12;
        $size = ($typeSizes[$read16($entry + 2)] ?? 0) * ($read32($entry + 4) ?? 0);
        if ($size > 4) {
            $valueOffset = $read32($entry + 8);
            if ($valueOffset !== null && $valueOffset + $size <= $len) {
                $tiff = substr_replace($tiff, str_repeat("\0", $size), $valueOffset, $size);
            }
        }
        $tiff = substr_replace($tiff, str_repeat("\0", 12), $entry, 12);
    }
    // Empty IFD: zero entries; the (now zeroed) following bytes read as "no next IFD".
    return substr_replace($tiff, "\0\0", $gpsOffset, 2);
}

function stripPngLocation(string $data): ?string
{
    $out = substr($data, 0, 8);
    $pos = 8;
    $len = strlen($data);

    while ($pos + 12 <= $len) {
        $chunkLen = unpack('N', $data, $pos)[1];
        $type = substr($data, $pos + 4, 4);
        $total = 12 + $chunkLen;
        if ($pos + $total > $len) {
            return null;
        }
        $body = substr($data, $pos + 8, min($chunkLen, 64));
        $isXmp = in_array($type, ['iTXt', 'tEXt', 'zTXt'], true) && str_starts_with($body, "XML:com.adobe.xmp\0");

        if ($type !== 'eXIf' && !$isXmp) {
            $out .= substr($data, $pos, $total);
        }
        $pos += $total;
        if ($type === 'IEND') {
            return $out;
        }
    }
    return null;
}

function stripWebpLocation(string $data): ?string
{
    $out = '';
    $pos = 12;
    $len = strlen($data);

    while ($pos + 8 <= $len) {
        $fourcc = substr($data, $pos, 4);
        $chunkLen = unpack('V', $data, $pos + 4)[1];
        $total = 8 + $chunkLen + ($chunkLen & 1);
        if ($pos + 8 + $chunkLen > $len) {
            return null;
        }
        $chunk = substr($data, $pos, $total);

        if ($fourcc === 'VP8X' && $chunkLen >= 1) {
            // Clear the EXIF (0x08) and XMP (0x04) flags.
            $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
        }
        if ($fourcc !== 'EXIF' && $fourcc !== 'XMP ') {
            $out .= $chunk;
        }
        $pos += $total;
    }

    return 'RIFF' . pack('V', 4 + strlen($out)) . 'WEBP' . $out;
}
