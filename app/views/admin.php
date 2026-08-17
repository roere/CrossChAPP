<?php
require_once __DIR__ . '/../src/BniRequestPolicy.php';
?>
<section class="page-hero admin-hero" data-detail-delay-ms="<?= BniRequestPolicy::DETAIL_DELAY_MS ?>">
    <div class="shell">
        <p class="eyebrow">Administration</p>
        <h1>Chapterdaten verwalten</h1>
        <p>BNI-Grunddaten importieren, lokale Details pflegen und den SQLite-Bestand kontrollieren.</p>
    </div>
</section>

<main class="shell content">
    <section class="panel admin-local-heading" aria-labelledby="local-heading">
        <div>
            <p class="section-kicker">Gespeicherter Bestand</p>
            <h2 id="local-heading">Lokale Datenbank</h2>
            <p>Die Übersicht wird direkt aus SQLite geladen. Das Öffnen dieses Bereichs ruft keine BNI-Daten ab.</p>
        </div>
        <div id="local-message" class="message" role="status" aria-live="polite">Lokale Daten werden geladen …</div>
    </section>

    <section id="results" class="results" hidden>
        <div id="stats" class="stats" aria-label="Chapter-Statistik"></div>
        <section class="panel batch-panel" aria-labelledby="batch-heading">
            <div class="section-heading">
                <div><p class="section-kicker">Kontrollierter Detailimport</p><h2 id="batch-heading">Fehlende Chapterdetails laden</h2></div>
            </div>
            <div id="batch-stats" class="batch-stats" aria-label="Detailstatus bestehender Chapter"></div>
            <div class="batch-controls">
                <fieldset class="choice-group batch-size-group">
                    <legend>Batch-Größe</legend>
                    <input id="batch-size" type="hidden" value="25">
                    <div id="batch-size-options" class="batch-size-options" role="group" aria-label="Batch-Größe für Chapterdetails">
                        <button type="button" data-batch-size="10" aria-pressed="false">10</button>
                        <button type="button" data-batch-size="25" aria-pressed="true">25</button>
                        <button type="button" data-batch-size="50" aria-pressed="false">50</button>
                    </div>
                </fieldset>
                <div class="batch-actions">
                    <button id="start-batch" type="button">Nächste fehlende Chapter laden</button>
                    <button id="stop-batch" type="button" class="secondary" hidden>Nach aktuellem Chapter stoppen</button>
                </div>
            </div>
            <div id="batch-progress" class="batch-progress" role="status" aria-live="polite"></div>
            <div id="batch-message" class="message" role="status" aria-live="polite"></div>
            <p class="batch-rate-hint">BNI-Abfragen erfolgen sequenziell mit mindestens 1,5 Sekunden Abstand. Bei einer Rate-Limitierung wird der Batch automatisch beendet.</p>
            <details id="automation-panel" class="automation-panel">
                <summary>Automatisierter Import</summary>
                <div class="automation-content">
                    <form id="automation-form">
                        <section class="automation-setting">
                            <div>
                                <h3>Aktualisierung bei Nutzung</h3>
                                <p>Wenn aktiviert, werden lokal vorhandene Chapterdaten aktualisiert, sobald ein Chapter in einer Suche erscheint oder geöffnet wird und die Detaildaten älter als X Tage sind.</p>
                            </div>
                            <label class="switch-label"><input id="usage-refresh-enabled" type="checkbox" role="switch"><span>Veraltete Chapter bei Suche oder Detailansicht aktualisieren</span></label>
                            <label>Älter als <span class="days-input"><input id="usage-refresh-days" type="number" min="1" max="365" value="7" required> Tage</span></label>
                        </section>
                        <section class="automation-setting">
                            <div>
                                <h3>Automatische Aktualisierung</h3>
                                <p>Wenn aktiviert, lädt CrossChAPP fehlende Chapterdetails erstmals ein und aktualisiert bereits vorhandene Daten, sobald sie älter als Y Tage sind.</p>
                            </div>
                            <label class="switch-label"><input id="automatic-refresh-enabled" type="checkbox" role="switch"><span>Veraltete Chapter automatisch aktualisieren</span></label>
                            <label>Älter als <span class="days-input"><input id="automatic-refresh-days" type="number" min="1" max="365" value="30" required> Tage</span></label>
                            <p class="automation-hint">Maximal 10 Chapter pro automatischem Lauf. BNI-Abfragen erfolgen sequenziell mit mindestens 1,5 Sekunden Abstand.</p>
                        </section>
                        <section class="automation-setting automation-daily-limit">
                            <div>
                                <h3>Gemeinsames Tageslimit</h3>
                                <p>Dieses Limit gilt gemeinsam für Aktualisierungen durch Suche, Detailöffnung und den automatischen Hintergrundimport. Manuelle Admin-Importe sind davon nicht betroffen.</p>
                            </div>
                            <label>Maximale automatische Aktualisierungen pro Tag
                                <input id="automatic-refresh-daily-limit" type="number" min="1" max="1000" value="50" required>
                            </label>
                        </section>
                        <button id="save-automation" type="submit">Einstellungen speichern</button>
                        <div id="automation-message" class="message" role="status" aria-live="polite"></div>
                    </form>
                    <section class="automation-statistics" aria-labelledby="automation-stats-heading">
                        <div class="section-heading"><div><p class="section-kicker">SQLite-Historie</p><h3 id="automation-stats-heading">Automatische Aktualisierungen</h3></div><span id="worker-status" class="status-badge">Status wird geladen</span></div>
                        <div id="automation-stats" class="automation-stats"></div>
                    </section>
                </div>
            </details>
        </section>
        <div id="empty-database" class="panel empty-database" hidden>
            <strong>Die lokale Datenbank enthält noch keine BNI-Organisationen.</strong>
            <a href="#data-import">Grunddaten von BNI aktualisieren</a>
        </div>
        <section class="panel controls" aria-labelledby="filter-heading">
            <div class="section-heading">
                <div><p class="section-kicker">Chapter finden</p><h2 id="filter-heading">Filter &amp; Aktionen</h2></div>
                <span id="selection-count">0 ausgewählt</span>
            </div>
            <div class="filter-grid">
                <label>Land<select id="country-filter"><option value="">Alle</option><option value="DE">Deutschland</option><option value="AT">Österreich</option></select></label>
                <label>Typ<select id="type-filter"><option value="">Alle</option><option value="CHAPTER">Chapter</option><option value="CORE_GROUP">Im Aufbau</option><option value="PLANNED_GROUP">Geplant</option></select></label>
                <label>Detailstatus<select id="detail-filter"><option value="">Alle</option><option value="loaded">geladen</option><option value="not_loaded">nicht geladen</option><option value="error">Fehler</option></select></label>
                <label>Suche<input id="text-filter" type="search" placeholder="orgId oder Chaptername"></label>
            </div>
            <label class="reload-option"><input id="reload-details" type="checkbox"> Bereits geladene Details erneut abrufen</label>
            <div class="actions">
                <button id="select-visible" type="button" class="secondary">Alle sichtbaren auswählen</button>
                <button id="clear-selection" type="button" class="secondary">Auswahl aufheben</button>
                <button id="load-details" type="button">Details für ausgewählte laden</button>
            </div>
            <div id="progress" class="progress" role="status" aria-live="polite"></div>
        </section>

        <section id="chapter-list" class="table-panel" aria-labelledby="chapter-list-heading">
            <div class="list-heading">
                <div><p class="section-kicker">Ergebnisse</p><h2 id="chapter-list-heading">Chapterliste</h2></div>
                <p id="visible-count" class="visible-count"></p>
            </div>
            <div class="table-scroll">
                <table>
                    <thead><tr>
                        <th class="select-column"><span class="sr-only">Auswahl</span></th><th class="toggle-column"><span class="sr-only">Details</span></th>
                        <th>Chaptername</th><th class="column-orgid">orgId</th><th class="column-country">Land</th><th class="column-type">Typ</th>
                        <th>Ort</th><th class="column-day">Wochentag</th><th class="column-time">Uhrzeit</th><th>Detailstatus</th><th class="column-updated">Zuletzt aktualisiert</th>
                    </tr></thead>
                    <tbody id="organization-list"></tbody>
                </table>
            </div>
        </section>
    </section>

    <section id="data-import" class="panel source-panel admin-update-panel" aria-labelledby="source-heading">
        <div>
            <p class="section-kicker">Bewusste Live-Aktion</p>
            <h2 id="source-heading">BNI-Daten aktualisieren</h2>
            <p>Die lokale Datenbank enthält die zuletzt importierten BNI-Daten. Mit „Grunddaten aktualisieren“ wird die aktuelle BNI-DACH-Kartenquelle erneut eingelesen.</p>
        </div>
        <form id="source-form" class="source-form">
            <label for="source-url">BNI-DACH-Link</label>
            <div class="input-row">
                <input id="source-url" name="url" type="url" value="https://bni.de/de/findachapter" required>
                <button id="read-button" type="submit">Grunddaten von BNI aktualisieren</button>
            </div>
        </form>
        <div id="message" class="message" role="status" aria-live="polite"></div>
    </section>
    <details id="misc-panel" class="panel misc-panel">
        <summary>Sonstiges</summary>
        <div class="misc-content">
            <section><p class="section-kicker">Konfiguration</p><h2>E-Mail-Versand</h2>
                <form id="mail-settings-form" class="mail-settings-grid">
                    <label>SMTP-Server<input name="smtpHost"></label><label>SMTP-Port<input name="smtpPort" type="number" min="1" max="65535" value="587"></label>
                    <label>SMTP-Benutzername<input name="smtpUsername" autocomplete="off"></label><label>SMTP-Passwort<input name="smtpPassword" type="password" autocomplete="new-password" placeholder="••••••••"></label>
                    <label>Verschlüsselung<select name="encryption"><option value="starttls">STARTTLS</option><option value="tls">SSL/TLS</option><option value="none">keine</option></select></label>
                    <label>Absender-E-Mail<input name="senderEmail" type="email"></label><label>Absendername<input name="senderName" value="CrossChAPP"></label><label>Basis-URL für Links<input name="baseUrl" type="url" value="http://localhost:8082"></label>
                    <button type="submit">E-Mail-Einstellungen speichern</button>
                </form><div id="mail-settings-message" class="message" role="status"></div>
                <div class="test-mail-row"><label>Test-E-Mail-Adresse<input id="test-mail-address" type="email"></label><button id="send-test-mail" type="button" class="secondary">Test-E-Mail senden</button></div><div id="test-mail-message" class="message" role="status"></div>
            </section>
            <section><p class="section-kicker">Inhalte</p><h2>E-Mail-Vorlagen</h2><form id="email-templates-form">
                <fieldset><legend>E-Mail-Adresse bestätigen</legend><label>Betreff<input name="verify_subject"></label><label>Text<textarea name="verify_body" rows="10"></textarea></label><p>{{first_name}}, {{last_name}}, {{email}}, {{verification_link}}, {{app_name}}</p></fieldset>
                <fieldset><legend>Passwort zurücksetzen</legend><label>Betreff<input name="reset_subject"></label><label>Text<textarea name="reset_body" rows="10"></textarea></label><p>{{first_name}}, {{last_name}}, {{reset_link}}, {{app_name}}</p></fieldset>
                <button type="submit">E-Mail-Vorlagen speichern</button>
            </form><div id="email-templates-message" class="message" role="status"></div></section>
        </div>
    </details>
</main>
