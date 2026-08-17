(() => {
    'use strict';

    document.querySelector('#login-form')?.addEventListener('submit', login);
    document.querySelector('#logout-button')?.addEventListener('click', logout);
    document.querySelector('#chapter-search-form')?.addEventListener('submit', searchChapters);

    if (document.querySelector('#data-basis')) loadDataBasis();

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
        };
        message.textContent = 'Ort wird gesucht und Entfernung berechnet …'; message.className = 'message'; button.disabled = true;
        try {
            const response = await fetch('/api/search.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Die Suche konnte nicht ausgeführt werden.');
            renderResults(result);
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
        section.hidden = false;
        heading.textContent = payload.count === 0
            ? 'Für diese Auswahl wurden keine passenden Chapter gefunden.'
            : `${payload.count} passende Chapter gefunden.`;
        around.textContent = `Suche rund um ${payload.location.label}`;
        const fragment = document.createDocumentFragment();
        payload.results.forEach(chapter => fragment.append(resultCard(chapter)));
        list.replaceChildren(fragment);
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function resultCard(chapter) {
        const article = document.createElement('article'); article.className = 'result-card';
        const header = document.createElement('div'); header.className = 'result-card-header';
        const title = document.createElement('h3'); title.textContent = chapter.chapterName || '—';
        const distance = document.createElement('strong'); distance.className = 'distance'; distance.textContent = `${formatNumber(chapter.distanceKm)} km`;
        header.append(title, distance);

        const facts = document.createElement('dl'); facts.className = 'result-facts';
        [
            ['Ort', chapter.city], ['Treffpunkt', chapter.venue], ['Wochentag', chapter.meetingDay],
            ['Uhrzeit', chapter.meetingTime ? `${chapter.meetingTime} Uhr` : null], ['Meetingtyp', chapter.meetingType],
            ['Mitglieder', chapter.memberCount],
        ].forEach(([label, value]) => appendFact(facts, label, value));

        const actions = document.createElement('div'); actions.className = 'result-actions';
        appendExternalAction(actions, chapter.chapterUrl, 'Chapter ansehen', 'secondary');
        appendExternalAction(actions, chapter.visitorRegistrationUrl, 'Als Besucher anmelden', 'primary');
        article.append(header, facts); if (actions.childElementCount) article.append(actions);
        return article;
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

    function formatNumber(value) {
        return new Intl.NumberFormat('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value);
    }
})();
