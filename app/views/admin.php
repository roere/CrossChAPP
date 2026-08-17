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
</main>
