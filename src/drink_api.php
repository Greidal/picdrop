<?php
/*
 * JSON API of the drink mode for the public guest pages (index.php, pegel.php, story.php).
 * Guests have no account: like photo uploads, requests are identified by the device id the
 * browser keeps in localStorage and are not CSRF-protected by design (see AGENTS.md).
 */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/uploads.php';
require_once __DIR__ . '/lib/drinks.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Method not allowed'], 405);
}

$eventId = $_GET['event'] ?? '';
if (!isValidUuid($eventId)) {
    respond(['error' => 'Ungültiges Event'], 400);
}
$stmt = $conn->prepare("SELECT name, setting_show_bar, setting_merge_by_device, setting_strip_location FROM events WHERE uuid = ?");
$stmt->bind_param("s", $eventId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
if (!$event) {
    respond(['error' => 'Event nicht gefunden'], 404);
}
if (!$event['setting_show_bar']) {
    respond(['error' => 'Der Getränkemodus ist für dieses Event ausgeschaltet.'], 403);
}

$device = mb_substr(trim((string) ($_POST['device_uuid'] ?? '')), 0, 64);
if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $device)) {
    respond(['error' => 'Gerät unbekannt – bitte Seite neu laden.'], 400);
}
$name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);

switch ($_GET['action'] ?? '') {
    case 'state':
        respond(guestState($conn, $eventId, $device, (bool) $event['setting_merge_by_device']));

    case 'guests':
        $guests = [];
        foreach (drinkGuests($conn, $eventId) as $ref => $g) {
            if ($g['device'] !== $device) {
                $guests[] = ['ref' => $ref, 'name' => $g['name']];
            }
        }
        respond(['guests' => $guests]);

    case 'checkin':
        if (isDeviceBlocked($conn, $eventId, $device)) {
            respond(['error' => '⛔ Dein Gerät wurde für dieses Event gesperrt.'], 403);
        }
        if ($name === '') {
            respond(['error' => 'Wer trinkt das? Bitte gib deinen Namen ein!'], 400);
        }
        $drinkId = (int) ($_POST['drink_id'] ?? 0);
        if (drinkDefaults($conn, $eventId, $drinkId) === null) {
            respond(['error' => 'Dieses Getränk gibt es hier nicht.'], 400);
        }
        $volume = isset($_POST['volume_ml']) ? (int) $_POST['volume_ml'] : null;
        $abv = isset($_POST['abv']) ? (float) $_POST['abv'] : null;
        $forSelf = ($_POST['self'] ?? '1') !== '0';
        $round = array_slice(array_map('strval', (array) ($_POST['round'] ?? [])), 0, 50);
        if (!$forSelf && !$round) {
            respond(['error' => 'Für wen ist das Getränk?'], 400);
        }

        $count = 0;
        if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            // The photo belongs to the person checking in; only their row links to it.
            $input = ['uploader' => $name, 'drink_id' => $drinkId, 'device_uuid' => $device,
                'volume_ml' => $volume, 'abv' => $abv, 'checkin_self' => $forSelf ? '1' : '0'];
            $result = handleGuestUpload($conn, $eventId, $_FILES['image'], $input, (bool) $event['setting_strip_location']);
            if (!$result['ok']) {
                respond(['error' => $result['message']], $result['status']);
            }
            $count += $forSelf ? 1 : 0;
            $count += drinkCheckin($conn, $eventId, $device, $name, $drinkId, $volume, $abv, false, $round);
        } else {
            $count = drinkCheckin($conn, $eventId, $device, $name, $drinkId, $volume, $abv, $forSelf, $round);
        }
        rememberName($conn, $eventId, $device, $name);

        $others = $count - ($forSelf ? 1 : 0);
        $message = $others > 0
            ? ($forSelf ? "Prost! 🍻 Runde für dich und $others weitere eingecheckt." : "Runde für $others " . ($others === 1 ? 'Person' : 'Personen') . " eingecheckt 🎁")
            : "Prost! 🍻 Check-in erledigt!";
        respond(['ok' => true, 'message' => $message, 'count' => $count]);

    case 'delete_checkin':
        // Guests can undo their own check-ins (also drinks someone else gave them).
        $id = (int) ($_POST['id'] ?? 0);
        $del = $conn->prepare("DELETE FROM checkins WHERE id = ? AND event_uuid = ? AND device_uuid = ?");
        $del->bind_param("iss", $id, $eventId, $device);
        $del->execute();
        respond(['ok' => $del->affected_rows > 0]);

    case 'profile':
        $changes = [];
        if (isset($_POST['weight_kg'])) {
            $changes['weight_kg'] = (float) str_replace(',', '.', (string) $_POST['weight_kg']);
        }
        if (isset($_POST['sex'])) {
            $changes['sex'] = $_POST['sex'] === 'f' ? 'f' : 'm';
        }
        if ($name !== '') {
            $changes['name'] = $name;
        }
        if (isset($_POST['new_team']) && trim((string) $_POST['new_team']) !== '') {
            $changes['team_id'] = drinkCreateTeam($conn, $eventId, (string) $_POST['new_team']);
        } elseif (isset($_POST['team_id'])) {
            $teamId = (int) $_POST['team_id'];
            $valid = array_filter(drinkTeams($conn, $eventId), static fn ($t) => $t['id'] === $teamId);
            $changes['team_id'] = $valid ? $teamId : null;
        }
        drinkSaveProfile($conn, $eventId, $device, $changes);
        respond(guestState($conn, $eventId, $device, (bool) $event['setting_merge_by_device']));

    case 'photos':
        // Own photos for the story collage (newest first).
        $sel = $conn->prepare("SELECT filename FROM uploads WHERE event_id = ? AND device_uuid = ? ORDER BY timestamp DESC LIMIT 12");
        $sel->bind_param("ss", $eventId, $device);
        $sel->execute();
        $photos = [];
        foreach ($sel->get_result() as $row) {
            $variant = imageVariantRelPath($eventId, $row['filename'], 'display');
            if (is_file(__DIR__ . '/' . $variant)) {
                $photos[] = implode('/', array_map('rawurlencode', explode('/', $variant)));
            }
        }
        respond(['photos' => $photos]);
}
respond(['error' => 'Unbekannte Aktion'], 400);

/** The name a guest uses is kept in the profile so others can pick them for a round. */
function rememberName(mysqli $conn, string $eventId, string $device, string $name): void
{
    $profile = drinkLoadProfile($conn, $eventId, $device);
    if ($profile['is_default'] || $profile['name'] !== $name) {
        drinkSaveProfile($conn, $eventId, $device, ['name' => $name]);
    }
}

/** Everything the "Mein Pegel" page shows. */
function guestState(mysqli $conn, string $eventId, string $device, bool $mergeByDevice): array
{
    $now = time();
    $profile = drinkLoadProfile($conn, $eventId, $device);
    $drinks = drinkLoadCheckins($conn, $eventId, $device);
    $sim = simulateBac($drinks, $profile, $now);
    $board = drinkBoard($conn, $eventId, $mergeByDevice, $now);

    $rank = null;
    foreach ($board['people'] as $i => $p) {
        if ($p['device'] === $device) {
            $rank = $i + 1;
            break;
        }
    }
    $teamRank = null;
    foreach ($board['teams'] as $i => $t) {
        if ($t['name'] === $profile['team']) {
            $teamRank = $i + 1;
        }
    }

    $guestNames = [];
    foreach (drinkGuests($conn, $eventId) as $g) {
        $guestNames[$g['device']] = $g['name'];
    }
    $history = array_map(static fn ($d) => [
        'id' => $d['id'],
        'ts' => $d['ts'],
        'drink' => $d['drink'],
        'img' => $d['img'],
        'volume_ml' => $d['volume_ml'],
        'abv' => $d['abv'],
        'grams' => $d['grams'],
        'has_photo' => $d['has_photo'],
        'from' => $d['round_by'] !== null && $d['round_by'] !== $device ? ($guestNames[$d['round_by']] ?? 'jemand') : null,
    ], array_reverse($drinks));

    $photos = drinkPhotoCount($conn, $eventId, $device);
    $rounds = drinkRoundsGiven($conn, $eventId, $device);
    $stats = drinkStats($drinks, $device) + ['photos' => $photos, 'rounds_given' => $rounds, 'rank' => $rank, 'team_rank' => $teamRank];

    return [
        'now' => $now,
        'profile' => [
            'name' => $profile['name'],
            'weight_kg' => $profile['weight_kg'],
            'sex' => $profile['sex'],
            'team_id' => $profile['team_id'],
            'team' => $profile['team'],
            'is_default' => $profile['is_default'],
        ],
        'bac' => $sim,
        'limit' => DRIVING_LIMIT,
        'stats' => $stats,
        'history' => $history,
        'badges' => drinkBadges($drinks, $sim, ['photos' => $photos, 'rounds' => $rounds, 'team' => $profile['team'] !== null, 'rank' => $rank]),
        'teams' => drinkTeams($conn, $eventId),
        'board' => [
            'people' => array_map(static fn ($p) => [
                'name' => $p['name'], 'team' => $p['team'], 'points' => $p['points'], 'drinks' => $p['drinks'],
                // other guests' BAC stays private on the guest pages
                'bac' => $p['device'] === $device ? $p['bac'] : null, 'me' => $p['device'] === $device,
            ], array_slice($board['people'], 0, 10)),
            'teams' => array_map(static fn ($t) => [
                'name' => $t['name'], 'members' => $t['members'], 'points' => $t['points'],
                'avg_points' => $t['avg_points'], 'me' => $t['name'] === $profile['team'],
            ], array_slice($board['teams'], 0, 10)),
        ],
    ];
}
