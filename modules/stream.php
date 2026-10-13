<?php
// modules/stream.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/TVHeadendAPI.php';
require_once __DIR__ . '/auth.php';

$uuid          = $_GET['uuid'] ?? '';
$channelNumber = $_GET['channel_number'] ?? '';
$channelUuid   = $_GET['channel_uuid'] ?? '';

$token   = $_GET['token'] ?? '';
$expires = (int)($_GET['expires'] ?? 0);

if (!$uuid and $channelNumber === '' and !$channelUuid) {
    http_response_code(400);
    exit('Weder DVR-UUID noch Channel angegeben.');
}

// Ziel-Identifikator für die Token-Signatur
$targetId = $uuid ?: ($channelUuid ?: (string)$channelNumber);

// Zugriffsprüfung: Aktive Web-Session ODER gültiges Signatur-Token
$hasSession = !empty($_SESSION['pvr_authenticated']);
$hasValidToken = ($token and $expires and Auth::verifyStreamToken($targetId, $token, $expires));

if (!$hasSession and !$hasValidToken) {
    http_response_code(403);
    exit('Zugriff verweigert: Ungueltiges oder abgelaufenes Token.');
}

// 1. M3U-Playlist für externe Player (VLC)
if (isset($_GET['m3u'])) {
    $host = 'pvr.street68.net';
    
    // Frisches Token für den eigentlichen Stream generieren
    $tokenData = Auth::createStreamToken($targetId, 12);
    $authParams = '&token=' . urlencode($tokenData['token']) . '&expires=' . $tokenData['expires'];

    $query = '';
    $duration = -1;
    $mediaTitle = 'Live-TV';

    if ($channelNumber !== '') {
        $query = 'channel_number=' . urlencode($channelNumber);
    } elseif ($channelUuid !== '') {
        $query = 'channel_uuid=' . urlencode($channelUuid);
    } else {
        $query = 'uuid=' . urlencode($uuid);
        $mediaTitle = 'Aufnahme';
        
        $api = new TVHeadendAPI();
        $finished = $api->getFinished();
        foreach ($finished['entries'] ?? [] as $item) {
            if (($item['uuid'] ?? '') === $uuid) {
                $duration = (int)($item['duration'] ?? 0);
                if (!empty($item['disp_title'])) {
                    $mediaTitle = $item['disp_title'];
                }
                break;
            }
        }
    }

    $streamUrl = 'https://' . $host . '/modules/stream.php?' . $query . $authParams;

    header('Content-Type: audio/x-mpegurl; charset=utf-8');
    header('Content-Disposition: attachment; filename="stream.m3u"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "#EXTM3U\r\n";
    echo "#EXTINF:" . $duration . "," . $mediaTitle . "\r\n";
    echo $streamUrl . "\r\n";
    exit;
}

// 2. LIVE-TV STREAM PROXY (Direkter MPEG-TS Pass-Through)
if ($channelNumber !== '' or $channelUuid !== '') {
    if ($channelNumber !== '') {
        $streamUrl = rtrim(TVH_HOST, '/') . '/stream/channelnumber/' . urlencode($channelNumber) . '?profile=pass';
    } else {
        $streamUrl = rtrim(TVH_HOST, '/') . '/stream/channel/' . urlencode($channelUuid) . '?profile=pass';
    }

    set_time_limit(0);
    ignore_user_abort(false);

    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');

    header('X-Accel-Buffering: no');
    header('Content-Type: video/mp2t');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    flush();

    $ch = curl_init($streamUrl);
    curl_setopt_array($ch, [
        CURLOPT_USERPWD        => TVH_USER . ':' . TVH_PASS,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_BUFFERSIZE     => 64 * 1024,
        CURLOPT_WRITEFUNCTION  => function($curl, $data) {
            echo $data;
            flush();
            return (connection_aborted() ? 0 : strlen($data));
        }
    ]);

    curl_exec($ch);
    curl_close($ch);
    exit;
}




// 3. DVR-AUFNAHMEN (Dateibasierter Pfad-Lookup)
$api = new TVHeadendAPI();
$finished = $api->getFinished();
$entries = $finished['entries'] ?? [];

$filePath = null;
$itemTitle = 'recording';

foreach ($entries as $item) {
    if (($item['uuid'] ?? '') === $uuid) {
        $filePath = $item['filename'] ?? ($item['path'] ?? ($item['files'][0]['filename'] ?? null));
        $itemTitle = $item['disp_title'] ?? 'recording';
        break;
    }
}

// Wenn die Datei unter /var/radio/video/aufnahmen liegt, leiten wir auf den nativen Apache-Alias um!
$baseDir = '/var/radio/video/aufnahmen/';
if ($filePath and str_starts_with($filePath, $baseDir) and file_exists($filePath)) {
    $relPath = substr($filePath, strlen($baseDir));
    $parts = explode('/', $relPath);
    $encodedParts = array_map('rawurlencode', $parts);
    $redirectUrl = '/recordings/' . implode('/', $encodedParts);

    // Schneller interner HTTP 302 Redirect an Apache für 100% natives Spulen im VLC
    header('Location: ' . $redirectUrl);
    exit;
}

// Direkter Datei-Stream Fallback (falls nicht im Alias-Ordner)
if ($filePath and file_exists($filePath) and is_readable($filePath)) {
    $size = filesize($filePath);
    $time = date('r', filemtime($filePath));
    $fm = @fopen($filePath, 'rb');

    if (!$fm) {
        http_response_code(500);
        exit('Datei konnte nicht geoeffnet werden.');
    }

    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', '0');

    $begin = 0;
    $end = $size - 1;

    header('X-Accel-Buffering: no');
    header('Accept-Ranges: bytes');
    header('Content-Type: video/mp2t');
    header('Content-Disposition: inline; filename="' . rawurlencode(basename($filePath)) . '"');

    if (isset($_SERVER['HTTP_RANGE'])) {
        if (preg_match('/bytes=\h*(\d+)-(\d*)[\D.*]?/i', $_SERVER['HTTP_RANGE'], $matches)) {
            $begin = intval($matches[1]);
            if (!empty($matches[2])) {
                $end = intval($matches[2]);
            }
        }
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes $begin-$end/$size");
        header('Content-Length: ' . ($end - $begin + 1));
    } else {
        header('HTTP/1.1 200 OK');
        header('Content-Length: ' . $size);
    }

    header("Last-Modified: $time");
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $cur = $begin;
    fseek($fm, $begin, SEEK_SET);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    flush();

    while (!feof($fm) and $cur <= $end and (connection_status() == 0)) {
        $bytesToRead = min(1024 * 128, ($end - $cur) + 1);
        $data = fread($fm, $bytesToRead);
        echo $data;
        flush();
        $cur += $bytesToRead;
    }
    fclose($fm);
    exit;
}

// 4. FALLBACK: TVHeadend HTTP API Proxy (falls Datei nicht lokal lesbar)
$streamUrl = rtrim(TVH_HOST, '/') . '/play/dvrfile/' . urlencode($uuid);

$requestHeaders = [];
if (isset($_SERVER['HTTP_RANGE'])) {
    $requestHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

set_time_limit(0);
ignore_user_abort(false);

header('X-Accel-Buffering: no');
header('Accept-Ranges: bytes');
header('Content-Type: video/mp2t');

$ch = curl_init($streamUrl);
curl_setopt_array($ch, [
    CURLOPT_USERPWD        => TVH_USER . ':' . TVH_PASS,
    CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 0,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER     => $requestHeaders,
    CURLOPT_HEADERFUNCTION => function($curl, $headerLine) {
        $lower = strtolower($headerLine);
        if (str_starts_with($lower, 'content-range:') or 
            str_starts_with($lower, 'content-length:') or 
            str_starts_with($lower, 'accept-ranges:')) {
            header(trim($headerLine));
        }
        return strlen($headerLine);
    },
    CURLOPT_WRITEFUNCTION  => function($curl, $data) {
        echo $data;
        flush();
        return (connection_aborted() ? 0 : strlen($data));
    }
]);

while (ob_get_level() > 0) { 
    ob_end_clean(); 
}

curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($httpCode === 206) {
    header('HTTP/1.1 206 Partial Content', true, 206);
}
curl_close($ch);
exit;