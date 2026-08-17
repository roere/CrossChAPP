<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BNI DACH Finder</title>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
</head>
<body>
    <header class="hero">
        <div>
            <p class="eyebrow">Lokaler Proof of Concept</p>
            <h1>BNI DACH Finder</h1>
            <p class="intro">Chapter und Gruppen gezielt erfassen, ohne unnötige Detailanfragen.</p>
        </div>
    </header>

    <main>
        <section class="panel source-panel" aria-labelledby="source-heading">
            <div>
                <h2 id="source-heading">Datenquelle auslesen</h2>
                <p>Der Abruf lädt einmalig die öffentliche Kartenübersicht. Details werden nur nach Auswahl geladen.</p>
            </div>
            <form id="source-form" class="source-form">
                <label for="source-url">BNI-DACH-Link</label>
                <div class="input-row">
                    <input id="source-url" name="url" type="url" value="https://bni.de/de/findachapter" required>
                    <button id="read-button" type="submit">Auslesen</button>
                </div>
            </form>
            <div id="message" class="message" role="status" aria-live="polite"></div>
        </section>

        <section id="results" class="results" hidden>
            <div id="stats" class="stats" aria-label="Statistik"></div>

            <section class="panel controls" aria-labelledby="filter-heading">
                <h2 id="filter-heading">Filtern und auswählen</h2>
                <div class="filter-grid">
                    <label>Land
                        <select id="country-filter">
                            <option value="">Alle</option>
                            <option value="DE">Deutschland</option>
                            <option value="AT">Österreich</option>
                        </select>
                    </label>
                    <label>Typ
                        <select id="type-filter">
                            <option value="">Alle</option>
                            <option value="CHAPTER">Chapter</option>
                            <option value="CORE_GROUP">Im Aufbau</option>
                            <option value="PLANNED_GROUP">Geplant</option>
                        </select>
                    </label>
                    <label>Freitext
                        <input id="text-filter" type="search" placeholder="orgId oder Chaptername">
                    </label>
                </div>
                <div class="actions">
                    <button id="select-visible" type="button" class="secondary">Alle sichtbaren auswählen</button>
                    <button id="clear-selection" type="button" class="secondary">Auswahl aufheben</button>
                    <button id="load-details" type="button">Details für ausgewählte laden</button>
                    <span id="selection-count">0 ausgewählt</span>
                </div>
                <div id="progress" class="progress" role="status" aria-live="polite"></div>
            </section>

            <section class="table-panel" aria-label="BNI-Organisationen">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th><span class="sr-only">Auswahl</span></th>
                                <th>orgId</th><th>Land</th><th>Typ</th><th>Longitude</th><th>Latitude</th><th>Chaptername</th><th>Detailstatus</th>
                            </tr>
                        </thead>
                        <tbody id="organization-list"></tbody>
                    </table>
                </div>
                <p id="visible-count" class="visible-count"></p>
            </section>
        </section>
    </main>
</body>
</html>
