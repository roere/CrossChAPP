<?php
require_once __DIR__ . '/../src/BniRequestPolicy.php';
?>
<main class="shell content no-hero-content" data-detail-delay-ms="<?= BniRequestPolicy::DETAIL_DELAY_MS ?>">
    <p class="page-intro-title"><?= $isAdmin ? 'Administration' : 'Anwenderverwaltung' ?></p>
    <?php if (!$isAdmin): ?><p class="page-meta">Rolle: Anwenderbetreuer</p><?php endif; ?>
    <?php if ($isAdmin): ?>
    <section class="panel admin-local-heading" aria-labelledby="local-heading">
        <div>
            <p class="section-kicker">Gespeicherter Bestand</p>
            <h2 id="local-heading">Lokale Datenbank</h2>
            <p>Die Übersicht verwendet die lokal gespeicherten Organisationsdaten. Das Öffnen dieses Bereichs ruft keine BNI-Daten ab.</p>
        </div>
        <div id="local-message" class="message" role="status" aria-live="polite">Lokale Daten werden geladen …</div>
    </section>

    <details id="chapter-data-panel" class="panel misc-panel"><summary>Chapterdaten</summary><section id="results" class="results chapter-data-content" hidden>
        <p class="page-meta">Gesamtbestand inkl. Chapter im Aufbau und in Planung</p>
        <div id="stats" class="stats" aria-label="Chapter-Statistik"></div>
        <section class="panel batch-panel" aria-labelledby="batch-heading">
            <div class="section-heading">
                <div><p class="section-kicker">Kontrollierter Detailimport</p><h2 id="batch-heading">Fehlende Chapterdetails laden</h2><p class="page-meta">Nur bestehende Chapter</p></div>
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
                            <p class="automation-hint">Die automatische Detailaktualisierung berücksichtigt bestehende Chapter, Gruppen im Aufbau und geplante Gruppen.</p>
                        </section>
                        <section class="automation-setting">
                            <div>
                                <h3>Automatische Grunddatenaktualisierung</h3>
                                <p>Wenn aktiviert, aktualisiert CrossChAPP die BNI-Grunddaten automatisch, sobald der letzte erfolgreiche Grunddatenimport älter als Z Tage ist.</p>
                            </div>
                            <label class="switch-label"><input id="map-refresh-enabled" type="checkbox" role="switch"><span>BNI-Grunddaten automatisch aktualisieren</span></label>
                            <label>Grunddaten aktualisieren, wenn älter als <span class="days-input"><input id="map-refresh-days" type="number" min="1" max="30" value="1" required> Tage</span></label>
                            <p class="automation-hint">Pro Fälligkeit erfolgt genau ein Sammelrequest. Dieser zählt nicht zum Tageslimit der Detailrequests.</p>
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
                        <div class="section-heading"><div><p class="section-kicker">Aktualisierungshistorie</p><h3 id="automation-stats-heading">Automatische Aktualisierungen</h3></div><span id="worker-status" class="status-badge">Status wird geladen</span></div>
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
                <button id="select-visible" type="button" class="secondary">Alle auswählen</button>
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
                <table id="admin-organization-table">
                    <thead><tr>
                        <th class="select-column"><span class="sr-only">Auswahl</span></th><th class="toggle-column"><span class="sr-only">Details</span></th>
                        <th data-sort-key="chapterName"><button type="button" class="sort-button">Chaptername <span class="sort-indicator" aria-hidden="true"></span></button></th><th class="column-orgid" data-sort-key="orgId"><button type="button" class="sort-button">orgId <span class="sort-indicator" aria-hidden="true"></span></button></th><th class="column-country" data-sort-key="country"><button type="button" class="sort-button">Land <span class="sort-indicator" aria-hidden="true"></span></button></th><th class="column-type" data-sort-key="type"><button type="button" class="sort-button">Typ <span class="sort-indicator" aria-hidden="true"></span></button></th>
                        <th data-sort-key="city"><button type="button" class="sort-button">Ort <span class="sort-indicator" aria-hidden="true"></span></button></th><th class="column-day" data-sort-key="meetingDay"><button type="button" class="sort-button">Wochentag <span class="sort-indicator" aria-hidden="true"></span></button></th><th class="column-time" data-sort-key="meetingTime"><button type="button" class="sort-button">Uhrzeit <span class="sort-indicator" aria-hidden="true"></span></button></th><th data-sort-key="detailStatus"><button type="button" class="sort-button">Detailstatus <span class="sort-indicator" aria-hidden="true"></span></button></th><th class="column-updated" data-sort-key="detailsLoadedAt"><button type="button" class="sort-button">Zuletzt aktualisiert <span class="sort-indicator" aria-hidden="true"></span></button></th>
                    </tr></thead>
                    <tbody id="organization-list"></tbody>
                </table>
            </div>
        </section>
    </section></details>

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
    <?php endif; ?>
    <details id="users-panel" class="panel misc-panel">
        <summary>Anwender</summary>
        <div class="misc-content">
            <div id="users-stats" class="stats" aria-label="Anwenderstatistik"></div>
            <section aria-labelledby="users-heading">
                <div class="section-heading"><div><p class="section-kicker">Benutzerkonten</p><h2 id="users-heading">Anwenderübersicht</h2></div></div>
                <div class="users-filter-grid">
                    <label>Suche<input id="users-search" type="search" placeholder="Name, E-Mail oder Chapter"></label>
                    <label>Status<select id="users-status-filter"><option value="">Alle</option><option value="active">Aktiv</option><option value="pending">Ausstehend</option><option value="disabled">Deaktiviert</option></select></label>
                    <label>Verifikation<select id="users-verification-filter"><option value="">Alle</option><option value="unverified">Nicht verifiziert</option><option value="directory_match">BNI-Chapter gefunden</option><option value="manual_verified">Verifiziert</option></select></label>
                    <label>Heimatchapter<select id="users-chapter-filter"><option value="">Alle</option><option value="yes">Ja</option><option value="no">Nein</option></select></label>
                </div>
                <div id="users-message" class="message" role="status" aria-live="polite"></div>
                <div id="users-table-wrap" class="table-scroll" hidden>
                    <table id="admin-users-table">
                        <thead><tr>
                            <th class="users-select-column"><span class="sr-only">Auswahl</span></th>
                            <th data-sort-key="name"><button type="button" class="sort-button">Name <span class="sort-indicator" aria-hidden="true"></span></button></th>
                            <th data-sort-key="email"><button type="button" class="sort-button">E-Mail <span class="sort-indicator" aria-hidden="true"></span></button></th>
                            <th data-sort-key="chapter"><button type="button" class="sort-button">Heimatchapter <span class="sort-indicator" aria-hidden="true"></span></button></th>
                            <th data-sort-key="status"><button type="button" class="sort-button">Status <span class="sort-indicator" aria-hidden="true"></span></button></th>
                            <th data-sort-key="verification"><button type="button" class="sort-button">Verifikation <span class="sort-indicator" aria-hidden="true"></span></button></th>
                            <th class="users-column-number" data-sort-key="offers"><button type="button" class="sort-button">Angebote <span class="sort-indicator" aria-hidden="true"></span></button></th>
                            <th class="users-column-number" data-sort-key="requests"><button type="button" class="sort-button">Gesuche <span class="sort-indicator" aria-hidden="true"></span></button></th>
                            <th class="users-column-contacts" data-sort-key="contacts"><button type="button" class="sort-button">Kontakte 30 Tage <span class="sort-indicator" aria-hidden="true"></span></button></th>
                            <th class="users-column-verified">E-Mail bestätigt</th>
                            <th class="users-column-created" data-sort-key="created"><button type="button" class="sort-button">Registriert <span class="sort-indicator" aria-hidden="true"></span></button></th>
                        </tr></thead>
                        <tbody id="users-list"></tbody>
                    </table>
                </div>
                <div id="users-actions" class="users-actions" aria-describedby="users-selection-hint">
                    <?php if ($isAdmin): ?><button id="reset-selected-user" type="button" disabled>Passwort zurücksetzen</button><?php endif; ?>
                    <button id="verify-selected-user" type="button" disabled>Verifizieren</button>
                    <?php if ($isAdmin): ?><button id="delete-selected-user" type="button" class="danger" disabled>Konto löschen</button><?php endif; ?>
                    <p id="users-selection-hint">Bitte wähle zuerst einen Anwender aus.</p>
                </div>
            </section>
        </div>
    </details>
    <?php if ($isAdmin): ?><dialog id="admin-reset-password-dialog" class="account-dialog" aria-modal="true" aria-labelledby="admin-reset-password-heading">
        <div class="account-dialog-card">
            <button id="close-admin-reset-password-icon" type="button" class="dialog-close" aria-label="Passwortreset schließen">×</button>
            <h2 id="admin-reset-password-heading">Passwort zurücksetzen</h2>
            <div id="admin-reset-password-confirmation"><p>Möchtest du eine E-Mail zum Zurücksetzen des Passworts an <strong data-admin-user-name></strong> senden?</p><p data-admin-user-email></p></div>
            <div id="admin-reset-password-message" class="message" role="status" aria-live="polite"></div>
            <div class="registration-actions"><button id="confirm-admin-reset-password" type="button">Reset-Link senden</button><button id="cancel-admin-reset-password" type="button" class="secondary">Abbrechen</button></div>
        </div>
    </dialog><?php endif; ?>
    <dialog id="admin-verify-user-dialog" class="account-dialog" aria-modal="true" aria-labelledby="admin-verify-user-heading">
        <div class="account-dialog-card">
            <button id="close-admin-verify-user-icon" type="button" class="dialog-close" aria-label="Verifizierungsdialog schließen">×</button>
            <h2 id="admin-verify-user-heading">Anwender verifizieren?</h2>
            <div id="admin-verify-user-confirmation"><p>Möchtest Du <strong data-admin-user-name></strong> als verifiziert kennzeichnen?</p><p>Die manuelle Verifizierung wird im Benutzerkonto gespeichert.</p></div>
            <div id="admin-verify-user-message" class="message" role="status" aria-live="polite"></div>
            <div class="registration-actions"><button id="confirm-admin-verify-user" type="button">Verifizieren</button><button id="cancel-admin-verify-user" type="button" class="secondary">Abbrechen</button></div>
        </div>
    </dialog>
    <?php if ($isAdmin): ?><dialog id="admin-user-role-dialog" class="account-dialog" aria-modal="true" aria-labelledby="admin-user-role-heading">
        <div class="account-dialog-card">
            <button id="close-admin-user-role-icon" type="button" class="dialog-close" aria-label="Rollendialog schließen">×</button>
            <h2 id="admin-user-role-heading">Rolle ändern?</h2>
            <div id="admin-user-role-confirmation"><p></p></div>
            <div id="admin-user-role-message" class="message" role="status" aria-live="polite"></div>
            <div class="registration-actions"><button id="confirm-admin-user-role" type="button">Rolle ändern</button><button id="cancel-admin-user-role" type="button" class="secondary">Abbrechen</button></div>
        </div>
    </dialog><?php endif; ?>
    <?php if ($isAdmin): ?><dialog id="admin-delete-user-dialog" class="account-dialog" aria-modal="true" aria-labelledby="admin-delete-user-heading">
        <div class="account-dialog-card">
            <button id="close-admin-delete-user-icon" type="button" class="dialog-close" aria-label="Anwenderlöschung schließen">×</button>
            <h2 id="admin-delete-user-heading">Anwender löschen</h2>
            <div id="admin-delete-user-confirmation"><p>Möchtest du das Benutzerkonto von <strong data-admin-user-name></strong> wirklich löschen?</p><p>Das Benutzerkonto und die zugehörigen aktuellen Vertretungsdaten werden dauerhaft gelöscht. Diese Aktion kann nicht rückgängig gemacht werden.</p></div>
            <div id="admin-delete-user-message" class="message" role="status" aria-live="polite"></div>
            <div class="registration-actions"><button id="confirm-admin-delete-user" type="button" class="danger">Anwender endgültig löschen</button><button id="cancel-admin-delete-user" type="button" class="secondary">Abbrechen</button></div>
        </div>
    </dialog><?php endif; ?>
    <details id="invitations-panel" class="panel misc-panel">
        <summary>Einladungen</summary>
        <div class="misc-content">
            <section><p class="section-kicker">Personen, die Du persönlich einlädst, bekommen den Status ‚verifiziert‘.</p><h2>Person einladen</h2>
                <form id="invitation-form" class="invitation-form">
                    <div class="auth-name-row"><label>Vorname *<input name="first_name" required maxlength="120"></label><label>Nachname *<input name="last_name" required maxlength="120"></label></div>
                    <label>E-Mail-Adresse *<input name="email" type="email" required maxlength="254"></label>
                    <?php $chapterPicker = ['legend'=>'Chapter *','required'=>true,'showAllByDefault'=>true,'inputId'=>'invitation-chapter-id','inputName'=>'home_chapter_org_id','countryId'=>'invitation-country','searchId'=>'invitation-search','locationId'=>'invitation-location','clearId'=>'clear-invitation-chapter','clearLabel'=>'Chapterauswahl zurücksetzen','selectedId'=>'invitation-selected-chapter','resultsId'=>'invitation-chapter-results','emptyLabel'=>'Kein Chapter ausgewählt.','ariaLabel'=>'Chapter auswählen']; require __DIR__ . '/partials/chapter-picker.php'; ?>
                    <button type="submit">Einladung senden</button>
                </form><div id="invitation-message" class="message" role="status"></div>
            </section>
            <section><h2>Offene Einladungen</h2><div class="table-scroll"><table><thead><tr><th>Name</th><th>E-Mail</th><th>Chapter</th><th>Gesendet</th><th>Gültig bis</th><th>Status</th><th>Aktion</th></tr></thead><tbody id="invitation-list"></tbody></table></div></section>
        </div>
    </details>
    <dialog id="invitation-verification-dialog" class="account-dialog" aria-modal="true" aria-labelledby="invitation-verification-heading">
        <div class="account-dialog-card">
            <h2 id="invitation-verification-heading">Chapter-Prüfung</h2>
            <p id="invitation-verification-detail"></p>
            <p><strong>Trotzdem einladen?</strong></p>
            <div class="registration-actions"><button id="confirm-invitation-override" type="button">Ja</button><button id="cancel-invitation-override" type="button" class="secondary">Nein</button></div>
        </div>
    </dialog>
    <dialog id="resend-invitation-dialog" class="account-dialog" aria-modal="true" aria-labelledby="resend-invitation-heading">
        <div class="account-dialog-card">
            <h2 id="resend-invitation-heading">Einladung erneut senden?</h2>
            <p id="resend-invitation-confirmation"></p>
            <div id="resend-invitation-message" class="message" role="status" aria-live="polite"></div>
            <div class="registration-actions"><button id="confirm-resend-invitation" type="button">Erneut senden</button><button id="cancel-resend-invitation" type="button" class="secondary">Abbrechen</button></div>
        </div>
    </dialog>
    <?php if ($isAdmin): ?><dialog id="cancel-invitation-dialog" aria-labelledby="cancel-invitation-title">
        <form method="dialog" class="auth-card">
            <h2 id="cancel-invitation-title">Einladung widerrufen</h2>
            <p>Möchtest du diese Einladung wirklich widerrufen?</p>
            <div id="cancel-invitation-message" class="message" role="status"></div>
            <div class="auth-actions"><button id="confirm-cancel-invitation" type="button">Widerrufen</button><button type="submit" class="secondary">Abbrechen</button></div>
        </form>
    </dialog><?php endif; ?>
    <?php if ($isAdmin): ?><details id="reports-panel" class="panel misc-panel"><summary>Meldungen</summary><div class="misc-content">
        <section id="user-error-monitor" aria-labelledby="user-error-heading">
            <p class="section-kicker">Anwendersichtbare Fehler</p><h2 id="user-error-heading">Letzte Meldungen</h2>
            <div id="user-error-table-wrap" class="table-scroll" hidden><table id="user-error-table"><thead><tr><th>Zeitpunkt</th><th>Meldung für Anwender</th><th>Echte Fehlermeldung</th><th>Code / Kontext</th></tr></thead><tbody id="user-error-list"></tbody></table></div>
            <p id="user-error-empty" class="page-meta" hidden>Keine Meldungen vorhanden.</p><div id="user-error-message" class="message" role="status" aria-live="polite"></div>
        </section>
        <dialog id="ambiguous-diagnostic-dialog" class="account-dialog" aria-modal="true" aria-labelledby="ambiguous-diagnostic-heading"><div class="account-dialog-card"><h2 id="ambiguous-diagnostic-heading">Chapter prüfen</h2><dl id="ambiguous-diagnostic-details" class="diagnostic-details"></dl><div id="ambiguous-diagnostic-links"></div><div class="registration-actions"><button id="close-ambiguous-diagnostic" type="button" class="secondary">Schließen</button></div></div></dialog>
        <section id="bni-performance-monitor" aria-labelledby="bni-performance-heading">
            <p class="section-kicker">Leistungsmonitor</p><div class="section-heading"><div><h2 id="bni-performance-heading">BNI-Anfragen – letzte 24 Stunden</h2><p>Gezählt werden ausschließlich tatsächlich gestartete externe BNI-Requests.</p></div><div class="performance-controls"><label>Zeitraum<select id="bni-performance-window"><option value="1">1 Minute</option><option value="5" selected>5 Minuten</option><option value="10">10 Minuten</option><option value="30">30 Minuten</option><option value="60">60 Minuten</option></select></label><label>Automatisch aktualisieren<select id="bni-performance-refresh"><option value="1">jede Minute</option><option value="2">alle 2 Minuten</option><option value="5" selected>alle 5 Minuten</option><option value="10">alle 10 Minuten</option><option value="60">alle 60 Minuten</option></select></label></div></div>
            <div id="bni-performance-stats" class="stats performance-stats" aria-label="BNI-Anfragestatistik"></div>
            <div class="performance-chart-wrap"><svg id="bni-performance-chart" viewBox="0 0 720 220" role="img" aria-labelledby="bni-performance-chart-title bni-performance-chart-description"><title id="bni-performance-chart-title">BNI-Anfragen der letzten 24 Stunden</title><desc id="bni-performance-chart-description">Rollierende Anzahl tatsächlich gestarteter BNI-Anfragen.</desc><line x1="42" y1="12" x2="42" y2="190" class="chart-axis"/><line x1="42" y1="190" x2="708" y2="190" class="chart-axis"/><polyline id="bni-performance-line" class="performance-line" points=""/><text id="bni-performance-y-max" x="36" y="18" text-anchor="end">0</text><text id="bni-performance-start" x="42" y="212">--:--</text><text id="bni-performance-end" x="708" y="212" text-anchor="end">--:--</text></svg></div>
            <section id="bni-throttle-monitor" class="throttle-monitor" aria-labelledby="bni-throttle-heading"><div class="section-heading"><div><p class="section-kicker">Technische Schutzfunktion</p><h3 id="bni-throttle-heading">Globale BNI-Anfragesteuerung</h3><p>Transparenz über reservierte Startzeiten und dadurch entstandene Wartezeiten.</p></div><span id="bni-throttle-status" class="status-badge status-loaded">Aktiv</span></div><div id="bni-throttle-current" class="stats performance-stats" aria-label="Aktueller Status der globalen BNI-Anfragesteuerung"></div><h4>Wartezeiten – letzte 24 Stunden</h4><div id="bni-throttle-stats" class="stats performance-stats" aria-label="Wartezeitstatistik der globalen BNI-Anfragesteuerung"></div><div class="performance-chart-wrap"><svg id="bni-throttle-chart" viewBox="0 0 720 220" role="img" aria-labelledby="bni-throttle-chart-title bni-throttle-chart-description"><title id="bni-throttle-chart-title">Wartezeit durch BNI-Anfragesteuerung – letzte 24 Stunden</title><desc id="bni-throttle-chart-description">Durchschnittliche und maximale Wartezeit in 5-Minuten-Buckets.</desc><line x1="42" y1="12" x2="42" y2="190" class="chart-axis"/><line x1="42" y1="190" x2="708" y2="190" class="chart-axis"/><polyline id="bni-throttle-average-line" class="performance-line" points=""/><polyline id="bni-throttle-maximum-line" class="performance-line throttle-maximum-line" points=""/><text id="bni-throttle-y-max" x="36" y="18" text-anchor="end">0 s</text><text id="bni-throttle-start" x="42" y="212">--:--</text><text id="bni-throttle-end" x="708" y="212" text-anchor="end">--:--</text></svg></div><p class="chart-legend"><span>Rot: Durchschnitt</span><span>Grau: Maximum</span></p></section>
            <div id="bni-performance-message" class="message" role="status" aria-live="polite"></div>
        </section>
    </div></details>
    <details id="representation-history-panel" class="panel misc-panel"><summary><span id="representation-history-title">Vertretungen</span></summary><div class="misc-content"><p class="page-meta">Historie der letzten maximal 500 Gesuche und konkreten Angebotstermine.</p><div class="representation-history-scroll"><table id="representation-history-table"><thead><tr><th data-sort-key="type"><button type="button" class="sort-button">Art <span class="sort-indicator" aria-hidden="true"></span></button></th><th data-sort-key="person"><button type="button" class="sort-button">Person <span class="sort-indicator" aria-hidden="true"></span></button></th><th data-sort-key="chapter"><button type="button" class="sort-button">Chapter <span class="sort-indicator" aria-hidden="true"></span></button></th><th data-sort-key="secondPerson"><button type="button" class="sort-button">Zweite Person <span class="sort-indicator" aria-hidden="true"></span></button></th><th data-sort-key="secondChapter"><button type="button" class="sort-button">Chapter zweite Person <span class="sort-indicator" aria-hidden="true"></span></button></th><th data-sort-key="date"><button type="button" class="sort-button">Datum <span class="sort-indicator" aria-hidden="true"></span></button></th><th data-sort-key="occurred"><button type="button" class="sort-button">Stattgefunden <span class="sort-indicator" aria-hidden="true"></span></button></th></tr></thead><tbody id="representation-history-list"></tbody></table></div><p id="representation-history-empty" class="page-meta" hidden>Keine Vertretungsvorgänge vorhanden.</p><div id="representation-history-message" class="message" role="status" aria-live="polite"></div></div></details>
    <details id="misc-panel" class="panel misc-panel">
        <summary>Sonstiges</summary>
        <div class="misc-content">
            <section><p class="section-kicker">Konfiguration</p><h2>E-Mail-Versand</h2>
                <form id="mail-settings-form" class="mail-settings-grid">
                    <label>SMTP-Server<input name="smtpHost"></label><label>SMTP-Port<input name="smtpPort" type="number" min="1" max="65535" value="587"></label>
                    <label>SMTP-Benutzername<input name="smtpUsername" autocomplete="off"></label><label>SMTP-Passwort<input name="smtpPassword" type="password" autocomplete="new-password" placeholder="••••••••"></label>
                    <label>Verschlüsselung<select name="encryption"><option value="starttls">STARTTLS</option><option value="tls">SSL/TLS</option><option value="none">keine</option></select></label>
                    <label>Absender-E-Mail<input name="senderEmail" type="email"></label><label>Absendername<input name="senderName" value="CrossChAPP"></label><label>Basis-URL für Links<input name="baseUrl" type="url"></label>
                    <button type="submit">E-Mail-Einstellungen speichern</button>
                </form><div id="mail-settings-message" class="message" role="status"></div>
                <div class="test-mail-row"><label>Test-E-Mail-Adresse<input id="test-mail-address" type="email"></label><button id="send-test-mail" type="button" class="secondary">Test-E-Mail senden</button></div><div id="test-mail-message" class="message" role="status"></div>
            </section>
            <section id="text-template-section"><p class="section-kicker">Inhalte</p><h2>Textbausteine</h2>
                <div class="text-template-layout">
                    <div class="text-template-selection">
                        <label class="text-template-search">Textbaustein suchen<input id="text-template-search" type="search"></label>
                        <div class="text-template-headings" aria-hidden="true"><span>Textbaustein</span><span>Verwendung</span></div>
                        <div id="text-template-list" class="text-template-list" role="listbox" aria-label="Textbaustein auswählen"></div>
                    </div>
                    <form id="email-templates-form" class="text-template-editor" aria-labelledby="text-template-editor-heading">
                        <p id="text-template-category" class="section-kicker"></p>
                        <h3 id="text-template-editor-heading">Textbaustein auswählen</h3>
                        <code id="text-template-editor-key"></code>
                        <label id="text-template-subject-label">Betreff<input id="text-template-subject" name="subject" maxlength="250"></label>
                        <label>Text<textarea id="text-template-body" name="body" rows="16" required maxlength="20000"></textarea></label>
                        <div><strong>Verfügbare Platzhalter</strong><div id="text-template-placeholders" class="text-template-placeholders"></div></div>
                        <p id="text-template-dirty" class="page-meta" hidden>Geändert</p>
                        <button type="submit">Speichern</button>
                        <div id="email-templates-message" class="message" role="status" aria-live="polite"></div>
                    </form>
                </div>
            </section>
            <dialog id="text-template-dirty-dialog" aria-labelledby="text-template-dirty-heading"><div class="account-dialog-card"><h2 id="text-template-dirty-heading">Ungespeicherte Änderungen</h2><p>Der aktuelle Textbaustein enthält ungespeicherte Änderungen.</p><div class="registration-actions"><button id="text-template-save-switch" type="button">Speichern und wechseln</button><button id="text-template-discard-switch" type="button" class="secondary">Verwerfen</button><button id="text-template-cancel-switch" type="button" class="secondary">Abbrechen</button></div></div></dialog>
            <section><p class="section-kicker">Inhalte</p><h2>Rechtliche Texte</h2>
                <form id="legal-settings-form" class="legal-settings-form">
                    <label>Impressum<textarea name="imprintText" rows="14" required></textarea></label>
                    <label>Datenschutzerklärung<textarea name="privacyText" rows="22" required></textarea></label>
                    <button type="submit">Rechtliche Texte speichern</button>
                </form><div id="legal-settings-message" class="message" role="status" aria-live="polite"></div>
            </section>
        </div>
    </details><?php endif; ?>
</main>
