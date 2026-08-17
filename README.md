# BNI DACH Finder

Lokaler Proof of Concept zum kontrollierten Auslesen und lokalen Speichern öffentlicher BNI-Chapterdaten für Deutschland und Österreich.

## Start

Voraussetzung sind Docker und Docker Compose.

```bash
docker compose up -d --build
```

Danach ist die Anwendung unter <http://localhost:8082/> erreichbar.

## Bedienung

1. Den voreingestellten BNI-DACH-Link beibehalten und **Auslesen** anklicken.
2. Die Anwendung lädt mit genau einem serverseitigen Request die öffentlichen Kartengrunddaten.
3. Die Tabelle kann ohne weitere BNI-Requests nach Land, Typ, `orgId` und bereits geladenem Chapternamen gefiltert werden.
4. Einzelne Zeilen markieren oder mit **Alle sichtbaren auswählen** nur die aktuelle Filtermenge auswählen.
5. Mit **Details für ausgewählte laden** höchstens 50 Datensätze abrufen.

Detailrequests laufen sequenziell und mit 300 ms Abstand. Bereits lokal gespeicherte Details werden nach einem Browser-Neuladen wieder angezeigt und standardmäßig nicht erneut bei BNI abgefragt. Die Checkbox **Bereits geladene Details erneut abrufen** erlaubt eine bewusste Aktualisierung.

## SQLite-Datenbank

Die Anwendung verwendet PDO SQLite. Das Schema wird beim ersten Datenbankzugriff automatisch erzeugt; eine manuelle SQL-Installation ist nicht erforderlich.

- Hostpfad: `./data/bni-dach.sqlite`
- Containerpfad: `/var/www/data/bni-dach.sqlite`
- Persistenz: `./data` ist als Docker-Bind-Mount eingebunden und bleibt bei Container-Neubauten erhalten.
- Webschutz: Die Datenbank liegt außerhalb von `/var/www/html` und kann nicht durch Apache ausgeliefert werden.
- Git: SQLite-Datei sowie Journal-/WAL-Dateien sind in `.gitignore` ausgeschlossen.

Beim Auslesen werden die Kartengrunddaten per UPSERT gespeichert. Vorhandene Detailfelder bleiben dabei erhalten. Erfolgreiche selektive Detailabrufe aktualisieren nur gelieferte Werte; fehlende BNI-Werte löschen keine bereits gespeicherten Angaben. Kleine additive Schemaerweiterungen werden beim Start automatisch angewendet.

### Datenbank lokal zurücksetzen

Es erfolgt niemals ein automatischer Reset. Für einen bewussten manuellen Reset zunächst den lokalen Container stoppen und danach ausschließlich diese Projektdatei löschen:

```bash
docker compose down
rm data/bni-dach.sqlite
docker compose up -d
```

Beim nächsten API-Zugriff wird eine leere Datenbank mit aktuellem Schema angelegt.

## Lokale API

### Health

```text
GET /api/health.php
```

### Kartengrunddaten

```text
GET /api/bni/map.php?url=https%3A%2F%2Fbni.de%2Fde%2Ffindachapter
```

Der Browser übergibt den BNI-DACH-Seitenlink nur zur Prüfung. Serverzugriffe sind auf HTTPS-Ziele unter `bni.de` und dessen Subdomains begrenzt; der tatsächliche Sammel-Endpunkt und dessen Parameter sind fest im PHP-Client hinterlegt.

### Chapterdetails

```text
POST /api/bni/details.php
Content-Type: application/json

{"items":[{"orgId":5725}],"reload":false}
```

Pro Request sind maximal 50 Einträge zulässig. Mehrere Einträge werden auch serverseitig sequenziell mit 300 ms Pause verarbeitet.

### Lokale Daten

```text
GET /api/bni/local.php
```

Liefert Anzahl, Anzahl mit gespeicherten Details und alle lokalen Organisationen, ohne BNI aufzurufen.

## Tests

Container und PHP-Erweiterung prüfen:

```bash
docker compose up -d --build
docker compose exec -T web php -m | grep -i pdo_sqlite
docker compose exec -T web php -r 'new PDO("sqlite:/var/www/data/bni-dach.sqlite");'
```

HTTP-Prüfungen erfolgen lokal auf Port 8082. Dazu gehören Health, Sammelabruf, lokale API, SSRF-Abweisung, 50er-Limit, ein selektiver Detailabruf und der bestehende Königsforst-Endpunkt. Der Testbestand wird nicht automatisch gelöscht.

## Bestehende PoC-Endpunkte

```text
GET /api/bni/koenigsforst/members.php
```

Dieser unabhängige Endpunkt liest weiterhin die öffentliche Mitgliederliste des Chapters Königsforst aus.

## Grenzen

- Die Kartenquelle umfasst derzeit Deutschland und Österreich, nicht die Schweiz.
- Ergebnisse hängen von Struktur und Verfügbarkeit der öffentlichen BNI-Endpunkte ab.
- Es werden nur Organisations- und Chapterdaten gespeichert, keine Mitglieder.
- Der PoC enthält noch keine Authentifizierung oder Profilbeanspruchung.
