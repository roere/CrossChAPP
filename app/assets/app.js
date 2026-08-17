(() => {
    'use strict';

    const state = { organizations: [], selected: new Set(), details: new Map(), statuses: new Map() };
    const typeLabels = { CHAPTER: 'Chapter', CORE_GROUP: 'Im Aufbau', PLANNED_GROUP: 'Geplant' };
    const countryLabels = { DE: 'Deutschland', AT: 'Österreich' };
    const detailFields = [
        ['Region', 'region'], ['Regions-ID', 'regionId'], ['Ort', 'city'], ['PLZ', 'postalCode'],
        ['Straße', 'street'], ['Treffpunkt', 'venue'], ['Wochentag', 'meetingDay'], ['Uhrzeit', 'meetingTime'],
        ['Meetingtyp', 'meetingType'], ['Meetingdauer', 'meetingDuration', value => `${value} Minuten`],
        ['Mitgliederzahl', 'memberCount'], ['Zeitzone', 'timezone'], ['Status', 'status'],
        ['Chapter-URL', 'chapterUrl', makeLink], ['Besucheranmeldung', 'visitorRegistrationUrl', makeLink],
        ['Online-Meeting', 'onlineMeetingUrl', makeLink], ['Beschreibung', 'description', null, true],
    ];
    const elements = {
        form: document.querySelector('#source-form'), url: document.querySelector('#source-url'), read: document.querySelector('#read-button'),
        message: document.querySelector('#message'), results: document.querySelector('#results'), stats: document.querySelector('#stats'),
        country: document.querySelector('#country-filter'), type: document.querySelector('#type-filter'), text: document.querySelector('#text-filter'),
        list: document.querySelector('#organization-list'), visibleCount: document.querySelector('#visible-count'),
        selectVisible: document.querySelector('#select-visible'), clearSelection: document.querySelector('#clear-selection'),
        loadDetails: document.querySelector('#load-details'), selectionCount: document.querySelector('#selection-count'), progress: document.querySelector('#progress'),
    };

    elements.form.addEventListener('submit', loadMap);
    [elements.country, elements.type, elements.text].forEach(element => element.addEventListener('input', render));
    elements.selectVisible.addEventListener('click', () => { visibleOrganizations().forEach(item => state.selected.add(item.orgId)); render(); });
    elements.clearSelection.addEventListener('click', () => { state.selected.clear(); render(); });
    elements.loadDetails.addEventListener('click', loadSelectedDetails);
    elements.list.addEventListener('change', event => {
        const checkbox = event.target.closest('input[type="checkbox"][data-id]');
        if (!checkbox) return;
        const id = Number(checkbox.dataset.id);
        checkbox.checked ? state.selected.add(id) : state.selected.delete(id);
        updateSelectionCount();
    });

    async function loadMap(event) {
        event.preventDefault();
        setMessage('Grunddaten werden mit einem Sammelrequest geladen …');
        elements.read.disabled = true;
        try {
            const response = await fetch(`/api/bni/map.php?url=${encodeURIComponent(elements.url.value.trim())}`);
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Grunddaten konnten nicht geladen werden.');
            state.organizations = payload.organizations;
            state.selected.clear(); state.details.clear(); state.statuses.clear();
            elements.results.hidden = false;
            renderStats(); render();
            setMessage(`${payload.count} Grunddatensätze geladen.`, 'success');
        } catch (error) {
            setMessage(error.message, 'error');
        } finally {
            elements.read.disabled = false;
        }
    }

    function visibleOrganizations() {
        const query = elements.text.value.trim().toLocaleLowerCase('de');
        return state.organizations.filter(item => {
            const name = state.details.get(item.orgId)?.chapterName || '';
            return (!elements.country.value || item.countryCode === elements.country.value)
                && (!elements.type.value || item.orgType === elements.type.value)
                && (!query || String(item.orgId).includes(query) || name.toLocaleLowerCase('de').includes(query));
        });
    }

    function render() {
        const visible = visibleOrganizations();
        const fragment = document.createDocumentFragment();
        visible.forEach(item => {
            const details = state.details.get(item.orgId);
            const status = state.statuses.get(item.orgId) || 'nicht geladen';
            const row = document.createElement('tr'); row.className = 'organization';
            appendCheckbox(row, item);
            [item.orgId, countryLabels[item.countryCode] || item.countryCode || '—', typeLabels[item.orgType] || item.orgType || '—',
                display(item.longitude), display(item.latitude), details?.chapterName || '—', statusLabel(status)]
                .forEach((value, index) => appendCell(row, value, index === 6 ? `status-${status}` : ''));
            fragment.append(row);
            if (details) fragment.append(detailRow(details));
        });
        elements.list.replaceChildren(fragment);
        elements.visibleCount.textContent = `${visible.length} von ${state.organizations.length} Datensätzen sichtbar`;
        updateSelectionCount();
    }

    function appendCheckbox(row, item) {
        const cell = document.createElement('td');
        const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.dataset.id = item.orgId;
        checkbox.checked = state.selected.has(item.orgId); checkbox.setAttribute('aria-label', `Organisation ${item.orgId} auswählen`);
        cell.append(checkbox); row.append(cell);
    }

    function appendCell(row, value, className = '') {
        const cell = document.createElement('td'); cell.textContent = String(value); if (className) cell.className = className; row.append(cell);
    }

    function detailRow(details) {
        const row = document.createElement('tr'); row.className = 'detail-row';
        const cell = document.createElement('td'); cell.colSpan = 8;
        const list = document.createElement('dl'); list.className = 'detail-grid';
        detailFields.forEach(([label, key, format, wide]) => {
            const wrapper = document.createElement('div'); if (wide) wrapper.className = 'wide';
            const term = document.createElement('dt'); term.textContent = label;
            const value = document.createElement('dd'); const raw = details[key];
            if (raw === null || raw === undefined || raw === '') value.textContent = '—';
            else if (format) value.append(format(raw));
            else value.textContent = String(raw);
            wrapper.append(term, value); list.append(wrapper);
        });
        cell.append(list); row.append(cell); return row;
    }

    function makeLink(url) {
        const anchor = document.createElement('a'); anchor.href = url; anchor.target = '_blank';
        anchor.rel = 'noopener noreferrer'; anchor.textContent = url; return anchor;
    }

    async function loadSelectedDetails() {
        if (state.selected.size > 50) {
            setMessage('Bitte höchstens 50 Datensätze auswählen. Es wurden keine Details abgerufen.', 'error'); return;
        }
        const pending = state.organizations.filter(item => state.selected.has(item.orgId) && !state.details.has(item.orgId) && state.statuses.get(item.orgId) !== 'loaded');
        if (!pending.length) { setMessage('Für die Auswahl sind keine neuen Details zu laden.'); return; }
        elements.loadDetails.disabled = true; elements.read.disabled = true;
        let completed = 0;
        try {
            for (const item of pending) {
                state.statuses.set(item.orgId, 'lädt'); render();
                const response = await fetch('/api/bni/details.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ items: [{ orgId: item.orgId, cmsSecurityHash: item.cmsSecurityHash }] }),
                });
                const payload = await response.json(); const result = payload.results?.[0];
                if (response.ok && result?.status === 'loaded') {
                    state.details.set(item.orgId, result.details); state.statuses.set(item.orgId, 'loaded');
                } else state.statuses.set(item.orgId, 'error');
                completed += 1; elements.progress.textContent = `${completed} von ${pending.length} Details geladen`; render();
                if (completed < pending.length) await wait(300);
            }
            setMessage(`${completed} Detailabrufe abgeschlossen.`, 'success');
        } catch (error) {
            setMessage(`Detailabruf abgebrochen: ${error.message}`, 'error');
        } finally {
            elements.loadDetails.disabled = false; elements.read.disabled = false;
        }
    }

    function renderStats() {
        const counts = [
            ['Gesamtzahl', state.organizations.length], ['Chapter', count('orgType', 'CHAPTER')],
            ['Im Aufbau', count('orgType', 'CORE_GROUP')], ['Geplant', count('orgType', 'PLANNED_GROUP')],
            ['Deutschland', count('countryCode', 'DE')], ['Österreich', count('countryCode', 'AT')],
        ];
        const fragment = document.createDocumentFragment();
        counts.forEach(([label, value]) => {
            const box = document.createElement('div'); box.className = 'stat';
            const strong = document.createElement('strong'); strong.textContent = value;
            const span = document.createElement('span'); span.textContent = label;
            box.append(strong, span); fragment.append(box);
        });
        elements.stats.replaceChildren(fragment);
    }

    function count(field, value) { return state.organizations.filter(item => item[field] === value).length; }
    function display(value) { return value === null || value === undefined || value === '' ? '—' : value; }
    function statusLabel(status) { return status === 'loaded' ? 'geladen' : status === 'error' ? 'Fehler' : status; }
    function updateSelectionCount() { elements.selectionCount.textContent = `${state.selected.size} ausgewählt`; }
    function setMessage(text, type = '') { elements.message.textContent = text; elements.message.className = `message ${type}`; }
    function wait(milliseconds) { return new Promise(resolve => window.setTimeout(resolve, milliseconds)); }
})();
