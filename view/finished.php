<?php
// view/finished.php
date_default_timezone_set('Europe/Berlin');

$api = new TVHeadendAPI();
$finishedData =$api->getFinished();
$entries =$finishedData['entries'] ?? [];

// Neueste Aufnahmen zuerst (absteigend nach Startzeitpunkt sortieren)
if (!empty($entries)) {
    usort($entries, function ($a,$b) {
        $startA = (int)($a['start'] ?? 0);
        $startB = (int)($b['start'] ?? 0);
        return $startB <=>$startA;
    });
}
?>


<h3 style="margin-bottom: 15px;">Aufnahmen-Archiv</h3>

<div class="table-container">
    <table class="pvr-table">
        <thead>
            <tr>
                <th>Sender</th>
                <th>Titel</th>
                <th>Datum & Zeit</th>
                <th>Größe</th>
                <th style="text-align: right;">Aktion</th>
            </tr>
        </thead>
        <tbody id="finishedTableBody">
            <?php if (empty($entries)): ?>
                <tr id="row-empty">
                    <td colspan="5" style="text-align: center; padding: 25px; color: #777;">
                        Keine fertigen Aufnahmen im Archiv gefunden.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($entries as$item): ?>
                    <?php 
                        $uuid = htmlspecialchars($item['uuid'] ?? '');$title = htmlspecialchars($item['disp_title'] ?? 'Unbekannt');$subtitle = htmlspecialchars($item['disp_extratext'] ?? ($item['disp_subtitle'] ?? ''));
                        $channel = htmlspecialchars($item['channelname'] ?? 'Kanal');
                        $date = date('d.m.Y H:i', (int)($item['start'] ?? 0));
                        $duration = (int)($item['duration'] ?? 0);
                        
                        $bytes = (int)($item['filesize'] ?? 0);
                        if ($bytes >= 1073741824) {
                            $filesize = number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
                        } else {
                            $filesize = number_format($bytes / 1048576, 1, ',', '.') . ' MB';
                        }

                        // Relativen Pfad unter /var/radio/video/aufnahmen für Apache ermitteln
                        $fullPath =$item['filename'] ?? ($item['path'] ?? ($item['files'][0]['filename'] ?? ''));
                        $baseDir  = '/var/radio/video/aufnahmen/';$relPath  = '';
                        if (str_starts_with($fullPath, $baseDir)) {$relPath = substr($fullPath, strlen($baseDir));
                        }
                    ?>
                    <tr id="row-<?= $uuid ?>">
                        <td>
                            <span class="channel-badge"><?= $channel ?></span>
                        </td>
                        <td>
                            <div class="title-cell"><?= $title ?></div>
                            <?php if ($subtitle): ?>
                                <div style="font-size: 0.8em; color: #888; margin-top: 2px;"><?= $subtitle ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="date-cell"><?= $date ?> Uhr</td>
                        <td class="size-cell"><?= $filesize ?></td>
                        <td style="text-align: right;">
                            <div class="action-control-group">
                                <select class="select-player" id="playerSelect_<?= $uuid ?>">
                                    <option value="vlc">VLC Player</option>
                                    <option value="web">Web-Player</option>
                                </select>
                                <button type="button" class="btn-play-action"
                                        data-uuid="<?= $uuid ?>"
                                        data-relpath="<?= htmlspecialchars($relPath) ?>"
                                        data-title="<?= $title ?>"
                                        data-duration="<?= $duration ?>"
                                        onclick="handlePlayAction(this)">
                                    ▶ Start
                                </button>
                                <button type="button" class="btn-delete-action"
                                        data-uuid="<?= $uuid ?>"
                                        data-title="<?= $title ?>"
                                        title="Aufnahme löschen"
                                        onclick="handleDeleteAction(this)">
                                    🗑
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Video Modal -->
<div id="videoModal" class="modal-backdrop" onclick="closeVideoModal(event)">
    <div class="modal-card-video">
        <div class="modal-header">
            <div id="videoTitle" class="modal-title"></div>
            <button class="modal-close" onclick="stopAndCloseModal()">&times;</button>
        </div>
        <div class="video-wrapper">
            <video id="player" class="video-player" controls preload="metadata">
                Dein Browser unterstützt diesen Videostream nicht direkt.
            </video>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/mpegts.js@latest/dist/mpegts.js"></script>

<script>
let flvPlayer = null;

function handlePlayAction(btn) {
    const uuid = btn.getAttribute('data-uuid');
    const relPath = btn.getAttribute('data-relpath') || '';
    const title = btn.getAttribute('data-title');
    const duration = btn.getAttribute('data-duration') || '0';
    const select = document.getElementById('playerSelect_' + uuid);
    const chosen = select ? select.value : 'vlc';

    let directStreamUrl = '';
    if (relPath !== '') {
        const parts = relPath.split('/').map(encodeURIComponent).join('/');
        directStreamUrl = window.location.origin + '/recordings/' + parts;
    } else {
        directStreamUrl = window.location.origin + '/modules/stream.php?uuid=' + encodeURIComponent(uuid);
    }

    if (chosen === 'vlc') {
        const isAndroid = /Android/i.test(navigator.userAgent);

        if (isAndroid) {
            const protocol = window.location.protocol.replace(':', '');
            const rawUrl = directStreamUrl.replace(/^https?:\/\//i, '');
            const intentUrl = 'intent://' + rawUrl + '#Intent;package=org.videolan.vlc;type=video/*;scheme=' + protocol + ';end';
            window.location.href = intentUrl;
        } else {
            const durVal = (parseInt(duration, 10) > 0) ? duration : '-1';
            const m3uContent = "#EXTM3U\r\n#EXTINF:" + durVal + "," + title + "\r\n" + directStreamUrl + "\r\n";
            const blob = new Blob([m3uContent], { type: 'audio/x-mpegurl' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = (title.replace(/[^a-zA-Z0-9_-]/g, '_')) + '.m3u';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    } else {
        openWebPlayer(directStreamUrl, title);
    }
}

function handleDeleteAction(btn) {
    const uuid = btn.getAttribute('data-uuid');
    const title = btn.getAttribute('data-title') || 'diese Aufnahme';

    if (!confirm(`Möchtest du die Aufnahme "${title}" wirklich löschen?`)) {
        return;
    }

    btn.disabled = true;
    btn.style.opacity = '0.5';

    const formData = new FormData();
    formData.append('uuid', uuid);

    fetch('edit/record_delete.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const row = document.getElementById('row-' + uuid);
            if (row) {
                row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                row.style.opacity = '0';
                row.style.transform = 'translateX(20px)';
                setTimeout(() => {
                    row.remove();
                    const tbody = document.getElementById('finishedTableBody');
                    if (tbody && tbody.querySelectorAll('tr').length === 0) {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 25px; color: #777;">
                                    Keine fertigen Aufnahmen im Archiv gefunden.
                                </td>
                            </tr>
                        `;
                    }
                }, 300);
            }
        } else {
            alert('Fehler beim Löschen: ' + (data.error || 'Unbekannter Fehler'));
            btn.disabled = false;
            btn.style.opacity = '1';
        }
    })
    .catch(err => {
        alert('Netzwerk- oder Serverfehler beim Löschen.');
        console.error(err);
        btn.disabled = false;
        btn.style.opacity = '1';
    });
}

function openWebPlayer(url, title) {
    const videoElement = document.getElementById('player');
    document.getElementById('videoTitle').innerText = title;
    document.getElementById('videoModal').style.display = 'flex';

    if (mpegts.isSupported()) {
        if (flvPlayer) {
            flvPlayer.destroy();
        }

        flvPlayer = mpegts.createPlayer({
            type: 'mse',
            isLive: false,
            url: url,
            hasAudio: true,
            hasVideo: true
        }, {
            enableWorker: true,
            lazyLoad: false
        });

        flvPlayer.attachMediaElement(videoElement);
        flvPlayer.load();
        flvPlayer.play();
    } else {
        videoElement.src = url;
        videoElement.play();
    }
}

function stopAndCloseModal() {
    const videoElement = document.getElementById('player');
    if (flvPlayer) {
        flvPlayer.pause();
        flvPlayer.unload();
        flvPlayer.detachMediaElement();
        flvPlayer.destroy();
        flvPlayer = null;
    }
    videoElement.pause();
    videoElement.src = '';
    document.getElementById('videoModal').style.display = 'none';
}

function closeVideoModal(e) {
    if (e.target.id === 'videoModal') {
        stopAndCloseModal();
    }
}
</script>