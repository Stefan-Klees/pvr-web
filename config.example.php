<?php
// config.example.php
define('TVH_HOST', 'http://127.0.0.1:9981'); // Lokal über Loopback
define('TVH_USER', 'User');                       // Anpassen
define('TVH_PASS', 'Password');                 // Anpassen

// Fehlerberichterstattung für Entwicklung
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Neues Passwort für die Weboberfläche (bitte anpassen!)
define('AUTH_PASSWORD', 'Password');

// Geheimer Schlüssel für Token-Signaturen (bitte eine zufällige Zeichenkette wählen!)
define('AUTH_SECRET', 'blablabladiekatzeliegtimschnee');