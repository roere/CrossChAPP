# CrossChAPP produktiv betreiben

Diese Anleitung bereitet den ersten Betrieb unter `https://bni.crosschapp.de` vor. Sie führt selbst keinen Deploy aus. Die Produktionskonfiguration veröffentlicht Apache ausschließlich als `127.0.0.1:8082`; nur Nginx ist öffentlich erreichbar. Der Worker gehört zum Profil `worker` und bleibt beim ersten Start aus.

## Architektur und Voraussetzungen

Entwicklung verwendet `docker-compose.yml` mit Web, Worker und MariaDB 11.4. Produktion verwendet `docker-compose.yml` plus `docker-compose.prod.yml`: Anwendungscode kommt aus dem Image, MariaDB liegt in einem benannten Volume, der Webport ist loopbackgebunden und der Worker ist opt-in. Port 3306 wird weder lokal noch produktiv veröffentlicht.

Benötigt werden:

- Linux-Server, empfohlenes Verzeichnis `/opt/crosschapp`
- Docker Engine und Docker Compose Plugin mit Unterstützung für `!override` (Compose 2.24.4 oder neuer)
- Nginx
- Certbot mit Nginx-Plugin, sofern kein vorhandener Zertifikatsmanager genutzt wird
- `sqlite3` ausschließlich für die einmalige Übernahme der bisherigen SQLite-Daten
- der MariaDB-Client bzw. `mariadb-dump` aus dem DB-Container für Backups und Diagnosen
- öffentlich nur 80/tcp und 443/tcp; SSH nach bestehender Serverregel

Port 8082 darf in Firewall oder Cloud-Firewall nicht öffentlich freigegeben werden. Docker bindet ihn zusätzlich nur an `127.0.0.1`.

## Dateien und Environment

Unter `/opt/crosschapp` liegen mindestens `app/`, `docker/`, `data/`, `deployment/`, `Dockerfile`, beide Compose-Dateien, `composer.json`, `composer.lock` und `.env.production`.

```bash
cd /opt/crosschapp
cp .env.production.example .env.production
chmod 600 .env.production
```

Die Vorlage enthält keine Secrets:

```dotenv
APP_ENV=production
APP_BASE_URL=https://bni.crosschapp.de
CROSSCHAPP_DB_DRIVER=mysql
CROSSCHAPP_DB_HOST=db
CROSSCHAPP_DB_PORT=3306
CROSSCHAPP_DB_NAME=crosschapp
CROSSCHAPP_DB_USER=crosschapp
CROSSCHAPP_DB_PASSWORD=EIN_STARKES_EIGENES_PASSWORT
CROSSCHAPP_DB_ROOT_PASSWORD=EIN_ANDERES_STARKES_PASSWORT
CROSSCHAPP_TRUSTED_PROXIES=172.30.0.1
```

`APP_BASE_URL` ist in Produktion die Quelle der Wahrheit für Verifikations-, Passwortreset- und Einladungslinks. Sie überschreibt die DB-Einstellung `mail_settings.base_url`; die Adminansicht zeigt den effektiven Wert. SMTP-Zugangsdaten bleiben in der geschützten Datenbankkonfiguration und gehören nicht in Git oder diese Vorlage. Die App verwendet nie den MariaDB-Root-Benutzer.

Das Produktionsnetz nutzt bewusst `172.30.0.0/24` mit Gateway `172.30.0.1`. Nur diese bekannte Proxyadresse darf standardmäßig `X-Forwarded-For` liefern. Direkte Clients können Forwarded-Header nicht zur Umgehung von Rate-Limits nutzen. Vor dem ersten öffentlichen Betrieb im Webcontainer kontrollieren, dass `REMOTE_ADDR` für einen Nginx-Aufruf tatsächlich `172.30.0.1` ist; andernfalls den konkreten Gatewaywert in `CROSSCHAPP_TRUSTED_PROXIES` anpassen, niemals ein beliebiges Subnetz oder `0.0.0.0/0` vertrauen.

In `APP_ENV=production` werden Sessioncookies immer mit `Secure`, außerdem mit `HttpOnly` und `SameSite=Lax` gesetzt. `session.use_strict_mode` und reine Cookie-Sessions sind aktiv; nach dem Login wird die Session-ID regeneriert. Lokale HTTP-Entwicklung setzt kein erzwungenes Secure-Cookie.

## MariaDB-Persistenz und einmalige SQLite-Übernahme

MariaDB speichert `/var/lib/mysql` im benannten Volume `mariadb-data`. Image-Neubauten und Containerersatz löschen dieses Volume nicht. `docker compose down --volumes` ist in Produktion deshalb verboten. Die Anwendung verbindet sich per PDO/MySQL mit `utf8mb4`, nativen Prepared Statements und InnoDB-Fremdschlüsseln. `schema_migrations` hält die angewandte Schemaversion fest.

Lokaler Kontrollstand vom 19.08.2026, ausschließlich als Übertragungscheck und ohne personenbezogene Details:

| Kennzahl | Anzahl |
|---|---:|
| Organisationen | 881 |
| gültige Chapter | 520 |
| Benutzer | 5 |
| Angebote | 14 |
| Gesuche | 10 |
| Einladungen | 3 |

Vor der ersten Übernahme den lokalen Worker stoppen und Schreibzugriffe vermeiden:

```bash
cd /pfad/zu/bni-dach
docker compose stop worker
sqlite3 data/bni-dach.sqlite ".backup '/tmp/crosschapp-production.sqlite'"
sqlite3 /tmp/crosschapp-production.sqlite "PRAGMA integrity_check;"
```

Die Backup-Datei nach `/opt/crosschapp/data/bni-dach.sqlite` übertragen. Nicht die laufende DB blind mit `cp` kopieren. Danach MariaDB zunächst ohne Web/Worker starten und die Migration einmalig ausführen:

```bash
docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml up -d db
docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml run --rm \
  -e CROSSCHAPP_DB_SKIP_SEED=1 \
  -e CROSSCHAPP_SQLITE_SOURCE=/var/www/data/bni-dach.sqlite \
  web php /var/www/html/bin/migrate-sqlite-to-mysql.php
```

Das Werkzeug verlangt ein leeres Ziel, überträgt IDs und Hashes transaktional, vergleicht jede Tabellenanzahl und prüft AUTO_INCREMENT. Verwaiste historische Tokenbesitzer werden auf `NULL` neutralisiert; Tokenhash und Zeile bleiben erhalten. Bei einem Fehler erfolgt Rollback. Die SQLite-Datei danach als unverändertes Rollback-Backup behalten.

Vor jedem Update ein konsistentes, nicht automatisch gelöschtes MariaDB-Backup erzeugen. Die Shell-Umleitung läuft auf dem Host; das Passwort wird aus der geschützten `.env.production` geladen bzw. interaktiv/über eine geschützte Clientkonfiguration bereitgestellt, nicht in die Kommandohistorie geschrieben:

```bash
stamp=$(date +%Y%m%d-%H%M%S)
docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml \
  exec -T db sh -c 'exec mariadb-dump --single-transaction --quick --routines --triggers -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' \
  > "/opt/crosschapp/backups/crosschapp-${stamp}.sql"
```

Restore nur bei gestopptem Web und Worker in eine leere, gezielt ausgewählte CrossChAPP-Datenbank: Dump zunächst separat sichern/prüfen, dann über den DB-Container mit dessen geschützten `MARIADB_*`-Variablen einspielen. Nie ungeprüft das MariaDB-Volume löschen.

## Codeübertragung

Empfohlen ist für den ersten Stand ein Git-basierter, eindeutig commitgebundener Deploy: Tests ausführen, Deployment-Commit erstellen, Repository nach `/opt/crosschapp` klonen und exakt diesen Commit oder ein Tag auschecken. `data/`, `.env.production` und Backups kommen nie aus Git.

Alternativ kann `rsync` genutzt werden:

```bash
rsync -a --delete --exclude '/data/' --exclude '/backups/' --exclude '/.env.production' ./ server:/opt/crosschapp/
```

Bei Updates dieselben Ausschlüsse beibehalten. Vor dem echten Upload lokal:

```bash
./tests/check-all.sh
git add .
git commit -m "Produktionsdeployment für CrossChAPP vorbereiten"
git status
```

Der Commit gehört bewusst nicht zu dieser Vorbereitung.

## Compose prüfen und Web erstmals starten

```bash
cd /opt/crosschapp
docker compose config
docker compose --env-file .env.production \
  -f docker-compose.yml -f docker-compose.prod.yml config
docker compose --env-file .env.production \
  -f docker-compose.yml -f docker-compose.prod.yml build web
docker compose --env-file .env.production \
  -f docker-compose.yml -f docker-compose.prod.yml up -d db web
docker compose --env-file .env.production \
  -f docker-compose.yml -f docker-compose.prod.yml ps
curl -fsS http://127.0.0.1:8082/api/health.php
```

Erwartet werden HTTP 200 und `{"ok":true}`. Noch keinen Worker starten. Das Workerprofil verhindert seinen unbeabsichtigten Start durch den obigen Web-Befehl.

## Nginx und HTTPS

`deployment/nginx-bni.crosschapp.de.conf.example` als Vorlage verwenden, nicht ungeprüft vorhandene Serverkonfiguration überschreiben. Die wichtigen Header sind `Host`, `X-Real-IP`, `X-Forwarded-For` und `X-Forwarded-Proto`. Die Vorlage setzt `X-Forwarded-For` beim einzelnen lokalen Proxy absichtlich auf `$remote_addr`, statt einen möglicherweise vom Internet eingeschleusten Wert mit `$proxy_add_x_forwarded_for` weiterzureichen.

```bash
sudo cp deployment/nginx-bni.crosschapp.de.conf.example /etc/nginx/sites-available/bni.crosschapp.de
sudo ln -s /etc/nginx/sites-available/bni.crosschapp.de /etc/nginx/sites-enabled/bni.crosschapp.de
sudo nginx -t
sudo systemctl reload nginx
```

Nach korrektem DNS und funktionierendem HTTP vorhandenes Zertifikatsmanagement nutzen oder:

```bash
sudo certbot --nginx -d bni.crosschapp.de
curl -I https://bni.crosschapp.de
```

## Risikoarmer erster Smoke-Test

Erlaubt sind zunächst nur: Startseite, CrossChAPPtern, Loginansicht, Adminlogin, Admin → Anwender, Admin → Einladungen, lokale Chapterliste und lokale Suche sowie das Öffnen von Vertretung anbieten/finden. Browserkonsole auf JavaScript- und Mixed-Content-Fehler prüfen.

Noch nicht ausführen: Einladung, Passwortreset, SMTP-Testmail, BNI-Grunddaten-/Detailabruf, X/Y/Z, `tests/check-live-bni.sh` oder Workerstart.

Ohne Mailversand lässt sich die produktive Linkbasis im Container prüfen:

```bash
docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml \
  exec -T web php -r 'require "/var/www/html/src/Database.php"; require "/var/www/html/src/MailSettingsRepository.php"; $s=new MailSettingsRepository((new Database())->connection()); echo $s->settings()["baseUrl"], PHP_EOL;'
```

Ausgabe muss exakt `https://bni.crosschapp.de` sein. Verifikation, Reset und Einladung lesen alle diese gemeinsame effektive Einstellung.

## Kritische Schritte vor öffentlicher Nutzung

1. Mit dem Entwicklungsadmin anmelden und über „Passwort ändern“ ein starkes, einzigartiges Passwort setzen.
2. Danach `admin/admin` testen: Anmeldung muss scheitern.
3. Unter Admin → Sonstiges SMTP-Server, Port, Benutzer, Verschlüsselung, Absender und die angezeigte Linkbasis prüfen. Erst nach erfolgreichem HTTPS-Smoke-Test gezielt eine Testmail senden.
4. Unter Admin → Automatisierung X/Y/Z, Tageslimit und Intervalle prüfen. Gespeicherte Schalter wurden mit der DB übernommen und dürfen nicht ungeprüft einen Worker steuern.

## Worker und Status

Der Worker schreibt bei jedem Zyklus einen UTC-Heartbeat in MariaDB. „Hintergrunddienst aktiv“ bedeutet, dass dieser Heartbeat jünger als zweimal das konfigurierte Intervall ist. Nach Stop kann die Anzeige daher höchstens bis zu zwei Intervalle nachlaufen; danach ist „nicht aktiv“ korrekt. Nach einem Neustart aktualisiert der Worker den persistenten Heartbeat sofort vor Automatisierungsarbeit.

Erst nach Web, HTTPS, Adminpasswort, DB-, SMTP- und Automatisierungsprüfung bewusst starten:

```bash
docker compose --env-file .env.production --profile worker \
  -f docker-compose.yml -f docker-compose.prod.yml up -d worker
docker compose --env-file .env.production --profile worker \
  -f docker-compose.yml -f docker-compose.prod.yml ps
```

Danach im Adminbereich „Hintergrunddienst aktiv“ kontrollieren. Dieser Start kann entsprechend der gespeicherten Schalter reale BNI-Abfragen auslösen und ist deshalb kein Teil des ersten Webstarts.

## Update

Der Standardweg für künftige Updates ist das versionierte Update-Skript. Es wird im sauberen Git-Arbeitsverzeichnis unter `/opt/crosschapp` mit einem exakten Tag oder dem Remote-Branch `main` gestartet:

```bash
cd /opt/crosschapp
./deployment/update-production.sh v0.13.0
# alternativ, bewusst der aktuelle Stand von origin/main:
./deployment/update-production.sh main
```

Voraussetzungen sind eine vorhandene geschützte `.env.production`, gültige Produktions-Compose-Dateien, ein sauberer Worktree sowie eine laufende und gesunde MariaDB. Das Skript validiert zunächst Compose und Git-Ref, holt Tags, checkt den Ref detached aus und erstellt vor Build, Webersatz und Schemamigration einen komprimierten konsistenten Dump unter `/opt/crosschapp/backups/`. Passwörter werden dabei nur aus den Umgebungsvariablen des DB-Containers gelesen und nicht ausgegeben.

Danach baut es Web und Worker, ersetzt zunächst ausschließlich Web, prüft den öffentlichen Health-Endpunkt mit Retries, startet die additive Migration und vergleicht `schema_migrations` mit `MysqlSchema::LATEST_VERSION`. Erst nach diesen erfolgreichen Prüfungen wird der Worker ersetzt. Ein frischer `automation_runtime.worker_last_seen_at` muss innerhalb von 90 Sekunden vorliegen. Abschließend werden DB- und Web-Gesundheit, Schema, Worker-Heartbeat und Git-Commit erneut ausgegeben.

Ein nichtmutierender Vorabcheck ist möglich, sofern der Ref bereits lokal vorhanden ist:

```bash
./deployment/update-production.sh --dry-run v0.13.0
```

Der Dry-run prüft Voraussetzungen, sauberen Worktree, Compose-Konfiguration und lokalen Git-Ref. Er führt weder Fetch/Checkout noch Backup, Build, Neustart oder Migration aus.

Bei Buildfehlern bleiben die laufenden Container unberührt. Bei einem Fehler nach dem Webersatz zeigt das Skript Schritt, Zeile, Exit-Code, vorherigen Commit, Backup-Pfad und Containerstatus; der Worker wird erst nach erfolgreichem Web-, Migrations- und Schemacheck aktualisiert. Es erfolgt bewusst weder ein automatischer Git- noch Datenbank-Rollback. Vor einem manuellen Rollback müssen Code-/Schema-Kompatibilität und das erzeugte Backup geprüft werden.

Der bisherige manuelle Ablauf bleibt als Diagnose- und Notfallreferenz erhalten:

1. Worker stoppen.
2. MariaDB mit `mariadb-dump --single-transaction` sichern und Dump prüfen.
3. Eindeutigen neuen Commit/Tag auschecken oder Code mit den Ausschlüssen synchronisieren.
4. Images bauen.
5. Nur Web aktualisieren.
6. Lokalen Healthcheck ausführen.
7. Öffentlichen risikoarmen Smoke-Test ausführen.
8. Automatisierungseinstellungen kontrollieren.
9. Worker bewusst starten.

```bash
docker compose --env-file .env.production --profile worker -f docker-compose.yml -f docker-compose.prod.yml stop worker
docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml build web
docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml up -d --no-deps web
curl -fsS http://127.0.0.1:8082/api/health.php
```

## Rollback

Code-Rollback: Worker stoppen, vorherigen Commit/Tag auschecken, Image neu bauen, Web starten und Health/Smoke prüfen. Die DB nicht automatisch zurücksetzen.

Daten-Rollback nur bei nachgewiesener Notwendigkeit: Worker und Web stoppen, den aktuellen DB-Stand zusätzlich dumpen, eine leere Zieldatenbank anlegen und den gewünschten SQL-Dump einspielen. Für den unmittelbaren Migrationsrollback stattdessen Web/Worker stoppen, `CROSSCHAPP_DB_DRIVER=sqlite` plus `CROSSCHAPP_DB_PATH` setzen und die unveränderte SQLite-Datei verwenden. Code- und Datenrollback nie automatisch koppeln.

## Produktionscheckliste

- [ ] Tests grün
- [ ] Deployment-Commit vorhanden
- [ ] Server Docker installiert
- [ ] SQLite-Übergangsbackup erstellt
- [ ] MariaDB-Ziel leer und Migration mit COUNT-Vergleich erfolgreich
- [ ] `.env.production` erstellt und Modus 0600
- [ ] Web ohne Worker gestartet
- [ ] Health lokal HTTP 200
- [ ] Nginx-Konfiguration mit `nginx -t` geprüft und aktiv
- [ ] HTTPS aktiv
- [ ] `https://bni.crosschapp.de` erreichbar
- [ ] Adminpasswort geändert; `admin/admin` scheitert
- [ ] Base-URL exakt `https://bni.crosschapp.de`
- [ ] SMTP geprüft
- [ ] X/Y/Z, Intervalle und Tageslimit geprüft
- [ ] Worker bewusst gestartet
- [ ] Abschluss-Smoke-Test bestanden
