<?php
// view/upcoming.php
date_default_timezone_set('Europe/Berlin');

$api = new TVHeadendAPI();
$upcomingData =$api->getUpcoming();
$entries =$upcomingData['entries'] ?? [];

$now = time();

// Chronologisch nach Startzeit (Aufnahmedatum) aufsteigend sortieren
if (!empty($entries)) {
    usort($entries, function ($a,$b) {
        $startA = (int)($a['start'] ?? 0);
        $startB = (int)($b['start'] ?? 0);
        return $startA <=>$startB;
    });
}

// Zeitliche Überschneidungen ermitteln (Intervall-Kollision: startA < stopB && startB < stopA)
$overlappingUuids = [];
$count = count($entries);
for ($i = 0; $i < $count; $i++) {
    $startI = (int)($entries[$i]['start'] ?? 0);$stopI  = (int)($entries[$i]['stop'] ?? 0);
    $uuidI  =$entries[$i]['uuid'] ?? (string)$i;

    for ($j = $i + 1; $j < $count; $j++) {
        $startJ = (int)($entries[$j]['start'] ?? 0);$stopJ  = (int)($entries[$j]['stop'] ?? 0);
        $uuidJ  =$entries[$j]['uuid'] ?? (string)$j;

        if ($startI <$stopJ && $startJ <$stopI) {
            $overlappingUuids[$uuidI] = true;
            $overlappingUuids[$uuidJ] = true;
        }
    }
}
?>

<style>
    .table-container {
        width: 100%;
        overflow-x: auto;
        background: var(--card);
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        border: 1px solid #2a2a2a;
    }

    .pvr-table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
        font-size: 0.95em;
    }

    .pvr-table th {
        background: #252525;
        color: var(--accent);
        padding: 14px 16px;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.8em;
        letter-spacing: 0.5px;
        border-bottom: 2px solid #333;
    }

    .pvr-table td {
        padding: 14px 16px;
        border-bottom: 1px solid #2a2a2a;
        color: #ddd;
        vertical-align: middle;
    }

    .pvr-table tbody tr:last-child td {
        border-bottom: none;
    }

    .pvr-table tbody tr:hover {
        background: rgba(0, 173, 181, 0.05);
    }

    /* Hervorhebung für aktiv laufende Aufnahmen */
    .pvr-table tbody tr.is-recording {
        background: rgba(230, 57, 70, 0.08);
    }
    .pvr-table tbody tr.is-recording:hover {
        background: rgba(230, 57, 70, 0.14);
    }

    /* Dezenter Rand für Überschneidungen */
    .pvr-table tbody tr.is-overlap {
        border-left: 3px solid #ffb703;
    }

    .channel-badge {
        background: #2a2a2a;
        color: #fff;
        padding: 4px 8px;
        border-radius: 6px;
        font-size: 0.85em;
        font-weight: 500;
        display: inline-block;
        border: 1px solid #3a3a3a;
    }

    .title-cell {
        font-weight: 600;
        color: #fff;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    /* Pulsierendes REC-Badge */
    .rec-badge {
        background: #e63946;
        color: #fff;
        font-size: 0.72em;
        font-weight: 800;
        letter-spacing: 0.5px;
        padding: 2px 7px;
        border-radius: 4px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        animation: recPulse 1.8s infinite;
    }

    /* Warn-Badge für Überschneidungen */
    .overlap-badge {
        background: rgba(255, 183, 3, 0.18);
        color: #ffb703;
        border: 1px solid #ffb703;
        font-size: 0.72em;
        font-weight: 700;
        letter-spacing: 0.4px;
        padding: 2px 6px;
        border-radius: 4px;
        display: inline-flex;
        align-items: center;
        gap: 3px;
        white-space: nowrap;
    }

    @keyframes recPulse {
        0% { opacity: 1; }
        50% { opacity: 0.45; }
        100% { opacity: 1; }
    }

    .subtitle-cell {
        font-size: 0.85em;
        color: #00adb5;
        margin-top: 3px;
    }

    .description-cell {
        font-size: 0.8em;
        color: #888;
        margin-top: 4px;
        max-width: 550px;
        line-height: 1.4;
    }

    .date-cell {
        color: #aaa;
        white-space: nowrap;
        font-size: 0.9em;
    }

    .date-label {
        color: #fff;
        font-weight: 500;
        display: block;
        margin-bottom: 2px;
    }

    .btn-delete-action {
        background: #2a1517;
        color: #ff5252;
        border: 1px solid #5a2022;
        padding: 6px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.85em;
        font-weight: bold;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .btn-delete-action:hover {
        background: #ff5252;
        color: #fff;
        border-color: #ff5252;
    }
</style>

<h3 style="margin-bottom: 15px;">Geplante Aufnahmen</h3>

<div class="table-container">
    <table class="pvr-table">
        <thead>
            <tr>
                <th>Sender</th>
                <th>Titel / Beschreibung</th>
                <th>Aufnahmezeit</th>
                <th style="text-align: right;">Aktion</th>
            </tr>
        </thead>
        <tbody id="upcomingTableBody">
            <?php if (empty($entries)): ?>
                <tr id="row-empty">
                    <td colspan="4" style="text-align: center; padding: 25px; color: #777;">
                        Aktuell keine geplante Aufnahmen vorhanden.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($entries as $index =>$timer): ?>
                    <?php 
                        $title = htmlspecialchars($timer['disp_title'] ?? ($timer['title']['ger'] ?? ($timer['title'] ?? 'Aufnahme')));
                        $subtitle = htmlspecialchars($timer['disp_extratext'] ?? ($timer['disp_subtitle'] ?? ($timer['extra'] ?? ($timer['subtitle'] ?? ''))));$rawDesc = !empty($timer['comment']) ?$timer['comment'] : (!empty($timer['disp_description']) ?$timer['disp_description'] : ($timer['description'] ?? ''));$description = htmlspecialchars($rawDesc);$channel = htmlspecialchars($timer['channelname'] ?? ($timer['channel_name'] ?? 'Kanal'));
                        
                        $startTimestamp = (int)($timer['start'] ?? 0);
                        $stopTimestamp  = (int)($timer['stop'] ?? 0);

                        $dateStr   = date('d.m.Y', $startTimestamp);$startTime = date('H:i', $startTimestamp);$stopTime  = ($stopTimestamp > 0) ? date('H:i', $stopTimestamp) : '--:--';

                        $uuid = $timer['uuid'] ?? (string)$index;

                        $statusRaw = strtolower($timer['status'] ?? ($timer['sched_state'] ?? ''));
                        $isRecording = ($statusRaw === 'recording') || ($startTimestamp <= $now && $stopTimestamp > $now);
                        $isOverlap = isset($overlappingUuids[$uuid]);


                        $rowClasses = [];
                        if ($isRecording)$rowClasses[] = 'is-recording';
                        if ($isOverlap)$rowClasses[] = 'is-overlap';
                    ?>
                    <tr id="row-<?= htmlspecialchars($uuid) ?>" class="<?= implode(' ', $rowClasses) ?>">
                        <td>
                            <span class="channel-badge"><?= $channel ?></span>
                        </td>
                        <td>
                            <div class="title-cell">
                                <span><?= $title ?></span>
                                <?php if ($isRecording): ?>
                                    <span class="rec-badge">● REC</span>
                                <?php endif; ?>
                                <?php if ($isOverlap): ?>
                                    <span class="overlap-badge" title="Gleichzeitige Aufnahme mit mindestens einem weiteren Timer">⚠ Überschneidung</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($subtitle): ?>
                                <div class="subtitle-cell"><?= $subtitle ?></div>
                            <?php endif; ?>
                            <?php if ($description && $description !==$subtitle): ?>
                                <div class="description-cell"><?= $description ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="date-cell">
                            <span class="date-label"><?= $dateStr ?></span>
                            <?= $startTime ?> - <?= $stopTime ?> Uhr
                        </td>
                        <td style="text-align: right;">
                            <button type="button" class="btn-delete-action"
                                    data-uuid="<?= htmlspecialchars($uuid) ?>"
                                    data-title="<?= $title ?>"
                                    data-recording="<?= $isRecording ? '1' : '0' ?>"
                                    title="<?= $isRecording ? 'Laufende Aufnahme abbrechen' : 'Timer löschen' ?>"
                                    onclick="handleDeleteTimer(this)">
                                <?= $isRecording ? '⏹ Stoppen' : '🗑' ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function handleDeleteTimer(btn) {
    const uuid = btn.getAttribute('data-uuid');
    const title = btn.getAttribute('data-title') || 'diesen Timer';
    const isRecording = btn.getAttribute('data-recording') === '1';

    const promptText = isRecording
        ? `Die Aufnahme "${title}" läuft gerade.\nMöchtest du sie wirklich vorzeitig stoppen und löschen?`
        : `Möchtest du den Timer für "${title}" wirklich löschen?`;

    if (!confirm(promptText)) {
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
                    const tbody = document.getElementById('upcomingTableBody');
                    if (tbody && tbody.querySelectorAll('tr').length === 0) {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 25px; color: #777;">
                                    Aktuell keine geplante Aufnahmen vorhanden.
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
</script>