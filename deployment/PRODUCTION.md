# CrossChAPP produktiv betreiben

Diese Anleitung bereitet den ersten Betrieb unter `https://bni.crosschapp.de` vor. Sie führt selbst keinen Deploy aus. Die Produktionskonfiguration veröffentlicht Apache ausschließlich als `127.0.0.1:8082`; nur Nginx ist öffentlich erreichbar. Der Worker gehört zum Profil `worker` und bleibt beim ersten Start aus.

## Architektur und Voraussetzungen

Entwicklung verwendet `docker-compose.yml` mit Quellcode-Bind-Mount, dem auf allen Interfaces veröffentlichten Port `8082` und einem standardmäßig laufenden Worker. Produktion verwendet `docker-compose.yml` plus `docker-compose.prod.yml`: Anwendungscode kommt aus dem Image, nur `./data` wird persistent eingebunden, der Port ist loopbackgebunden und der Worker ist opt-in.

Benötigt werden:

- Linux-Server, empfohlenes Verzeichnis `/opt/crosschapp`
- Docker Engine und Docker Compose Plugin mit Unterstützung für `!override` (Compose 2.24.4 oder neuer)
- Nginx
- Certbot mit Nginx-Plugin, sofern kein vorhandener Zertifikatsmanager genutzt wird
- `sqlite3` für konsistente Backups und Diagnosen
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
CROSSCHAPP_DB_PATH=/var/www/data/bni-dach.sqlite
CROSSCHAPP_TRUSTED_PROXIES=172.30.0.1
```

`APP_BASE_URL` ist in Produktion die Quelle der Wahrheit für Verifikations-, Passwortreset- und Einladungslinks. Sie überschreibt die SQLite-Einstellung `mail_settings.base_url`; die Adminansicht zeigt den effektiven Wert. Ohne Environment-Override bleibt lokal die SQLite-Einstellung mit Standard `http://localhost:8082` wirksam. SMTP-Zugangsdaten bleiben in der bestehenden geschützten Admin-/SQLite-Konfiguration und gehören nicht in Git oder diese Vorlage.

Das Produktionsnetz nutzt bewusst `172.30.0.0/24` mit Gateway `172.30.0.1`. Nur diese bekannte Proxyadresse darf standardmäßig `X-Forwarded-For` liefern. Direkte Clients können Forwarded-Header nicht zur Umgehung von Rate-Limits nutzen. Vor dem ersten öffentlichen Betrieb im Webcontainer kontrollieren, dass `REMOTE_ADDR` für einen Nginx-Aufruf tatsächlich `172.30.0.1` ist; andernfalls den konkreten Gatewaywert in `CROSSCHAPP_TRUSTED_PROXIES` anpassen, niemals ein beliebiges Subnetz oder `0.0.0.0/0` vertrauen.

In `APP_ENV=production` werden Sessioncookies immer mit `Secure`, außerdem mit `HttpOnly` und `SameSite=Lax` gesetzt. `session.use_strict_mode` und reine Cookie-Sessions sind aktiv; nach dem Login wird die Session-ID regeneriert. Lokale HTTP-Entwicklung setzt kein erzwungenes Secure-Cookie.

## SQLite, Übernahme und Rechte

Die Anwendung verwendet `/var/www/data/bni-dach.sqlite`; auf dem Host ist das `/opt/crosschapp/data/bni-dach.sqlite`. Das gesamte Verzeichnis wird eingebunden, damit DB, `-wal`, `-shm` und Lockdateien denselben persistenten und schreibbaren Datenträger verwenden. Die Anwendung aktiviert WAL, Foreign Keys und einen Busy-Timeout. Ein Image-Neubau berührt `data/` nicht, und das Entrypoint legt nur ein fehlendes Verzeichnis an; eine vorhandene DB wird durch `CREATE TABLE IF NOT EXISTS`/additive Schemaanpassungen nicht ersetzt.

Lokaler Kontrollstand vom 19.08.2026, ausschließlich als Übertragungscheck und ohne personenbezogene Details:

| Kennzahl | Anzahl |
|---|---:|
| Organisationen | 881 |
| gültige Chapter | 520 |
| Benutzer | 5 |
| Angebote | 5 |
| Gesuche | 8 |
| Einladungen | 3 |

Vor der ersten Übernahme den lokalen Worker stoppen und Schreibzugriffe vermeiden:

```bash
cd /pfad/zu/bni-dach
docker compose stop worker
sqlite3 data/bni-dach.sqlite ".backup '/tmp/crosschapp-production.sqlite'"
sqlite3 /tmp/crosschapp-production.sqlite "PRAGMA integrity_check;"
```

Erst danach die Backup-Datei separat nach `/opt/crosschapp/data/bni-dach.sqlite` übertragen. Nicht die laufende DB blind mit `cp` kopieren. Auf dem Server vor dem Containerstart:

```bash
sudo install -d -o 33 -g 33 -m 0770 /opt/crosschapp/data /opt/crosschapp/backups
sudo chown 33:33 /opt/crosschapp/data/bni-dach.sqlite
sudo chmod 0660 /opt/crosschapp/data/bni-dach.sqlite
```

Das Image verwendet `www-data` mit UID/GID 33. Vor Anwendung auf einem abweichenden Docker-/User-Namespace-System mit `docker run --rm <image> id www-data` verifizieren. Kein `chmod -R 777`. PHP-Sessions liegen im Container im vom PHP-Image vorbereiteten Sessionverzeichnis; persistiert werden müssen nur Anwendungsdaten, nicht Login-Sessions über einen Rebuild hinweg.

Vor jedem Update ein konsistentes, nicht automatisch gelöschtes Backup erzeugen:

```bash
stamp=$(date +%Y%m%d-%H%M%S)
sqlite3 /opt/crosschapp/data/bni-dach.sqlite ".backup '/opt/crosschapp/backups/crosschapp-${stamp}.sqlite'"
sqlite3 "/opt/crosschapp/backups/crosschapp-${stamp}.sqlite" "PRAGMA integrity_check;"
```

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
  -f docker-compose.yml -f docker-compose.prod.yml up -d --no-deps web
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

Der Worker schreibt bei jedem Zyklus einen UTC-Heartbeat in SQLite. „Hintergrunddienst aktiv“ bedeutet, dass dieser Heartbeat jünger als zweimal das konfigurierte Intervall ist. Nach Stop kann die Anzeige daher höchstens bis zu zwei Intervalle nachlaufen; danach ist „nicht aktiv“ korrekt. Nach einem Neustart aktualisiert der Worker den persistenten Heartbeat sofort vor Automatisierungsarbeit. UTC-Zeitstempel und SQLite vermeiden eine Abhängigkeit von Containerdateien oder lokaler Zeitzone.

Erst nach Web, HTTPS, Adminpasswort, DB-, SMTP- und Automatisierungsprüfung bewusst starten:

```bash
docker compose --env-file .env.production --profile worker \
  -f docker-compose.yml -f docker-compose.prod.yml up -d worker
docker compose --env-file .env.production --profile worker \
  -f docker-compose.yml -f docker-compose.prod.yml ps
```

Danach im Adminbereich „Hintergrunddienst aktiv“ kontrollieren. Dieser Start kann entsprechend der gespeicherten Schalter reale BNI-Abfragen auslösen und ist deshalb kein Teil des ersten Webstarts.

## Update

1. Worker stoppen.
2. SQLite mit `.backup` sichern und Integrität prüfen.
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

Daten-Rollback nur bei nachgewiesener Notwendigkeit: Worker und Web stoppen, den aktuellen beschädigten/unerwünschten DB-Stand zusätzlich sichern, gewünschtes Backup nach `data/bni-dach.sqlite` zurückspielen, Owner/Rechte prüfen, Web starten und Integrität/Health prüfen. Worker erst anschließend bewusst starten. Code- und Datenrollback nie automatisch koppeln.

## Produktionscheckliste

- [ ] Tests grün
- [ ] Deployment-Commit vorhanden
- [ ] Server Docker installiert
- [ ] SQLite-Backup erstellt
- [ ] DB übertragen
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
