(() => {
    'use strict';

    const list = document.querySelector('#representation-list');
    if (!list) return;

    const state = { organizations: [], offers: [], selectedDates: new Set(), selectedOrganizationId: null, allDates: false, location: null, viewer: { authenticated: false, homeChapterOrgId: null }, pendingDelete: null, deleteTrigger: null, map: null, mapTrigger: null, routeCache: new Map() };
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const countryLabels = { DE: 'Deutschland', AT: 'Österreich', CH: 'Schweiz' };
    const elements = {
        date: document.querySelector('#representation-date'), datePicker: document.querySelector('#representation-picker'),
        allDates: document.querySelector('#representation-all-dates'), dateMessage: document.querySelector('#representation-date-message'),
        dateChips: document.querySelector('#representation-date-chips'), country: document.querySelector('#representation-country'),
        text: document.querySelector('#representation-text'),
        location: document.querySelector('#representation-location'), radius: document.querySelector('#representation-radius'),
        applyRadius: document.querySelector('#apply-representation-radius'), locationMessage: document.querySelector('#representation-location-message'),
        clear: document.querySelector('#clear-representations'),
        counts: document.querySelector('#representation-counts'), distanceHeading: document.querySelector('#representation-distance-heading'), list,
        save: document.querySelector('#save-representation-offer'), saveMessage: document.querySelector('#representation-save-message'),
        ownSection: document.querySelector('#my-representation-offers'), ownList: document.querySelector('#representation-own-list'),
        deleteDialog: document.querySelector('#delete-representation-dialog'), deleteSummary: document.querySelector('#delete-representation-summary'),
        deleteMessage: document.querySelector('#delete-representation-message'), confirmDelete: document.querySelector('#confirm-delete-representation'),
        cancelDelete: document.querySelector('#cancel-delete-representation'), closeDelete: document.querySelector('#close-delete-representation'),
    };
    const sortState = window.CrossChappSort.bind(document.querySelector('#representation-table'), render);
    const sortFields = {
        chapterName: { type: 'string', value: item => item.chapterName },
        country: { type: 'string', value: item => countryLabels[item.countryCode] || item.countryCode },
        city: { type: 'string', value: item => item.city },
        street: { type: 'string', value: item => item.street },
        meetingDay: { type: 'weekday', value: item => item.meetingDay },
        meetingTime: { type: 'time', value: item => item.meetingTime },
        distance: { type: 'number', value: item => item.distanceKm },
    };

    const datePicker = window.CrossChappDatePicker.create({
        root: elements.datePicker,
        minDate: document.documentElement.dataset.today || localToday(),
        disabled: true,
        onSelect: addDate,
        invalidMessage: 'Bitte gib ein gültiges Datum im Format TT.MM.JJJJ ein.',
        pastMessage: 'Bitte ein heutiges oder zukünftiges Datum auswählen.',
    });
    elements.allDates.disabled = true;
    elements.allDates.addEventListener('change', () => {
        state.allDates = elements.allDates.checked;
        datePicker.setDisabled(state.allDates || !selectedOrganization()?.meetingDay);
        renderDates(); render();
    });
    elements.dateChips.addEventListener('click', removeDate);
    [elements.country, elements.text].forEach(element => element.addEventListener('input', render));
    elements.location.addEventListener('input', () => { state.location = null; elements.locationMessage.textContent = ''; render(); });
    elements.radius.addEventListener('input', () => { if (state.location) render(); });
    elements.applyRadius.addEventListener('click', applyRadius);
    elements.clear.addEventListener('click', () => selectOrganization(null));
    elements.list.addEventListener('change', event => {
        const radio = event.target.closest('input[data-org-id]'); if (!radio) return;
        selectOrganization(radio.checked ? Number(radio.dataset.orgId) : null);
    });
    elements.list.addEventListener('click', event => { const button = event.target.closest('button[data-map-org-id]'); if (button) openMap(Number(button.dataset.mapOrgId), button); });
    elements.save.addEventListener('click', saveOffer);
    elements.ownList.addEventListener('click', openDeleteDialog);
    elements.confirmDelete.addEventListener('click', deleteOffer);
    elements.cancelDelete.addEventListener('click', closeDeleteDialog);
    elements.closeDelete.addEventListener('click', closeDeleteDialog);
    elements.deleteDialog.addEventListener('cancel', event => { event.preventDefault(); closeDeleteDialog(); });
    const mapDialog = document.querySelector('#representation-map-dialog');
    document.querySelector('#close-representation-map').addEventListener('click', closeMap);
    document.querySelector('#close-representation-map-button').addEventListener('click', closeMap);
    mapDialog.addEventListener('cancel', event => { event.preventDefault(); closeMap(); });
    mapDialog.addEventListener('click', event => { if (event.target === mapDialog) closeMap(); });
    loadOrganizations();

    async function loadOrganizations() {
        try {
            const response = await fetch('/api/representation/organizations.php'); const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Die lokale Chapterliste konnte nicht geladen werden.');
            state.organizations = Array.isArray(payload.organizations) ? payload.organizations : [];
            state.viewer = payload.viewer || state.viewer;
            elements.ownSection.hidden = !state.viewer.authenticated;
            if (state.viewer.authenticated) loadOffers();
            render();
        } catch (error) {
            elements.list.replaceChildren(messageRow(error.message));
        }
    }

    function addDate(value) {
        if (state.selectedDates.has(value)) {
            setDateMessage('Dieser Termin wurde bereits ausgewählt.', 'error'); return false;
        }
        const incompatible = selectedOrganizationsIncompatibleWith(value);
        if (incompatible.length) {
            setDateMessage(`Der ${formatDate(value)} passt nicht zum Meetingtag von ${incompatible.map(item => item.chapterName).join(', ')}.`, 'error');
            return false;
        }
        state.selectedDates.add(value); setDateMessage(''); renderDates(); render(); return true;
    }

    function removeDate(event) {
        const button = event.target.closest('button[data-date]');
        if (!button || state.allDates) return;
        state.selectedDates.delete(button.dataset.date); renderDates(); render();
    }

    function renderDates() {
        const fragment = document.createDocumentFragment();
        [...state.selectedDates].sort().forEach(date => {
            const chip = document.createElement('span'); chip.className = `date-chip${state.allDates ? ' disabled' : ''}`;
            const label = document.createElement('span'); label.textContent = formatDate(date);
            const remove = document.createElement('button'); remove.type = 'button'; remove.dataset.date = date; remove.textContent = '×';
            remove.disabled = state.allDates; remove.setAttribute('aria-label', `${formatDate(date)} entfernen`);
            chip.append(label, remove); fragment.append(chip);
        });
        elements.dateChips.replaceChildren(fragment);
    }

    async function applyRadius() {
        const query = elements.location.value.trim(); const radius = Number(elements.radius.value);
        if (!query) { state.location = null; elements.locationMessage.textContent = ''; render(); return; }
        if (!Number.isInteger(radius) || radius < 1 || radius > 500) {
            setLocationMessage('Der Umkreis muss zwischen 1 und 500 km liegen.', 'error'); return;
        }
        elements.applyRadius.disabled = true; setLocationMessage('Ort wird gesucht …');
        try {
            const response = await fetch('/api/representation/geocode.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ location: query }),
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Ort oder PLZ konnte nicht gefunden werden.');
            state.location = payload.location; setLocationMessage(`Umkreis um ${payload.location.label} aktiv.`, 'success'); render();
        } catch (error) {
            state.location = null; setLocationMessage(error.message, 'error'); render();
        } finally { elements.applyRadius.disabled = false; }
    }

    function visibleOrganizations() {
        const query = elements.text.value.trim().toLocaleLowerCase('de');
        const radius = Number(elements.radius.value);
        const filtered = state.organizations.map(item => ({ ...item, distanceKm: distanceFor(item) })).filter(item => {
            const haystack = [item.chapterName, item.city, item.postalCode, item.region].filter(value => value !== null && value !== undefined).join(' ').toLocaleLowerCase('de');
            return (!elements.country.value || item.countryCode === elements.country.value)
                && (!query || haystack.includes(query))
                && (!state.location || (item.distanceKm !== null && item.distanceKm <= radius));
        });
        return window.CrossChappSort.sort(filtered, sortState, sortFields);
    }

    function selectOrganization(orgId) {
        if (state.selectedOrganizationId === orgId) return;
        state.selectedOrganizationId = orgId;
        state.selectedDates.clear(); state.allDates = false; elements.allDates.checked = false;
        datePicker.clear(); renderDates(); configureDatePicker(); setDateMessage(''); render();
    }

    function selectedOrganization() { return state.organizations.find(item => item.orgId === state.selectedOrganizationId) || null; }

    function configureDatePicker() {
        const organization = selectedOrganization(), meetingDay = String(organization?.meetingDay || '').trim();
        elements.allDates.disabled = organization === null;
        datePicker.configure({
            allowedWeekdays: meetingDay ? [weekdayNumber(meetingDay)] : null,
            disabled: organization === null || state.allDates || !meetingDay,
            weekdayMessage: meetingDay ? `Bitte wähle einen ${meetingDay}.` : 'Für dieses Chapter ist kein regelmäßiger Meetingtag hinterlegt.',
        });
    }

    function weekdayForDate(date) {
        return new Intl.DateTimeFormat('de-DE', { weekday: 'long', timeZone: 'Europe/Berlin' }).format(new Date(`${date}T12:00:00+02:00`)).toLocaleLowerCase('de');
    }

    function selectedOrganizationsIncompatibleWith(date) {
        const weekday = weekdayForDate(date);
        return state.organizations.filter(item => item.orgId === state.selectedOrganizationId && String(item.meetingDay || '').trim().toLocaleLowerCase('de') !== weekday);
    }

    function validateSelection() {
        if (state.allDates) return [];
        return state.organizations.filter(item => item.orgId === state.selectedOrganizationId && [...state.selectedDates].some(date => String(item.meetingDay || '').trim().toLocaleLowerCase('de') !== weekdayForDate(date)));
    }

    function render() {
        const visible = visibleOrganizations(); const fragment = document.createDocumentFragment();
        visible.forEach(item => fragment.append(organizationRow(item)));
        if (!visible.length) fragment.append(messageRow('Für diese Filter wurden keine Organisationen gefunden.'));
        elements.list.replaceChildren(fragment); elements.distanceHeading.hidden = !state.location;
        renderCounts(visible.length);
    }

    function organizationRow(item) {
        const row = document.createElement('tr');
        const select = document.createElement('td'); select.className = 'select-column';
        const checkbox = document.createElement('input'); checkbox.type = 'radio'; checkbox.name = 'representation_chapter'; checkbox.dataset.orgId = item.orgId;
        const isHomeChapter = item.orgId === state.viewer.homeChapterOrgId;
        checkbox.checked = state.selectedOrganizationId === item.orgId; checkbox.disabled = isHomeChapter;
        checkbox.title = isHomeChapter ? 'Für dein eigenes Chapter kannst du kein Vertretungsangebot anlegen.' : '';
        checkbox.setAttribute('aria-label', isHomeChapter ? `${item.chapterName || 'Chapter'}: eigenes Chapter, nicht auswählbar` : `${item.chapterName || 'Chapter'} auswählen`); select.append(checkbox); row.append(select);
        const chapterCell = document.createElement('td'), chapterButton = document.createElement('button'); chapterButton.type = 'button'; chapterButton.className = 'chapter-map-link'; chapterButton.dataset.mapOrgId = item.orgId; chapterButton.textContent = item.chapterName || '—'; chapterButton.setAttribute('aria-label', `${item.chapterName || 'Chapter'} auf der Karte anzeigen`); chapterCell.append(chapterButton); row.append(chapterCell);
        [countryLabels[item.countryCode] || item.countryCode || '—', locationLabel(item), item.street || '–', item.meetingDay || '—', item.meetingTime || '—'].forEach(value => {
            const cell = document.createElement('td'); cell.textContent = String(value); row.append(cell);
        });
        if (isHomeChapter) { row.classList.add('home-chapter-row'); const badge = document.createElement('span'); badge.className = 'home-chapter-badge'; badge.textContent = 'Heimatchapter'; row.children[1].append(' ', badge); }
        if (state.location) { const cell = document.createElement('td'); cell.textContent = item.distanceKm === null ? '—' : `${item.distanceKm.toLocaleString('de-DE', { maximumFractionDigits: 1 })} km`; row.append(cell); }
        return row;
    }

    function distanceFor(item) {
        if (!state.location || !Number.isFinite(item.latitude) || !Number.isFinite(item.longitude)) return null;
        const radius = 6371.0088; const toRadians = degrees => degrees * Math.PI / 180;
        const latitudeDelta = toRadians(item.latitude - state.location.latitude); const longitudeDelta = toRadians(item.longitude - state.location.longitude);
        const a = Math.sin(latitudeDelta / 2) ** 2 + Math.cos(toRadians(state.location.latitude)) * Math.cos(toRadians(item.latitude)) * Math.sin(longitudeDelta / 2) ** 2;
        return radius * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function formatDate(date) { return new Intl.DateTimeFormat('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'Europe/Berlin' }).format(new Date(`${date}T12:00:00+02:00`)); }
    function locationLabel(item) { return [item.postalCode, item.city].filter(Boolean).join(' ') || '—'; }
    function renderCounts(visible) {
        const selected = selectedOrganization();
        elements.counts.textContent = `${visible} sichtbar · ${selected ? `ausgewählt: ${selected.chapterName}` : 'kein Chapter ausgewählt'}`;
        elements.clear.disabled = selected === null;
        updateSaveState();
    }
    function messageRow(text) { const row = document.createElement('tr'); const cell = document.createElement('td'); cell.colSpan = 8; cell.textContent = text; row.append(cell); return row; }
    function setDateMessage(text, type = '') { elements.dateMessage.textContent = text; elements.dateMessage.className = `message ${type}`.trim(); }
    function localToday() { const now = new Date(); return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`; }
    function weekdayNumber(day) { return ({ Sonntag: 0, Montag: 1, Dienstag: 2, Mittwoch: 3, Donnerstag: 4, Freitag: 5, Samstag: 6 })[day] ?? -1; }
    function setLocationMessage(text, type = '') { elements.locationMessage.textContent = text; elements.locationMessage.className = `message ${type}`.trim(); }

    async function saveOffer() {
        if (!state.viewer.authenticated) { window.location.href = '/?view=login'; return; }
        if (state.selectedOrganizationId === null) { setSaveMessage('Bitte wähle ein Chapter aus.', 'error'); return; }
        if (!state.allDates && !state.selectedDates.size) { setSaveMessage('Bitte wähle mindestens einen Termin oder Alle Daten aus.', 'error'); return; }
        const incompatible = validateSelection();
        if (incompatible.length) {
            selectOrganization(null);
            setSaveMessage(`Das nicht passende Chapter wurde abgewählt: ${incompatible[0].chapterName}.`, 'error'); return;
        }
        elements.save.disabled = true; setSaveMessage('Vertretungsangebot wird gespeichert …');
        try {
            const response = await fetch('/api/representation/offers.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }, body: JSON.stringify({ orgIds: [state.selectedOrganizationId], allDates: state.allDates, dates: [...state.selectedDates] }) });
            const payload = await response.json(); if (!response.ok) throw new Error(payload.error || 'Das Vertretungsangebot konnte nicht gespeichert werden.');
            selectOrganization(null); setSaveMessage(payload.count === 1 ? 'Vertretungsangebot gespeichert.' : `${payload.count} Vertretungsangebote gespeichert.`, 'success'); await loadOffers();
        } catch (error) { setSaveMessage(error.message, 'error'); } finally { updateSaveState(); }
    }

    async function loadOffers() {
        try {
            const response = await fetch('/api/representation/offers.php'); const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Die Angebote konnten nicht geladen werden.');
            renderOffers(payload.offers || []);
        } catch (error) { elements.ownList.replaceChildren(messageParagraph(error.message)); }
    }

    function renderOffers(offers) {
        state.offers = offers;
        if (!offers.length) { elements.ownList.replaceChildren(messageParagraph('Du hast noch keine Vertretungsangebote gespeichert.')); return; }
        elements.ownList.replaceChildren(...offers.map(offer => {
            const card = document.createElement('article'); card.className = 'representation-offer-card';
            const heading = document.createElement('h3'); heading.textContent = offer.chapterName || '—';
            const dates = document.createElement('div'); dates.className = 'representation-offer-dates';
            if (offer.allDates) dates.append(offerBadge('Immer')); else offer.dates.forEach(date => dates.append(offerBadge(formatDate(date))));
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'secondary'; remove.dataset.deleteOffer = offer.id; remove.textContent = 'Löschen';
            card.append(heading, dates, remove); return card;
        }));
    }

    function openDeleteDialog(event) {
        const button = event.target.closest('button[data-delete-offer]'); if (!button) return;
        const offer = state.offers.find(item => item.id === Number(button.dataset.deleteOffer)); if (!offer) return;
        state.pendingDelete = offer; state.deleteTrigger = button; elements.deleteMessage.textContent = '';
        const heading = document.createElement('strong'); heading.textContent = offer.chapterName || '—';
        const availability = document.createElement('div'); availability.className = 'representation-offer-dates';
        if (offer.allDates) availability.append(offerBadge('Immer')); else offer.dates.forEach(date => availability.append(offerBadge(formatDate(date))));
        elements.deleteSummary.replaceChildren(heading, availability); elements.deleteDialog.showModal(); elements.confirmDelete.focus();
    }

    function closeDeleteDialog() {
        if (elements.deleteDialog.open) elements.deleteDialog.close();
        const trigger = state.deleteTrigger; state.pendingDelete = null; state.deleteTrigger = null; elements.deleteMessage.textContent = '';
        if (trigger?.isConnected) trigger.focus();
    }

    async function deleteOffer() {
        if (!state.pendingDelete) return;
        const offerId = state.pendingDelete.id; elements.confirmDelete.disabled = true; elements.cancelDelete.disabled = true;
        try {
            const response = await fetch('/api/representation/offers.php', { method: 'DELETE', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }, body: JSON.stringify({ offerId }) });
            const payload = await response.json(); if (!response.ok) throw new Error(payload.error || 'Das Vertretungsangebot konnte nicht gelöscht werden.');
            closeDeleteDialog(); setSaveMessage('Vertretungsangebot gelöscht.', 'success'); await loadOffers();
        } catch (error) { elements.deleteMessage.textContent = error.message; elements.deleteMessage.className = 'message error'; }
        finally { elements.confirmDelete.disabled = false; elements.cancelDelete.disabled = false; }
    }
    async function openMap(orgId, trigger) {
        const organization = state.organizations.find(item => item.orgId === orgId); if (!organization) return;
        state.mapTrigger = trigger; const heading = document.querySelector('#representation-map-heading'), address = document.querySelector('#representation-map-address'), root = document.querySelector('#representation-map'), message = document.querySelector('#representation-map-message');
        heading.textContent = organization.chapterName || 'Chapterkarte'; address.textContent = addressLabel(organization); message.textContent = ''; message.className = 'message'; mapDialog.showModal(); document.querySelector('#close-representation-map').focus();
        const target = coordinate(organization.latitude, organization.longitude);
        if (!target) { root.hidden = true; message.textContent = 'Für dieses Chapter sind derzeit keine Kartendaten verfügbar.'; return; }
        root.hidden = false;
        try {
            if (!state.map) state.map = window.CrossChappMap.create(root); state.map.clear();
            const chapterPopup = popup(organization.chapterName || 'Chapter', addressLabel(organization)); state.map.marker(target, chapterPopup, organization.chapterName || 'Chapter');
            const points = [target], start = state.location ? coordinate(state.location.latitude, state.location.longitude) : null;
            if (!start) { state.map.fit(points); return; }
            state.map.start(start, popup('Ausgangspunkt', state.location.label || elements.location.value.trim())); points.push(start);
            const key = [orgId, start[0], start[1], target[0], target[1]].join(':'); let route = state.routeCache.get(key);
            if (!route) {
                message.textContent = 'Route wird geladen …';
                const response = await fetch('/api/representation/route.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }, body: JSON.stringify({ startLatitude: start[0], startLongitude: start[1], endLatitude: target[0], endLongitude: target[1] }) });
                const payload = await response.json(); if (!response.ok) throw new Error(payload.error || 'Die Route konnte derzeit nicht geladen werden.'); route = payload.route; state.routeCache.set(key, route);
            }
            state.map.route(route.coordinates); route.coordinates.forEach(point => points.push([point[1], point[0]])); state.map.fit(points);
            message.textContent = `Strecke: ${Number(route.distanceKm).toLocaleString('de-DE', { maximumFractionDigits: 1 })} km · ca. ${Number(route.durationMinutes)} Min.`; message.className = 'message success';
        } catch (error) { if (state.map) state.map.fit([target, ...(state.location ? [[state.location.latitude, state.location.longitude]] : [])]); message.textContent = 'Die Route konnte derzeit nicht geladen werden.'; message.className = 'message error'; }
    }
    function closeMap() { if (mapDialog.open) mapDialog.close(); const trigger = state.mapTrigger; state.mapTrigger = null; if (trigger?.isConnected) trigger.focus(); }
    function coordinate(latitude, longitude) { return Number.isFinite(latitude) && Number.isFinite(longitude) && latitude >= -90 && latitude <= 90 && longitude >= -180 && longitude <= 180 ? [latitude, longitude] : null; }
    function addressLabel(item) { return [item.street, [item.postalCode, item.city].filter(Boolean).join(' ')].filter(Boolean).join('\n') || '—'; }
    function popup(title, text) { const wrapper = document.createElement('div'), strong = document.createElement('strong'), paragraph = document.createElement('p'); strong.textContent = title; paragraph.textContent = text || '—'; wrapper.append(strong, paragraph); return wrapper; }
    function offerBadge(text) { const chip = document.createElement('span'); chip.className = 'date-chip'; chip.textContent = text; return chip; }
    function messageParagraph(text) { const paragraph = document.createElement('p'); paragraph.textContent = text; return paragraph; }
    function setSaveMessage(text, type = '') { elements.saveMessage.textContent = text; elements.saveMessage.className = `message ${type}`.trim(); }
    function updateSaveState() { elements.save.disabled = state.selectedOrganizationId === null || (!state.allDates && state.selectedDates.size === 0); }
})();
