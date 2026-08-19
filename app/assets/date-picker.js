(() => {
    'use strict';

    const weekdays = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];

    function create(options) {
        const root = options.root;
        if (!root) throw new Error('DatePicker benötigt ein Wurzelelement.');
        const input = root.querySelector('[data-date-picker-input]');
        const trigger = root.querySelector('[data-date-picker-trigger]');
        const calendar = root.querySelector('[data-date-picker-calendar]');
        const monthLabel = root.querySelector('[data-date-picker-month]');
        const body = root.querySelector('tbody');
        const previous = root.querySelector('[data-date-picker-previous]');
        const next = root.querySelector('[data-date-picker-next]');
        const error = root.querySelector('[data-date-picker-error]');
        let settings = { minDate: localToday(), allowedWeekdays: null, disabled: false, ...options };
        let view = null;
        let committing = false;

        trigger.addEventListener('click', toggle);
        previous.addEventListener('click', () => changeMonth(-1));
        next.addEventListener('click', () => changeMonth(1));
        body.addEventListener('click', selectCalendarDate);
        calendar.addEventListener('keydown', navigateCalendar);
        input.addEventListener('input', validateCompleteInput);
        input.addEventListener('blur', () => { if (input.value.trim()) commitInput(); });
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter') { event.preventDefault(); commitInput(); }
            if (event.key === 'ArrowDown' && event.altKey) { event.preventDefault(); open(); }
        });
        document.addEventListener('click', event => { if (!root.contains(event.target)) close(false); });
        document.addEventListener('keydown', event => { if (event.key === 'Escape' && !calendar.hidden) { event.preventDefault(); close(true); } });
        applyState();

        function configure(nextSettings) { settings = { ...settings, ...nextSettings }; applyState(); }
        function applyState() { input.disabled = Boolean(settings.disabled); trigger.disabled = Boolean(settings.disabled); if (settings.disabled) close(false); }
        function toggle() { calendar.hidden ? open() : close(false); }
        function open() {
            if (settings.disabled) return;
            const start = parseIso(input.dataset.isoValue || settings.minDate) || parseIso(settings.minDate) || new Date();
            view = new Date(Date.UTC(start.getUTCFullYear(), start.getUTCMonth(), 1));
            renderCalendar(); calendar.hidden = false; trigger.setAttribute('aria-expanded', 'true');
            window.setTimeout(() => calendar.querySelector('button[data-date]:not(:disabled)')?.focus(), 0);
        }
        function close(restoreFocus) { calendar.hidden = true; trigger.setAttribute('aria-expanded', 'false'); if (restoreFocus) trigger.focus(); }
        function changeMonth(offset) { view = new Date(Date.UTC(view.getUTCFullYear(), view.getUTCMonth() + offset, 1)); renderCalendar(); calendar.querySelector('button[data-date]:not(:disabled)')?.focus(); }
        function renderCalendar() {
            const year = view.getUTCFullYear(), month = view.getUTCMonth(), first = new Date(Date.UTC(year, month, 1));
            const leading = (first.getUTCDay() + 6) % 7, days = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
            monthLabel.textContent = new Intl.DateTimeFormat('de-DE', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(first);
            body.replaceChildren(); let row = document.createElement('tr');
            for (let index = 0; index < leading; index += 1) row.append(document.createElement('td'));
            for (let day = 1; day <= days; day += 1) {
                if (row.children.length === 7) { body.append(row); row = document.createElement('tr'); }
                const date = new Date(Date.UTC(year, month, day)), iso = isoDate(date), cell = document.createElement('td'), button = document.createElement('button');
                button.type = 'button'; button.dataset.date = iso; button.textContent = String(day); button.setAttribute('aria-label', formatLong(iso));
                const enabled = isAllowed(iso); button.disabled = !enabled; button.setAttribute('aria-disabled', String(!enabled));
                if (iso === settings.minDate) button.setAttribute('aria-current', 'date');
                cell.append(button); row.append(cell);
            }
            while (row.children.length < 7) row.append(document.createElement('td')); body.append(row);
            const minimum = parseIso(settings.minDate);
            previous.disabled = year < minimum.getUTCFullYear() || (year === minimum.getUTCFullYear() && month <= minimum.getUTCMonth());
        }
        function selectCalendarDate(event) { const button = event.target.closest('button[data-date]'); if (!button || button.disabled) return; close(true); commit(button.dataset.date); }
        function navigateCalendar(event) {
            const button = event.target.closest('button[data-date]'), offsets = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
            if (!button || !(event.key in offsets)) return; event.preventDefault();
            const candidate = parseIso(button.dataset.date); candidate.setUTCDate(candidate.getUTCDate() + offsets[event.key]);
            calendar.querySelector(`button[data-date="${isoDate(candidate)}"]:not(:disabled)`)?.focus();
        }
        function validateCompleteInput() {
            clearError(); const value = input.value.trim();
            if (/^\d{2}\.\d{2}\.\d{4}$/.test(value) || /^\d{4}-\d{2}-\d{2}$/.test(value)) commitInput();
        }
        function commitInput() { const iso = normalize(input.value); if (!iso) return fail(settings.invalidMessage || 'Bitte gib ein gültiges Datum im Format TT.MM.JJJJ ein.'); commit(iso); }
        async function commit(iso) {
            if (committing) return;
            const validation = validationError(iso); if (validation) return fail(validation);
            clearError(); input.value = formatInput(iso); input.dataset.isoValue = iso; committing = true;
            try { const accepted = await settings.onSelect?.(iso); if (accepted !== false && settings.clearAfterSelect !== false) clear(); }
            finally { committing = false; }
        }
        function validationError(iso) {
            if (!parseIso(iso)) return settings.invalidMessage || 'Bitte gib ein gültiges Datum im Format TT.MM.JJJJ ein.';
            if (iso < settings.minDate) return settings.pastMessage || 'Bitte wähle einen heutigen oder zukünftigen Termin.';
            if (!isAllowed(iso)) return typeof settings.weekdayMessage === 'function' ? settings.weekdayMessage(iso) : (settings.weekdayMessage || `Bitte wähle einen ${weekdays[settings.allowedWeekdays?.[0]]}.`);
            return '';
        }
        function isAllowed(iso) { const date = parseIso(iso); return Boolean(date) && iso >= settings.minDate && (!settings.allowedWeekdays || settings.allowedWeekdays.includes(date.getUTCDay())); }
        function fail(message) { input.setAttribute('aria-invalid', 'true'); error.textContent = message; error.hidden = false; settings.onError?.(message); return false; }
        function clearError() { input.setAttribute('aria-invalid', 'false'); error.textContent = ''; error.hidden = true; }
        function clear() { input.value = ''; delete input.dataset.isoValue; clearError(); }
        function setDisabled(disabled) { configure({ disabled }); }
        return { configure, clear, setDisabled, open, input };
    }

    function normalize(value) {
        const trimmed = value.trim(); let year, month, day;
        let match = /^(\d{2})\.(\d{2})\.(\d{4})$/.exec(trimmed);
        if (match) [, day, month, year] = match;
        else { match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(trimmed); if (!match) return null; [, year, month, day] = match; }
        const iso = `${year}-${month}-${day}`; return parseIso(iso) ? iso : null;
    }
    function parseIso(value) { const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || ''); if (!match) return null; const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]))); return isoDate(date) === value ? date : null; }
    function isoDate(date) { return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`; }
    function formatInput(iso) { const [year, month, day] = iso.split('-'); return `${day}.${month}.${year}`; }
    function formatLong(iso) { return new Intl.DateTimeFormat('de-DE', { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' }).format(parseIso(iso)); }
    function localToday() { const now = new Date(); return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`; }
    window.CrossChappDatePicker = { create, normalize };
})();
