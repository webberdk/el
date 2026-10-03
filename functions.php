<?php
declare(strict_types=1);
require_once __DIR__ . "/config.php";
date_default_timezone_set("Europe/Copenhagen");
/** Validerer og normaliserer prisdata (fra API eller cache) – alt udefra er utroværdigt. */
function rensPriser($records, string $tidFelt, string $prisFelt, float $divisor): array {
    $ud = [];
    if (!is_array($records)) return $ud;
    foreach ($records as $r) {
        if (!is_array($r)) continue;
        $t = $r[$tidFelt] ?? null;
        $v = $r[$prisFelt] ?? null;
        if (!is_string($t) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $t)) continue;
        $format = strlen($t) === 16 ? '!Y-m-d\TH:i' : '!Y-m-d\TH:i:s';
        $dato = DateTimeImmutable::createFromFormat($format, $t, new DateTimeZone('UTC'));
        $fejl = DateTimeImmutable::getLastErrors();
        if (!$dato || ($fejl && ($fejl['warning_count'] || $fejl['error_count']))) continue;
        if (!is_int($v) && !is_float($v)) continue;
        $spot = $v / $divisor;
        if (!is_finite($spot) || abs($spot) > 100) continue; // urimelige værdier (kr/kWh) afvises
        $ud[] = ['t' => $t, 'spot' => $spot];
    }
    return $ud;
}

function cacheSti(): string {
    // Egen mappe med adgang nægtet via .htaccess. Delt /tmp på webhoteller undgås,
    // fordi andre kunder på samme server kan skrive til den.
    $mappe = __DIR__ . '/cache';
    if (!is_dir($mappe)) @mkdir($mappe, 0750);
    if (is_dir($mappe) && !is_file($mappe . '/.htaccess')) {
        @file_put_contents($mappe . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
    // Ny fil: den tidligere cache indeholdt lokal tid og kan ikke bruges som UTC.
    return $mappe . '/spot-' . PRISOMRAADE . '-utc.json';
}

function hentSpotpriser(): array {
    $cacheFil = cacheSti();
    $gemt = is_file($cacheFil) ? json_decode((string)@file_get_contents($cacheFil), true) : null;
    $cache = rensPriser($gemt['priser'] ?? null, 't', 'spot', 1);
    $hentet = $gemt['hentet'] ?? null;
    if (!is_int($hentet) || $hentet <= 0 || $hentet > time()) {
        $cache = [];
        $hentet = null;
    }
    $foraeldet = $hentet === null || time() - $hentet >= CACHE_SEKUNDER;
    // mtime styrer næste forsøg; hentet bevarer tidspunktet for faktisk API-succes.
    if ($cache && time() - (int)@filemtime($cacheFil) < CACHE_SEKUNDER) {
        return ['priser' => $cache, 'hentet' => $hentet, 'foraeldet' => $foraeldet];
    }

    $url = 'https://api.energidataservice.dk/dataset/DayAheadPrices?' . http_build_query([
        'start'   => date('Y-m-d', strtotime('-1 day')),
        'filter'  => json_encode(['PriceArea' => [PRISOMRAADE]]),
        'columns' => 'TimeUTC,DayAheadPriceDKK',
        'sort'    => 'TimeUTC asc',
        'limit'   => 0,
    ]);

    $svar = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXFILESIZE    => 2000000,
            CURLOPT_USERAGENT      => 'smedegaard-flexenergi/1.0',
        ]);
        $svar = curl_exec($ch);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) $svar = false;
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 8, 'follow_location' => 0, 'user_agent' => 'smedegaard-flexenergi/1.0'],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $svar = @file_get_contents($url, false, $ctx, 0, 2000000);
    }

    $json = is_string($svar) ? json_decode($svar, true) : null;
    $priser = rensPriser($json['records'] ?? null, 'TimeUTC', 'DayAheadPriceDKK', 1000); // DKK/MWh -> kr/kWh

    if ($priser) {
        $hentet = time();
        $data = ['priser' => $priser, 'hentet' => $hentet];
        @file_put_contents($cacheFil, json_encode($data), LOCK_EX);
        return $data + ['foraeldet' => false];
    }
    // Fejl hos Energinet: brug gammel cache og vent 2 min. før næste forsøg,
    // så siden ikke hænger på timeout ved hvert eneste besøg.
    if ($cache) @touch($cacheFil, time() - CACHE_SEKUNDER + 120);
    return ['priser' => $cache, 'hentet' => $hentet, 'foraeldet' => true];
}

function tillaeg(int $ts): ?float {
    if (date('Y-m-d', $ts) < SATSER_FRA || date('Y-m-d', $ts) >= SATSER_TIL) return null;
    $winter = (int)date('n', $ts) <= 3 || (int)date('n', $ts) >= 10;
    $hour = (int)date('G', $ts);
    $net = $hour < 6 ? RADIUS_LAV : ($hour >= 17 && $hour < 21
        ? ($winter ? RADIUS_VINTER_SPIDS : RADIUS_SOMMER_SPIDS)
        : ($winter ? RADIUS_VINTER_HOEJ : RADIUS_SOMMER_HOEJ));
    return ANDEL_TILLAEG + ($net + ENERGINET_NET + ENERGINET_SYSTEM + ELAFGIFT) * MOMS;
}

/** Kun komplette timer med fire forskellige UTC-kvarterer må få en timepris. */
function samlTimepriser(array $kvarterer, int $nu): array {
    $idagStart = strtotime(date('Y-m-d', $nu) . ' 00:00:00');
    $timer = [];
    $konflikter = [];
    foreach ($kvarterer as $k) {
        $ts = strtotime($k['t'] . 'Z');
        if ($ts === false || $ts < $idagStart || $ts % 900 !== 0) continue;
        $start = intdiv($ts, 3600) * 3600;
        if (isset($timer[$start][$ts]) && $timer[$start][$ts] !== $k['spot']) {
            $konflikter[$start] = true;
        }
        $timer[$start][$ts] = $k['spot'];
    }
    ksort($timer, SORT_NUMERIC);
    $liste = [];
    foreach ($timer as $ts => $kvarterpriser) {
        if (count($kvarterpriser) !== 4 || isset($konflikter[$ts])) continue;
        $spot = array_sum($kvarterpriser) / 4;
        $liste[] = ['ts' => $ts, 'spot' => round($spot, 4),
                    'pris' => tillaeg($ts) === null ? null : round($spot * MOMS + tillaeg($ts), 2),
                    'nu' => $nu >= $ts && $nu < $ts + 3600, 'fortid' => $ts + 3600 <= $nu];
    }
    return $liste;
}

function billigsteVindue(array $kommende): ?array {
    $bedste = null;
    for ($i = 0; $i + 2 < count($kommende); $i++) {
        if ($kommende[$i+1]['ts'] !== $kommende[$i]['ts'] + 3600 ||
            $kommende[$i+2]['ts'] !== $kommende[$i]['ts'] + 7200) continue;
        $snit = ($kommende[$i]['pris'] + $kommende[$i+1]['pris'] + $kommende[$i+2]['pris']) / 3;
        if (!$bedste || $snit < $bedste['snit']) $bedste = ['start' => $kommende[$i]['ts'], 'snit' => $snit];
    }
    return $bedste;
}


/** DMI CoverageJSON, one grid point from one latest HARMONIE model run.
 * Units from DMI's parameter catalogue: K, m/s, kg/m² (equivalent to mm), 0..1.
 * https://www.dmi.dk/friedata/dokumentation/data/weather-model-harmonie-edr-api-parameter-list
 */
function rensVejr($data): array {
    if (!is_array($data) || ($data['type'] ?? '') !== 'Coverage') return [];
    $axes = $data['domain']['axes'] ?? [];
    $times = $axes['t']['values'] ?? null;
    if (!is_array($times) || !$times || count($times) > 200
        || !is_array($axes['x']['values'] ?? null) || !is_array($axes['y']['values'] ?? null)
        || count($axes['x']['values']) !== 1 || count($axes['y']['values']) !== 1) return [];
    $values = [];
    foreach (['temperature-2m', 'wind-speed-10m', 'total-precipitation', 'fraction-of-cloud-cover'] as $key) {
        $range = $data['ranges'][$key] ?? [];
        if (($range['axisNames'] ?? []) !== ['t', 'y', 'x']
            || ($range['shape'] ?? []) !== [count($times), 1, 1]
            || !is_array($range['values'] ?? null) || count($range['values']) !== count($times)) return [];
        $values[$key] = $range['values'];
    }
    $types = $data['ranges']['precipitation-type'] ?? [];
    $values['precipitation-type'] = ($types['axisNames'] ?? []) === ['t', 'y', 'x']
        && ($types['shape'] ?? []) === [count($times), 1, 1] && is_array($types['values'] ?? null) ? $types['values'] : [];
    $stamps = [];
    foreach ($times as $t) {
        if (!is_string($t) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/', $t)) return [];
        $format = strpos($t, '.') === false ? '!Y-m-d\TH:i:s\Z' : '!Y-m-d\TH:i:s.v\Z';
        $date = DateTimeImmutable::createFromFormat($format, $t, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) return [];
        $ts = $date->getTimestamp();
        if ($ts % 3600 !== 0 || ($stamps && $ts <= end($stamps))) return [];
        $stamps[] = $ts;
    }
    $number = static function ($v, float $min, float $max): ?float {
        return (is_int($v) || is_float($v)) && is_finite((float)$v) && $v >= $min && $v <= $max ? (float)$v : null;
    };
    $rows = [];
    foreach ($stamps as $i => $ts) {
        $kelvin = $number($values['temperature-2m'][$i], 183.15, 338.15);
        $wind = $number($values['wind-speed-10m'][$i], 0, 120);
        $cloud = $number($values['fraction-of-cloud-cover'][$i], 0, 1);
        $now = $number($values['total-precipitation'][$i], 0, 5000);
        $next = ($stamps[$i + 1] ?? null) === $ts + 3600
            ? $number($values['total-precipitation'][$i + 1], 0, 5000) : null;
        // Difference within this response/model run for [ts, ts + 1h).
        // A reset, missing sample, or gap means unknown, never a fabricated zero.
        $rain = $now !== null && $next !== null && $next >= $now - 0.001
            ? $number(max(0, $next - $now), 0, 500) : null;
        if ($kelvin === null && $wind === null && $cloud === null && $rain === null) continue;
        $type = $values['precipitation-type'][$i] ?? null;
        $sun = date_sun_info($ts, VEJR_LAT, VEJR_LON);
        $day = is_int($sun['sunrise']) && is_int($sun['sunset']) && $ts >= $sun['sunrise'] && $ts < $sun['sunset'];
        $rows[$ts] = ['temperatur' => $kelvin === null ? null : round($kelvin - 273.15, 4),
            'vind' => $wind, 'nedboer' => $rain, 'skydaekke' => $cloud,
            'nedboerstype' => (is_int($type) || is_float($type)) && floor($type) === (float)$type && $type >= 0 && $type <= 7 ? (int)$type : null,
            'dag' => $day];
    }
    return $rows;
}

function hentVejr(): array {
    cacheSti();
    $file = __DIR__ . '/cache/dmi-weather-v1-' . VEJR_LAT . '-' . VEJR_LON . '.json';
    $cached = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    $fetched = $cached['hentet'] ?? null;
    $rows = rensVejr($cached['data'] ?? null);
    if (!is_int($fetched) || $fetched <= 0 || $fetched > time()) { $rows = []; $fetched = null; }
    if ($rows && time() - (int)@filemtime($file) < VEJR_CACHE_SEKUNDER)
        return ['timer' => $rows, 'hentet' => $fetched, 'foraeldet' => time() - $fetched >= VEJR_CACHE_SEKUNDER];
    $url = 'https://opendataapi.dmi.dk/v1/forecastedr/collections/harmonie_dini_sf/position?' . http_build_query([
        'coords' => 'POINT(' . VEJR_LON . ' ' . VEJR_LAT . ')', 'crs' => 'crs84',
        'parameter-name' => 'temperature-2m,wind-speed-10m,total-precipitation,fraction-of-cloud-cover,precipitation-type',
        'f' => 'CoverageJSON',
    ]);
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 12, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 2000000]);
        $body = curl_exec($ch);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) $body = false;
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $context = stream_context_create(['http' => ['timeout' => 12, 'follow_location' => 0],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents($url, false, $context, 0, 2000000);
    }
    $json = is_string($body) ? json_decode($body, true) : null;
    $new = rensVejr($json);
    if ($new) {
        $fetched = time();
        @file_put_contents($file, json_encode(['hentet' => $fetched, 'data' => $json]), LOCK_EX);
        return ['timer' => $new, 'hentet' => $fetched, 'foraeldet' => false];
    }
    if ($rows) @touch($file, time() - VEJR_CACHE_SEKUNDER + 120);
    return ['timer' => $rows, 'hentet' => $fetched, 'foraeldet' => true];
}

/** Simplified local symbols, not DMI's official weather-condition codes. */
function vejrSymbol(?array $weather): array {
    if (!$weather) return [null, 'Vejr mangler'];
    $rain = $weather['nedboer'] ?? null;
    $cloud = $weather['skydaekke'] ?? null;
    $type = $weather['nedboerstype'] ?? null;
    if ($rain !== null && $rain >= 0.1) {
        if (in_array($type, [2,3,6,7], true)) return ['cloud-snow', $type === 2 ? 'Slud' : ($type === 7 ? 'Hagl' : 'Sne')];
        if (in_array($type, [4,5], true)) return ['cloud-rain', 'Underafkølet nedbør'];
        return ['cloud-rain', 'Nedbør'];
    }
    if ($cloud === null) return [null, 'Vejrtype mangler'];
    if ($cloud <= 0.2) return [$weather['dag'] ? 'sun' : 'moon', $weather['dag'] ? 'Klart' : 'Klar nat'];
    if ($cloud <= 0.8) return [$weather['dag'] ? 'cloud-sun' : 'cloud-moon', 'Delvist skyet'];
    return ['cloud', 'Overskyet'];
}
