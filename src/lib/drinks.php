<?php
/*
 * Drink mode: check-ins, blood alcohol estimate (Widmark formula with linear absorption),
 * personal stats, badges, teams and rankings. Guests have no account; they are identified
 * by the random device id their browser keeps in localStorage.
 */

const ETHANOL_DENSITY = 0.789;       // g/ml
const RESORPTION_DEFICIT = 0.10;     // ~10 % of the alcohol never reaches the blood
const ABSORPTION_MINUTES = 45;       // time until a drink is fully absorbed
const ELIMINATION_PER_HOUR = 0.15;   // ‰ per hour
const BAC_STEP_SECONDS = 120;        // resolution of the curve
const WIDMARK_R = ['m' => 0.68, 'f' => 0.55];
const DRIVING_LIMIT = 0.5;           // ‰

/** Drinking vessels offered when checking in: label, ml. */
const DRINK_CONTAINERS = [
    ['Shot', 20],
    ['Doppelter', 40],
    ['Sekt', 100],
    ['Wein', 200],
    ['Glas', 250],
    ['Flasche', 330],
    ['Halbe', 500],
    ['Maß', 1000],
];

function drinkDefaultProfile(): array
{
    return ['name' => '', 'weight_kg' => 75.0, 'sex' => 'm', 'team_id' => null, 'team' => null, 'is_default' => true];
}

function drinkLoadProfiles(mysqli $conn, string $eventId): array
{
    $stmt = $conn->prepare("
        SELECT p.device_uuid, p.name, p.weight_kg, p.sex, p.team_id, t.name AS team
        FROM drinker_profiles p LEFT JOIN teams t ON t.id = p.team_id
        WHERE p.event_uuid = ?");
    $stmt->bind_param("s", $eventId);
    $stmt->execute();
    $profiles = [];
    foreach ($stmt->get_result() as $row) {
        $profiles[$row['device_uuid']] = [
            'name' => (string) $row['name'],
            'weight_kg' => (float) $row['weight_kg'],
            'sex' => $row['sex'],
            'team_id' => $row['team_id'] !== null ? (int) $row['team_id'] : null,
            'team' => $row['team'],
            'is_default' => false,
        ];
    }
    return $profiles;
}

function drinkLoadProfile(mysqli $conn, string $eventId, string $deviceUuid): array
{
    return drinkLoadProfiles($conn, $eventId)[$deviceUuid] ?? drinkDefaultProfile();
}

/** Creates or updates the profile; only the given keys are changed. */
function drinkSaveProfile(mysqli $conn, string $eventId, string $deviceUuid, array $changes): void
{
    $profile = array_merge(drinkLoadProfile($conn, $eventId, $deviceUuid), $changes);
    $name = mb_substr(trim((string) $profile['name']), 0, 100);
    $weight = max(30.0, min(250.0, (float) $profile['weight_kg']));
    $sex = $profile['sex'] === 'f' ? 'f' : 'm';
    $teamId = $profile['team_id'] !== null ? (int) $profile['team_id'] : null;

    $stmt = $conn->prepare("
        INSERT INTO drinker_profiles (event_uuid, device_uuid, name, weight_kg, sex, team_id) VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name = VALUES(name), weight_kg = VALUES(weight_kg), sex = VALUES(sex), team_id = VALUES(team_id)");
    $stmt->bind_param("sssdsi", $eventId, $deviceUuid, $name, $weight, $sex, $teamId);
    $stmt->execute();
}

function drinkGrams(int $volumeMl, float $abv): float
{
    return $volumeMl * $abv / 100 * ETHANOL_DENSITY;
}

/**
 * All check-ins of an event (or of one device), oldest first.
 * @return list<array{id:int, device:?string, name:string, ts:int, drink_id:int, drink:string, img:?string,
 *                    volume_ml:int, abv:float, grams:float, score:float, round_by:?string, has_photo:bool}>
 */
function drinkLoadCheckins(mysqli $conn, string $eventId, ?string $deviceUuid = null): array
{
    $sql = "
        SELECT c.id, c.device_uuid, c.name, UNIX_TIMESTAMP(c.created_at) AS ts, c.drink_id, d.name AS drink,
               d.image_path, c.volume_ml, c.abv, d.score_factor, c.round_by, c.upload_id
        FROM checkins c JOIN drinks d ON d.id = c.drink_id
        WHERE c.event_uuid = ?" . ($deviceUuid !== null ? " AND c.device_uuid = ?" : "") . "
        ORDER BY c.created_at ASC, c.id ASC";
    $stmt = $conn->prepare($sql);
    if ($deviceUuid !== null) {
        $stmt->bind_param("ss", $eventId, $deviceUuid);
    } else {
        $stmt->bind_param("s", $eventId);
    }
    $stmt->execute();

    $checkins = [];
    foreach ($stmt->get_result() as $row) {
        $checkins[] = [
            'id' => (int) $row['id'],
            'device' => $row['device_uuid'],
            'name' => (string) $row['name'],
            'ts' => (int) $row['ts'],
            'drink_id' => (int) $row['drink_id'],
            'drink' => $row['drink'],
            'img' => $row['image_path'],
            'volume_ml' => (int) $row['volume_ml'],
            'abv' => (float) $row['abv'],
            'grams' => round(drinkGrams((int) $row['volume_ml'], (float) $row['abv']), 1),
            'score' => (float) $row['score_factor'],
            'round_by' => $row['round_by'],
            'has_photo' => $row['upload_id'] !== null,
        ];
    }
    return $checkins;
}

/**
 * Blood alcohol curve in fixed steps: every drink is absorbed linearly over ABSORPTION_MINUTES,
 * the body eliminates ELIMINATION_PER_HOUR as long as there is alcohol. The curve runs from the
 * first drink until the person is sober again (at most 24 h after max($now, last drink)).
 *
 * @param list<array{ts:int, grams:float}> $drinks sorted by time
 * @return list<array{0:int, 1:float}> [unix time, ‰]
 */
function bacCurve(array $drinks, array $profile, int $now): array
{
    if (!$drinks) {
        return [];
    }
    $bodyWater = WIDMARK_R[$profile['sex']] * $profile['weight_kg'];
    $absorbSeconds = ABSORPTION_MINUTES * 60;
    $elimPerStep = ELIMINATION_PER_HOUR / 3600 * BAC_STEP_SECONDS;
    $lastDrink = end($drinks)['ts'];
    $end = max($now, $lastDrink) + 24 * 3600;

    $points = [[$drinks[0]['ts'], 0.0]];
    $bac = 0.0;
    for ($t = $drinks[0]['ts'] + BAC_STEP_SECONDS; $t <= $end; $t += BAC_STEP_SECONDS) {
        $absorbed = 0.0;
        foreach ($drinks as $d) {
            // share of this drink that enters the blood during this step
            $from = max($t - BAC_STEP_SECONDS, $d['ts']);
            $to = min($t, $d['ts'] + $absorbSeconds);
            if ($to > $from) {
                $absorbed += $d['grams'] * (1 - RESORPTION_DEFICIT) * ($to - $from) / $absorbSeconds;
            }
        }
        $bac = max(0.0, $bac + $absorbed / $bodyWater - $elimPerStep);
        $points[] = [$t, $bac];

        if ($bac == 0.0 && $t >= $lastDrink + $absorbSeconds && $t >= $now) {
            break;
        }
    }
    return $points;
}

/** Interpolated value of a curve at $ts (0 outside of it). */
function bacAt(array $curve, int $ts): float
{
    $count = count($curve);
    if ($count === 0 || $ts < $curve[0][0] || $ts > $curve[$count - 1][0]) {
        return 0.0;
    }
    $i = min($count - 1, intdiv($ts - $curve[0][0], BAC_STEP_SECONDS));
    if ($i + 1 >= $count) {
        return $curve[$i][1];
    }
    [$t0, $v0] = $curve[$i];
    [$t1, $v1] = $curve[$i + 1];
    return $v0 + ($v1 - $v0) * ($ts - $t0) / max(1, $t1 - $t0);
}

/**
 * Current value, peak and forecast of the curve, reduced to the current "session"
 * (since the value was last 0 before the latest drink).
 */
function simulateBac(array $drinks, array $profile, int $now, bool $withSeries = true): array
{
    $result = [
        'series' => [], 'current' => 0.0, 'peak' => null, 'session_start' => null,
        'below_limit_at' => null, 'sober_at' => null,
    ];
    $curve = bacCurve($drinks, $profile, $now);
    if (!$curve) {
        return $result;
    }

    $lastDrink = end($drinks)['ts'];
    $start = 0;
    foreach ($curve as $i => [$t, $v]) {
        if ($v == 0.0 && $t <= $lastDrink) {
            $start = $i;
        }
    }
    $curve = array_slice($curve, $start);

    $peak = [$curve[0][0], 0.0];
    $prev = 0.0;
    foreach ($curve as [$t, $v]) {
        if ($v > $peak[1]) {
            $peak = [$t, $v];
        }
        if ($t > $now && $t >= $lastDrink) {
            if ($result['below_limit_at'] === null && $v < DRIVING_LIMIT && $prev >= DRIVING_LIMIT) {
                $result['below_limit_at'] = $t;
            }
            if ($result['sober_at'] === null && $v == 0.0 && $prev > 0) {
                $result['sober_at'] = $t;
            }
        }
        $prev = $v;
    }

    $result['current'] = round(bacAt($curve, $now), 2);
    $result['peak'] = ['ts' => $peak[0], 'value' => round($peak[1], 2)];
    $result['session_start'] = $curve[0][0];
    if ($withSeries) {
        $result['series'] = array_map(static fn ($p) => [$p[0], round($p[1], 3)], $curve);
    }
    return $result;
}

/** Key figures for the stats block and the story. */
function drinkStats(array $drinks, string $deviceUuid): array
{
    $count = count($drinks);
    $grams = array_sum(array_column($drinks, 'grams'));
    $span = $count ? end($drinks)['ts'] - $drinks[0]['ts'] : 0;

    $favorite = null;
    if ($count) {
        $perDrink = array_count_values(array_column($drinks, 'drink'));
        arsort($perDrink);
        $favorite = ['name' => array_key_first($perDrink), 'count' => reset($perDrink)];
    }

    return [
        'count' => $count,
        'grams' => round($grams, 1),
        'volume_l' => round(array_sum(array_column($drinks, 'volume_ml')) / 1000, 2),
        'points' => array_sum(array_column($drinks, 'score')),
        'duration_min' => intdiv($span, 60),
        // drinks per hour only makes sense after a while
        'per_hour' => $span >= 1800 ? round($count / ($span / 3600), 1) : null,
        'favorite' => $favorite,
        'received_rounds' => count(array_filter($drinks, static fn ($d) => $d['round_by'] !== null && $d['round_by'] !== $deviceUuid)),
    ];
}

/** Rounds this device paid for: distinct check-in times where it bought for someone else. */
function drinkRoundsGiven(mysqli $conn, string $eventId, string $deviceUuid): int
{
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT created_at) AS n FROM checkins
        WHERE event_uuid = ? AND round_by = ? AND (device_uuid IS NULL OR device_uuid != round_by)");
    $stmt->bind_param("ss", $eventId, $deviceUuid);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_assoc()['n'];
}

function drinkPhotoCount(mysqli $conn, string $eventId, string $deviceUuid): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM uploads WHERE event_id = ? AND device_uuid = ?");
    $stmt->bind_param("ss", $eventId, $deviceUuid);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_assoc()['n'];
}

/**
 * Achievements of one guest.
 * @param array{photos:int, rounds:int, team:bool, rank:?int} $extra
 */
function drinkBadges(array $drinks, array $sim, array $extra): array
{
    $tz = new DateTimeZone(APP_TIMEZONE);
    $hour = static fn (int $ts): int => (int) (new DateTimeImmutable("@$ts"))->setTimezone($tz)->format('G');

    $shots = count(array_filter($drinks, static fn ($d) => $d['volume_ml'] <= 50 && $d['abv'] >= 15));
    $kinds = count(array_unique(array_column($drinks, 'drink_id')));
    $owl = (bool) array_filter($drinks, static fn ($d) => $hour($d['ts']) >= 2 && $hour($d['ts']) < 6);
    $mass = (bool) array_filter($drinks, static fn ($d) => $d['volume_ml'] >= 1000);
    $span = $drinks ? end($drinks)['ts'] - $drinks[0]['ts'] : 0;

    $sprint = false;
    $times = array_column($drinks, 'ts');
    for ($i = 2; $i < count($times); $i++) {
        if ($times[$i] - $times[$i - 2] <= 1800) {
            $sprint = true;
            break;
        }
    }
    $peak = $sim['peak']['value'] ?? 0.0;

    $defs = [
        ['first', '🍻', 'Erste Runde', 'Das erste Getränk eingecheckt', count($drinks) >= 1],
        ['ten', '🔟', 'Zweistellig', '10 Getränke an einem Abend', count($drinks) >= 10],
        ['mass', '🏅', 'Maß-Meister', 'Einen Liter auf einmal', $mass],
        ['shots', '🥃', 'Shot-Schütze', '3 Shots eingecheckt', $shots >= 3],
        ['variety', '🌈', 'Probierfreudig', '4 verschiedene Getränke', $kinds >= 4],
        ['sprint', '🚀', 'Senkrechtstarter', '3 Getränke in 30 Minuten', $sprint],
        ['owl', '🦉', 'Nachteule', 'Nach 2 Uhr noch was getrunken', $owl],
        ['marathon', '🏃', 'Marathon', 'Über 5 Stunden dabei', $span >= 5 * 3600],
        ['chill', '🧘', 'Genießer', '3+ Getränke und nie über 0,5 ‰', count($drinks) >= 3 && $peak < DRIVING_LIMIT],
        ['round', '🎁', 'Spendierhosen', 'Eine Runde ausgegeben', $extra['rounds'] >= 1],
        ['paparazzo', '📸', 'Paparazzo', '5 Fotos hochgeladen', $extra['photos'] >= 5],
        ['team', '🤝', 'Teamplayer', 'Einem Team beigetreten', $extra['team']],
        ['top', '👑', 'Spitzenreiter', 'Platz 1 im Ranking', $extra['rank'] === 1],
    ];
    return array_map(static fn ($b) => [
        'key' => $b[0], 'icon' => $b[1], 'title' => $b[2], 'desc' => $b[3], 'earned' => $b[4],
    ], $defs);
}

/**
 * Ranking of all guests and teams by points (sum of the drinks' score factors).
 * With $mergeByDevice the check-ins of a device count together, otherwise every name counts separately.
 */
function drinkBoard(mysqli $conn, string $eventId, bool $mergeByDevice = true, ?int $now = null): array
{
    $now ??= time();
    $profiles = drinkLoadProfiles($conn, $eventId);

    $groups = [];
    foreach (drinkLoadCheckins($conn, $eventId) as $c) {
        $key = $mergeByDevice ? ($c['device'] ?? 'name:' . $c['name']) : 'name:' . mb_strtolower($c['name']);
        if (!$mergeByDevice && $c['name'] === '') {
            continue;
        }
        $groups[$key] ??= ['device' => null, 'name' => '', 'drinks' => []];
        $groups[$key]['drinks'][] = $c;
        if ($c['device'] !== null) {
            $groups[$key]['device'] = $c['device'];
        }
        if ($c['name'] !== '') {
            $groups[$key]['name'] = $c['name'];
        }
    }

    $people = [];
    foreach ($groups as $key => $g) {
        $profile = $profiles[$g['device'] ?? ''] ?? drinkDefaultProfile();
        $name = $mergeByDevice && $profile['name'] !== '' ? $profile['name'] : $g['name'];
        $sim = simulateBac($g['drinks'], $profile, $now, false);
        $details = [];
        foreach ($g['drinks'] as $d) {
            $details[$d['drink']] ??= ['drink' => $d['drink'], 'img' => $d['img'], 'qty' => 0];
            $details[$d['drink']]['qty']++;
        }
        usort($details, static fn ($a, $b) => $b['qty'] <=> $a['qty']);
        $people[] = [
            'key' => (string) $key,
            'device' => $g['device'],
            'name' => $name !== '' ? $name : 'Anonym',
            'team' => $profile['team'],
            'points' => array_sum(array_column($g['drinks'], 'score')),
            'drinks' => count($g['drinks']),
            'bac' => $sim['current'],
            'peak' => $sim['peak']['value'] ?? 0.0,
            'details' => $details,
        ];
    }
    usort($people, static fn ($a, $b) => [$b['points'], $b['drinks']] <=> [$a['points'], $a['drinks']]);

    $teams = [];
    foreach ($people as $p) {
        if ($p['team'] === null) {
            continue;
        }
        $teams[$p['team']] ??= ['name' => $p['team'], 'members' => 0, 'points' => 0.0, 'drinks' => 0, 'bac_sum' => 0.0];
        $teams[$p['team']]['members']++;
        $teams[$p['team']]['points'] += $p['points'];
        $teams[$p['team']]['drinks'] += $p['drinks'];
        $teams[$p['team']]['bac_sum'] += $p['bac'];
    }
    $teams = array_values(array_map(static function ($t) {
        $t['avg_points'] = round($t['points'] / $t['members'], 1);
        $t['avg_bac'] = round($t['bac_sum'] / $t['members'], 2);
        unset($t['bac_sum']);
        return $t;
    }, $teams));
    usort($teams, static fn ($a, $b) => [$b['points'], $b['drinks']] <=> [$a['points'], $a['drinks']]);

    return ['people' => $people, 'teams' => $teams];
}

/** Public, non-reversible reference to a device, so guests can pick others for a round. */
function drinkGuestRef(string $eventId, string $deviceUuid): string
{
    return substr(hash('sha256', $eventId . '|' . $deviceUuid), 0, 16);
}

/** Guests with a name who took part in the event: ref => ['device' => …, 'name' => …]. */
function drinkGuests(mysqli $conn, string $eventId): array
{
    $stmt = $conn->prepare("
        SELECT device_uuid, name FROM (
            SELECT device_uuid, name, created_at AS ts FROM checkins WHERE event_uuid = ? AND device_uuid IS NOT NULL
            UNION ALL
            SELECT device_uuid, uploader_name, timestamp FROM uploads WHERE event_id = ? AND device_uuid IS NOT NULL
            UNION ALL
            SELECT device_uuid, name, updated_at FROM drinker_profiles WHERE event_uuid = ?
        ) x WHERE name IS NOT NULL AND name != '' ORDER BY ts ASC");
    $stmt->bind_param("sss", $eventId, $eventId, $eventId);
    $stmt->execute();

    $guests = [];
    foreach ($stmt->get_result() as $row) {
        // newest name wins
        $guests[drinkGuestRef($eventId, $row['device_uuid'])] = ['device' => $row['device_uuid'], 'name' => $row['name']];
    }
    uasort($guests, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
    return $guests;
}

function isDeviceBlocked(mysqli $conn, string $eventId, string $deviceUuid): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM blocked_devices WHERE event_uuid = ? AND device_uuid = ?");
    $stmt->bind_param("ss", $eventId, $deviceUuid);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/** Default amount and alcohol content of a drink of this event, null if it doesn't belong to it. */
function drinkDefaults(mysqli $conn, string $eventId, int $drinkId): ?array
{
    $stmt = $conn->prepare("SELECT volume_ml, abv FROM drinks WHERE id = ? AND event_uuid = ?");
    $stmt->bind_param("is", $drinkId, $eventId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Records a check-in for the guest (if $forSelf) and, as a round, for the guests in $roundRefs.
 * All rows share one timestamp so a round stays recognisable. Returns the number of rows.
 */
function drinkCheckin(mysqli $conn, string $eventId, string $deviceUuid, string $name, int $drinkId,
    ?int $volumeMl, ?float $abv, bool $forSelf = true, array $roundRefs = [], ?int $uploadId = null): int
{
    $defaults = drinkDefaults($conn, $eventId, $drinkId);
    if ($defaults === null) {
        return 0;
    }
    $volumeMl = max(1, min(3000, $volumeMl ?? (int) $defaults['volume_ml']));
    $abv = max(0.0, min(96.0, $abv ?? (float) $defaults['abv']));

    $recipients = [];
    if ($forSelf) {
        $recipients[] = ['device' => $deviceUuid, 'name' => $name, 'upload' => $uploadId];
    }
    $guests = $roundRefs ? drinkGuests($conn, $eventId) : [];
    foreach (array_unique($roundRefs) as $ref) {
        if (isset($guests[$ref]) && $guests[$ref]['device'] !== $deviceUuid) {
            $recipients[] = $guests[$ref] + ['upload' => null];
        }
    }
    $roundBy = count($recipients) > ($forSelf ? 1 : 0) ? $deviceUuid : null;
    $now = time();

    $insert = $conn->prepare("INSERT INTO checkins (event_uuid, device_uuid, name, drink_id, volume_ml, abv, upload_id, round_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))");
    foreach ($recipients as $r) {
        $device = $r['device'] !== '' ? $r['device'] : null;
        $insert->bind_param("sssiidisi", $eventId, $device, $r['name'], $drinkId, $volumeMl, $abv, $r['upload'], $roundBy, $now);
        $insert->execute();
    }
    return count($recipients);
}

/** @return list<array{id:int, name:string, members:int}> */
function drinkTeams(mysqli $conn, string $eventId): array
{
    $stmt = $conn->prepare("
        SELECT t.id, t.name, COUNT(p.id) AS members
        FROM teams t LEFT JOIN drinker_profiles p ON p.team_id = t.id
        WHERE t.event_uuid = ? GROUP BY t.id ORDER BY t.name");
    $stmt->bind_param("s", $eventId);
    $stmt->execute();
    return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'members' => (int) $r['members']],
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
}

/** Returns the id of the (new or existing) team, null for an invalid name. */
function drinkCreateTeam(mysqli $conn, string $eventId, string $name): ?int
{
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', $name) ?? ''), 0, 40);
    if ($name === '') {
        return null;
    }
    $stmt = $conn->prepare("INSERT INTO teams (event_uuid, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
    $stmt->bind_param("ss", $eventId, $name);
    $stmt->execute();
    return (int) $conn->insert_id;
}

/** Quick-add templates for the drink menu: name, ml, Vol-%, points factor. */
const DRINK_TEMPLATES = [
    ['Bier', 500, 5.0, 1.0],
    ['Helles', 500, 5.0, 1.0],
    ['Weißbier', 500, 5.4, 1.0],
    ['Radler', 500, 2.5, 0.5],
    ['Maß', 1000, 5.5, 2.0],
    ['Weißwein', 200, 12.0, 1.0],
    ['Rotwein', 200, 13.0, 1.0],
    ['Sekt', 100, 11.0, 1.0],
    ['Aperol Spritz', 250, 8.0, 1.0],
    ['Gin Tonic', 250, 8.0, 1.5],
    ['Cuba Libre', 250, 8.0, 1.5],
    ['Shot', 20, 38.0, 1.0],
    ['Jägermeister', 20, 35.0, 1.0],
    ['Alkoholfreies Bier', 500, 0.5, 0.5],
    ['Wasser', 300, 0.0, 0.0],
];
