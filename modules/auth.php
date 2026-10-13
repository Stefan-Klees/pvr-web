<?php
// modules/auth.php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    $lifetime = 86400 * 90;

    ini_set('session.gc_maxlifetime', (string)$lifetime);

    // Eigener Session-Ordner für die PVR-App (wird nicht vom Ubuntu-Systemdienst bereinigt):
    $pvrSessionDir = __DIR__ . '/../sessions';
    if (!is_dir($pvrSessionDir)) {
        @mkdir($pvrSessionDir, 0700, true);
    }
    session_save_path($pvrSessionDir);

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}


class Auth {
    // Prüft, ob der Nutzer im Web-Interface angemeldet ist
    public static function checkWebAccess(): void {
        if (!empty($_SESSION['pvr_authenticated'])) {
            return;
        }

        // Falls noch nicht eingeloggt, zur Login-Seite weiterleiten
        header('Location: login.php');
        exit;
    }

    // Erzeugt ein signiertes Token für VLC (Standard: 12 Stunden gültig)
    public static function createStreamToken(string $targetId, int $validHours = 12): array {
        $expires = time() + ($validHours * 3600);
        $payload = $targetId . '|' . $expires;
        $hash = hash_hmac('sha256', $payload, AUTH_SECRET);

        return [
            'expires' => $expires,
            'token'   => $hash
        ];
    }

    // Validiert ein übergebenes Stream-Token
    public static function verifyStreamToken(string $targetId, string $token, int $expires): bool {
        if (time() > $expires) {
            return false; // Abgelaufen
        }

        $expectedPayload = $targetId . '|' . $expires;
        $expectedHash = hash_hmac('sha256', $expectedPayload, AUTH_SECRET);

        return hash_equals($expectedHash, $token);
    }
}