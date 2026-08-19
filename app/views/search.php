<main class="shell public-content no-hero-content">
    <p class="page-intro-title">Finde passende BNI-Chaptertreffen in deiner Nähe.</p>
    <section class="search-panel" aria-labelledby="search-heading">
        <div class="search-panel-heading">
            <p class="section-kicker">Deine Suche</p>
            <h2 id="search-heading">Wo möchtest du netzwerken?</h2>
        </div>
        <form id="chapter-search-form">
            <label class="location-field" for="search-location">Ort / PLZ
                <input id="search-location" name="location" placeholder="PLZ oder Ort" autocomplete="postal-code" required>
                <span class="field-examples">Zum Beispiel: 51149, Köln oder 51149 Köln</span>
            </label>

            <fieldset class="choice-group day-group">
                <legend>Wochentage <span>Mehrfachauswahl möglich</span></legend>
                <div class="day-options">
                    <?php foreach (['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'] as $day): ?>
                        <label><input type="checkbox" name="days" value="<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>"><span><?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?></span></label>
                    <?php endforeach; ?>
                </div>
                <p>Ohne Auswahl werden alle Wochentage berücksichtigt.</p>
            </fieldset>

            <div class="search-options-row">
                <fieldset class="choice-group time-group">
                    <legend>Uhrzeit</legend>
                    <div class="segmented-options">
                        <label><input type="radio" name="time" value="any" checked><span>egal</span></label>
                        <label><input type="radio" name="time" value="early"><span>früh</span></label>
                        <label><input type="radio" name="time" value="late"><span>spät</span></label>
                    </div>
                    <p>Früh: vor 09:00 Uhr · Spät: ab 09:00 Uhr</p>
                </fieldset>
                <div class="search-selects">
                    <fieldset class="choice-group result-limit-group">
                        <legend>Anzahl Ergebnisse</legend>
                        <input id="search-limit" name="limit" type="hidden" value="10">
                        <div class="result-limit-options" role="group" aria-label="Anzahl der Suchergebnisse">
                            <?php foreach (['5' => '5', '10' => '10', '20' => '20', '50' => '50', 'all' => 'Alle'] as $value => $label): ?>
                                <button type="button" data-limit="<?= $value ?>" aria-pressed="<?= $value === 10 ? 'true' : 'false' ?>"><?= $label ?></button>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <label class="sort-field">Sortierung
                        <select id="search-sort" name="sort">
                            <option value="distance">Entfernung</option><option value="time">Uhrzeit</option><option value="members">Mitgliederzahl</option>
                        </select>
                    </label>
                </div>
            </div>

            <label class="representation-request-filter"><input type="checkbox" name="has_representation_requests"> <span>Nur Chapter mit Vertretungsgesuchen anzeigen</span></label>
            <button id="search-button" type="submit" class="search-submit">Treffen finden</button>
        </form>
        <div id="search-message" class="message" role="status" aria-live="polite"></div>
    </section>

    <section id="search-results" class="search-results" aria-labelledby="results-heading" hidden>
        <div class="results-summary">
            <div><p class="section-kicker">Suchergebnis</p><h2 id="results-heading"></h2><p id="search-around"></p></div>
            <button id="map-toggle" type="button" class="secondary" aria-expanded="false" aria-controls="results-map">Karte anzeigen</button>
        </div>
        <div id="results-map-panel" class="results-map-panel" hidden>
            <div id="results-map" role="region" aria-label="Karte mit Suchstandort und gefundenen Chaptern"></div>
        </div>
        <div id="result-list" class="result-list"></div>
    </section>
</main>
<dialog id="request-contact-dialog" class="account-dialog" aria-labelledby="request-contact-heading"><div class="account-dialog-card">
    <button id="close-request-contact" type="button" class="dialog-close" aria-label="Rückmeldung schließen">×</button><div id="request-contact-content"><h2 id="request-contact-heading">Vertretungsgesuch annehmen</h2><p id="request-contact-hint"></p>
    <form id="request-contact-form"><div id="anonymous-request-contact-fields" class="request-contact-identity" hidden><label>Vorname *<input id="request-contact-first-name" maxlength="100" autocomplete="given-name" required></label><span id="request-contact-first-name-error" class="field-error"></span><label>Nachname *<input id="request-contact-last-name" maxlength="100" autocomplete="family-name" required></label><span id="request-contact-last-name-error" class="field-error"></span><label>E-Mail-Adresse *<input id="request-contact-email" type="email" maxlength="254" autocomplete="email" required></label><span id="request-contact-email-error" class="field-error"></span></div><p id="request-contact-subject" class="mail-preview-subject"></p><div id="request-contact-before" class="mail-preview-fixed"></div><label>Deine Nachricht<textarea id="request-contact-message" rows="8" minlength="20" maxlength="3000" required></textarea></label><div id="request-contact-after" class="mail-preview-fixed"></div><div id="request-contact-error" class="message" role="alert"></div><div class="registration-actions"><button type="submit">Rückmeldung senden</button><button id="cancel-request-contact" type="button" class="secondary">Abbrechen</button></div></form></div>
</div></dialog>
