<?php $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d'); ?>
<section class="page-hero representation-hero"><div class="shell">
    <p class="eyebrow">Vertretung anbieten</p><h1>Vertretung für folgende Chapter anbieten</h1>
    <p>Wähle Termine und passende Chapter aus deinem lokalen CrossChAPP-Datenbestand.</p>
</div></section>

<main class="shell content representation-content">
    <section class="panel representation-dates" aria-labelledby="representation-date-heading">
        <div><p class="section-kicker">Termine</p><h2 id="representation-date-heading">Termine auswählen</h2></div>
        <div class="date-add-row">
            <label>Datum auswählen<input id="representation-date" type="date" min="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>"></label>
            <button id="add-representation-date" type="button">Hinzufügen</button>
            <label class="all-dates-option"><input id="representation-all-dates" type="checkbox"> Alle Daten</label>
        </div>
        <div id="representation-date-message" class="message" role="status" aria-live="polite"></div>
        <div id="representation-date-chips" class="date-chips" aria-label="Ausgewählte Termine"></div>
    </section>

    <section class="panel representation-filters" aria-labelledby="representation-filter-heading">
        <div><p class="section-kicker">Auswahl eingrenzen</p><h2 id="representation-filter-heading">Filter</h2></div>
        <div class="representation-filter-grid">
            <label>Land<select id="representation-country"><option value="">Alle</option><option value="DE">Deutschland</option><option value="AT">Österreich</option></select></label>
            <label>Typ<select id="representation-type"><option value="">Alle</option><option value="CHAPTER">Chapter</option><option value="CORE_GROUP">Im Aufbau</option><option value="PLANNED_GROUP">Geplant</option></select></label>
            <label>Suche<input id="representation-text" type="search" placeholder="Chaptername, orgId, Ort oder Region"></label>
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

    <section class="table-panel representation-list-panel" aria-labelledby="representation-list-heading">
        <div class="list-heading"><div><p class="section-kicker">Lokale Datenbank</p><h2 id="representation-list-heading">Chapterliste</h2></div></div>
        <div class="table-scroll"><table>
            <thead><tr><th class="select-column"><span class="sr-only">Auswahl</span></th><th>Chaptername</th><th>orgId</th><th>Land</th><th>Typ</th><th>Ort</th><th>Wochentag</th><th>Uhrzeit</th><th id="representation-distance-heading" hidden>Entfernung</th></tr></thead>
            <tbody id="representation-list"><tr><td colspan="9">Lokale Organisationen werden geladen …</td></tr></tbody>
        </table></div>
    </section>
</main>
