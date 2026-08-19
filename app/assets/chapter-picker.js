(() => {
    'use strict';

    const countryNames = { DE: 'Deutschland', AT: 'Österreich' };
    const normalize = value => String(value || '').trim().toLocaleLowerCase('de');

    class ChapterPicker {
        constructor(options) {
            this.list = options.list;
            this.countryInput = options.countryInput || null;
            this.searchInput = options.searchInput || null;
            this.locationInput = options.locationInput || null;
            this.selectedInput = options.selectedInput;
            this.selectedOutput = options.selectedOutput;
            this.clearButton = options.clearButton || null;
            this.radioName = options.radioName;
            this.selectedLabelPrefix = options.selectedLabelPrefix || 'Ausgewähltes Chapter:';
            this.emptyLabel = options.emptyLabel || 'Kein Chapter ausgewählt.';
            this.onSelect = options.onSelect || (() => {});
            this.showAllByDefault = options.showAllByDefault === true;
            this.maxResults = options.maxResults === null ? null : (Number(options.maxResults) || 20);
            this.collapseAfterSelect = options.collapseAfterSelect !== false;
            this.resetFiltersOnClear = options.resetFiltersOnClear === true;
            this.chapters = [];
            this.guidance = this.list.closest('[data-chapter-picker]')?.querySelector('[data-chapter-picker-guidance]') || null;

            [this.countryInput, this.searchInput, this.locationInput].filter(Boolean)
                .forEach(input => input.addEventListener('input', () => this.render()));
            this.list.addEventListener('change', event => this.selectFromEvent(event));
            this.clearButton?.addEventListener('click', () => this.clear());
        }

        setChapters(chapters) {
            this.chapters = (Array.isArray(chapters) ? chapters : []).filter(item =>
                Number.isInteger(Number(item.orgId))
                && typeof item.chapterName === 'string'
                && item.chapterName.trim() !== ''
            );
            this.render();
        }

        setSelectedOrgId(orgId) {
            this.selectedInput.value = orgId === null || orgId === undefined ? '' : String(orgId);
            const selected = this.chapters.find(item => String(item.orgId) === this.selectedInput.value);
            this.selectedOutput.textContent = selected
                ? `${this.selectedLabelPrefix} ${selected.chapterName}`
                : this.emptyLabel;
            this.render();
        }

        clear() {
            if (this.resetFiltersOnClear) {
                [this.countryInput, this.searchInput, this.locationInput].filter(Boolean).forEach(input => { input.value = ''; });
            }
            this.setSelectedOrgId('');
            this.onSelect(null);
        }

        visibleChapters() {
            const country = this.countryInput?.value || '';
            const search = normalize(this.searchInput?.value);
            const location = normalize(this.locationInput?.value);
            const matches = this.chapters.filter(item => {
                const all = normalize([item.chapterName, item.city, item.postalCode, item.region].filter(Boolean).join(' '));
                const place = normalize([item.city, item.postalCode].filter(Boolean).join(' '));
                return (!country || item.countryCode === country)
                    && (!search || all.includes(search))
                    && (!location || place.includes(location));
            });
            return this.maxResults === null ? matches : matches.slice(0, this.maxResults);
        }

        render() {
            const hasFilter = Boolean(this.countryInput?.value || normalize(this.searchInput?.value) || normalize(this.locationInput?.value));
            if (!this.showAllByDefault && !hasFilter) {
                this.list.replaceChildren();
                this.list.hidden = true;
                if (this.guidance) this.guidance.hidden = false;
                return;
            }
            const selectedOrgId = this.selectedInput.value;
            this.list.replaceChildren(...this.visibleChapters().map(item => this.createItem(item, selectedOrgId)));
            this.list.hidden = false;
            if (this.guidance) this.guidance.hidden = true;
        }

        createItem(item, selectedOrgId) {
            const label = document.createElement('label');
            label.className = 'chapter-picker-item';
            const radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = this.radioName;
            radio.value = item.orgId;
            radio.checked = String(item.orgId) === selectedOrgId;
            const copy = document.createElement('span');
            copy.className = 'chapter-picker-copy';
            const name = document.createElement('strong');
            name.textContent = item.chapterName;
            const meta = document.createElement('small');
            meta.className = 'chapter-picker-meta';
            meta.textContent = [item.postalCode, item.city, item.region, countryNames[item.countryCode] || item.countryCode]
                .filter(Boolean).join(' · ');
            copy.append(name, meta);
            label.append(radio, copy);
            return label;
        }

        selectFromEvent(event) {
            const radio = event.target.closest('input[type="radio"]');
            if (!radio || !this.list.contains(radio)) return;
            const chapter = this.chapters.find(item => String(item.orgId) === radio.value);
            if (!chapter) return;
            this.selectedInput.value = String(chapter.orgId);
            this.selectedOutput.textContent = `${this.selectedLabelPrefix} ${chapter.chapterName}`;
            this.list.hidden = this.collapseAfterSelect;
            if (this.guidance) this.guidance.hidden = true;
            this.onSelect(chapter);
        }
    }

    window.CrossChappChapterPicker = {
        create: options => new ChapterPicker(options),
        countryName: code => countryNames[code] || code || '',
    };
})();
