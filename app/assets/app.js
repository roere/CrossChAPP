(() => {
    'use strict';

    if (!document.querySelector('#source-form')) return;

    const BNI_DETAIL_DELAY_MS = Number(document.querySelector('.admin-hero')?.dataset.detailDelayMs);

    const state = {
        organizations: [], selected: new Set(), expanded: new Set(), details: new Map(), statuses: new Map(),
        batchRunning: false, batchStopRequested: false,
    };
    const typeLabels = { CHAPTER: 'Chapter', CORE_GROUP: 'Im Aufbau', PLANNED_GROUP: 'Geplant' };
    const countryLabels = { DE: 'Deutschland', AT: 'Österreich' };
    const elements = {
        form: document.querySelector('#source-form'), url: document.querySelector('#source-url'), read: document.querySelector('#read-button'),
        message: document.querySelector('#message'), results: document.querySelector('#results'), stats: document.querySelector('#stats'),
        localMessage: document.querySelector('#local-message'), emptyDatabase: document.querySelector('#empty-database'),
        country: document.querySelector('#country-filter'), type: document.querySelector('#type-filter'), detail: document.querySelector('#detail-filter'), text: document.querySelector('#text-filter'),
        list: document.querySelector('#organization-list'), visibleCount: document.querySelector('#visible-count'),
        selectVisible: document.querySelector('#select-visible'), clearSelection: document.querySelector('#clear-selection'),
        loadDetails: document.querySelector('#load-details'), reloadDetails: document.querySelector('#reload-details'), selectionCount: document.querySelector('#selection-count'), progress: document.querySelector('#progress'),
        batchStats: document.querySelector('#batch-stats'), batchSize: document.querySelector('#batch-size'), batchSizeOptions: document.querySelector('#batch-size-options'),
        startBatch: document.querySelector('#start-batch'), stopBatch: document.querySelector('#stop-batch'), batchProgress: document.querySelector('#batch-progress'), batchMessage: document.querySelector('#batch-message'),
    };

    elements.form.addEventListener('submit', loadMap);
    [elements.country, elements.type, elements.detail, elements.text].forEach(element => element.addEventListener('input', render));
    elements.selectVisible.addEventListener('click', () => { visibleOrganizations().forEach(item => state.selected.add(item.orgId)); render(); });
    elements.clearSelection.addEventListener('click', () => { state.selected.clear(); render(); });
    elements.loadDetails.addEventListener('click', loadSelectedDetails);
    elements.list.addEventListener('change', handleSelection);
    elements.list.addEventListener('click', handleToggle);
    elements.batchSizeOptions.addEventListener('click', selectBatchSize);
    elements.startBatch.addEventListener('click', startDetailBatch);
    elements.stopBatch.addEventListener('click', () => {
        state.batchStopRequested = true;
        elements.stopBatch.disabled = true;
        elements.batchMessage.textContent = 'Der Import stoppt nach dem aktuellen Chapter.';
    });
    loadLocal();

    async function loadLocal() {
        try {
            const response = await fetch('/api/bni/local.php');
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Lokale Daten konnten nicht geladen werden.');
            applyOrganizations(payload);
            elements.localMessage.textContent = `${payload.count} Organisationen aus SQLite geladen, ${payload.with_details} mit Detaildaten.`;
            elements.localMessage.className = 'message success';
        } catch (error) {
            elements.localMessage.textContent = error.message;
            elements.localMessage.className = 'message error';
        }
    }

    async function loadMap(event) {
        event.preventDefault();
        setMessage('Grunddaten werden mit einem Sammelrequest geladen …');
        elements.read.disabled = true;
        try {
            const response = await fetch(`/api/bni/map.php?url=${encodeURIComponent(elements.url.value.trim())}`);
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Grunddaten konnten nicht geladen werden.');
            applyOrganizations(payload);
            setMessage(`${payload.count} Grunddatensätze geladen, ${payload.with_details} mit lokalen Details.`, 'success');
        } catch (error) {
            setMessage(error.message, 'error');
        } finally {
            elements.read.disabled = false;
        }
    }

    function applyOrganizations(payload) {
        state.organizations = Array.isArray(payload.organizations) ? payload.organizations : [];
        const availableIds = new Set(state.organizations.map(item => item.orgId));
        state.expanded = new Set([...state.expanded].filter(id => availableIds.has(id)));
        state.selected.clear();
        hydrateLocalDetails();
        elements.results.hidden = false;
        elements.emptyDatabase.hidden = state.organizations.length !== 0;
        renderStats();
        renderBatchStats();
        render();
    }

    function selectBatchSize(event) {
        const button = event.target.closest('button[data-batch-size]');
        if (!button || state.batchRunning) return;
        elements.batchSizeOptions.querySelectorAll('button').forEach(option => option.setAttribute('aria-pressed', String(option === button)));
        elements.batchSize.value = button.dataset.batchSize;
    }

    async function startDetailBatch() {
        if (state.batchRunning) return;
        state.batchRunning = true; state.batchStopRequested = false;
        setBatchControls(true); setBatchMessage('Fehlende Chapter werden lokal ermittelt …');
        elements.batchProgress.textContent = '';
        let processed = 0; let successful = 0; let skipped = 0; let errors = 0; let target = 0;
        let batchStopReason = null; let retryAfter = null;

        try {
            const pendingResponse = await fetch(`/api/bni/pending.php?limit=${encodeURIComponent(elements.batchSize.value)}`);
            const pendingPayload = await pendingResponse.json();
            if (!pendingResponse.ok) throw new Error(pendingPayload.error || 'Der Detailbatch konnte nicht vorbereitet werden.');
            const chapters = Array.isArray(pendingPayload.chapters) ? pendingPayload.chapters : [];
            target = chapters.length;
            if (!target) {
                setBatchMessage('Es sind keine fehlenden Chapterdetails vorhanden.', 'success');
                return;
            }

            for (const chapter of chapters) {
                if (state.batchStopRequested) break;
                const item = state.organizations.find(organization => organization.orgId === chapter.orgId);
                if (item) { state.statuses.set(item.orgId, 'lädt'); render(); }

                try {
                    const response = await fetch('/api/bni/details.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ items: [{ orgId: chapter.orgId }], reload: false }),
                    });
                    const payload = await response.json(); const result = payload.results?.[0];
                    if (response.ok && result?.status === 'loaded') {
                        if (result.skipped) skipped += 1; else successful += 1;
                        if (item && result.details) {
                            Object.assign(item, result.details);
                            state.details.set(item.orgId, item); state.statuses.set(item.orgId, 'loaded');
                        }
                    } else if (result?.status === 'rate_limited' || result?.status === 'forbidden') {
                        batchStopReason = result.status;
                        retryAfter = Number.isInteger(result.retryAfter) ? result.retryAfter : null;
                        if (item) state.statuses.set(item.orgId, 'not_loaded');
                    } else {
                        errors += 1;
                        if (item) state.statuses.set(item.orgId, 'error');
                    }
                } catch {
                    errors += 1;
                    if (item) state.statuses.set(item.orgId, 'error');
                }

                processed += 1;
                const protection = batchStopReason === 'rate_limited' ? ' · Rate-Limit: 1' : batchStopReason === 'forbidden' ? ' · Abgewiesen: 1' : '';
                elements.batchProgress.textContent = `${processed} von ${target} Chapterdetails geladen · Erfolgreich: ${successful} · Übersprungen: ${skipped} · Fehler: ${errors}${protection}`;
                renderStats(); renderBatchStats(); render();
                if (batchStopReason) break;
                if (state.batchStopRequested) break;
                if (processed < target) await wait(BNI_DETAIL_DELAY_MS);
            }

            if (batchStopReason === 'rate_limited') {
                const retryHint = retryAfter === null ? '' : ` Erneuter Versuch frühestens in ${retryAfter} Sekunden empfohlen.`;
                setBatchMessage(`BNI begrenzt derzeit die Anzahl der Anfragen. Der Import wurde gestoppt. ${processed} von ${target} verarbeitet, ${successful} erfolgreich.${retryHint}`, 'error');
            } else if (batchStopReason === 'forbidden') {
                setBatchMessage(`BNI hat weitere Anfragen abgewiesen. Der Import wurde gestoppt. ${processed} von ${target} verarbeitet, ${successful} erfolgreich.`, 'error');
            } else if (state.batchStopRequested) setBatchMessage(`Import nach ${processed} von ${target} Chaptern gestoppt.`, 'success');
            else setBatchMessage(`${processed} Chapter verarbeitet: ${successful} erfolgreich, ${skipped} übersprungen, ${errors} Fehler.`, errors ? 'error' : 'success');
        } catch (error) {
            setBatchMessage(error.message, 'error');
        } finally {
            state.batchRunning = false;
            setBatchControls(false);
            await loadLocal();
        }
    }

    function setBatchControls(running) {
        elements.startBatch.disabled = running;
        elements.loadDetails.disabled = running;
        elements.read.disabled = running;
        elements.stopBatch.hidden = !running;
        elements.stopBatch.disabled = false;
        elements.batchSizeOptions.querySelectorAll('button').forEach(button => { button.disabled = running; });
    }

    function renderBatchStats() {
        const chapters = state.organizations.filter(item => item.orgType === 'CHAPTER');
        const loaded = chapters.filter(item => (state.statuses.get(item.orgId) || item.detailStatus) === 'loaded').length;
        const errors = chapters.filter(item => (state.statuses.get(item.orgId) || item.detailStatus) === 'error').length;
        const counts = [
            ['Bestehende Chapter gesamt', chapters.length], ['Mit Detaildaten', loaded],
            ['Ohne Detaildaten', chapters.length - loaded], ['Fehlerhafte Detailabrufe', errors],
        ];
        const fragment = document.createDocumentFragment();
        counts.forEach(([label, value]) => {
            const box = document.createElement('div'); const strong = document.createElement('strong'); const span = document.createElement('span');
            strong.textContent = value; span.textContent = label; box.append(strong, span); fragment.append(box);
        });
        elements.batchStats.replaceChildren(fragment);
    }

    function visibleOrganizations() {
        const query = elements.text.value.trim().toLocaleLowerCase('de');
        return state.organizations.filter(item => {
            const name = state.details.get(item.orgId)?.chapterName || '';
            return (!elements.country.value || item.countryCode === elements.country.value)
                && (!elements.type.value || item.orgType === elements.type.value)
                && (!elements.detail.value || (state.statuses.get(item.orgId) || 'not_loaded') === elements.detail.value)
                && (!query || String(item.orgId).includes(query) || name.toLocaleLowerCase('de').includes(query));
        });
    }

    function render() {
        const visible = visibleOrganizations();
        const fragment = document.createDocumentFragment();
        visible.forEach(item => {
            fragment.append(organizationRow(item));
            if (state.expanded.has(item.orgId)) fragment.append(detailRow(item));
        });
        elements.list.replaceChildren(fragment);
        elements.visibleCount.textContent = `${visible.length} von ${state.organizations.length} sichtbar`;
        updateSelectionCount();
    }

    function organizationRow(item) {
        const details = state.details.get(item.orgId);
        const status = state.statuses.get(item.orgId) || 'not_loaded';
        const row = document.createElement('tr'); row.className = 'organization'; row.dataset.id = item.orgId;
        appendCheckbox(row, item);
        appendToggle(row, item);
        appendCell(row, details?.chapterName || '—', 'chapter-name');
        appendCell(row, item.orgId, 'column-orgid numeric');
        appendCell(row, countryLabels[item.countryCode] || item.countryCode || '—', 'column-country');
        appendCell(row, typeLabels[item.orgType] || item.orgType || '—', 'column-type');
        appendCell(row, details?.city || '—', 'column-city');
        appendCell(row, details?.meetingDay || '—', 'column-day');
        appendCell(row, details?.meetingTime || '—', 'column-time');
        appendStatusCell(row, status);
        appendCell(row, formatTimestamp(item.detailsLoadedAt), 'column-updated updated-at');
        return row;
    }

    function appendCheckbox(row, item) {
        const cell = document.createElement('td'); cell.className = 'select-column';
        const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.dataset.id = item.orgId;
        checkbox.checked = state.selected.has(item.orgId); checkbox.setAttribute('aria-label', `Organisation ${item.orgId} auswählen`);
        cell.append(checkbox); row.append(cell);
    }

    function appendToggle(row, item) {
        const expanded = state.expanded.has(item.orgId);
        const cell = document.createElement('td'); cell.className = 'toggle-column';
        const button = document.createElement('button'); button.type = 'button'; button.className = 'row-toggle'; button.dataset.id = item.orgId;
        button.setAttribute('aria-expanded', String(expanded)); button.setAttribute('aria-controls', `details-${item.orgId}`);
        button.setAttribute('aria-label', `Details für Organisation ${item.orgId} ${expanded ? 'schließen' : 'öffnen'}`);
        button.textContent = expanded ? '▼' : '▶';
        cell.append(button); row.append(cell);
    }

    function appendCell(row, value, className = '') {
        const cell = document.createElement('td'); cell.textContent = display(value); cell.className = className; row.append(cell);
    }

    function appendStatusCell(row, status) {
        const cell = document.createElement('td'); cell.className = 'status-column';
        const statusClass = status === 'lädt' ? 'loading' : status;
        const badge = document.createElement('span'); badge.className = `status-badge status-${statusClass}`; badge.textContent = statusLabel(status);
        cell.append(badge); row.append(cell);
    }

    function detailRow(item) {
        const row = document.createElement('tr'); row.className = 'detail-row'; row.id = `details-${item.orgId}`;
        const cell = document.createElement('td'); cell.colSpan = 11;
        const panel = document.createElement('div'); panel.className = 'detail-panel';
        const details = state.details.get(item.orgId);

        if (!details) {
            const notice = document.createElement('div'); notice.className = 'empty-detail';
            const title = document.createElement('strong'); title.textContent = 'Für dieses Chapter wurden noch keine Detaildaten geladen.';
            const hint = document.createElement('p'); hint.textContent = "Chapter auswählen und 'Details für ausgewählte laden' verwenden.";
            notice.append(title, hint); panel.append(notice); cell.append(panel); row.append(cell); return row;
        }

        const view = { ...details, detailStatus: state.statuses.get(item.orgId) || details.detailStatus };
        const grid = document.createElement('div'); grid.className = 'detail-groups';
        grid.append(
            detailGroup('Chapter', [
                ['Chaptername', view.chapterName], ['orgId', view.orgId], ['Typ', typeLabels[view.orgType] || view.orgType],
                ['Region', view.region], ['Regions-ID', view.regionId], ['Land', countryLabels[view.countryCode] || view.countryCode],
            ]),
            detailGroup('Treffen', [
                ['Wochentag', view.meetingDay], ['Uhrzeit', view.meetingTime], ['Meetingtyp', view.meetingType],
                ['Meetingdauer', view.meetingDuration, minutes], ['Treffpunkt', view.venue],
            ]),
            detailGroup('Adresse', [['Straße', view.street], ['PLZ', view.postalCode], ['Ort', view.city]]),
            detailGroup('Netzwerk', [
                ['Mitgliederzahl', view.memberCount], ['Besucher-anmeldung', view.visitorRegistrationUrl, externalLink],
                ['Chapter-Webseite', view.chapterUrl, externalLink], ['Online-Meeting', view.onlineMeetingUrl, externalLink],
            ]),
            detailGroup('System', [
                ['Detailstatus', statusLabel(view.detailStatus)], ['Zuletzt aktualisiert', formatTimestamp(view.detailsLoadedAt)],
                ['Zeitzone', view.timezone], ['Chapterstatus', view.status],
            ]),
        );
        const description = detailGroup('Beschreibung', [['ChapterText', view.description]], 'description-group');
        panel.append(grid, description); cell.append(panel); row.append(cell); return row;
    }

    function detailGroup(title, fields, extraClass = '') {
        const section = document.createElement('section'); section.className = `detail-group ${extraClass}`.trim();
        const heading = document.createElement('h3'); heading.textContent = title;
        const list = document.createElement('dl');
        fields.forEach(([label, raw, formatter]) => {
            const wrapper = document.createElement('div');
            const term = document.createElement('dt'); term.textContent = label;
            const value = document.createElement('dd');
            if (raw === null || raw === undefined || raw === '') value.textContent = '—';
            else if (formatter) value.append(formatter(raw));
            else value.textContent = String(raw);
            wrapper.append(term, value); list.append(wrapper);
        });
        section.append(heading, list); return section;
    }

    function externalLink(url) {
        try {
            const parsed = new URL(url);
            if (!['http:', 'https:'].includes(parsed.protocol)) throw new Error('unsupported');
            const anchor = document.createElement('a'); anchor.href = parsed.href; anchor.target = '_blank';
            anchor.rel = 'noopener noreferrer'; anchor.textContent = 'Öffnen ↗'; return anchor;
        } catch {
            return document.createTextNode(String(url));
        }
    }

    function minutes(value) { return document.createTextNode(`${value} Minuten`); }

    function handleSelection(event) {
        const checkbox = event.target.closest('input[type="checkbox"][data-id]');
        if (!checkbox) return;
        const id = Number(checkbox.dataset.id);
        checkbox.checked ? state.selected.add(id) : state.selected.delete(id);
        updateSelectionCount();
    }

    function handleToggle(event) {
        const button = event.target.closest('.row-toggle');
        if (!button) return;
        const id = Number(button.dataset.id);
        state.expanded.has(id) ? state.expanded.delete(id) : state.expanded.add(id);
        render();
        document.querySelector(`.row-toggle[data-id="${id}"]`)?.focus();
    }

    async function loadSelectedDetails() {
        if (state.selected.size > 50) {
            setMessage('Bitte höchstens 50 Datensätze auswählen. Es wurden keine Details abgerufen.', 'error'); return;
        }
        const pending = state.organizations.filter(item => state.selected.has(item.orgId)
            && (elements.reloadDetails.checked || state.statuses.get(item.orgId) !== 'loaded'));
        if (!pending.length) { setMessage('Für die Auswahl sind keine neuen Details zu laden.'); return; }
        elements.loadDetails.disabled = true; elements.read.disabled = true;
        let completed = 0; let stoppedForProtection = false;
        try {
            for (const item of pending) {
                state.statuses.set(item.orgId, 'lädt'); render();
                const response = await fetch('/api/bni/details.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ items: [{ orgId: item.orgId }], reload: elements.reloadDetails.checked }),
                });
                const payload = await response.json(); const result = payload.results?.[0];
                if (response.ok && result?.status === 'loaded') {
                    Object.assign(item, result.details);
                    state.details.set(item.orgId, item); state.statuses.set(item.orgId, 'loaded');
                } else if (result?.status === 'rate_limited' || result?.status === 'forbidden') {
                    stoppedForProtection = true;
                    state.statuses.set(item.orgId, 'not_loaded');
                    const retryHint = result.status === 'rate_limited' && Number.isInteger(result.retryAfter)
                        ? ` Erneuter Versuch frühestens in ${result.retryAfter} Sekunden empfohlen.` : '';
                    setMessage(result.status === 'rate_limited'
                        ? `BNI begrenzt derzeit die Anzahl der Anfragen. Der Abruf wurde gestoppt.${retryHint}`
                        : 'BNI hat weitere Anfragen abgewiesen. Der Abruf wurde gestoppt.', 'error');
                    completed += 1; render(); break;
                } else state.statuses.set(item.orgId, 'error');
                completed += 1; elements.progress.textContent = `${completed} von ${pending.length} Details geladen`; render();
                if (completed < pending.length) await wait(BNI_DETAIL_DELAY_MS);
            }
            renderStats();
            if (!stoppedForProtection) setMessage(`${completed} Detailabrufe abgeschlossen.`, 'success');
        } catch (error) {
            setMessage(`Detailabruf abgebrochen: ${error.message}`, 'error');
        } finally {
            elements.loadDetails.disabled = false; elements.read.disabled = false;
        }
    }

    function renderStats() {
        const counts = [
            ['Organisationen gesamt', state.organizations.length], ['Chapter', count('orgType', 'CHAPTER')],
            ['Im Aufbau', count('orgType', 'CORE_GROUP')], ['Geplant', count('orgType', 'PLANNED_GROUP')],
            ['Deutschland', count('countryCode', 'DE')], ['Österreich', count('countryCode', 'AT')],
            ['Mit Detaildaten', state.organizations.filter(item => item.detailsLoadedAt).length],
            ['Ohne Detaildaten', state.organizations.filter(item => !item.detailsLoadedAt).length],
        ];
        const fragment = document.createDocumentFragment();
        counts.forEach(([label, value], index) => {
            const box = document.createElement('div'); box.className = `stat${index === 0 ? ' stat-primary' : ''}`;
            const strong = document.createElement('strong'); strong.textContent = value;
            const span = document.createElement('span'); span.textContent = label;
            box.append(strong, span); fragment.append(box);
        });
        elements.stats.replaceChildren(fragment);
    }

    function count(field, value) { return state.organizations.filter(item => item[field] === value).length; }
    function hydrateLocalDetails() {
        state.details.clear(); state.statuses.clear();
        state.organizations.forEach(item => {
            state.statuses.set(item.orgId, item.detailStatus || 'not_loaded');
            if (item.detailsLoadedAt) state.details.set(item.orgId, item);
        });
    }
    function display(value) { return value === null || value === undefined || value === '' ? '—' : String(value); }
    function statusLabel(status) { return status === 'loaded' ? 'geladen' : status === 'error' ? 'Fehler' : status === 'lädt' ? 'lädt' : 'nicht geladen'; }
    function formatTimestamp(value) { return value ? new Intl.DateTimeFormat('de-DE', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '—'; }
    function updateSelectionCount() { elements.selectionCount.textContent = `${state.selected.size} ausgewählt`; }
    function setMessage(text, type = '') { elements.message.textContent = text; elements.message.className = `message ${type}`; }
    function setBatchMessage(text, type = '') { elements.batchMessage.textContent = text; elements.batchMessage.className = `message ${type}`; }
    function wait(milliseconds) { return new Promise(resolve => window.setTimeout(resolve, milliseconds)); }
})();
