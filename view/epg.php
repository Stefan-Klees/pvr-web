<?php
// view/epg.php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../modules/auth.php';

$api = new TVHeadendAPI();

// Parameter aus GET-Request abfangen
$limitParam      = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$selectedChannel = $_GET['channel'] ?? 'all';
$selectedGenre   = $_GET['genre'] ?? 'all';
$searchQuery     = trim($_GET['q'] ?? '');

$fetchLimit = ($searchQuery !== '' or $selectedChannel !== 'all' or $selectedGenre !== 'all' or $limitParam === 0) ? 1000 : max($limitParam, 100);

// Definition der Standard-DVB-Genres (Wert => [ID-Präfix, Label])
$genreList = [
    'movie'      => ['label' => 'Movie / Drama',                          'id' => 16],
    'news'       => ['label' => 'News / Current affairs',                 'id' => 32],
    'show'       => ['label' => 'Show / Game show',                      'id' => 48],
    'sports'     => ['label' => 'Sports',                                 'id' => 64],
    'children'   => ['label' => "Children's / Youth programs",            'id' => 80],
    'music'      => ['label' => 'Music / Ballet / Dance',                 'id' => 96],
    'arts'       => ['label' => 'Arts / Culture (without music)',         'id' => 112],
    'social'     => ['label' => 'Social / Political issues / Economics',  'id' => 128],
    'education'  => ['label' => 'Education / Science / Factual topics',   'id' => 144],
    'leisure'    => ['label' => 'Leisure hobbies',                        'id' => 160],
];

// 1. EPG-Daten für die Tabelle laden (mit dem aktuellen Senderfilter)
$epgData = $api->getEPG($fetchLimit, $searchQuery, $selectedChannel);
$rawEntries = $epgData['entries'] ?? [];

// 2. Vollständige Kanalliste für das Dropdown laden
$channels = [];
if (method_exists($api, 'getChannels')) {
    $allChannelsData = $api->getChannels();
    foreach ($allChannelsData['entries'] ?? [] as $ch) {
        $name = $ch['name'] ?? ($ch['val'] ?? '');
        if ($name !== '') {
            $channels[$name] = $name;
        }
    }
}

// Fallback: Falls getChannels nicht da ist oder leer war, ungefiltertes EPG-Sample für die Sendernamen nutzen
if (empty($channels)) {
    if ($selectedChannel === 'all') {
        foreach ($rawEntries as $event) {
            if (!empty($event['channelName'])) {
                $channels[$event['channelName']] = $event['channelName'];
            }
        }
    } else {
        $allEpgSample = $api->getEPG(300, '', 'all');
        foreach ($allEpgSample['entries'] ?? [] as $event) {
            if (!empty($event['channelName'])) {
                $channels[$event['channelName']] = $event['channelName'];
            }
        }
        if ($selectedChannel !== 'all') {
            $channels[$selectedChannel] = $selectedChannel;
        }
    }
}
ksort($channels);

$upcomingData = $api->getUpcoming();
$upcomingEntries = $upcomingData['entries'] ?? [];

// Event-IDs & Vorhandene Timer in Map speichern
$recordingMap = [];
$recordingTimes = [];

foreach ($upcomingEntries as $up) {
    $uUuid = $up['uuid'] ?? '';
    if (!empty($up['eventId'])) {
        $recordingMap[(int)$up['eventId']] = $uUuid;
    }
    if (!empty($up['broadcast'])) {
        $recordingMap[(int)$up['broadcast']] = $uUuid;
    }

    $uChan = $up['channel'] ?? ($up['channelname'] ?? '');
    $uStart = (int)($up['start'] ?? 0);
    if ($uChan !== '' and $uStart > 0) {
        $recordingTimes[] = [
            'uuid'    => $uUuid,
            'channel' => $uChan,
            'start'   => $uStart
        ];
    }
}

$now = time();

// Filter: Vergangene Sendungen ausblenden + Sender/Genre/Suche anwenden
$filteredEntries = array_filter($rawEntries, function($event) use ($selectedChannel, $selectedGenre, $searchQuery, $now,$genreList) {
    $stop = (int)($event['stop'] ?? 0);
    if ($stop > 0 and $stop <$now) {
        return false;
    }

    if ($selectedChannel !== 'all' and ($event['channelName'] ?? '') !==$selectedChannel) {
        return false;
    }
    
    // Genre-Filterung
    if ($selectedGenre !== 'all' and isset($genreList[$selectedGenre])) {$targetId = $genreList[$selectedGenre]['id'];
        $eventGenres =$event['genre'] ?? [];
        if (!is_array($eventGenres)) {$eventGenres = !empty($eventGenres) ? [$eventGenres] : [];
        }

        // Auch nach content / contentType / categories schauen, falls TVHeadend das Feld anders benennt
        if (empty($eventGenres) and !empty($event['content'])) {
            $eventGenres = is_array($event['content']) ? $event['content'] : [$event['content']];
        }

        $genreMatch = false;
        foreach ($eventGenres as$g) {
            $gInt = (int)$g;
            // DVB-Hauptkategorie prüfen (z. B. 16 bis 31 für Movie / Drama)
            if ($gInt >= $targetId and$gInt < ($targetId + 16)) {$genreMatch = true;
                break;
            }
            // Fallback für Textabgleich
            if (is_string($g) and stripos($g,$genreList[$selectedGenre]['label']) !== false) {$genreMatch = true;
                break;
            }
        }
        if (!$genreMatch) {
            return false;
        }
    }

    if ($searchQuery !== '') {
        $title =$event['title'] ?? '';
        $summary =$event['summary'] ?? '';
        $searchTerm = mb_strtolower($searchQuery);
        
        if (mb_strpos(mb_strtolower($title),$searchTerm) === false and mb_strpos(mb_strtolower($summary),$searchTerm) === false) {
            return false;
        }
    }
    return true;
});

if ($limitParam > 0) {$entries = array_slice($filteredEntries, 0,$limitParam);
} else {
    $entries =$filteredEntries;
}
?>

<style>
    .filter-bar {
        background: var(--card, #1e1e1e);
        padding: 15px 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        border: 1px solid #2a2a2a;
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        align-items: center;
        justify-content: space-between;
    }

    .filter-group {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .filter-control {
        background: #141414;
        border: 1px solid #3a3a3a;
        color: #fff;
        padding: 8px 12px;
        border-radius: 8px;
        font-size: 0.9em;
        outline: none;
    }

    .filter-btn {
        background: var(--accent, #00adb5);
        color: #fff;
        border: none;
        padding: 8px 16px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
    }

    .reset-link { color: #888; text-decoration: none; font-size: 0.85em; }
    .reset-link:hover { color: #fff; }

    .table-container {
        width: 100%;
        overflow-x: auto;
        background: var(--card, #1e1e1e);
        border-radius: 12px;
        border: 1px solid #2a2a2a;
    }

    .pvr-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.95em; }
    .pvr-table th {
        background: #252525;
        color: var(--accent, #00adb5);
        padding: 14px 16px;
        font-weight: 600;
        font-size: 0.8em;
        text-transform: uppercase;
        border-bottom: 2px solid #333;
    }

    .pvr-table td { padding: 14px 16px; border-bottom: 1px solid #2a2a2a; color: #ddd; vertical-align: middle; }
    .pvr-table tbody tr:hover { background: rgba(0, 173, 181, 0.05); }

    .channel-badge {
        background: #2a2a2a; color: #fff; padding: 4px 8px; border-radius: 6px;
        font-size: 0.85em; font-weight: 500; border: 1px solid #3a3a3a; white-space: nowrap;
    }

    .live-badge {
        background: #2ea44f; color: #fff; padding: 2px 6px; border-radius: 4px;
        font-size: 0.75em; font-weight: bold; margin-left: 6px; text-transform: uppercase;
    }

    .action-group {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
        align-items: center;
    }

    .btn-info {
        background: #2a2a2a;
        color: #00adb5;
        border: 1px solid #00adb5;
        padding: 8px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.85em;
        font-weight: bold;
        transition: all 0.2s;
    }

    .btn-info:hover {
        background: #00adb5;
        color: #fff;
    }

    .btn-record {
        background: #e63946;
        color: #fff;
        border: 1px solid #e63946;
        padding: 8px 14px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 0.85em;
        font-weight: bold;
        white-space: nowrap;
        display: inline-block;
    }

    .btn-record:hover { background: #b82e38; }

    .btn-record-cancel {
        background: #3a3a3a;
        color: #ff4d4d;
        border: 1px solid #ff4d4d;
        padding: 8px 14px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 0.85em;
        font-weight: bold;
        transition: all 0.2s ease;
        white-space: nowrap;
        display: inline-block;
    }

    .btn-record-cancel:hover {
        background: #ff4d4d;
        color: #fff;
    }

    .btn-live-stream {
        background: #00adb5;
        color: #fff;
        border: 1px solid #00adb5;
        padding: 8px 14px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.85em;
        font-weight: bold;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;
    }

    .btn-live-stream:hover {
        background: #00848a;
        border-color: #00848a;
        box-shadow: 0 2px 10px rgba(0, 173, 181, 0.4);
    }

    /* Modal / Pop-up Styles */
    .modal-backdrop {
        display: none;
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(3px);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }

    .modal-card {
        background: #1e1e1e;
        border: 1px solid #333;
        border-radius: 12px;
        max-width: 600px;
        width: 90%;
        max-height: 80vh;
        overflow-y: auto;
        padding: 24px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        position: relative;
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        border-bottom: 1px solid #333;
        padding-bottom: 12px;
        margin-bottom: 15px;
    }

    .modal-title { font-size: 1.2em; font-weight: bold; color: #fff; }
    .modal-close { background: none; border: none; color: #888; font-size: 1.4em; cursor: pointer; }
    .modal-close:hover { color: #fff; }
    .modal-body { color: #ccc; font-size: 0.95em; line-height: 1.5; }
    .modal-meta { font-size: 0.85em; color: #00adb5; margin-bottom: 10px; }

    .modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 20px;
        padding-top: 15px;
        border-top: 1px solid #333;
        flex-wrap: wrap;
    }
</style>

<h3 style="margin-bottom: 15px;">Programmübersicht (EPG)</h3>

<!-- Filter -->
<form method="GET" action="" class="filter-bar">
    <div class="filter-group">
        <input type="text" name="q" class="filter-control" placeholder="Programm suchen..." value="<?= htmlspecialchars($searchQuery) ?>" style="min-width: 180px;">
        <select name="channel" class="filter-control">
            <option value="all">Alle Sender</option>
            <?php foreach ($channels as$chan): ?>
                <option value="<?= htmlspecialchars($chan) ?>" <?= $selectedChannel ===$chan ? 'selected' : '' ?>>
                    <?= htmlspecialchars($chan) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="genre" class="filter-control">
            <option value="all">Alle Genres</option>
            <?php foreach ($genreList as $key =>$gInfo): ?>
                <option value="<?= htmlspecialchars($key) ?>" <?= $selectedGenre ===$key ? 'selected' : '' ?>>
                    <?= htmlspecialchars($gInfo['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="limit" class="filter-control">
            <option value="25" <?= $limitParam === 25 ? 'selected' : '' ?>>25 Zeilen</option>
            <option value="50" <?= $limitParam === 50 ? 'selected' : '' ?>>50 Zeilen</option>
            <option value="100" <?= $limitParam === 100 ? 'selected' : '' ?>>100 Zeilen</option>
            <option value="0" <?= $limitParam === 0 ? 'selected' : '' ?>>Alle Zeilen</option>
        </select>
        <button type="submit" class="filter-btn">Filtern</button>
    </div>

    <?php if ($searchQuery !== '' or$selectedChannel !== 'all' or $selectedGenre !== 'all' or$limitParam !== 50): ?>
        <div><a href="?" class="reset-link">✖ Filter zurücksetzen</a></div>
    <?php endif; ?>
</form>

<!-- Tabelle -->
<div class="table-container">
    <table class="pvr-table">
        <thead>
            <tr>
                <th>Sender</th>
                <th>Zeit</th>
                <th>Titel / Beschreibung</th>
                <th style="text-align: right;">Aktion</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($entries)): ?>
                <tr>
                    <td colspan="4" style="text-align: center; padding: 25px; color: #777;">
                        Keine passenden EPG-Einträge gefunden.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($entries as$event): ?>
                    <?php 
                        $startTime = (int)($event['start'] ?? 0);
                        $stopTime  = (int)($event['stop'] ?? 0);
                        
                        $isToday = (date('Y-m-d',$startTime) === date('Y-m-d', $now));$isTomorrow = (date('Y-m-d', $startTime) === date('Y-m-d',$now + 86400));
                        
                        if ($isToday) {$dateStr = 'Heute'; }
                        elseif ($isTomorrow) {$dateStr = 'Morgen'; }
                        else { $dateStr = date('d.m.Y',$startTime); }

                        $start = date('H:i', $startTime);$stop  = date('H:i', $stopTime);$isLive = ($now >=$startTime and $now <=$stopTime);

                        $channel       = htmlspecialchars($event['channelName'] ?? 'Unbekannt');$channelUuid   = htmlspecialchars($event['channelUuid'] ?? ($event['channel'] ?? ''));
                        $channelNumber = htmlspecialchars((string)($event['channelNumber'] ?? ''));
                        $rawTitle      =$event['title'] ?? 'Kein Titel';
                        $title         = htmlspecialchars($rawTitle);
                        $rawSummary    =$event['summary'] ?? ($event['subtitle'] ?? '');$summary       = htmlspecialchars($rawSummary);$rawDesc       = $event['description'] ?? $rawSummary;
                        $description   = htmlspecialchars($rawDesc);
                        $eventId       = (int)($event['eventId'] ?? 0);

                        // 1. Erkennung via EventId / Broadcast
                        $dvrUuid = $recordingMap[$eventId] ?? '';

                        // 2. Fallback-Erkennung via Channel + Startzeit (+/- 15 Min Toleranz)
                        if ($dvrUuid === '') {
                            foreach ($recordingTimes as$rec) {
                                if (($rec['channel'] ===$channelUuid or $rec['channel'] ===$event['channelName']) and abs($rec['start'] -$startTime) <= 900) {
                                    $dvrUuid =$rec['uuid'];
                                    break;
                                }
                            }
                        }

                        $isScheduled = ($dvrUuid !== '');

                        // Signiertes Streaming-Token für Live-TV erzeugen (12h gültig)
                        $liveTargetId = $channelUuid ?: $channelNumber;
                        $liveTokenData = $liveTargetId ? Auth::createStreamToken($liveTargetId, 12) : null;
                        $liveToken =$liveTokenData['token'] ?? '';
                        $liveExpires =$liveTokenData['expires'] ?? 0;

                        $recordUrl = 'edit/record_add.php?' . http_build_query([
                            'eventId'      => $eventId,
                            'channel_uuid' => $event['channelUuid'] ?? ($event['channel'] ?? ''),
                            'start'        => $startTime,
                            'stop'         => $stopTime,
                            'title'        => $rawTitle,
                            'subtitle'     => $rawSummary,
                            'description'  => $rawDesc
                        ]);
                    ?>
                    <tr>
                        <td><span class="channel-badge"><?= $channel ?></span></td>
                        <td>
                            <span style="color: #888; font-size: 0.85em; display: block;"><?= $dateStr ?></span>
                            <?= $start ?> - <?= $stop ?> Uhr
                            <?php if ($isLive): ?><span class="live-badge">Live</span><?php endif; ?>
                        </td>
                        <td>
                            <div style="font-weight: 600; color: #fff;"><?= $title ?></div>
                            <?php if ($summary): ?>
                                <div style="font-size: 0.8em; color: #888; margin-top: 3px; max-width: 450px;">
                                    <?= $summary ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <div class="action-group">
                                <button type="button" class="btn-info" 
                                        data-eventid="<?= $eventId ?>"
                                        data-channelnumber="<?= $channelNumber ?>"
                                        data-channeluuid="<?= $channelUuid ?>"
                                        data-livetoken="<?= htmlspecialchars($liveToken) ?>"
                                        data-liveexpires="<?= $liveExpires ?>"
                                        data-start="<?= $startTime ?>"
                                        data-stop="<?= $stopTime ?>"
                                        data-title="<?= $title ?>" 
                                        data-channel="<?= $channel ?>" 
                                        data-time="<?= $dateStr ?>, <?= $start ?> - <?= $stop ?> Uhr"
                                        data-desc="<?= $description ?: 'Keine weitere Beschreibung vorhanden.' ?>"
                                        data-isscheduled="<?= $isScheduled ? '1' : '0' ?>"
                                        data-dvruuid="<?= htmlspecialchars($dvrUuid) ?>"
                                        data-recordurl="<?= htmlspecialchars($recordUrl) ?>"
                                        onclick="openInfoModal(this)">
                                    ℹ Info
                                </button>

                                <?php if ($isScheduled): ?>
                                    <a href="edit/record_delete.php?uuid=<?= urlencode($dvrUuid) ?>" class="btn-record-cancel">
                                        🗑 Aufnahme löschen
                                    </a>
                                <?php else: ?>
                                    <a href="<?= $recordUrl ?>" class="btn-record">
                                        ⏺ Aufnehmen
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal Dialog -->
<div id="infoModal" class="modal-backdrop" onclick="closeInfoModal(event)">
    <div class="modal-card">
        <div class="modal-header">
            <div>
                <div id="modalTitle" class="modal-title"></div>
                <div id="modalMeta" class="modal-meta"></div>
            </div>
            <button class="modal-close" onclick="document.getElementById('infoModal').style.display='none'">&times;</button>
        </div>
        <div id="modalBody" class="modal-body"></div>
        <div class="modal-footer" id="modalFooter"></div>
    </div>
</div>

<script>
let currentLiveChannelNumber = '';
let currentLiveChannelUuid = '';
let currentLiveToken = '';
let currentLiveExpires = 0;

function openInfoModal(btn) {
    const isScheduled = btn.getAttribute('data-isscheduled') === '1';
    const dvrUuid = btn.getAttribute('data-dvruuid');
    const recordUrl = btn.getAttribute('data-recordurl');
    
    currentLiveChannelNumber = btn.getAttribute('data-channelnumber') || '';
    currentLiveChannelUuid = btn.getAttribute('data-channeluuid') || '';
    currentLiveToken = btn.getAttribute('data-livetoken') || '';
    currentLiveExpires = parseInt(btn.getAttribute('data-liveexpires') || '0', 10);

    const startTime = parseInt(btn.getAttribute('data-start') || '0', 10);
    const stopTime = parseInt(btn.getAttribute('data-stop') || '0', 10);
    const now = Math.floor(Date.now() / 1000);
    const hasIdentifier = (currentLiveChannelNumber !== '' || currentLiveChannelUuid !== '');
    const isCurrentlyLive = (now >= startTime && now <= stopTime && hasIdentifier);

    document.getElementById('modalTitle').innerText = btn.getAttribute('data-title');
    document.getElementById('modalMeta').innerText = btn.getAttribute('data-channel') + ' | ' + btn.getAttribute('data-time');
    document.getElementById('modalBody').innerText = btn.getAttribute('data-desc');

    const footer = document.getElementById('modalFooter');
    let buttonsHtml = '';

    if (isCurrentlyLive) {
        buttonsHtml += `
            <button type="button" class="btn-live-stream" onclick="triggerLiveStream()">
                ▶ Jetzt live ansehen
            </button>
        `;
    }

    if (isScheduled) {
        buttonsHtml += `
            <a href="edit/record_delete.php?uuid=${encodeURIComponent(dvrUuid)}" class="btn-record-cancel">
                🗑 Aufnahme löschen
            </a>
        `;
    } else {
        buttonsHtml += `
            <a href="${recordUrl}" class="btn-record">
                ⏺ Aufnehmen
            </a>
        `;
    }

    footer.innerHTML = buttonsHtml;
    document.getElementById('infoModal').style.display = 'flex';
}

function triggerLiveStream() {
    let queryParams = [];
    if (currentLiveChannelUuid !== '') {
        queryParams.push('channel_uuid=' + encodeURIComponent(currentLiveChannelUuid));
    } else if (currentLiveChannelNumber !== '') {
        queryParams.push('channel_number=' + encodeURIComponent(currentLiveChannelNumber));
    } else {
        return;
    }

    if (currentLiveToken !== '') {
        queryParams.push('token=' + encodeURIComponent(currentLiveToken));
        queryParams.push('expires=' + currentLiveExpires);
    }

    const isAndroid = /Android/i.test(navigator.userAgent);
    const streamUrl = window.location.origin + '/modules/stream.php?' + queryParams.join('&');

    if (isAndroid) {
        const protocol = window.location.protocol.replace(':', '');
        const rawUrl = streamUrl.replace(/^https?:\/\//i, '');
        const intentUrl = 'intent://' + rawUrl + '#Intent;package=org.videolan.vlc;type=video/*;scheme=' + protocol + ';end';
        window.location.href = intentUrl;
    } else {
        window.location.href = streamUrl + '&m3u=1';
    }
}

function closeInfoModal(e) {
    if (e.target.id === 'infoModal') {
        document.getElementById('infoModal').style.display = 'none';
    }
}
</script>