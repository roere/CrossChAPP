<main class="shell content no-hero-content">
    <p class="page-intro-title">Vertretung anbieten</p>
<?php if ($currentUser === null): ?>
    <section class="panel representation-access-hint">
        <p>Um Vertretungsangebote zu machen musst du angemeldet sein.</p>
        <a class="button-link" href="/?view=login&amp;return_view=vertretung">Anmelden</a>
    </section>
<?php else: ?>
    <div class="representation-content">
    <section class="panel representation-filters" aria-labelledby="representation-filter-heading">
        <div><p class="section-kicker">Auswahl eingrenzen</p><h2 id="representation-filter-heading">Filter</h2></div>
        <div class="representation-filter-grid">
            <label>Land<select id="representation-country"><option value="">Alle</option><option value="DE">Deutschland</option><option value="AT">Österreich</option></select></label>
            <label>Suche<input id="representation-text" type="search" placeholder="Chaptername, Ort oder Region"></label>
            <label>Ort oder PLZ<input id="representation-location" type="text" placeholder="z. B. 51149 Köln"></label>
            <label>Umkreis <span class="radius-input"><input id="representation-radius" type="number" min="1" max="500" value="25"> km</span></label>
            <button id="apply-representation-radius" type="button" class="secondary">Umkreis anwenden</button>
        </div>
        <div id="representation-location-message" class="message" role="status" aria-live="polite"></div>
        <div class="representation-actions">
            <button id="select-visible-representations" type="button" class="secondary">Alle sichtbaren auswählen</button>
            <button id="clear-representations" type="button" class="secondary">Auswahl aufheben</button>
            <strong id="representation-counts">0 sichtbar · 0 Chapter ausgewählt</strong>
        </div>
    </section>

    <section class="panel representation-dates" aria-labelledby="representation-date-heading">
        <div><p class="section-kicker">Termine</p><h2 id="representation-date-heading">Termine auswählen</h2></div>
        <div class="date-add-row">
            <?php require __DIR__ . '/partials/date-picker.php'; ?>
            <label class="all-dates-option"><input id="representation-all-dates" type="checkbox"> Alle Daten</label>
        </div>
        <div id="representation-date-message" class="message" role="status" aria-live="polite"></div>
        <div id="representation-date-chips" class="date-chips" aria-label="Ausgewählte Termine"></div>
    </section>

    <section class="table-panel representation-list-panel" aria-labelledby="representation-list-heading">
        <div class="list-heading"><div><p class="section-kicker">Lokale Datenbank</p><h2 id="representation-list-heading">Chapterliste</h2></div></div>
        <div class="table-scroll"><table id="representation-table">
            <thead><tr><th class="select-column"><span class="sr-only">Auswahl</span></th><th data-sort-key="chapterName"><button type="button" class="sort-button">Chaptername <span class="sort-indicator" aria-hidden="true"></span></button></th><th data-sort-key="country"><button type="button" class="sort-button">Land <span class="sort-indicator" aria-hidden="true"></span></button></th><th data-sort-key="city"><button type="button" class="sort-button">Ort <span class="sort-indicator" aria-hidden="true"></span></button></th><th data-sort-key="meetingDay"><button type="button" class="sort-button">Wochentag <span class="sort-indicator" aria-hidden="true"></span></button></th><th data-sort-key="meetingTime"><button type="button" class="sort-button">Uhrzeit <span class="sort-indicator" aria-hidden="true"></span></button></th><th id="representation-distance-heading" data-sort-key="distance" hidden><button type="button" class="sort-button">Entfernung <span class="sort-indicator" aria-hidden="true"></span></button></th></tr></thead>
            <tbody id="representation-list"><tr><td colspan="7">Lokale Organisationen werden geladen …</td></tr></tbody>
        </table></div>
    </section>
    <section class="panel representation-save-panel">
        <button id="save-representation-offer" type="button">Vertretungsangebot speichern</button>
        <div id="representation-save-message" class="message" role="status" aria-live="polite"></div>
    </section>
    <section id="my-representation-offers" class="panel representation-own-panel" aria-labelledby="representation-own-heading" hidden>
        <h2 id="representation-own-heading">Meine Vertretungsangebote</h2>
        <div id="representation-own-list" class="representation-offer-grid"></div>
    </section>
    </div>
<?php endif; ?>
</main>

<?php if ($currentUser !== null): ?>
<dialog id="delete-representation-dialog" class="account-dialog" role="dialog" aria-modal="true" aria-labelledby="delete-representation-heading">
    <div class="account-dialog-card">
        <button id="close-delete-representation" type="button" class="dialog-close" aria-label="Löschdialog schließen">×</button>
        <h2 id="delete-representation-heading">Vertretungsangebot löschen</h2>
        <p>Möchtest du dieses Vertretungsangebot wirklich löschen?</p>
        <div id="delete-representation-summary" class="delete-representation-summary"></div>
        <div id="delete-representation-message" class="message" role="alert" aria-live="polite"></div>
        <div class="registration-actions">
            <button id="confirm-delete-representation" type="button">Löschen</button>
            <button id="cancel-delete-representation" type="button" class="secondary">Abbrechen</button>
        </div>
    </div>
</dialog>
<?php endif; ?>
