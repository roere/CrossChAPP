(() => {
    'use strict';

    document.querySelector('#login-form')?.addEventListener('submit', login);
    document.querySelector('#logout-button')?.addEventListener('click', logout);
    document.querySelector('#chapter-search-form')?.addEventListener('submit', searchChapters);

    const searchState = {
        payload: null, expanded: new Set(), map: null, markerLayer: null,
        refreshQueue: [], refreshQueued: new Set(), refreshCompleted: new Set(), refreshRunning: false, refreshGeneration: 0,
    };
    document.querySelector('#result-list')?.addEventListener('click', toggleResultDetails);
    document.querySelector('#map-toggle')?.addEventListener('click', toggleMap);
    document.querySelector('.result-limit-options')?.addEventListener('click', selectResultLimit);

    if (document.querySelector('#data-basis')) loadDataBasis();

    function selectResultLimit(event) {
        const button = event.target.closest('button[data-limit]');
        if (!button) return;
        const group = event.currentTarget;
        group.querySelectorAll('button[data-limit]').forEach(option => option.setAttribute('aria-pressed', String(option === button)));
        document.querySelector('#search-limit').value = button.dataset.limit;
    }

    async function login(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const message = document.querySelector('#login-message');
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true; message.textContent = '';
        try {
            const response = await fetch('/api/auth/login.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: form.username.value, password: form.password.value }),
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Anmeldung fehlgeschlagen.');
            window.location.assign('/?view=admin');
        } catch (error) {
            message.textContent = error.message; message.className = 'message error';
            form.password.value = ''; form.password.focus();
        } finally {
            button.disabled = false;
        }
    }

    async function logout() {
        const button = document.querySelector('#logout-button');
        button.disabled = true;
        try {
            await fetch('/api/auth/logout.php', { method: 'POST' });
        } finally {
            window.location.assign('/');
        }
    }

    async function loadDataBasis() {
        const element = document.querySelector('#data-basis');
        try {
            const response = await fetch('/api/search-basis.php');
            const payload = await response.json();
            if (!response.ok) throw new Error();
            element.textContent = `Datengrundlage: ${payload.data_basis} Chapter mit lokal gespeicherten Treffendaten.`;
        } catch {
            element.textContent = 'Die lokale Datengrundlage konnte nicht ermittelt werden.';
        }
    }

    async function searchChapters(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const message = document.querySelector('#search-message');
        const button = document.querySelector('#search-button');
        const days = [...form.querySelectorAll('input[name="days"]:checked')].map(input => input.value);
        const payload = {
            location: form.location.value.trim(), days,
            time: form.querySelector('input[name="time"]:checked').value,
            sort: form.sort.value,
            limit: form.limit.value,
        };
        message.textContent = 'Ort wird gesucht und Entfernung berechnet …'; message.className = 'message'; button.disabled = true;
        try {
            const response = await fetch('/api/search.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Die Suche konnte nicht ausgeführt werden.');
            renderResults(result);
            queueSearchRefreshes(result);
            document.querySelector('#data-basis').textContent = `Datengrundlage: ${result.data_basis} Chapter mit lokal gespeicherten Treffendaten.`;
            message.textContent = '';
        } catch (error) {
            document.querySelector('#search-results').hidden = true;
            message.textContent = error.message; message.className = 'message error';
        } finally {
            button.disabled = false;
        }
    }

    function renderResults(payload) {
        const section = document.querySelector('#search-results');
        const heading = document.querySelector('#results-heading');
        const around = document.querySelector('#search-around');
        const list = document.querySelector('#result-list');
        searchState.payload = payload;
        searchState.expanded.clear();
        searchState.refreshGeneration += 1;
        searchState.refreshQueue = [];
        searchState.refreshQueued.clear();
        searchState.refreshCompleted.clear();
        section.hidden = false;
        heading.textContent = payload.result_count === 0
            ? 'Für diese Auswahl wurden keine passenden Chapter gefunden.'
            : payload.result_count < payload.total_matching
                ? `${payload.total_matching} passende Chapter gefunden. ${payload.result_count} werden angezeigt.`
                : `${payload.total_matching} passende Chapter gefunden.`;
        around.textContent = `Suche rund um ${payload.search_location.display_name}`;
        const fragment = document.createDocumentFragment();
        payload.results.forEach(chapter => fragment.append(resultCard(chapter)));
        list.replaceChildren(fragment);
        if (!document.querySelector('#results-map-panel').hidden) renderMap();
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function resultCard(chapter) {
        const article = document.createElement('article'); article.className = 'result-card'; article.id = `result-${chapter.orgId}`;
        const header = document.createElement('div'); header.className = 'result-card-header';
        const title = document.createElement('h3'); title.textContent = chapter.chapterName || '—';
        const distance = document.createElement('strong'); distance.className = 'distance'; distance.textContent = `${formatNumber(chapter.distanceKm)} km`;
        header.append(title, distance);

        const facts = document.createElement('dl'); facts.className = 'result-facts';
        [
            ['Ort', chapter.city], ['Wochentag', chapter.meetingDay],
            ['Uhrzeit', chapter.meetingTime ? `${chapter.meetingTime} Uhr` : null], ['Meetingtyp', chapter.meetingType],
            ['Mitglieder', chapter.memberCount],
        ].forEach(([label, value]) => appendFact(facts, label, value));

        const actions = document.createElement('div'); actions.className = 'result-actions';
        appendExternalAction(actions, chapter.visitorRegistrationUrl, 'Als Besucher anmelden', 'primary');
        const toggle = document.createElement('button'); toggle.type = 'button'; toggle.className = 'result-detail-toggle secondary';
        toggle.dataset.id = chapter.orgId; toggle.setAttribute('aria-expanded', 'false'); toggle.setAttribute('aria-controls', `result-details-${chapter.orgId}`);
        toggle.setAttribute('aria-label', `Details für ${chapter.chapterName || `Organisation ${chapter.orgId}`} öffnen`); toggle.textContent = '▶ Details';
        actions.prepend(toggle);
        const details = resultDetailPanel(chapter); details.hidden = true;
        const refreshStatus = document.createElement('p'); refreshStatus.className = 'result-refresh-status'; refreshStatus.setAttribute('aria-live', 'polite');
        article.append(header, facts, actions, refreshStatus, details);
        return article;
    }

    function resultDetailPanel(chapter) {
        const panel = document.createElement('div'); panel.id = `result-details-${chapter.orgId}`; panel.className = 'result-detail-panel';
        const grid = document.createElement('div'); grid.className = 'detail-groups';
        grid.append(
            detailGroup('Chapter', [
                ['Chaptername', chapter.chapterName], ['orgId', chapter.orgId], ['Typ', typeLabel(chapter.orgType)],
                ['Region', chapter.region], ['Regions-ID', chapter.regionId], ['Land', countryLabel(chapter.countryCode)],
            ]),
            detailGroup('Treffen', [
                ['Wochentag', chapter.meetingDay], ['Uhrzeit', chapter.meetingTime], ['Meetingtyp', chapter.meetingType],
                ['Meetingdauer', chapter.meetingDuration, value => `${value} Minuten`], ['Treffpunkt', chapter.venue],
            ]),
            detailGroup('Adresse', [['Straße', chapter.street], ['PLZ', chapter.postalCode], ['Ort', chapter.city]]),
            detailGroup('Netzwerk', [
                ['Mitgliederzahl', chapter.memberCount], ['Chapter-Webseite', chapter.chapterUrl, externalLink],
                ['Besucheranmeldung', chapter.visitorRegistrationUrl, externalLink], ['Online-Meeting-Link', chapter.onlineMeetingUrl, externalLink],
            ]),
            detailGroup('System', [
                ['Detailstatus', detailStatusLabel(chapter.detailStatus)], ['Zuletzt aktualisiert', formatTimestamp(chapter.detailsLoadedAt)],
                ['Zeitzone', chapter.timezone], ['Chapterstatus', chapter.status],
            ]),
            detailGroup('Entfernung', [['Entfernung zum Suchstandort', `${formatNumber(chapter.distanceKm)} km`]]),
        );
        panel.append(grid, detailGroup('Beschreibung', [['ChapterText', chapter.description]], 'description-group'));
        return panel;
    }

    function detailGroup(title, fields, extraClass = '') {
        const section = document.createElement('section'); section.className = `detail-group ${extraClass}`.trim();
        const heading = document.createElement('h3'); heading.textContent = title;
        const list = document.createElement('dl');
        fields.forEach(([label, rawValue, formatter]) => {
            const wrapper = document.createElement('div'); const term = document.createElement('dt'); const value = document.createElement('dd');
            term.textContent = label;
            if (rawValue === null || rawValue === undefined || rawValue === '') value.textContent = '—';
            else if (formatter) {
                const formatted = formatter(rawValue);
                value.append(formatted instanceof Node ? formatted : document.createTextNode(formatted));
            } else value.textContent = String(rawValue);
            wrapper.append(term, value); list.append(wrapper);
        });
        section.append(heading, list); return section;
    }

    function toggleResultDetails(event) {
        const button = event.target.closest('.result-detail-toggle');
        if (!button) return;
        const id = Number(button.dataset.id); const panel = document.querySelector(`#result-details-${id}`);
        const expanded = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', String(expanded)); button.textContent = `${expanded ? '▼' : '▶'} Details`;
        button.setAttribute('aria-label', `Details für Organisation ${id} ${expanded ? 'schließen' : 'öffnen'}`);
        panel.hidden = !expanded;
        expanded ? searchState.expanded.add(id) : searchState.expanded.delete(id);
        if (expanded) {
            const chapter = searchState.payload?.results.find(item => item.orgId === id);
            if (chapter) enqueueUsageRefresh(chapter, 'usage_detail');
        }
    }

    function queueSearchRefreshes(payload) {
        if (!payload.refresh_policy?.usage_enabled) return;
        payload.results.forEach(chapter => enqueueUsageRefresh(chapter, 'usage_search'));
    }

    function enqueueUsageRefresh(chapter, triggerType) {
        const policy = searchState.payload?.refresh_policy;
        if (!policy?.usage_enabled || !isStale(chapter.detailsLoadedAt, policy.usage_days)) return;
        if (searchState.refreshQueued.has(chapter.orgId) || searchState.refreshCompleted.has(chapter.orgId)) return;
        searchState.refreshQueued.add(chapter.orgId);
        searchState.refreshQueue.push({ orgId: chapter.orgId, triggerType });
        setChapterRefreshStatus(chapter.orgId, 'Daten werden aktualisiert …');
        runUsageRefreshQueue(searchState.refreshGeneration);
    }

    async function runUsageRefreshQueue(generation) {
        if (searchState.refreshRunning) return;
        searchState.refreshRunning = true;
        try {
            while (searchState.refreshQueue.length && generation === searchState.refreshGeneration) {
                const queued = searchState.refreshQueue.shift();
                let stopQueue = false;
                try {
                    const response = await fetch('/api/refresh/usage.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ org_id: queued.orgId, trigger_type: queued.triggerType }),
                    });
                    const payload = await response.json(); const result = payload.result;
                    if (response.ok && result?.status === 'success') {
                        const chapter = searchState.payload?.results.find(item => item.orgId === queued.orgId);
                        if (chapter && result.details) Object.assign(chapter, result.details);
                        setChapterRefreshStatus(queued.orgId, 'gerade aktualisiert');
                    } else if (result?.status === 'rate_limited' || result?.status === 'forbidden') {
                        stopQueue = true;
                        setChapterRefreshStatus(queued.orgId, 'Aktualisierung derzeit nicht möglich.');
                    } else if (result?.status === 'skipped' && result?.reason === 'daily_limit') {
                        stopQueue = true;
                        setChapterRefreshStatus(queued.orgId, 'Lokale Daten werden angezeigt.');
                    } else if (result?.status === 'skipped') {
                        setChapterRefreshStatus(queued.orgId, '');
                    } else {
                        setChapterRefreshStatus(queued.orgId, 'Lokale Daten werden weiterhin angezeigt.');
                    }
                } catch {
                    setChapterRefreshStatus(queued.orgId, 'Lokale Daten werden weiterhin angezeigt.');
                }
                searchState.refreshQueued.delete(queued.orgId);
                searchState.refreshCompleted.add(queued.orgId);
                if (stopQueue) {
                    searchState.refreshQueue.forEach(pending => setChapterRefreshStatus(pending.orgId, 'Aktualisierung zurückgestellt.'));
                    searchState.refreshQueue = [];
                    searchState.refreshQueued.clear();
                    break;
                }
                if (searchState.refreshQueue.length && generation === searchState.refreshGeneration) {
                    await new Promise(resolve => window.setTimeout(resolve, searchState.payload.refresh_policy.detail_delay_ms));
                }
            }
        } finally {
            searchState.refreshRunning = false;
            if (searchState.refreshQueue.length) runUsageRefreshQueue(searchState.refreshGeneration);
        }
    }

    function isStale(timestamp, days) {
        const loadedAt = Date.parse(timestamp || '');
        return !Number.isFinite(loadedAt) || loadedAt < Date.now() - (Number(days) * 86_400_000);
    }

    function setChapterRefreshStatus(orgId, text) {
        const element = document.querySelector(`#result-${orgId} .result-refresh-status`);
        if (element) element.textContent = text;
    }

    function toggleMap() {
        const button = document.querySelector('#map-toggle'); const panel = document.querySelector('#results-map-panel');
        const expanded = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', String(expanded)); button.textContent = expanded ? 'Karte ausblenden' : 'Karte anzeigen'; panel.hidden = !expanded;
        if (expanded) renderMap();
    }

    function renderMap() {
        if (!searchState.payload || !window.L) return;
        if (!searchState.map) {
            searchState.map = L.map('results-map');
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            }).addTo(searchState.map);
            searchState.markerLayer = L.layerGroup().addTo(searchState.map);
        }
        searchState.markerLayer.clearLayers();
        const points = []; const location = searchState.payload.search_location;
        const locationPoint = [location.latitude, location.longitude]; points.push(locationPoint);
        L.circleMarker(locationPoint, { radius: 9, color: '#202124', weight: 3, fillColor: '#ffffff', fillOpacity: 1 })
            .bindPopup(popupContent('Suchstandort', location.display_name)).addTo(searchState.markerLayer);
        searchState.payload.results.forEach(chapter => {
            if (!Number.isFinite(chapter.latitude) || !Number.isFinite(chapter.longitude)) return;
            const point = [chapter.latitude, chapter.longitude]; points.push(point);
            L.marker(point).bindPopup(chapterPopup(chapter)).addTo(searchState.markerLayer);
        });
        window.setTimeout(() => {
            searchState.map.invalidateSize();
            points.length === 1 ? searchState.map.setView(points[0], 11) : searchState.map.fitBounds(points, { padding: [35, 35], maxZoom: 13 });
        }, 0);
    }

    function chapterPopup(chapter) {
        const wrapper = document.createElement('div'); wrapper.className = 'chapter-popup';
        const heading = document.createElement('strong'); heading.textContent = chapter.chapterName || '—';
        const facts = document.createElement('p'); facts.textContent = `${formatNumber(chapter.distanceKm)} km · ${chapter.city || '—'} · ${chapter.meetingDay || '—'} · ${chapter.meetingTime || '—'}`;
        const button = document.createElement('button'); button.type = 'button'; button.textContent = 'Zum Ergebnis';
        button.addEventListener('click', () => document.querySelector(`#result-${chapter.orgId}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
        wrapper.append(heading, facts, button); return wrapper;
    }

    function popupContent(title, text) {
        const wrapper = document.createElement('div'); const strong = document.createElement('strong'); const paragraph = document.createElement('p');
        strong.textContent = title; paragraph.textContent = text || '—'; wrapper.append(strong, paragraph); return wrapper;
    }

    function appendFact(list, label, value) {
        const wrapper = document.createElement('div'); const term = document.createElement('dt'); const detail = document.createElement('dd');
        term.textContent = label; detail.textContent = value === null || value === undefined || value === '' ? '—' : String(value);
        wrapper.append(term, detail); list.append(wrapper);
    }

    function appendExternalAction(container, url, label, style) {
        if (!url) return;
        try {
            const parsed = new URL(url);
            if (!['http:', 'https:'].includes(parsed.protocol)) return;
            const anchor = document.createElement('a'); anchor.href = parsed.href; anchor.target = '_blank'; anchor.rel = 'noopener noreferrer';
            anchor.className = `result-link ${style}`; anchor.textContent = label; container.append(anchor);
        } catch { /* Ungültige lokale Daten nicht als Link ausgeben. */ }
    }

    function externalLink(url) {
        try {
            const parsed = new URL(url);
            if (!['http:', 'https:'].includes(parsed.protocol)) throw new Error('unsupported');
            const anchor = document.createElement('a'); anchor.href = parsed.href; anchor.target = '_blank'; anchor.rel = 'noopener noreferrer'; anchor.textContent = 'Öffnen ↗';
            return anchor;
        } catch { return document.createTextNode(String(url)); }
    }

    function typeLabel(value) { return ({ CHAPTER: 'Chapter', CORE_GROUP: 'Im Aufbau', PLANNED_GROUP: 'Geplant' })[value] || value || '—'; }
    function countryLabel(value) { return ({ DE: 'Deutschland', AT: 'Österreich', CH: 'Schweiz' })[value] || value || '—'; }
    function detailStatusLabel(value) { return value === 'loaded' ? 'geladen' : value === 'error' ? 'Fehler' : 'nicht geladen'; }
    function formatTimestamp(value) { return value ? new Intl.DateTimeFormat('de-DE', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '—'; }

    function formatNumber(value) {
        return new Intl.NumberFormat('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value);
    }
})();
