(() => {
    'use strict';

    const collator = new Intl.Collator('de-DE', { sensitivity: 'base' });
    const weekdays = new Map(['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'].map((day, index) => [day, index]));

    function bind(table, onChange) {
        const state = { key: null, direction: 'ascending' };
        table.querySelectorAll('th[data-sort-key]').forEach(heading => {
            heading.setAttribute('aria-sort', 'none');
            heading.querySelector('.sort-button')?.addEventListener('click', () => {
                const key = heading.dataset.sortKey;
                state.direction = state.key === key && state.direction === 'ascending' ? 'descending' : 'ascending';
                state.key = key;
                updateHeadings(table, state);
                onChange();
            });
        });
        return state;
    }

    function updateHeadings(table, state) {
        table.querySelectorAll('th[data-sort-key]').forEach(heading => {
            const active = heading.dataset.sortKey === state.key;
            heading.setAttribute('aria-sort', active ? state.direction : 'none');
            const indicator = heading.querySelector('.sort-indicator');
            if (indicator) indicator.textContent = active ? (state.direction === 'ascending' ? '▲' : '▼') : '';
        });
    }

    function sort(items, state, fields) {
        if (!state.key || !fields[state.key]) return items;
        const field = fields[state.key];
        return items.map((item, index) => ({ item, index })).sort((left, right) => {
            const compared = compare(field.value(left.item), field.value(right.item), field.type, state.direction);
            return compared || left.index - right.index;
        }).map(entry => entry.item);
    }

    function compare(left, right, type, direction) {
        const leftMissing = missing(left); const rightMissing = missing(right);
        if (leftMissing || rightMissing) return leftMissing === rightMissing ? 0 : leftMissing ? 1 : -1;
        let result = 0;
        if (type === 'number') result = Number(left) - Number(right);
        else if (type === 'weekday') result = (weekdays.get(String(left)) ?? 99) - (weekdays.get(String(right)) ?? 99);
        else if (type === 'time') result = timeValue(left) - timeValue(right);
        else if (type === 'date') result = new Date(left).getTime() - new Date(right).getTime();
        else result = collator.compare(String(left), String(right));
        return direction === 'descending' ? -result : result;
    }

    function timeValue(value) {
        const match = String(value).match(/(\d{1,2}):(\d{2})/);
        return match ? Number(match[1]) * 60 + Number(match[2]) : Number.POSITIVE_INFINITY;
    }

    function missing(value) { return value === null || value === undefined || value === '' || value === '—' || (typeof value === 'number' && !Number.isFinite(value)); }

    window.CrossChappSort = { bind, sort };
})();
