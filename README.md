# BNI DACH Finder

Lokaler Proof of Concept zum kontrollierten Auslesen öffentlicher BNI-Chapterdaten für Deutschland und Österreich.

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

Detailrequests laufen sequenziell und mit 300 ms Abstand. Bereits in derselben Browser-Sitzung erfolgreich geladene Datensätze werden nicht erneut abgefragt. Es gibt keine Datenbank und keinen persistenten Cache.

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

{"items":[{"orgId":5725,"cmsSecurityHash":"..."}]}
```

Pro Request sind maximal 50 Einträge zulässig. Mehrere Einträge werden auch serverseitig sequenziell mit 300 ms Pause verarbeitet.

## Bestehende PoC-Endpunkte

```text
GET /api/bni/koenigsforst/members.php
```

Dieser unabhängige Endpunkt liest weiterhin die öffentliche Mitgliederliste des Chapters Königsforst aus.

## Grenzen

- Die Kartenquelle umfasst derzeit Deutschland und Österreich, nicht die Schweiz.
- Ergebnisse hängen von Struktur und Verfügbarkeit der öffentlichen BNI-Endpunkte ab.
- Es findet keine persistente Speicherung statt.
- Der PoC enthält noch keine Authentifizierung oder Profilbeanspruchung.
