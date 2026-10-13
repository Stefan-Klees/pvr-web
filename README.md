# PVR Web-Interface für TVHeadend

Ein schlankes, responsives PHP-Web-Interface zur Verwaltung und Überwachung von Aufnahmen eines lokalen [TVHeadend](https://tvheadend.org/)-Servers.

---

## Features

- **Übersicht anstehender Aufnahmen (`upcoming.php`):** Liste aller geplanten Aufnahmen inklusive Anzeige des aktuellen Aufnahme-Status (Live-REC-Badge).
- **Überschneidungsprüfung:** Automatische Erkennung zeitlicher Konflikte zwischen parallelen Aufnahmen.
- **Abgeschlossene Aufnahmen (`finished.php`):** Archivierte und fertige Aufnahmen einsehen und verwalten.
- **EPG-Ansicht (`epg.php`):** Elektronischer Programmführer zum schnellen Durchstöbern des TV-Programms.
- **Direktes Planen & Löschen:** Neue Timer anlegen (`record_add.php`) oder bestehende löschen (`record_delete.php`).
- **Sitzungsverwaltung:** Sichere Token-basierte Authentifizierung mit anpassbarer Session-Lebensdauer.

---

## Voraussetzungen

- **Webserver:** Apache oder Nginx mit **PHP 8.x**
- **PHP-Erweiterungen:** `curl`, `json`, `session`
- **TVHeadend:** Laufende TVHeadend-Instanz mit aktiviertem HTTP-API-Zugriff

---

## Installation & Einrichtung

1. **Repository klonen / bereitstellen:**
   ```bash
   git clone [https://github.com/Stefan-Klees/pvr-web.git](https://github.com/Stefan-Klees/pvr-web.git) /var/www/pvr
   cd /var/www/pvr

2. **Konfiguration erstellen:**
    Kopiere die Vorlage und trage deine Zugangsdaten ein:

    cp config.example.php config.php
    nano config.php

3. **Parameter anpassen:**

    TVH_HOST: URL/IP deines TVHeadend-Servers (z. B. http://127.0.0.1:9981)

    TVH_USER / TVH_PASS: Zugangsdaten für das TVHeadend-API

    AUTH_PASSWORD: Passwort für den Login in die Weboberfläche

    AUTH_SECRET: Beliebige zufällige Zeichenkette für Tokens

4.  **Berechtigungen setzen:**
    Stelle sicher, dass der Webserver Schreibrechte auf die Verzeichnisse sessions/ und data/ besitzt:

    sudo chown -R www-data:www-data sessions/ data/
    
    sudo chmod -R 775 sessions/ data/

    
    
    
    ## Lizenz
    Dieses Projekt ist unter der GNU General Public License v3 lizenziert – siehe die [LICENSE](LICENSE) Datei für Details.
