# CrossChAPP

CrossChAPP findet passende BNI-Chaptertreffen in der Nähe. Die öffentliche Suche arbeitet mit lokal gespeicherten Chapterdetails; Import und Datenpflege liegen in einem geschützten Adminbereich.

## Start

Voraussetzung sind Docker und Docker Compose.

```bash
docker compose up -d --build
```

Danach ist die Anwendung unter <http://localhost:8082/> erreichbar.

## Anwendersicht

Die öffentliche Navigation besitzt drei Bereiche:

- **CrossChAPPtern** unter `/` beziehungsweise `/?view=crosschaptern` enthält die vollständige Chapter-Suche.
- **Vertretung anbieten** unter `/?view=vertretung` speichert für jedes ausgewählte Chapter ein eigenes Angebot mit konkreten künftigen Terminen oder „Immer“. Eine Mehrfachauswahl wird dabei atomar in mehrere Einzelangebote aufgeteilt; identische Angebote werden abgewiesen. Das eigene Heimatchapter ist sichtbar, aber client- und serverseitig ausgeschlossen. Eigene Angebote bleiben in SQLite erhalten und werden über einen barrierearmen CrossChAPP-Löschdialog gelöscht.
- **Vertretung finden** ist immer in der Navigation sichtbar. Ausgeloggte Benutzer erhalten eine Anmeldungsmöglichkeit, Konten ohne Heimatchapter einen lokalen Hinweis; in beiden Fällen wird die Angebots-API nicht aufgerufen. Mit Heimatchapter werden ausschließlich aktuelle Angebote anderer Benutzer für dieses Chapter angezeigt; Namen erscheinen als Vorname plus Nachnamensinitial.
- Beide Vertretungsbereiche verwenden ausschließlich lokale SQLite-Organisationsdaten. Sie lösen keine BNI-Abfragen aus. Schreibzugriffe sind session- und CSRF-geschützt; das Zielchapter der Suche wird serverseitig aus dem Benutzerprofil bestimmt.

Die Vertretungsauswahl zeigt zuerst die Filter, danach Termine und Chapterliste. Sie lädt ihre Organisationen ausschließlich aus SQLite. Konkrete Termine werden im Browser als ISO-Datum gehalten, deutsch dargestellt und auf den wiederkehrenden Meeting-Wochentag abgebildet. „Alle Daten“ deaktiviert diesen Filter vorübergehend, erhält aber die ausgewählten Termin-Chips. Land, Organisationstyp, Freitext und optional Ort/PLZ mit einem Radius von 1 bis 500 km lassen sich kombinieren.

„Alle sichtbaren auswählen“ betrifft ausschließlich das aktuelle kombinierte Filterergebnis. Ausgefilterte Auswahlen bleiben im Browserzustand erhalten, bis „Auswahl aufheben“ verwendet oder die Seite neu geladen wird.

Unter <http://localhost:8082/> stehen folgende Suchkriterien bereit:

- gemeinsames Feld für PLZ oder Ort
- beliebig viele Wochentage; ohne Auswahl gelten alle Tage
- Uhrzeit `egal`, `früh` oder `spät`
- Ergebnisanzahl 5, 10, 20, 50 oder alle lokalen Treffer; Standard ist 10
- Sortierung nach Entfernung, Uhrzeit oder Mitgliederzahl

Ein öffentlicher Organisationstyp-Filter und eine Typkennzeichnung in Trefferkarten oder Details werden bewusst nicht angeboten.

`früh` bedeutet Meetingbeginn vor 09:00 Uhr, `spät` beginnt ab 09:00 Uhr. Standard ist Entfernung aufsteigend. „Alle“ hebt nur das serverseitige Ergebnislimit auf und umfasst weiterhin ausschließlich passende SQLite-Datensätze.

Die Suchantwort berücksichtigt datengetrieben alle lokal bekannten Organisationstypen, sofern Detailstatus, Name, Koordinaten, Wochentag und Uhrzeit eine sinnvolle Treffendarstellung erlauben. Sie wird immer unmittelbar aus SQLite erzeugt und wartet nicht auf BNI. Ist die nutzungsabhängige Aktualisierung aktiviert, stößt der Browser erst nach der lokalen Antwort für tatsächlich angezeigte, veraltete Treffer einen getrennten kontrollierten Refresh an. Der Organisationstyp bleibt dabei intern erhalten, wird im öffentlichen Suchbereich aber weder gefiltert noch angezeigt.

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

`admin/admin` ist ausschließlich ein lokaler Entwicklungszugang und muss vor jedem produktiven Einsatz ersetzt werden.

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
GET|POST|DELETE /api/representation/offers.php
GET  /api/representation/find.php
```

Die Such-API selbst kommuniziert nur mit Nominatim und SQLite. Ein optionaler X-Refresh erfolgt danach über den getrennten lokalen Refresh-Endpunkt.

### Authentifizierung

```text
POST /api/auth/login.php
POST /api/auth/logout.php
GET  /api/auth/status.php
POST /api/auth/register.php
GET  /api/auth/chapters.php
POST /api/auth/verify-email.php
POST /api/auth/resend-verification.php
POST /api/auth/forgot-password.php
POST /api/auth/reset-password.php
```

Der Login ist universell: Normale Benutzer melden sich mit ihrer E-Mail-Adresse an, der lokale Entwicklungsadmin weiterhin mit `admin`. Registrierungen benötigen Vorname, Nachname, eine eindeutige E-Mail-Adresse und ein Passwort mit mindestens acht Zeichen. Ein Heimatchapter ist optional und wird ausschließlich aus lokalen `CHAPTER`-Datensätzen in SQLite gewählt; die filterbare Auswahl löst keinen BNI-Request aus.

Neue Benutzer bleiben bis zur E-Mail-Bestätigung im Status `pending`. Bestätigungs- und Passwort-Reset-Tokens entstehen mit `random_bytes()`, werden ausschließlich als SHA-256-Hash gespeichert, laufen nach 24 Stunden beziehungsweise 60 Minuten ab und sind einmal verwendbar. Passwörter werden ausschließlich mit `password_hash()` gespeichert und mit `password_verify()` geprüft; Klartextpasswörter werden weder gespeichert noch versendet. Passwort-Reset-Anfragen antworten unabhängig von der Existenz des Kontos identisch.

Schreibende Auth- und Adminaktionen sind CSRF-geschützt. Fehlgeschlagene Anmeldungen sowie Reset- und erneute Bestätigungsanforderungen werden lokal in SQLite begrenzt. Nach erfolgreicher Anmeldung wird die Session-ID regeneriert. Normale Benutzer erhalten keine Adminrolle und sehen keine Adminnavigation.

### E-Mail-Versand

CrossChAPP versendet ausgehende Nachrichten per SMTP mit PHPMailer; IMAP wird nicht benötigt. Im standardmäßig geschlossenen Adminbereich „Sonstiges“ lassen sich SMTP-Server, Port, Benutzername, Verschlüsselung, Absender und Basis-URL konfigurieren. Ein leeres Passwortfeld behält das vorhandene SMTP-Passwort bei. API-Antworten geben das gespeicherte Passwort niemals zurück.

Die deutschen Vorlagen `verification` und `password_reset` sind in SQLite gespeichert und im Adminbereich editierbar. Es werden ausschließlich die dort dokumentierten Platzhalter ersetzt. „Test-E-Mail senden“ verwendet die gespeicherte Konfiguration und zeigt bei Problemen eine bereinigte Fehlermeldung ohne SMTP-Secrets.

Für diesen lokalen Entwicklungsstand darf das SMTP-Passwort in der außerhalb des Webroots liegenden SQLite-Datei gespeichert werden. Für einen produktiven Betrieb ist stattdessen eine dedizierte Secret-Verwaltung einzusetzen. Ohne konfigurierte SMTP-Daten wird keine Nachricht versendet; die Tests verwenden einen injizierbaren lokalen Testtransport.

Geschützte Mail-APIs:

```text
GET|POST /api/admin/mail-settings.php
GET|POST /api/admin/email-templates.php
POST     /api/admin/test-email.php
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

### Automatisierte Detail- und Grunddatenaktualisierung

Der standardmäßig geschlossene Adminbereich „Automatisierter Import“ verwaltet drei voneinander unabhängige, dauerhaft in SQLite gespeicherte Mechanismen. Alle sind initial deaktiviert:

- **X – Aktualisierung bei Nutzung:** Standardalter 7 Tage. Nach einer lokalen Suchantwort werden nur die tatsächlich ausgegebenen veralteten Treffer in eine deduplizierte Browser-Queue gestellt. Auch das Öffnen einer veralteten Detailkarte kann einen Refresh anstoßen. Lokale Daten bleiben sofort sichtbar; die Suche blockiert nicht.
- **Y – automatische Aktualisierung:** Standardalter 30 Tage. Der lokale Docker-Worker prüft standardmäßig alle 60 Minuten und verarbeitet pro Lauf höchstens 10 fällige Organisationen der Typen `CHAPTER`, `CORE_GROUP` und `PLANNED_GROUP`. Zuerst werden noch nie geladene Details erstmalig geladen, danach erneut versuchbare Fehler und anschließend bereits geladene, aber veraltete Details. Innerhalb dieser Gruppen bleibt die Reihenfolge stabil. Welche Detailfelder tatsächlich verfügbar sind, hängt von der Antwort des öffentlichen BNI-Detailendpunkts ab; fehlende Felder bleiben lokal `NULL`.
- **Z – automatische Grunddatenaktualisierung:** Standardalter 1 Tag, konfigurierbar von 1 bis 30 Tagen. Fehlt ein erfolgreicher Sammelimport oder ist `last_map_refresh_at` älter als Z, führt der Worker genau einen `getMapData`-Request aus und verwendet denselben SQLite-UPsert wie der manuelle Adminimport. Grunddaten laufen vor Y; Details werden weder gelöscht noch automatisch nachgeladen.

Die Schwellwerte X und Y sind im Bereich 1 bis 365 Tage konfigurierbar. Änderungen werden erst mit „Einstellungen speichern“ aktiv. Der Worker läuft nur zusammen mit der lokalen Docker-Anwendung; ist Y ausgeschaltet, führt er keine BNI-Anfrage aus.

Manuelle und automatische Grunddatenläufe verwenden ein gemeinsames, ablaufendes SQLite-Lock und werden als `map_manual` beziehungsweise `map_automatic` in `map_refresh_log` protokolliert. HTTP 429 berücksichtigt `Retry-After`, HTTP 403 und 429 stoppen den Lauf, 5xx-/Netzwerkfehler werden ohne schnelle Wiederholung protokolliert. Der nächste reguläre Workerzyklus darf erneut prüfen. Maprequests zählen nicht zum gemeinsamen Tageslimit der Detailrequests; ihre Erfolge, Fehler, Schutzstopps und Fälligkeit werden separat angezeigt.

Zusätzlich gilt ein gemeinsames, in SQLite gespeichertes Tageslimit, standardmäßig 50. Es zählt jeden tatsächlich gestarteten externen Detailrequest der Trigger `usage_search`, `usage_detail` und `automatic` – unabhängig davon, ob er erfolgreich ist oder mit Fehler, HTTP 429 oder HTTP 403 endet. Lokale Prüfungen, frische oder gesperrte Chapter, Deduplizierungen und manuelle Adminimporte zählen nicht. Der Kalendertag wird in `Europe/Berlin` bestimmt. Ist das Limit erreicht, bleiben Suche und Detailansicht vollständig lokal nutzbar; X und Y starten bis zum nächsten lokalen Tag keine weiteren BNI-Requests. Der Adminbereich zeigt Verbrauch, Limit, Rest und Prozentwert.

Alle Trigger (`manual`, `usage_search`, `usage_detail`, `automatic`) verwenden denselben Refresh-Service und `BniRequestPolicy::DETAIL_DELAY_MS`. BNI-Aufrufe erfolgen sequenziell mit mindestens 1,5 Sekunden Abstand und ohne automatische Retries. Ablaufende SQLite-Sperren verhindern parallele Aktualisierungen derselben `org_id`; die atomare SQLite-Budgetreservierung verhindert eine Überschreitung des Tageslimits durch konkurrierende Prozesse. HTTP 429 oder 403 beendet die jeweilige Queue beziehungsweise den Worker-Lauf; der Datensatz bleibt erneut aktualisierbar. 5xx- und Netzwerkfehler betreffen nur das einzelne Chapter.

`chapter_refresh_log` speichert ausschließlich technische Metadaten zu Trigger, Zeitpunkt, Ergebnis und HTTP-Fehlerkategorie. Daraus sowie aus den lokalen Chapterdaten entstehen die Adminstatistiken; dafür erfolgen keine BNI-Abfragen. `chapter_refresh_locks` enthält kurzlebige Sperren, `automation_runtime` den Worker-Heartbeat und den nächsten vorgesehenen Prüflauf.

Die Y-Statistik trennt überschneidungsfrei zwischen noch nie geladenen Organisationen, erneut versuchbaren Fehlern und bereits geladenen, nach Y veralteten Organisationen. Sie weist die Fälligkeit zusätzlich nach Chapter, Gruppen im Aufbau und geplanten Gruppen aus. Eine erfolgreiche Erstbefüllung setzt `detail_status = loaded` und `details_loaded_at`; enthält die Antwort die notwendigen Treffendaten, wächst dadurch automatisch die ausschließlich lokale Suchbasis.

Y berücksichtigt `CHAPTER`, `CORE_GROUP` („Im Aufbau“) und `PLANNED_GROUP` („Geplant“) nach denselben Fälligkeits-, Locking-, Tageslimit- und Schutzregeln. X besitzt ebenfalls keine Typ-Sperre und aktualisiert ausschließlich die konkret in einem Nutzungskontext ausgegebene Organisation. Z bleibt davon unabhängig und aktualisiert die Grunddaten aller Typen über genau einen Sammelrequest. Der ausdrücklich gestartete manuelle Detail-Batch bleibt dagegen weiterhin auf bestehende `CHAPTER` beschränkt.

Geschützte Automatisierungs-APIs:

```text
GET|POST /api/automation/settings.php
GET      /api/automation/stats.php
```

Die öffentliche lokale Refresh-API akzeptiert ausschließlich eine vorhandene `org_id` und den Trigger `usage_search` oder `usage_detail`; Aktivierung, Alter und Sperre werden serverseitig erneut geprüft:

```text
POST /api/refresh/usage.php
```

### Lokale Daten

### Vertretungsangebote und Kontaktanfragen

„Vertretung finden“ zeigt ausschließlich Angebote für das serverseitig im Benutzerkonto hinterlegte Heimatchapter. Konkrete zukünftige Termine werden chronologisch gruppiert; pauschale „Immer“-Anbieter ergänzen diese vorhandenen Termine, erzeugen jedoch keine künstlichen Folgetermine, und erscheinen zusätzlich in einem eigenen Bereich. Anbieter werden nur als Vorname plus Nachnamensinitial dargestellt; E-Mail-Adresse, Benutzer-ID und Heimatchaptername des Anbieters werden nicht ausgegeben.

Angemeldete Benutzer mit Heimatchapter können unter „Meine Vertretungsgesuche“ über einen nativen Datumspicker konkrete heutige oder zukünftige Meetingtermine speichern. Ein falscher Wochentag wird sofort clientseitig markiert und zwingend nochmals serverseitig abgewiesen. Der Server übernimmt das Zielchapter aus dem Benutzerkonto und akzeptiert nur den regulären Meeting-Wochentag in der hinterlegten Chapter-Zeitzone (Fallback `Europe/Berlin`). Gesuche werden additiv in `representation_requests` gespeichert, chronologisch angezeigt und über das X am Chip unmittelbar gelöscht. Ein Gesuch erzeugt einen sichtbaren Termin auch ohne vorhandenen Anbieter; pauschale „Immer“-Anbieter werden auch dort ergänzt. Es gibt weder frei wählbare Chapter-IDs noch BNI-Requests in diesem Ablauf.

Kontaktanfragen werden über CrossChAPP-Overlays versendet. Im angebotsorientierten unteren Block besitzt jedes Angebot genau einen Kontaktbutton: Ein Einzeltermin ist fest vorbelegt, bei mehreren Terminen stehen ausschließlich die noch gültigen Angebotstermine zur Auswahl, ein „Immer“-Angebot verwendet weiterhin den regulären Meetingtag. Die administrativ konfigurierte Vorlage wird serverseitig am Platzhalter `{{custom_message}}` geteilt; Betreff sowie feste Teile vor und nach dem editierbaren Freitext zeigen bereits die vollständige spätere Mail. Unter Admin → Sonstiges sind für Angebotsanfragen und Antworten auf Gesuche zwei getrennte, persistente Standardnachrichten pflegbar. Sie befüllen den editierbaren Freitext beim Öffnen des jeweiligen Overlays. Fehlen Pflichtplatzhalter, ist der automatisch ergänzte Pflichtblock ebenfalls in der Vorschau sichtbar. Beim Versand übermittelt der Browser weiterhin nur Referenz, Termin und Freitext; der Server baut die Mail erneut aus der aktuellen Vorlage auf.

CrossChAPPtern lädt aktive Vertretungsgesuche für alle tatsächlich zurückgegebenen Treffer in einer einzigen lokalen SQLite-Abfrage. Anonym enthält die API keinerlei Namensbestandteile; das Frontend nummeriert die Gesuche je sichtbarem Termin lediglich als „Person 1“, „Person 2“ usw. Eine Kontaktaufnahme ist ohne Konto möglich: Vorname, Nachname und E-Mail werden im Overlay validiert, erscheinen in der vollständigen Vorschau und bestimmen das Reply-To. Empfänger und Chapter kommen ausschließlich serverseitig aus dem Gesuch. Anonyme Kontakte sind per Session-CSRF, fünf Anfragen je IP und Stunde sowie zehnminütigem E-Mail-Hash-/Gesuch-Dublettenschutz abgesichert; Klartext-E-Mails werden nicht protokolliert. Eingeloggt bleiben Initialname, unveränderbare Kontodaten und das bestehende Limit von zehn Kontakten je Stunde erhalten. Die Vorlage „Vertretungsgesuch annehmen“, ihr Hinweistext und ihre Standardnachricht liegen unter Admin → Sonstiges.

Unter „Vertretung anbieten“ gibt es bewusst keinen öffentlichen Organisationstyp-Filter und keine Typspalte oder Typ-Sortierung. `org_type` bleibt intern erhalten; Datum, Land, Freitext, Ort/PLZ und Umkreis bestimmen gemeinsam die sichtbare lokale Auswahl. Gerenderte Mailvorschauen und versendete Mails werden nach der erlaubten Platzhalterersetzung zusätzlich von verbliebenen `{{...}}`-Tokens bereinigt. Im anonymen Gesuchskontakt wird der Empfängername nicht eingesetzt; eine Vorlage wie `Hallo {{request_owner_first_name}},` erscheint dort neutral als `Hallo,`.

`RepresentationCleanupService` entfernt bei jedem Workerzyklus sowie vor dem Lesen von Gesuchen/Angeboten abgelaufene Gesuche und vergangene Angebotstermine anhand der jeweiligen Chapter-Zeitzone. Bleibt bei einem datumsgebundenen Angebot kein heutiger oder zukünftiger Termin übrig, wird auch das leere Angebot entfernt. „Immer“-Angebote werden nicht automatisch gelöscht. Der Cleanup arbeitet ausschließlich in SQLite und erzeugt keine externen Requests.

Die internen Organisations-IDs bleiben für APIs und Persistenz erhalten, werden in öffentlichen Chapter- und Vertretungsansichten jedoch nicht angezeigt. Der Adminbereich zeigt sie weiterhin.

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

HTTP-Prüfungen erfolgen lokal auf Port 8082. Dazu gehören Authentifizierung, Adminschutz, Suchbasis, Geocoding, Tages-/Zeitfilter, Haversine-Sortierung, Health, Sammelabruf, SSRF-Abweisung, 50er-Limit und der bestehende Königsforst-Endpunkt. `tests/account-auth.php` prüft Registrierung, optionale Heimatchapter, Hashes, Verifikation, Passwortreset, Rollen, Rate-Limit und Vorlagen mit einem In-Memory-Schema und Testtransport. `tests/account-browser.py` prüft den universellen Dialog, die lokale Chapterauswahl, Adminanmeldung und Mailkonfiguration praktisch in Chromium. Zusätzlich deckt `tests/automation-refresh.php` Einstellungen, Stale-Prüfung, Locking, Historie, Trigger, Worker-Limits sowie simulierte 429-, 403-, 5xx- und Netzwerkfehler ohne absichtlich erzeugtes Live-Rate-Limit ab. Der persistente SQLite-Bestand wird nicht automatisch gelöscht.

## Bestehende PoC-Endpunkte

```text
GET /api/bni/koenigsforst/members.php
```

Dieser unabhängige Endpunkt liest weiterhin die öffentliche Mitgliederliste des Chapters Königsforst aus.

## Grenzen

- Die Kartenquelle umfasst derzeit Deutschland und Österreich, nicht die Schweiz.
- Ergebnisse hängen von Struktur und Verfügbarkeit der öffentlichen BNI-Endpunkte ab.
- Es werden nur Organisations- und Chapterdaten gespeichert, keine Mitglieder.
- Der lokale Entwicklungsadmin `admin/admin` ist fest konfiguriert und muss vor einem produktiven Einsatz ersetzt werden.
- Ohne konfigurierte SMTP-Verbindung können Bestätigungs- und Reset-Nachrichten nicht extern zugestellt werden.
- Ein vollständiger Benutzerprofilbereich ist noch nicht umgesetzt; nach dem Login wird zunächst nur der Kontoname angezeigt.
- Geocoding hängt von der Verfügbarkeit des öffentlichen Nominatim-Dienstes ab.
