<section class="search-hero">
    <div class="shell search-hero-inner">
        <div>
            <p class="eyebrow">Chaptertreffen entdecken</p>
            <h1>CrossChAPP</h1>
            <p>Finde passende BNI-Chaptertreffen in deiner Nähe.</p>
        </div>
        <div class="search-accent" aria-hidden="true">C</div>
    </div>
</section>

<main class="shell public-content">
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

            <button id="search-button" type="submit" class="search-submit">Treffen finden</button>
        </form>
        <p id="data-basis" class="data-basis">Datengrundlage wird ermittelt …</p>
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
