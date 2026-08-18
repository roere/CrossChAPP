(() => {
    'use strict';
    const list = document.querySelector('#available-representations');
    if (!list) return;
    fetch('/api/representation/find.php').then(async response => {
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.error || 'Die Vertretungsangebote konnten nicht geladen werden.');
        document.querySelector('#representation-home-chapter').textContent = payload.homeChapterName || '—';
        const offers = Array.isArray(payload.offers) ? payload.offers : [];
        if (!offers.length) { list.replaceChildren(paragraph('Aktuell sind keine Vertretungsangebote für dein Chapter hinterlegt.')); return; }
        list.replaceChildren(...offers.map(renderOffer));
    }).catch(error => list.replaceChildren(paragraph(error.message)));

    function renderOffer(offer) {
        const card = document.createElement('article'); card.className = 'representation-offer-card';
        const name = document.createElement('h3'); name.textContent = offer.providerName;
        const availability = document.createElement('div'); availability.className = 'representation-offer-dates';
        if (offer.allDates) availability.append(badge('Immer verfügbar'));
        else offer.dates.forEach(date => availability.append(badge(formatDate(date))));
        card.append(name, availability);
        if (offer.providerHomeChapter) { const home = document.createElement('p'); home.className = 'offer-meta'; home.textContent = `Heimatchapter: ${offer.providerHomeChapter}`; card.append(home); }
        return card;
    }
    function badge(text) { const item = document.createElement('span'); item.className = 'date-chip'; item.textContent = text; return item; }
    function paragraph(text) { const item = document.createElement('p'); item.textContent = text; return item; }
    function formatDate(date) { return new Intl.DateTimeFormat('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'Europe/Berlin' }).format(new Date(`${date}T12:00:00+02:00`)); }
})();
