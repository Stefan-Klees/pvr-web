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
                                        title="Sendungsdetails anzeigen"
                                        onclick="openInfoModal(this)">
                                    ℹ
                                </button>

                                <?php if ($isScheduled): ?>
                                    <a href="edit/record_delete.php?uuid=<?= urlencode($dvrUuid) ?>" class="btn-record-cancel" title="Timer / Aufnahme löschen">
                                        🗑
                                    </a>
                                <?php else: ?>
                                    <a href="<?= $recordUrl ?>" class="btn-record" title="Sendung zur Aufnahme vormerken">
                                        ⏺
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