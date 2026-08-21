(() => {
    'use strict';
    const dated = document.querySelector('#dated-representations');
    if (!dated) return;
    const always = document.querySelector('#all-date-representations');
    const alwaysSection = document.querySelector('#all-dates-representations-section');
    const requestChips = document.querySelector('#representation-request-chips');
    const requestMessage = document.querySelector('#representation-request-message');
    const requestDayHint = document.querySelector('#representation-request-day-hint');
    const requestPicker = document.querySelector('#representation-request-picker');
    const homeChapter = document.querySelector('#representation-home-chapter');
    const overview = document.querySelector('#representation-offers-overview');
    const dialog = document.querySelector('#representation-contact-dialog');
    const form = document.querySelector('#representation-contact-form');
    const dateInput = document.querySelector('#representation-contact-date');
    const dateLabel = document.querySelector('#representation-contact-date-label');
    const fixedDate = document.querySelector('#representation-contact-fixed-date');
    const dateOptions = document.querySelector('#representation-contact-date-options');
    const message = document.querySelector('#representation-contact-message');
    const error = document.querySelector('#representation-contact-error');
    const content = document.querySelector('#representation-contact-content');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let payload = null; let selection = null; let trigger = null;
    const requestDatePicker = window.CrossChappDatePicker.create({
        root: requestPicker,
        minDate: localToday(),
        disabled: true,
        onSelect: createRequest,
        invalidMessage: 'Bitte gib ein gültiges Datum im Format TT.MM.JJJJ ein.',
        pastMessage: 'Bitte wähle einen heutigen oder zukünftigen Termin.',
    });

    requestChips.addEventListener('click', deleteRequest);
    initialize();

    async function initialize() {
        try {
            const response = await fetch('/api/representation/find.php', { headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Die Vertretungsangebote konnten nicht geladen werden.');
            payload = data;
            configureRequestPicker(data);
            render(data);
        } catch (cause) {
            payload = null;
            requestChips.replaceChildren(paragraph('Die Vertretungsgesuche konnten nicht geladen werden.'));
            dated.replaceChildren(paragraph(cause instanceof Error ? cause.message : 'Die Vertretungsangebote konnten nicht geladen werden.'));
            always.replaceChildren();
            alwaysSection.hidden = true;
            overview.replaceChildren(paragraph('Die Vertretungsangebote konnten nicht geladen werden.'));
        }
    }

    function render(data) {
        const chapterName = String(data.chapter?.chapterName || '').trim();
        homeChapter.textContent = chapterName ? `Heimatchapter: ${chapterName}` : '';
        homeChapter.hidden = chapterName === '';
        renderRequests(data.requests || []);
        const groups = data.datedOffers || [];
        dated.replaceChildren(...(groups.length ? groups.map(dateGroup) : [paragraph('Aktuell sind keine Angebote hinterlegt.')]));
        const providers = data.allDatesOffers || [];
        always.replaceChildren(...providers.map(provider => providerCard(provider, null)));
        alwaysSection.hidden = providers.length === 0;
        const offers = data.offers || [];
        overview.replaceChildren(...(offers.length ? offers.map(overviewCard) : [paragraph('Aktuell sind keine Angebote hinterlegt.')]));
    }

    function configureRequestPicker(data) {
        const meetingDay = data.chapter?.meetingDay;
        requestDayHint.textContent = meetingDay ? `Dein Chapter trifft sich ${weekdayAdverb(meetingDay)}. Bitte wähle einen ${meetingDay}.` : 'Für dein Heimatchapter ist aktuell kein regelmäßiger Meetingtag hinterlegt.';
        requestPicker.hidden = false;
        requestDatePicker.configure({
            minDate: data.today || localToday(),
            allowedWeekdays: meetingDay ? [weekdayNumber(meetingDay)] : null,
            disabled: !meetingDay,
            weekdayMessage: `Bitte wähle einen ${meetingDay || 'gültigen Meetingtag'}.`,
        });
        requestDatePicker.clear();
    }

    function overviewCard(offer) {
        const card = document.createElement('article'); card.className = 'representation-offer-card';
        const name = document.createElement('h3'); name.textContent = offer.displayName; card.append(name);
        if (offer.isVerified) card.append(verifiedBadge());
        if (offer.isBniMember) { const member = document.createElement('p'); member.className = 'offer-meta'; member.textContent = 'BNI Mitglied'; card.append(member); }
        const availability = document.createElement('div'); availability.className = 'representation-offer-dates';
        if (offer.allDates) availability.append(badge('Immer verfügbar')); else (offer.dates || []).forEach(date => availability.append(badge(formatDate(date)))); card.append(availability);
        card.append(contactButton(offer, offer.allDates ? null : (offer.dates || [])));
        return card;
    }

    function renderRequests(requests) {
        if (!requests.length) { requestChips.replaceChildren(paragraph('Du hast noch keine zukünftigen Vertretungsgesuche gespeichert.')); return; }
        requestChips.replaceChildren(...requests.map(item => {
            const chip = document.createElement('span'); chip.className = 'date-chip';
            const label = document.createElement('span'); label.textContent = formatDate(item.requestDate);
            const remove = document.createElement('button'); remove.type = 'button'; remove.dataset.requestId = item.id; remove.textContent = '×'; remove.setAttribute('aria-label', `${formatDate(item.requestDate)} löschen`);
            chip.append(label, remove); return chip;
        }));
    }

    async function createRequest(value) {
        setRequestMessage(''); requestDatePicker.setDisabled(true);
        try {
            const response = await fetch('/api/representation/requests.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ request_date: value }) });
            const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Das Vertretungsgesuch konnte nicht gespeichert werden.');
            setRequestMessage('Vertretungsgesuch gespeichert.', 'success'); await initialize(); return true;
        } catch (cause) { setRequestMessage(cause instanceof Error ? cause.message : 'Das Vertretungsgesuch konnte nicht gespeichert werden.', 'error'); return false; }
        finally { requestDatePicker.setDisabled(!payload?.chapter?.meetingDay); }
    }

    function weekdayNumber(day){return({Sonntag:0,Montag:1,Dienstag:2,Mittwoch:3,Donnerstag:4,Freitag:5,Samstag:6})[day]??-1;}

    async function deleteRequest(event) {
        const button = event.target.closest('button[data-request-id]'); if (!button) return;
        button.disabled = true; setRequestMessage('');
        try {
            const response = await fetch('/api/representation/requests.php', { method: 'DELETE', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ request_id: Number(button.dataset.requestId) }) });
            const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Das Vertretungsgesuch konnte nicht gelöscht werden.');
            setRequestMessage('Vertretungsgesuch gelöscht.', 'success'); await initialize();
        } catch (cause) { button.disabled = false; setRequestMessage(cause instanceof Error ? cause.message : 'Das Vertretungsgesuch konnte nicht gelöscht werden.', 'error'); }
    }

    function dateGroup(group) {
        const section = document.createElement('section'); section.className = 'representation-date-group';
        const heading = document.createElement('h3'); heading.textContent = formatDate(group.date);
        const grid = document.createElement('div'); grid.className = 'representation-offer-grid';
        const providers = group.providers || [];
        if (providers.length) providers.forEach(provider => grid.append(providerCard(provider, group.date)));
        else grid.append(paragraph('Aktuell kein Vertretungsangebot.'));
        section.append(heading, grid); return section;
    }

    function providerCard(provider, date) {
        const card = document.createElement('article'); card.className = 'representation-provider-card';
        const name = document.createElement('strong'); name.textContent = provider.displayName; card.append(name);
        if (provider.isVerified) card.append(verifiedBadge());
        if (provider.isBniMember) { const member = document.createElement('span'); member.className = 'offer-meta'; member.textContent = 'BNI Mitglied'; card.append(member); }
        card.append(contactButton(provider, date)); return card;
    }
    function contactButton(provider, date, label = 'Kontaktieren') { const button = document.createElement('button'); button.type = 'button'; button.className = 'secondary contact-provider'; button.textContent = label; button.addEventListener('click', () => openContact(provider, date, button)); return button; }

    async function openContact(provider, dateOrDates, button) {
        const dates=Array.isArray(dateOrDates)?dateOrDates:dateOrDates?[dateOrDates]:[]; selection = { offerId: provider.offerId, date: dates.length===1?dates[0]:null, dates, allDates:dateOrDates===null }; trigger = button; error.textContent = '';
        dateOptions.hidden=dates.length<=1; dateOptions.querySelector('div').replaceChildren(...dates.map(date=>{const label=document.createElement('label'),radio=document.createElement('input');radio.type='radio';radio.name='offer_contact_date';radio.value=date;radio.addEventListener('change',()=>{selection.date=date;loadContactPreview();});label.append(radio,document.createTextNode(formatDate(date)));return label;}));
        dateLabel.hidden = !selection.allDates; fixedDate.hidden = dates.length!==1; fixedDate.textContent = dates.length===1 ? `Termin: ${formatDate(dates[0])}` : '';
        dateInput.value = ''; dateInput.min = localToday(); dateInput.onchange=()=>{selection.date=dateInput.value;loadContactPreview();}; dialog.showModal();
        if(selection.date) await loadContactPreview(); else (selection.allDates?dateInput:dateOptions.querySelector('input'))?.focus();
    }
    async function loadContactPreview(){if(!selection?.date)return;try{const response=await fetch('/api/representation/contact-preview.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({offer_id:selection.offerId,requested_date:selection.date})});const result=await response.json();if(!response.ok)throw new Error(result.error);const preview=result.preview;document.querySelector('#representation-contact-hint').textContent=result.hint;document.querySelector('#representation-contact-subject').textContent=`Betreff: ${preview.subject}`;document.querySelector('#representation-contact-before').textContent=preview.before;document.querySelector('#representation-contact-after').textContent=preview.after;message.value=preview.customMessage;message.focus();}catch(cause){error.textContent=cause.message||'Die Vorschau konnte nicht geladen werden.';error.className='message error';}}
    function closeContact() { if (dialog.open) dialog.close(); selection = null; const previous = trigger; trigger = null; if (previous?.isConnected) previous.focus(); }
    document.querySelector('#close-representation-contact').addEventListener('click', closeContact);
    document.querySelector('#cancel-representation-contact').addEventListener('click', closeContact);
    dialog.addEventListener('cancel', event => { event.preventDefault(); closeContact(); });
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (!selection) return; const submit = form.querySelector('button[type="submit"]'); submit.disabled = true; error.textContent = '';
        try {
            if(!selection.date)throw new Error('Bitte wähle einen Termin aus.');
            const response = await fetch('/api/representation/contact.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ offer_id: selection.offerId, requested_date: selection.date, custom_message: message.value }) });
            const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Die Anfrage konnte nicht gesendet werden.');
            content.replaceChildren(paragraph('Anfrage wurde gesendet.'));
        } catch (cause) { error.textContent = cause instanceof Error ? cause.message : 'Die Anfrage konnte nicht gesendet werden.'; error.className = 'message error'; submit.disabled = false; }
    });

    function setRequestMessage(text, type = '') { requestMessage.textContent = text; requestMessage.className = `message ${type}`.trim(); }
    function paragraph(text) { const item = document.createElement('p'); item.textContent = text; return item; }
    function badge(text) { const item = document.createElement('span'); item.className = 'date-chip'; item.textContent = text; return item; }
    function verifiedBadge() { const item=document.createElement('span');item.className='verified-badge';item.textContent='Verifiziert';return item; }
    function weekdayAdverb(day) { return ({ Montag: 'montags', Dienstag: 'dienstags', Mittwoch: 'mittwochs', Donnerstag: 'donnerstags', Freitag: 'freitags', Samstag: 'samstags', Sonntag: 'sonntags' })[day] || `am ${day}`; }
    function formatDate(date) { return new Intl.DateTimeFormat('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'Europe/Berlin' }).format(new Date(`${date}T12:00:00+02:00`)); }
    function localToday() { const now = new Date(); return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`; }
})();
