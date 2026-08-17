<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BNI DACH Chapter Finder</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='3' fill='%23cf2030'/%3E%3Cpath d='M8 9h16v4H8zm0 7h16v7H8z' fill='white'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
</head>
<body>
    <header class="site-header">
        <div class="topbar">
            <div class="shell topbar-inner">
                <span>Lokale Anwendung</span>
                <strong>BNI DACH Finder</strong>
            </div>
        </div>
        <div class="main-header">
            <div class="shell header-inner">
                <a class="wordmark" href="/" aria-label="BNI DACH Finder Startseite">
                    <span class="wordmark-main">BNI DACH</span>
                    <span class="wordmark-sub">FINDER</span>
                    <span class="wordmark-tagline">Chapter &amp; Mitglieder finden</span>
                </a>
                <nav aria-label="Hauptnavigation">
                    <a class="active" href="#chapter-list">Chapter</a>
                    <span class="nav-disabled" aria-disabled="true">Personen <small>später</small></span>
                    <a href="#data-import">Datenimport</a>
                </nav>
            </div>
        </div>
    </header>

    <section class="page-hero">
        <div class="shell">
            <p class="eyebrow">Chapter-Verzeichnis</p>
            <h1>BNI DACH Chapter Finder</h1>
            <p>Chapter in Deutschland und Österreich suchen, auswählen und lokal verwalten.</p>
        </div>
    </section>

    <main class="shell content">
        <section id="data-import" class="panel source-panel" aria-labelledby="source-heading">
            <div>
                <h2 id="source-heading">BNI-Daten einlesen</h2>
                <p>Ein Sammelabruf aktualisiert die lokale Chapterübersicht. Details werden nur nach Auswahl geladen.</p>
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
            <div id="stats" class="stats" aria-label="Chapter-Statistik"></div>

            <section class="panel controls" aria-labelledby="filter-heading">
                <div class="section-heading">
                    <div>
                        <p class="section-kicker">Chapter finden</p>
                        <h2 id="filter-heading">Filter &amp; Aktionen</h2>
                    </div>
                    <span id="selection-count">0 ausgewählt</span>
                </div>
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
                    <label>Detailstatus
                        <select id="detail-filter">
                            <option value="">Alle</option>
                            <option value="loaded">geladen</option>
                            <option value="not_loaded">nicht geladen</option>
                            <option value="error">Fehler</option>
                        </select>
                    </label>
                    <label>Suche
                        <input id="text-filter" type="search" placeholder="orgId oder Chaptername">
                    </label>
                </div>
                <label class="reload-option">
                    <input id="reload-details" type="checkbox">
                    Bereits geladene Details erneut abrufen
                </label>
                <div class="actions">
                    <button id="select-visible" type="button" class="secondary">Alle sichtbaren auswählen</button>
                    <button id="clear-selection" type="button" class="secondary">Auswahl aufheben</button>
                    <button id="load-details" type="button">Details für ausgewählte laden</button>
                </div>
                <div id="progress" class="progress" role="status" aria-live="polite"></div>
            </section>

            <section id="chapter-list" class="table-panel" aria-labelledby="chapter-list-heading">
                <div class="list-heading">
                    <div>
                        <p class="section-kicker">Ergebnisse</p>
                        <h2 id="chapter-list-heading">Chapterliste</h2>
                    </div>
                    <p id="visible-count" class="visible-count"></p>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th class="select-column"><span class="sr-only">Auswahl</span></th>
                                <th class="toggle-column"><span class="sr-only">Details</span></th>
                                <th>Chaptername</th>
                                <th class="column-orgid">orgId</th>
                                <th class="column-country">Land</th>
                                <th class="column-type">Typ</th>
                                <th>Ort</th>
                                <th class="column-day">Wochentag</th>
                                <th class="column-time">Uhrzeit</th>
                                <th>Detailstatus</th>
                                <th class="column-updated">Zuletzt aktualisiert</th>
                            </tr>
                        </thead>
                        <tbody id="organization-list"></tbody>
                    </table>
                </div>
            </section>
        </section>
    </main>
</body>
</html>
