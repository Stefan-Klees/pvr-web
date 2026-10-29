<?php
// view/upcoming.php
date_default_timezone_set('Europe/Berlin');

$api = new TVHeadendAPI();
$upcomingData = $api->getUpcoming();
$entries = $upcomingData['entries'] ?? [];

$now = time();

// Chronologisch nach Startzeit (Aufnahmedatum) aufsteigend sortieren
if (!empty($entries)) {
    usort($entries, function ($a, $b) {
        $startA = (int)($a['start'] ?? 0);
        $startB = (int)($b['start'] ?? 0);
        return $startA <=> $startB;
    });
}

// Zeitliche Überschneidungen ermitteln (Intervall-Kollision: startA < stopB && startB < stopA)
$overlappingUuids = [];
$count = count($entries);
for ($i = 0; $i < $count; $i++) {
    $startI = (int)($entries[$i]['start'] ?? 0);
    $stopI  = (int)($entries[$i]['stop'] ?? 0);
    $uuidI  = $entries[$i]['uuid'] ?? (string)$i;

    for ($j = $i + 1; $j < $count; $j++) {
        $startJ = (int)($entries[$j]['start'] ?? 0);
        $stopJ  = (int)($entries[$j]['stop'] ?? 0);
        $uuidJ  = $entries[$j]['uuid'] ?? (string)$j;

        if ($startI < $stopJ && $startJ < $stopI) {
            $overlappingUuids[$uuidI] = true;
            $overlappingUuids[$uuidJ] = true;
        }
    }
}
?>

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
                <?php foreach ($entries as $index => $timer): ?>
                    <?php 
                        $title = htmlspecialchars($timer['disp_title'] ?? ($timer['title']['ger'] ?? ($timer['title'] ?? 'Aufnahme')));
                        $subtitle = htmlspecialchars($timer['disp_extratext'] ?? ($timer['disp_subtitle'] ?? ($timer['extra'] ?? ($timer['subtitle'] ?? ''))));
                        $rawDesc = !empty($timer['comment']) ? $timer['comment'] : (!empty($timer['disp_description']) ? $timer['disp_description'] : ($timer['description'] ?? ''));
                        $description = htmlspecialchars($rawDesc);
                        $channel = htmlspecialchars($timer['channelname'] ?? ($timer['channel_name'] ?? 'Kanal'));
                        
                        $startTimestamp = (int)($timer['start'] ?? 0);
                        $stopTimestamp  = (int)($timer['stop'] ?? 0);

                        $dateStr   = date('d.m.Y', $startTimestamp);
                        $startTime = date('H:i', $startTimestamp);
                        $stopTime  = ($stopTimestamp > 0) ? date('H:i', $stopTimestamp) : '--:--';

                        $uuid = $timer['uuid'] ?? (string)$index;

                        $statusRaw = strtolower($timer['status'] ?? ($timer['sched_state'] ?? ''));
                        $isRecording = ($statusRaw === 'recording') || ($startTimestamp <= $now && $stopTimestamp > $now);
                        $isOverlap = isset($overlappingUuids[$uuid]);

                        $rowClasses = [];
                        if ($isRecording) $rowClasses[] = 'is-recording';
                        if ($isOverlap) $rowClasses[] = 'is-overlap';
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
                            <?php if ($description && $description !== $subtitle): ?>
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
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(async res => {
        // Falls der Server ein Redirect liefert oder die Antwort kein reines JSON ist
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            // Falls record_delete.php noch per 302 umleitet, aber der Löschvorgang erfolgreich war:
            if (res.ok || res.redirected) {
                return { success: true };
            }
            throw new Error('Ungültige Serverantwort: ' + text.substring(0, 100));
        }
    })
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
                            <tr id="row-empty">
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
        alert('Netzwerk- oder Serverfehler beim Löschen: ' + err.message);
        console.error(err);
        btn.disabled = false;
        btn.style.opacity = '1';
    });
}
</script>