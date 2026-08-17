# CrossChAPP

CrossChAPP findet passende BNI-Chaptertreffen in der Nähe. Die öffentliche Suche arbeitet mit lokal gespeicherten Chapterdetails; Import und Datenpflege liegen in einem geschützten Adminbereich.

## Start

Voraussetzung sind Docker und Docker Compose.

```bash
docker compose up -d --build
```

Danach ist die Anwendung unter <http://localhost:8082/> erreichbar.

## Anwendersicht

Unter <http://localhost:8082/> stehen folgende Suchkriterien bereit:

- gemeinsames Feld für PLZ oder Ort
- beliebig viele Wochentage; ohne Auswahl gelten alle Tage
- Uhrzeit `egal`, `früh` oder `spät`
- Ergebnisanzahl 5, 10, 20, 50 oder alle lokalen Treffer; Standard ist 10
- Sortierung nach Entfernung, Uhrzeit oder Mitgliederzahl

`früh` bedeutet Meetingbeginn vor 09:00 Uhr, `spät` beginnt ab 09:00 Uhr. Standard ist Entfernung aufsteigend. „Alle“ hebt nur das serverseitige Ergebnislimit auf und umfasst weiterhin ausschließlich passende SQLite-Datensätze.

Die Suche berücksichtigt ausschließlich bestehende `CHAPTER`-Datensätze mit lokal vorhandenen Namen, Koordinaten, Wochentag und Uhrzeit. Sie löst niemals einen BNI-Request oder eine automatische Detailnachladung aus.

Trefferkarten lassen sich ohne weiteren Request aufklappen und zeigen alle mit der Suchantwort gelieferten lokalen Chapterdetails. Die einblendbare Leaflet-Karte verwendet OpenStreetMap-Kacheln und markiert den geocodierten Suchstandort sowie genau die aktuell zurückgegebenen Treffer. Chapter werden weder für die Karte geocodiert noch bei BNI nachgeladen.

## Geocoding und Entfernung

Die Benutzereingabe wird pro Suche mit höchstens einem Request über die öffentliche Nominatim-Such-API von OpenStreetMap geocodiert. `countrycodes=de,at,ch` begrenzt die Ortsauflösung. Eine lokale Sperre verhindert parallele Geocodingrequests. Chapterkoordinaten kommen ausschließlich aus SQLite.

Die Luftlinienentfernung wird lokal mit der Haversine-Formel berechnet; eine Routing-API wird nicht verwendet.

## Adminansicht

Der Adminbereich ist unter <http://localhost:8082/?view=admin> erreichbar. Lokale Entwicklungsanmeldung:

```text
Benutzername: admin
Passwort: admin
```

Die Anmeldung wird serverseitig mit sicherem Passwort-Hash und PHP-Session geprüft. Vor der Anmeldung ist der Admin-Navigationspunkt nicht sichtbar; direkte Adminaufrufe bleiben serverseitig geschützt. Nach dem Login stehen der BNI-Sammelimport, Filter, Auswahl, selektive Detailpflege, Reload-Option und aufklappbare Details zur Verfügung.

Beim Öffnen des Adminbereichs wird zuerst der vorhandene SQLite-Bestand über die lokale API geladen. Dabei findet kein BNI-Abruf statt. Der getrennte Bereich „BNI-Daten aktualisieren“ startet erst nach einem bewussten Klick auf „Grunddaten von BNI aktualisieren“ genau einen Sammelrequest und baut die Ansicht anschließend aus den aktualisierten lokalen Daten neu auf.

Der Sammelimport erzeugt weiterhin genau einen BNI-Request. Detailrequests laufen sequenziell mit 300 ms Abstand und sind auf 50 ausgewählte Organisationen begrenzt. Bereits gespeicherte Details werden standardmäßig nicht erneut geladen.

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

### Öffentliche Suche

```text
GET  /api/search-basis.php
POST /api/search.php
```

Die Such-API kommuniziert nur mit Nominatim und SQLite, nie mit BNI.

### Authentifizierung

```text
POST /api/auth/login.php
POST /api/auth/logout.php
GET  /api/auth/status.php
```

### Health

```text
GET /api/health.php
```

### Kartengrunddaten

```text
GET /api/bni/map.php?url=https%3A%2F%2Fbni.de%2Fde%2Ffindachapter
```

Der Browser übergibt den BNI-DACH-Seitenlink nur zur Prüfung. Serverzugriffe sind auf HTTPS-Ziele unter `bni.de` und dessen Subdomains begrenzt; der tatsächliche Sammel-Endpunkt und dessen Parameter sind fest im PHP-Client hinterlegt.

Import-, Detail- und lokale Verwaltungs-API benötigen eine aktive Admin-Session.

### Chapterdetails

```text
POST /api/bni/details.php
Content-Type: application/json

{"items":[{"orgId":5725}],"reload":false}
```

Pro Request sind maximal 50 Einträge zulässig. Mehrere Einträge werden auch serverseitig sequenziell mit 300 ms Pause verarbeitet.

### Batch-Import fehlender Chapterdetails

Der Adminbereich kann die nächsten 10, 25 oder 50 noch nicht geladenen bestehenden Chapter über einen ausdrücklich gestarteten Batch importieren. `CORE_GROUP` und `PLANNED_GROUP` bleiben ausgeschlossen. Die Auswahl stammt ausschließlich aus SQLite und ist stabil nach `org_id` sortiert:

```text
GET /api/bni/pending.php?limit=25
```

Die geschützte Pending-API löst selbst keinen BNI-Request aus. Der Browser verarbeitet die Kandidaten anschließend einzeln über den bestehenden Detail-Endpunkt und wartet zwischen zwei Requests 300 ms. Erfolgreiche Ergebnisse und Fehlerstatus werden sofort in SQLite gespeichert. Dadurch setzt ein späterer Batch – auch nach einem Browserneustart – bei den weiterhin fehlenden Chaptern fort. Fehlerhafte Datensätze dürfen erneut versucht werden. „Nach aktuellem Chapter stoppen“ beendet den Lauf nach dem gerade aktiven Request, ohne bereits gespeicherte Ergebnisse zurückzunehmen.

Der Detail-Batch ist vom Button „Grunddaten von BNI aktualisieren“ getrennt: Nur dieser separate Grunddatenimport ruft die BNI-Kartenquelle auf; ein Detail-Batch führt keinen `getMapData`-Sammelrequest aus.

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

HTTP-Prüfungen erfolgen lokal auf Port 8082. Dazu gehören Authentifizierung, Adminschutz, Suchbasis, Geocoding, Tages-/Zeitfilter, Haversine-Sortierung, Health, Sammelabruf, SSRF-Abweisung, 50er-Limit und der bestehende Königsforst-Endpunkt. Die Suche darf dabei keinen BNI-Request auslösen. Der SQLite-Testbestand wird nicht automatisch gelöscht.

## Bestehende PoC-Endpunkte

```text
GET /api/bni/koenigsforst/members.php
```

Dieser unabhängige Endpunkt liest weiterhin die öffentliche Mitgliederliste des Chapters Königsforst aus.

## Grenzen

- Die Kartenquelle umfasst derzeit Deutschland und Österreich, nicht die Schweiz.
- Ergebnisse hängen von Struktur und Verfügbarkeit der öffentlichen BNI-Endpunkte ab.
- Es werden nur Organisations- und Chapterdaten gespeichert, keine Mitglieder.
- Die lokale Adminanmeldung ist noch kein Mehrbenutzersystem und besitzt noch keine Rollenverwaltung.
- Geocoding hängt von der Verfügbarkeit des öffentlichen Nominatim-Dienstes ab.
