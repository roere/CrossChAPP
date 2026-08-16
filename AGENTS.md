# AGENTS.md

## Geltungsbereich

Diese Regeln gelten für das gesamte Projekt `bni-dach` und alle darin enthaltenen Dateien und Verzeichnisse.

## Sicherheits- und Arbeitsregeln

- Ausschließlich innerhalb des aktuell geöffneten Projektverzeichnisses `bni-dach` arbeiten.
- Keine Dateien außerhalb dieses Projekts lesen, ändern, erstellen oder löschen.
- Das separate Projekt `Rauchfrei-App` weder lesen noch verändern.
- Keine produktiven Deployments ausführen.
- Keine Zugangsdaten, Tokens, Passwörter oder sonstigen Secrets anlegen oder committen.
- Keine Secrets in Quellcode, Konfiguration, Logs, Dokumentation oder Testdaten aufnehmen.
- Nur lokale Entwicklungs- und Testumgebungen verwenden, sofern nicht ausdrücklich anders beauftragt.
- Keinen Git-Commit erstellen, solange dies nicht ausdrücklich beauftragt wurde.
- Vorhandene, nicht zur Aufgabe gehörende Änderungen respektieren und nicht zurücksetzen oder überschreiben.

## Projektziel

Die Web-App `BNI DACH Finder` soll Chapter und Mitglieder von BNI in Deutschland, Österreich und der Schweiz auffindbar machen.

Langfristig vorgesehen sind:

1. Chapter-Suche nach PLZ/Ort, Entfernung, Wochentag, Uhrzeit und Meetingtyp.
2. Mitgliedersuche nach Name, Unternehmen, Fachgebiet/Gewerk, Chapter und Ort.
3. Fehlertolerante Personensuche mit unvollständigen Angaben, etwa nach einer Crosschapter-Begegnung.
4. Beanspruchen und Ergänzen vorhandener Chapter-Datensätze.
5. Beanspruchen und Ergänzen vorhandener Mitgliederprofile.

## Aktueller technischer Rahmen

- PHP 8.3 mit Apache
- Docker und Docker Compose
- lokale Anwendung auf Port `8082`
- Git-Branch `main`
- derzeit keine Datenbank
- Health-Endpunkt: `GET /api/health.php`
- Mitglieder-PoC: `GET /api/bni/koenigsforst/members.php`

## Änderungsdisziplin

- Änderungen klein, nachvollziehbar und auf den jeweiligen Auftrag begrenzt halten.
- Funktionierenden Anwendungscode nicht ohne konkreten Auftrag umgestalten.
- Nach Codeänderungen passende lokale Tests ausführen.
- Keine produktiven BNI-Daten dauerhaft speichern, solange Datenmodell, Rechtsgrundlage und Aktualisierungsstrategie nicht geklärt sind.
