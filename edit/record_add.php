<?php
// edit/record_add.php
require_once '../config.php';
require_once '../modules/TVHeadendAPI.php';

$eventId     = (int)($_POST['eventId'] ?? $_GET['eventId'] ?? 0);
$channelUuid = $_POST['channel_uuid'] ?? $_GET['channel_uuid'] ?? '';
$start       = (int)($_POST['start'] ?? $_GET['start'] ?? 0);
$stop        = (int)($_POST['stop'] ?? $_GET['stop'] ?? 0);
$title       = $_POST['title'] ?? $_GET['title'] ?? '';
$subtitle    = $_POST['subtitle'] ?? $_GET['subtitle'] ?? '';
$description = $_POST['description'] ?? $_GET['description'] ?? '';

$success = false;

if ($channelUuid !== '' && $start > 0 && $stop > 0) {
    $api = new TVHeadendAPI();
    $success = $api->createRecord([
        'eventId'      => $eventId,
        'channel_uuid' => $channelUuid,
        'start'        => $start,
        'stop'         => $stop,
        'title'        => $title,
        'subtitle'     => $subtitle,
        'description'  => $description
    ]);
}

$referer = $_SERVER['HTTP_REFERER'] ?? '';
if (!empty($referer)) {
    header('Location: ' . $referer);
} else {
    header('Location: ../index.php?page=epg&status=' . ($success ? 'ok' : 'error'));
}
exit;