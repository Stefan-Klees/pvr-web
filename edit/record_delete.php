<?php
// edit/record_delete.php
require_once '../config.php';
require_once '../modules/TVHeadendAPI.php';

$uuid = trim($_GET['uuid'] ?? '');

if (!empty($uuid)) {
    $api = new TVHeadendAPI();
    $api->cancelEntry($uuid);
}

// Rückleitung je nach Herkunftsseite
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if (!empty($referer) and str_contains($referer, 'page=epg')) {
    header('Location: ../index.php?page=epg');
} else {
    header('Location: ../index.php?page=upcoming');
}
exit;