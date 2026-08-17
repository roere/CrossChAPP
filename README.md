# CrossChAPP

CrossChAPP findet passende BNI-Chaptertreffen in der Nähe. Die öffentliche Suche arbeitet mit lokal gespeicherten Chapterdetails; Import und Datenpflege liegen in einem geschützten Adminbereich.

## Start

Voraussetzung sind Docker und Docker Compose.

```bash
docker compose up -d --build
```

Danach ist die Anwendung unter <http://localhost:8082/> erreichbar.

## Anwendersicht

Die öffentliche Navigation besitzt zwei Bereiche:

- **Crosschaptern** unter `/` beziehungsweise `/?view=crosschaptern` enthält die vollständige Chapter-Suche.
- **Vertretung anbieten** unter `/?view=vertretung` bietet eine noch nicht persistente Auswahloberfläche für konkrete Termine, „Alle Daten“, lokale Chapterfilter und eine Chapterauswahl. Sie speichert noch kein Vertretungsangebot und ruft weder BNI- noch Mitgliederdaten ab.

Die Vertretungsauswahl lädt ihre Organisationen ausschließlich aus SQLite. Konkrete Termine werden im Browser als ISO-Datum gehalten, deutsch dargestellt und auf den wiederkehrenden Meeting-Wochentag abgebildet. „Alle Daten“ deaktiviert diesen Filter vorübergehend, erhält aber die ausgewählten Termin-Chips. Land, Organisationstyp, Freitext und optional Ort/PLZ mit einem Radius von 1 bis 500 km lassen sich kombinieren. Nur die explizite Ortssuche verwendet den bestehenden Nominatim-Geocoder; Entfernungen zu den vorhandenen Chapterkoordinaten werden danach lokal mit der Haversine-Formel berechnet. Checkbox-, Datums- und sonstige Filteraktionen erzeugen keine weiteren Server- oder BNI-Requests.

„Alle sichtbaren auswählen“ betrifft ausschließlich das aktuelle kombinierte Filterergebnis. Ausgefilterte Auswahlen bleiben im Browserzustand erhalten, bis „Auswahl aufheben“ verwendet oder die Seite neu geladen wird.

Unter <http://localhost:8082/> stehen folgende Suchkriterien bereit:

- gemeinsames Feld für PLZ oder Ort
- beliebig viele Wochentage; ohne Auswahl gelten alle Tage
- Uhrzeit `egal`, `früh` oder `spät`
- Ergebnisanzahl 5, 10, 20, 50 oder alle lokalen Treffer; Standard ist 10
- Sortierung nach Entfernung, Uhrzeit oder Mitgliederzahl

`früh` bedeutet Meetingbeginn vor 09:00 Uhr, `spät` beginnt ab 09:00 Uhr. Standard ist Entfernung aufsteigend. „Alle“ hebt nur das serverseitige Ergebnislimit auf und umfasst weiterhin ausschließlich passende SQLite-Datensätze.

Die Suchantwort berücksichtigt ausschließlich bestehende `CHAPTER`-Datensätze mit lokal vorhandenen Namen, Koordinaten, Wochentag und Uhrzeit. Sie wird immer unmittelbar aus SQLite erzeugt und wartet nicht auf BNI. Ist die nutzungsabhängige Aktualisierung aktiviert, stößt der Browser erst nach der lokalen Antwort für tatsächlich angezeigte, veraltete Treffer einen getrennten kontrollierten Refresh an.

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

Der Sammelimport erzeugt weiterhin genau einen BNI-Request. Detailrequests laufen sequenziell mit mindestens 1,5 Sekunden Abstand und sind auf 50 ausgewählte Organisationen begrenzt. Bereits gespeicherte Details werden standardmäßig nicht erneut geladen.

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
GET  /api/representation/organizations.php
POST /api/representation/geocode.php
```

Die Such-API selbst kommuniziert nur mit Nominatim und SQLite. Ein optionaler X-Refresh erfolgt danach über den getrennten lokalen Refresh-Endpunkt.

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

Pro Request sind maximal 50 Einträge zulässig. Mehrere Einträge werden auch serverseitig sequenziell mit mindestens 1,5 Sekunden Pause verarbeitet.

### Batch-Import fehlender Chapterdetails

Der Adminbereich kann die nächsten 10, 25 oder 50 noch nicht geladenen bestehenden Chapter über einen ausdrücklich gestarteten Batch importieren. `CORE_GROUP` und `PLANNED_GROUP` bleiben ausgeschlossen. Die Auswahl stammt ausschließlich aus SQLite und ist stabil nach `org_id` sortiert:

```text
GET /api/bni/pending.php?limit=25
```

Die geschützte Pending-API löst selbst keinen BNI-Request aus. Der Browser verarbeitet die Kandidaten anschließend einzeln über den bestehenden Detail-Endpunkt und wartet zwischen zwei Requests mindestens 1,5 Sekunden. Der Wert ist zentral in `BniRequestPolicy::DETAIL_DELAY_MS` konfiguriert. Erfolgreiche Ergebnisse und Fehlerstatus werden sofort in SQLite gespeichert. Dadurch setzt ein späterer Batch – auch nach einem Browserneustart – bei den weiterhin fehlenden Chaptern fort. Fehlerhafte Datensätze dürfen erneut versucht werden. „Nach aktuellem Chapter stoppen“ beendet den Lauf nach dem gerade aktiven Request, ohne bereits gespeicherte Ergebnisse zurückzunehmen.

Bei HTTP 429 endet der gesamte laufende Batch sofort. Ein vorhandener `Retry-After`-Header wird sowohl als Sekundenwert als auch als HTTP-Datum ausgewertet und als Empfehlung angezeigt; die Anwendung wartet oder startet nicht automatisch neu. HTTP 403 beendet den Batch ebenfalls sofort. Der betroffene Datensatz bleibt in beiden Fällen `not_loaded`, da die Ursache kein fachlicher Fehler seiner Chapterdaten ist, und kann später erneut importiert werden. Normale 5xx-Antworten, Timeouts und Verbindungsfehler markieren nur den einzelnen Datensatz als `error`; der Batch fährt ohne automatischen Retry mit dem nächsten Kandidaten fort. Bereits geladene Chapter bleiben bei allen Fehlerarten gespeichert.

Der Detail-Batch ist vom Button „Grunddaten von BNI aktualisieren“ getrennt: Nur dieser separate Grunddatenimport ruft die BNI-Kartenquelle auf; ein Detail-Batch führt keinen `getMapData`-Sammelrequest aus.

### Automatisierte Detailaktualisierung

Der standardmäßig geschlossene Adminbereich „Automatisierter Import“ verwaltet zwei voneinander unabhängige, dauerhaft in SQLite gespeicherte Mechanismen. Beide sind initial deaktiviert:

- **X – Aktualisierung bei Nutzung:** Standardalter 7 Tage. Nach einer lokalen Suchantwort werden nur die tatsächlich ausgegebenen veralteten Treffer in eine deduplizierte Browser-Queue gestellt. Auch das Öffnen einer veralteten Detailkarte kann einen Refresh anstoßen. Lokale Daten bleiben sofort sichtbar; die Suche blockiert nicht.
- **Y – automatische Aktualisierung:** Standardalter 30 Tage. Der lokale Docker-Worker prüft standardmäßig alle 60 Minuten und verarbeitet pro Lauf höchstens 10 fällige `CHAPTER`-Datensätze. Zuerst werden noch nie geladene Chapterdetails erstmalig geladen, danach erneut versuchbare Fehler und anschließend bereits geladene, aber veraltete Details. Innerhalb dieser Gruppen bleibt die Reihenfolge stabil.

Die Schwellwerte X und Y sind im Bereich 1 bis 365 Tage konfigurierbar. Änderungen werden erst mit „Einstellungen speichern“ aktiv. Der Worker läuft nur zusammen mit der lokalen Docker-Anwendung; ist Y ausgeschaltet, führt er keine BNI-Anfrage aus.

Zusätzlich gilt ein gemeinsames, in SQLite gespeichertes Tageslimit, standardmäßig 50. Es zählt jeden tatsächlich gestarteten externen Detailrequest der Trigger `usage_search`, `usage_detail` und `automatic` – unabhängig davon, ob er erfolgreich ist oder mit Fehler, HTTP 429 oder HTTP 403 endet. Lokale Prüfungen, frische oder gesperrte Chapter, Deduplizierungen und manuelle Adminimporte zählen nicht. Der Kalendertag wird in `Europe/Berlin` bestimmt. Ist das Limit erreicht, bleiben Suche und Detailansicht vollständig lokal nutzbar; X und Y starten bis zum nächsten lokalen Tag keine weiteren BNI-Requests. Der Adminbereich zeigt Verbrauch, Limit, Rest und Prozentwert.

Alle Trigger (`manual`, `usage_search`, `usage_detail`, `automatic`) verwenden denselben Refresh-Service und `BniRequestPolicy::DETAIL_DELAY_MS`. BNI-Aufrufe erfolgen sequenziell mit mindestens 1,5 Sekunden Abstand und ohne automatische Retries. Ablaufende SQLite-Sperren verhindern parallele Aktualisierungen derselben `org_id`; die atomare SQLite-Budgetreservierung verhindert eine Überschreitung des Tageslimits durch konkurrierende Prozesse. HTTP 429 oder 403 beendet die jeweilige Queue beziehungsweise den Worker-Lauf; der Datensatz bleibt erneut aktualisierbar. 5xx- und Netzwerkfehler betreffen nur das einzelne Chapter.

`chapter_refresh_log` speichert ausschließlich technische Metadaten zu Trigger, Zeitpunkt, Ergebnis und HTTP-Fehlerkategorie. Daraus sowie aus den lokalen Chapterdaten entstehen die Adminstatistiken; dafür erfolgen keine BNI-Abfragen. `chapter_refresh_locks` enthält kurzlebige Sperren, `automation_runtime` den Worker-Heartbeat und den nächsten vorgesehenen Prüflauf.

Die Y-Statistik trennt überschneidungsfrei zwischen noch nie geladenen Chaptern, erneut versuchbaren Fehlern und bereits geladenen, nach Y veralteten Chaptern. Deren Summe wird als „Für Automatik fällig“ angezeigt. Eine erfolgreiche Erstbefüllung setzt `detail_status = loaded` und `details_loaded_at`; enthält die Antwort die notwendigen Treffendaten, wächst dadurch automatisch die ausschließlich lokale Suchbasis.

Geschützte Automatisierungs-APIs:

```text
GET|POST /api/automation/settings.php
GET      /api/automation/stats.php
```

Die öffentliche lokale Refresh-API akzeptiert ausschließlich eine vorhandene `org_id` und den Trigger `usage_search` oder `usage_detail`; Aktivierung, Typ, Alter und Sperre werden serverseitig erneut geprüft:

```text
POST /api/refresh/usage.php
```

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

HTTP-Prüfungen erfolgen lokal auf Port 8082. Dazu gehören Authentifizierung, Adminschutz, Suchbasis, Geocoding, Tages-/Zeitfilter, Haversine-Sortierung, Health, Sammelabruf, SSRF-Abweisung, 50er-Limit und der bestehende Königsforst-Endpunkt. Zusätzlich deckt `tests/automation-refresh.php` Einstellungen, Stale-Prüfung, Locking, Historie, Trigger, Worker-Limits sowie simulierte 429-, 403-, 5xx- und Netzwerkfehler ohne absichtlich erzeugtes Live-Rate-Limit ab. Der SQLite-Testbestand wird nicht automatisch gelöscht.

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
