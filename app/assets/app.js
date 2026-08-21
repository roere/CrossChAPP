(() => {
    'use strict';

    if (!document.querySelector('#source-form')) return;

    const BNI_DETAIL_DELAY_MS = Number(document.querySelector('[data-detail-delay-ms]')?.dataset.detailDelayMs);
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const state = {
        organizations: [], selected: new Set(), expanded: new Set(), details: new Map(), statuses: new Map(),
        batchRunning: false, batchStopRequested: false, users: [], selectedAdminUserId: null,
    };
    const typeLabels = { CHAPTER: 'Chapter', CORE_GROUP: 'Im Aufbau', PLANNED_GROUP: 'Geplant' };
    const countryLabels = { DE: 'Deutschland', AT: 'Österreich' };
    const invitationChapterPicker = window.CrossChappChapterPicker.create({
        list: document.querySelector('#invitation-chapter-results'),
        countryInput: document.querySelector('#invitation-country'),
        searchInput: document.querySelector('#invitation-search'),
        locationInput: document.querySelector('#invitation-location'),
        selectedInput: document.querySelector('#invitation-form').elements.home_chapter_org_id,
        selectedOutput: document.querySelector('#invitation-selected-chapter'),
        clearButton: document.querySelector('#clear-invitation-chapter'),
        radioName: 'invitation_chapter_choice',
        selectedLabelPrefix: 'Ausgewähltes Chapter:',
        emptyLabel: 'Kein Chapter ausgewählt.',
        showAllByDefault: true,
        maxResults: null,
        collapseAfterSelect: false,
        resetFiltersOnClear: true,
    });
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
        automationPanel: document.querySelector('#automation-panel'), automationForm: document.querySelector('#automation-form'),
        usageEnabled: document.querySelector('#usage-refresh-enabled'), usageDays: document.querySelector('#usage-refresh-days'),
        automaticEnabled: document.querySelector('#automatic-refresh-enabled'), automaticDays: document.querySelector('#automatic-refresh-days'),
        mapEnabled: document.querySelector('#map-refresh-enabled'), mapDays: document.querySelector('#map-refresh-days'),
        automaticDailyLimit: document.querySelector('#automatic-refresh-daily-limit'),
        automationMessage: document.querySelector('#automation-message'), automationStats: document.querySelector('#automation-stats'), workerStatus: document.querySelector('#worker-status'),
        miscPanel: document.querySelector('#misc-panel'), mailSettingsForm: document.querySelector('#mail-settings-form'),
        mailSettingsMessage: document.querySelector('#mail-settings-message'), templatesForm: document.querySelector('#email-templates-form'), templatesMessage: document.querySelector('#email-templates-message'),
        legalSettingsForm: document.querySelector('#legal-settings-form'), legalSettingsMessage: document.querySelector('#legal-settings-message'),
        testMailAddress: document.querySelector('#test-mail-address'), sendTestMail: document.querySelector('#send-test-mail'), testMailMessage: document.querySelector('#test-mail-message'),
        usersPanel: document.querySelector('#users-panel'), usersStats: document.querySelector('#users-stats'), usersSearch: document.querySelector('#users-search'), usersStatus: document.querySelector('#users-status-filter'), usersVerification: document.querySelector('#users-verification-filter'), usersChapter: document.querySelector('#users-chapter-filter'), usersMessage: document.querySelector('#users-message'), usersTableWrap: document.querySelector('#users-table-wrap'), usersList: document.querySelector('#users-list'), usersActions: document.querySelector('#users-actions'), usersSelectionHint: document.querySelector('#users-selection-hint'), resetSelectedUser: document.querySelector('#reset-selected-user'), verifySelectedUser: document.querySelector('#verify-selected-user'), deleteSelectedUser: document.querySelector('#delete-selected-user'), resetUserDialog: document.querySelector('#admin-reset-password-dialog'), resetUserConfirmation: document.querySelector('#admin-reset-password-confirmation'), resetUserMessage: document.querySelector('#admin-reset-password-message'), confirmResetUser: document.querySelector('#confirm-admin-reset-password'), cancelResetUser: document.querySelector('#cancel-admin-reset-password'), verifyUserDialog: document.querySelector('#admin-verify-user-dialog'), verifyUserConfirmation: document.querySelector('#admin-verify-user-confirmation'), verifyUserMessage: document.querySelector('#admin-verify-user-message'), confirmVerifyUser: document.querySelector('#confirm-admin-verify-user'), cancelVerifyUser: document.querySelector('#cancel-admin-verify-user'), deleteUserDialog: document.querySelector('#admin-delete-user-dialog'), deleteUserConfirmation: document.querySelector('#admin-delete-user-confirmation'), deleteUserMessage: document.querySelector('#admin-delete-user-message'), confirmDeleteUser: document.querySelector('#confirm-admin-delete-user'), cancelDeleteUser: document.querySelector('#cancel-admin-delete-user'),
        invitationsPanel: document.querySelector('#invitations-panel'), invitationForm: document.querySelector('#invitation-form'), invitationMessage: document.querySelector('#invitation-message'), invitationList: document.querySelector('#invitation-list'), invitationResults: document.querySelector('#invitation-chapter-results'), invitationTemplateForm: document.querySelector('#invitation-template-form'), invitationTemplateMessage: document.querySelector('#invitation-template-message'), cancelInvitationDialog: document.querySelector('#cancel-invitation-dialog'), confirmCancelInvitation: document.querySelector('#confirm-cancel-invitation'), cancelInvitationMessage: document.querySelector('#cancel-invitation-message'),
    };
    const sortState = window.CrossChappSort.bind(document.querySelector('#admin-organization-table'), render);
    const usersSortState = window.CrossChappSort.bind(document.querySelector('#admin-users-table'), renderUsers);
    const sortFields = {
        chapterName: { type: 'string', value: item => state.details.get(item.orgId)?.chapterName },
        orgId: { type: 'number', value: item => item.orgId },
        country: { type: 'string', value: item => countryLabels[item.countryCode] || item.countryCode },
        type: { type: 'string', value: item => typeLabels[item.orgType] || item.orgType },
        city: { type: 'string', value: item => state.details.get(item.orgId)?.city },
        meetingDay: { type: 'weekday', value: item => state.details.get(item.orgId)?.meetingDay },
        meetingTime: { type: 'time', value: item => state.details.get(item.orgId)?.meetingTime },
        detailStatus: { type: 'string', value: item => statusLabel(state.statuses.get(item.orgId) || 'not_loaded') },
        detailsLoadedAt: { type: 'date', value: item => item.detailsLoadedAt },
    };
    const userStatusLabels = { active: 'Aktiv', pending: 'Ausstehend', disabled: 'Deaktiviert' };
    const verificationLabels = { unverified: 'Nicht verifiziert', directory_match: 'BNI-Chapter gefunden', manual_verified: 'Verifiziert' };
    const usersSortFields = {
        name: { type: 'string', value: user => `${user.lastName} ${user.firstName}` }, email: { type: 'string', value: user => user.email },
        chapter: { type: 'string', value: user => user.homeChapterName }, status: { type: 'string', value: user => userStatusLabels[user.status] || user.status },
        verification: { type: 'string', value: user => verificationLabels[user.verificationStatus] || user.verificationStatus },
        offers: { type: 'number', value: user => user.currentOffers }, requests: { type: 'number', value: user => user.currentRequests }, contacts: { type: 'number', value: user => user.contacts30Days }, created: { type: 'date', value: user => user.createdAt },
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
    elements.automationForm.addEventListener('submit', saveAutomationSettings);
    elements.automationPanel.addEventListener('toggle', () => { if (elements.automationPanel.open) loadAutomationStats(); });
    elements.miscPanel.addEventListener('toggle', () => { if (elements.miscPanel.open) loadMailConfiguration(); });
    elements.mailSettingsForm.addEventListener('submit', saveMailSettings);
    elements.templatesForm.addEventListener('submit', saveEmailTemplates);
    elements.legalSettingsForm.addEventListener('submit', saveLegalSettings);
    elements.sendTestMail.addEventListener('click', sendTestMail);
    elements.usersPanel.addEventListener('toggle', () => { if (elements.usersPanel.open && !elements.usersPanel.dataset.loaded) loadUsers(); });
    [elements.usersSearch, elements.usersStatus, elements.usersVerification, elements.usersChapter].forEach(input => input.addEventListener('input', renderUsers));
    elements.usersList.addEventListener('change', selectAdminUser);
    elements.resetSelectedUser.addEventListener('click', event => openResetUserDialog(event.currentTarget));
    elements.verifySelectedUser.addEventListener('click', event => openVerifyUserDialog(event.currentTarget));
    elements.deleteSelectedUser.addEventListener('click', event => openDeleteUserDialog(event.currentTarget));
    elements.confirmResetUser.addEventListener('click', sendAdminPasswordReset); elements.cancelResetUser.addEventListener('click', closeResetUserDialog); document.querySelector('#close-admin-reset-password-icon').addEventListener('click', closeResetUserDialog); elements.resetUserDialog.addEventListener('cancel',event=>{event.preventDefault();closeResetUserDialog();});
    elements.confirmVerifyUser.addEventListener('click', verifyAdminUser); elements.cancelVerifyUser.addEventListener('click', closeVerifyUserDialog); document.querySelector('#close-admin-verify-user-icon').addEventListener('click', closeVerifyUserDialog); elements.verifyUserDialog.addEventListener('cancel',event=>{event.preventDefault();closeVerifyUserDialog();});
    elements.confirmDeleteUser.addEventListener('click', deleteAdminUser); elements.cancelDeleteUser.addEventListener('click', closeDeleteUserDialog); document.querySelector('#close-admin-delete-user-icon').addEventListener('click', closeDeleteUserDialog); elements.deleteUserDialog.addEventListener('cancel',event=>{event.preventDefault();closeDeleteUserDialog();});
    elements.invitationsPanel.addEventListener('toggle', () => { if (elements.invitationsPanel.open && !elements.invitationsPanel.dataset.loaded) loadInvitations(); });
    elements.invitationForm.addEventListener('submit', sendInvitation);
    elements.invitationTemplateForm.addEventListener('submit', saveInvitationTemplate);
    elements.invitationList.addEventListener('click',cancelInvitation);
    elements.confirmCancelInvitation.addEventListener('click', confirmCancelInvitation);
    loadLocal();
    loadAutomationSettings();
    loadInvitations();

    async function loadLocal() {
        try {
            const response = await fetch('/api/bni/local.php');
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Lokale Daten konnten nicht geladen werden.');
            applyOrganizations(payload);
            elements.localMessage.textContent = `${payload.count} Organisationen geladen, ${payload.with_details} mit Detaildaten.`;
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
            const response = await fetch(`/api/bni/map.php?url=${encodeURIComponent(elements.url.value.trim())}`, { headers: { 'X-CSRF-Token': CSRF_TOKEN } });
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
                        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                        body: JSON.stringify({ items: [{ orgId: chapter.orgId }], reload: false }),
                    });
                    const payload = await response.json(); const result = payload.results?.[0];
                    if (response.ok && result?.status === 'loaded') {
                        if (result.skipped) skipped += 1; else successful += 1;
                        if (item && result.details) {
                            Object.assign(item, result.details);
                            state.details.set(item.orgId, item); state.statuses.set(item.orgId, 'loaded');
                        }
                    } else if (result?.status === 'skipped') {
                        skipped += 1;
                        if (item) state.statuses.set(item.orgId, item.detailStatus || 'not_loaded');
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
            if (elements.automationPanel.open) await loadAutomationStats();
        }
    }

    async function loadAutomationSettings() {
        try {
            const response = await fetch('/api/automation/settings.php'); const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Einstellungen konnten nicht geladen werden.');
            const settings = payload.settings;
            elements.usageEnabled.checked = settings.usageRefreshEnabled;
            elements.usageDays.value = settings.usageRefreshDays;
            elements.automaticEnabled.checked = settings.automaticRefreshEnabled;
            elements.automaticDays.value = settings.automaticRefreshDays;
            elements.automaticDailyLimit.value = settings.automaticRefreshDailyLimit;
            elements.mapEnabled.checked = settings.mapRefreshEnabled;
            elements.mapDays.value = settings.mapRefreshDays;
        } catch (error) {
            setAutomationMessage(error.message, 'error');
        }
    }

    async function saveAutomationSettings(event) {
        event.preventDefault();
        const button = document.querySelector('#save-automation'); button.disabled = true;
        try {
            const response = await fetch('/api/automation/settings.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify({
                    usage_refresh_enabled: elements.usageEnabled.checked,
                    usage_refresh_days: Number(elements.usageDays.value),
                    automatic_refresh_enabled: elements.automaticEnabled.checked,
                    automatic_refresh_days: Number(elements.automaticDays.value),
                    automatic_refresh_daily_limit: Number(elements.automaticDailyLimit.value),
                    map_refresh_enabled: elements.mapEnabled.checked,
                    map_refresh_days: Number(elements.mapDays.value),
                }),
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Einstellungen konnten nicht gespeichert werden.');
            setAutomationMessage('Einstellungen gespeichert.', 'success');
            await loadAutomationStats();
        } catch (error) {
            setAutomationMessage(error.message, 'error');
        } finally {
            button.disabled = false;
        }
    }

    async function loadAutomationStats() {
        try {
            const response = await fetch('/api/automation/stats.php'); const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Statistiken konnten nicht geladen werden.');
            renderAutomationStats(payload.statistics);
        } catch (error) {
            setAutomationMessage(error.message, 'error');
        }
    }

    function renderAutomationStats(stats) {
        const fields = [
            ['Letzte automatische Aktualisierung', formatTimestamp(stats.lastAutomaticRefresh)],
            ['Letzte nutzungsabhängige Aktualisierung', formatTimestamp(stats.lastUsageRefresh)],
            ['Automatisch heute', stats.automaticToday], ['Nutzungsabhängig heute', stats.usageToday],
            ['Automatische/nutzungsabhängige Requests heute', `${stats.dailyUsed} / ${stats.dailyLimit}`],
            ['Heute noch verfügbar', stats.dailyRemaining], ['Anteil des Tageslimits', `${stats.dailyPercent} %`],
            ['Aktualisierungen letzte 7 Tage', stats.updatesSevenDays], ['Erfolgreich letzte 7 Tage', stats.successSevenDays],
            ['Fehler letzte 7 Tage', stats.errorsSevenDays], ['Rate-Limit-Stopps letzte 7 Tage', stats.protectionStopsSevenDays],
            ['Letzter 429/403', formatTimestamp(stats.lastProtectionStop)], ['Aktuell stale nach X', stats.staleUsage],
            ['Noch nie geladene Organisationen', stats.automaticNeverLoaded], ['Veraltete Organisationen nach Y', stats.staleAutomatic],
            ['Fehlerhaft / erneut versuchbar', stats.automaticRetryableErrors], ['Für Automatik fällig', stats.automaticDueTotal],
            ['Davon Chapter', stats.automaticDueChapter], ['Davon im Aufbau', stats.automaticDueCoreGroup], ['Davon geplant', stats.automaticDuePlannedGroup],
            ['Organisationen mit Detaildaten', stats.chaptersWithDetails],
            ['Nächster automatischer Prüflauf', formatTimestamp(stats.nextAutomaticCheckAt)],
            ['Grunddatenautomatik', stats.mapRefreshEnabled ? 'EIN' : 'AUS'], ['Grunddatenintervall Z', `${stats.mapRefreshDays} Tage`],
            ['Letzte erfolgreiche Grunddatenaktualisierung', formatTimestamp(stats.lastMapRefreshAt)],
            ['Letzter automatischer Grunddatenversuch', formatTimestamp(stats.lastAutomaticMapAttempt)],
            ['Grunddatenaktualisierungen heute', stats.mapRefreshToday], ['Grunddatenläufe letzte 7 Tage', stats.mapRefreshSevenDays],
            ['Grunddatenfehler letzte 7 Tage', stats.mapErrorsSevenDays], ['Grunddaten 429/403 letzte 7 Tage', stats.mapProtectionStopsSevenDays],
            ['Grunddaten aktuell fällig', stats.mapRefreshDue ? 'Ja' : 'Nein'], ['Nächste Grunddatenfälligkeit', formatTimestamp(stats.nextMapRefreshDueAt)],
        ];
        const fragment = document.createDocumentFragment();
        fields.forEach(([label, value]) => {
            const box = document.createElement('div'); const span = document.createElement('span'); const strong = document.createElement('strong');
            span.textContent = label; strong.textContent = display(value); box.append(span, strong); fragment.append(box);
        });
        elements.automationStats.replaceChildren(fragment);
        if (stats.dailyLimitReached) {
            const warning = document.createElement('p'); warning.className = 'daily-limit-warning'; warning.textContent = 'Tageslimit erreicht';
            elements.automationStats.prepend(warning);
        }
        elements.workerStatus.textContent = stats.workerActive ? 'Hintergrunddienst aktiv' : 'Hintergrunddienst nicht aktiv';
        elements.workerStatus.className = `status-badge ${stats.workerActive ? 'status-loaded' : 'status-error'}`;
    }

    async function loadMailConfiguration() {
        try {
            const [settingsResponse, templatesResponse, legalResponse] = await Promise.all([fetch('/api/admin/mail-settings.php'), fetch('/api/admin/email-templates.php'), fetch('/api/admin/legal-settings.php')]);
            const settingsPayload = await settingsResponse.json(); const templatesPayload = await templatesResponse.json(); const legalPayload = await legalResponse.json();
            if (!settingsResponse.ok || !templatesResponse.ok || !legalResponse.ok) throw new Error('Die Konfiguration konnte nicht geladen werden.');
            const form = elements.mailSettingsForm; const settings = settingsPayload.settings;
            ['smtpHost', 'smtpPort', 'smtpUsername', 'encryption', 'senderEmail', 'senderName', 'baseUrl'].forEach(name => { form.elements[name].value = settings[name] ?? ''; });
            form.elements.smtpPassword.value = ''; form.elements.smtpPassword.placeholder = settings.hasSmtpPassword ? '••••••••' : '';
            const templates = templatesPayload.templates; elements.templatesForm.elements.verify_subject.value = templates.verify_email.subject; elements.templatesForm.elements.verify_body.value = templates.verify_email.body;
            elements.templatesForm.elements.reset_subject.value = templates.reset_password.subject; elements.templatesForm.elements.reset_body.value = templates.reset_password.body;
            elements.templatesForm.elements.contact_hint.value = templatesPayload.contactHint || ''; elements.templatesForm.elements.contact_subject.value = templates.representation_contact.subject; elements.templatesForm.elements.contact_body.value = templates.representation_contact.body;
            elements.templatesForm.elements.request_contact_hint.value = templatesPayload.requestContactHint || ''; elements.templatesForm.elements.request_contact_subject.value = templates.representation_request_contact.subject; elements.templatesForm.elements.request_contact_body.value = templates.representation_request_contact.body;
            elements.templatesForm.elements.offer_custom_message.value = templatesPayload.offerCustomMessage || ''; elements.templatesForm.elements.request_custom_message.value = templatesPayload.requestCustomMessage || '';
            document.querySelectorAll('[data-assignment-mail-template]').forEach(fieldset=>{const key=fieldset.dataset.assignmentMailTemplate;if(!templates[key])return;elements.templatesForm.elements[`${key}_subject`].value=templates[key].subject;elements.templatesForm.elements[`${key}_body`].value=templates[key].body;});
            elements.legalSettingsForm.elements.imprintText.value = legalPayload.settings.imprintText || ''; elements.legalSettingsForm.elements.privacyText.value = legalPayload.settings.privacyText || '';
        } catch (error) { elements.mailSettingsMessage.textContent = error.message; elements.mailSettingsMessage.className = 'message error'; }
    }

    async function saveMailSettings(event) {
        event.preventDefault(); const form = event.currentTarget;
        try { const values = Object.fromEntries(new FormData(form)); values.smtpPort = Number(values.smtpPort); const response = await fetch('/api/admin/mail-settings.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN }, body: JSON.stringify(values) }); const payload = await response.json(); if (!response.ok) throw new Error(payload.error); elements.mailSettingsMessage.textContent = 'E-Mail-Einstellungen gespeichert.'; elements.mailSettingsMessage.className = 'message success'; form.elements.smtpPassword.value = ''; form.elements.smtpPassword.placeholder = payload.settings.hasSmtpPassword ? '••••••••' : ''; }
        catch (error) { elements.mailSettingsMessage.textContent = error.message; elements.mailSettingsMessage.className = 'message error'; }
    }

    async function saveEmailTemplates(event) {
        event.preventDefault(); const form = event.currentTarget;
        const body = { verify_email: { subject: form.elements.verify_subject.value, body: form.elements.verify_body.value }, reset_password: { subject: form.elements.reset_subject.value, body: form.elements.reset_body.value }, representation_contact: { subject: form.elements.contact_subject.value, body: form.elements.contact_body.value }, representation_request_contact: { subject: form.elements.request_contact_subject.value, body: form.elements.request_contact_body.value }, contactHint: form.elements.contact_hint.value, requestContactHint: form.elements.request_contact_hint.value, offerCustomMessage: form.elements.offer_custom_message.value, requestCustomMessage: form.elements.request_custom_message.value };
        document.querySelectorAll('[data-assignment-mail-template]').forEach(fieldset=>{const key=fieldset.dataset.assignmentMailTemplate;body[key]={subject:form.elements[`${key}_subject`].value,body:form.elements[`${key}_body`].value};});
        try { const response = await fetch('/api/admin/email-templates.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN }, body: JSON.stringify(body) }); const payload = await response.json(); if (!response.ok) throw new Error(payload.error); elements.templatesMessage.textContent = 'E-Mail-Vorlagen gespeichert.'; elements.templatesMessage.className = 'message success'; }
        catch (error) { elements.templatesMessage.textContent = error.message; elements.templatesMessage.className = 'message error'; }
    }

    async function saveLegalSettings(event) {
        event.preventDefault(); const values = Object.fromEntries(new FormData(event.currentTarget));
        try { const response = await fetch('/api/admin/legal-settings.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN }, body: JSON.stringify(values) }); const payload = await response.json(); if (!response.ok) throw new Error(payload.error); elements.legalSettingsMessage.textContent = payload.message; elements.legalSettingsMessage.className = 'message success'; }
        catch (error) { elements.legalSettingsMessage.textContent = error.message; elements.legalSettingsMessage.className = 'message error'; }
    }

    async function sendTestMail() {
        elements.sendTestMail.disabled = true;
        try { const response = await fetch('/api/admin/test-email.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN }, body: JSON.stringify({ email: elements.testMailAddress.value }) }); const payload = await response.json(); if (!response.ok) throw new Error(payload.error); elements.testMailMessage.textContent = payload.message; elements.testMailMessage.className = 'message success'; }
        catch (error) { elements.testMailMessage.textContent = error.message; elements.testMailMessage.className = 'message error'; }
        finally { elements.sendTestMail.disabled = false; }
    }

    async function loadInvitations(){if(elements.invitationsPanel.dataset.loading)return;elements.invitationsPanel.dataset.loading='true';try{const [response,chaptersResponse]=await Promise.all([fetch('/api/admin/invitations.php'),fetch('/api/auth/chapters.php')]),payload=await response.json(),chaptersPayload=await chaptersResponse.json();if(!response.ok||!chaptersResponse.ok)throw new Error(payload.error||chaptersPayload.error);invitationChapterPicker.setChapters(chaptersPayload.chapters||[]);elements.invitationTemplateForm.elements.subject.value=payload.template?.subject||'';elements.invitationTemplateForm.elements.body.value=payload.template?.body||'';renderInvitations(payload.invitations||[]);elements.invitationsPanel.dataset.loaded='true';}catch(error){setInvitationMessage(error.message,'error');}finally{delete elements.invitationsPanel.dataset.loading;}}
    async function loadUsers(){if(elements.usersPanel.dataset.loading)return;elements.usersPanel.dataset.loading='true';elements.usersMessage.textContent='Anwender werden geladen …';try{const response=await fetch('/api/admin/users.php'),payload=await response.json();if(!response.ok)throw new Error(payload.error);state.users=payload.users||[];if(!state.users.some(user=>user.userId===state.selectedAdminUserId))state.selectedAdminUserId=null;renderUserStats(payload.stats||{});renderUsers();elements.usersPanel.dataset.loaded='true';}catch(error){elements.usersMessage.textContent=error.message;elements.usersMessage.className='message error';}finally{delete elements.usersPanel.dataset.loading;}}
    function renderUserStats(stats){const values=[['Anwender',stats.total||0],['aktive Anwender',stats.active||0],['verifiziert',stats.verified||0],['mit Heimatchapter',stats.withHomeChapter||0]];elements.usersStats.replaceChildren(...values.map(([label,value])=>{const box=document.createElement('div'),strong=document.createElement('strong'),span=document.createElement('span');box.className='stat user-stat';strong.className='stat-value';span.className='stat-label';strong.textContent=value;span.textContent=label;box.append(strong,document.createTextNode(' '),span);return box;}));}
    function visibleUsers(){const query=elements.usersSearch.value.trim().toLocaleLowerCase('de');const filtered=state.users.filter(user=>{const text=`${user.firstName} ${user.lastName} ${user.email} ${user.homeChapterName||''}`.toLocaleLowerCase('de');return(!query||text.includes(query))&&(!elements.usersStatus.value||user.status===elements.usersStatus.value)&&(!elements.usersVerification.value||user.verificationStatus===elements.usersVerification.value)&&(!elements.usersChapter.value||(elements.usersChapter.value==='yes')===(user.homeChapterName!==null));});return window.CrossChappSort.sort(filtered,usersSortState,usersSortFields);}
    function selectedAdminUser(){return state.users.find(user=>user.userId===state.selectedAdminUserId)||null;}
    function updateUserActions(){const selected=selectedAdminUser(),alreadyVerified=selected?.verificationStatus==='manual_verified',missingChapter=selected&&selected.homeChapterOrgId===null;elements.resetSelectedUser.disabled=!selected;elements.verifySelectedUser.disabled=!selected||alreadyVerified||missingChapter;elements.verifySelectedUser.title=!selected?'':alreadyVerified?'Anwender ist bereits verifiziert.':missingChapter?'Dieser Anwender benötigt zuerst ein Heimatchapter.':'';elements.deleteSelectedUser.disabled=!selected;elements.usersSelectionHint.hidden=!!selected;}
    function renderUsers(){const users=visibleUsers();elements.usersList.replaceChildren(...users.map(user=>{const row=document.createElement('tr'),selected=user.userId===state.selectedAdminUserId,selectCell=document.createElement('td'),radio=document.createElement('input');row.classList.toggle('is-selected',selected);selectCell.className='users-select-cell';radio.type='radio';radio.name='selected_admin_user';radio.value=user.userId;radio.checked=selected;radio.setAttribute('aria-label',`${user.firstName} ${user.lastName} auswählen`);selectCell.append(radio);row.append(selectCell);appendCell(row,`${user.firstName} ${user.lastName}`);appendCell(row,user.email);appendCell(row,user.homeChapterName);appendCell(row,userStatusLabels[user.status]||user.status);appendCell(row,verificationLabels[user.verificationStatus]||user.verificationStatus);appendCell(row,user.currentOffers,'users-column-number users-number');appendCell(row,user.currentRequests,'users-column-number users-number');appendCell(row,user.contacts30Days,'users-column-contacts users-number');appendCell(row,user.emailVerified?'Ja':'Nein','users-column-verified');appendCell(row,formatTimestamp(user.createdAt),'users-column-created');return row;}));elements.usersTableWrap.hidden=users.length===0;elements.usersActions.hidden=state.users.length===0;elements.usersMessage.textContent=users.length===0?'Noch keine Anwender vorhanden.':`${users.length} von ${state.users.length} Anwendern sichtbar.`;elements.usersMessage.className='message';updateUserActions();}
    function selectAdminUser(event){const radio=event.target.closest('input[name=selected_admin_user]');if(!radio)return;state.selectedAdminUserId=Number(radio.value);renderUsers();}
    function openResetUserDialog(trigger){const user=selectedAdminUser();if(!user)return;elements.resetUserDialog.dataset.triggerId=user.userId;elements.resetUserConfirmation.hidden=false;elements.resetUserConfirmation.querySelector('[data-admin-user-name]').textContent=`${user.firstName} ${user.lastName}`;elements.resetUserConfirmation.querySelector('[data-admin-user-email]').textContent=user.email;elements.resetUserMessage.textContent='';elements.confirmResetUser.hidden=false;elements.cancelResetUser.textContent='Abbrechen';elements.resetUserDialog.showModal();elements.confirmResetUser.focus();elements.resetUserDialog._trigger=trigger;}
    function closeResetUserDialog(){elements.resetUserDialog.close();elements.resetUserDialog._trigger?.focus();}
    async function sendAdminPasswordReset(){const user=selectedAdminUser();if(!user)return;elements.confirmResetUser.disabled=true;try{const response=await fetch('/api/admin/user-password-reset.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},body:JSON.stringify({userId:user.userId})}),payload=await response.json();if(!response.ok)throw new Error(payload.message||payload.error);elements.resetUserConfirmation.hidden=true;elements.resetUserMessage.textContent=payload.message;elements.resetUserMessage.className='message success';elements.confirmResetUser.hidden=true;elements.cancelResetUser.textContent='Schließen';}catch(error){elements.resetUserMessage.textContent=error.message;elements.resetUserMessage.className='message error';}finally{elements.confirmResetUser.disabled=false;}}
    function openVerifyUserDialog(trigger){const user=selectedAdminUser();if(!user||elements.verifySelectedUser.disabled)return;elements.verifyUserConfirmation.hidden=false;elements.verifyUserConfirmation.querySelector('[data-admin-user-name]').textContent=`${user.firstName} ${user.lastName}`;elements.verifyUserMessage.textContent='';elements.confirmVerifyUser.hidden=false;elements.cancelVerifyUser.textContent='Abbrechen';elements.verifyUserDialog.showModal();elements.confirmVerifyUser.focus();elements.verifyUserDialog._trigger=trigger;}
    function closeVerifyUserDialog(){elements.verifyUserDialog.close();elements.verifyUserDialog._trigger?.focus();}
    async function verifyAdminUser(){const user=selectedAdminUser();if(!user)return;elements.confirmVerifyUser.disabled=true;try{const response=await fetch('/api/admin/user-verify.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},body:JSON.stringify({userId:user.userId})}),payload=await response.json();if(!response.ok)throw new Error(payload.message||payload.error);elements.verifyUserConfirmation.hidden=true;elements.verifyUserMessage.textContent=payload.message;elements.verifyUserMessage.className='message success';elements.confirmVerifyUser.hidden=true;elements.cancelVerifyUser.textContent='Schließen';delete elements.usersPanel.dataset.loaded;await loadUsers();}catch(error){elements.verifyUserMessage.textContent=error.message;elements.verifyUserMessage.className='message error';}finally{elements.confirmVerifyUser.disabled=false;}}
    function openDeleteUserDialog(trigger){const user=selectedAdminUser();if(!user)return;elements.deleteUserConfirmation.hidden=false;elements.deleteUserConfirmation.querySelector('[data-admin-user-name]').textContent=`${user.firstName} ${user.lastName}`;elements.deleteUserMessage.textContent='';elements.confirmDeleteUser.hidden=false;elements.cancelDeleteUser.textContent='Abbrechen';elements.deleteUserDialog.showModal();elements.confirmDeleteUser.focus();elements.deleteUserDialog._trigger=trigger;}
    function closeDeleteUserDialog(){elements.deleteUserDialog.close();elements.deleteUserDialog._trigger?.focus();}
    async function deleteAdminUser(){const user=selectedAdminUser();if(!user)return;elements.confirmDeleteUser.disabled=true;try{const response=await fetch('/api/admin/users.php',{method:'DELETE',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},body:JSON.stringify({userId:user.userId})}),payload=await response.json();if(!response.ok)throw new Error(payload.error);state.selectedAdminUserId=null;elements.deleteUserConfirmation.hidden=true;elements.deleteUserMessage.textContent=payload.message;elements.deleteUserMessage.className='message success';elements.confirmDeleteUser.hidden=true;elements.cancelDeleteUser.textContent='Schließen';delete elements.usersPanel.dataset.loaded;await loadUsers();}catch(error){elements.deleteUserMessage.textContent=error.message;elements.deleteUserMessage.className='message error';}finally{elements.confirmDeleteUser.disabled=false;}}
    async function saveInvitationTemplate(event){event.preventDefault();const body=Object.fromEntries(new FormData(event.currentTarget));try{const response=await fetch('/api/admin/invitations.php',{method:'PUT',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},body:JSON.stringify(body)}),payload=await response.json();if(!response.ok)throw new Error(payload.error);elements.invitationTemplateMessage.textContent='Einladungstext wurde gespeichert.';elements.invitationTemplateMessage.className='message success';}catch(error){elements.invitationTemplateMessage.textContent=error.message;elements.invitationTemplateMessage.className='message error';}}
    async function sendInvitation(event){event.preventDefault();const form=event.currentTarget,values=Object.fromEntries(new FormData(form));if(!values.home_chapter_org_id){setInvitationMessage('Bitte wähle ein Chapter.','error');return;}try{const response=await fetch('/api/admin/invitations.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},body:JSON.stringify(values)}),payload=await response.json();if(!response.ok)throw new Error(payload.error);form.reset();invitationChapterPicker.clear();setInvitationMessage('Einladung wurde versendet.','success');loadInvitations();}catch(error){setInvitationMessage(error.message,'error');}}
    function cancelInvitation(event){const button=event.target.closest('button[data-invitation-id]');if(!button)return;elements.cancelInvitationDialog.dataset.invitationId=button.dataset.invitationId;elements.cancelInvitationMessage.textContent='';elements.cancelInvitationDialog.showModal();}
    async function confirmCancelInvitation(){const id=Number(elements.cancelInvitationDialog.dataset.invitationId);elements.confirmCancelInvitation.disabled=true;try{const response=await fetch('/api/admin/invitations.php',{method:'DELETE',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},body:JSON.stringify({id})}),payload=await response.json();if(!response.ok)throw new Error(payload.error);elements.cancelInvitationDialog.close();loadInvitations();}catch(error){elements.cancelInvitationMessage.textContent=error.message;elements.cancelInvitationMessage.className='message error';}finally{elements.confirmCancelInvitation.disabled=false;}}
    function renderInvitations(items){elements.invitationList.replaceChildren(...items.map(item=>{const row=document.createElement('tr');[`${item.firstName} ${item.lastName}`,item.email,item.chapterName,formatTimestamp(item.sentAt),formatTimestamp(item.expiresAt),item.status].forEach(value=>appendCell(row,value));const cell=document.createElement('td'),button=document.createElement('button');button.type='button';button.className='secondary';button.dataset.invitationId=item.id;button.textContent='Widerrufen';cell.append(button);row.append(cell);return row;}));}
    function setInvitationMessage(text,type=''){elements.invitationMessage.textContent=text;elements.invitationMessage.className=`message ${type}`;}

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
        const filtered = state.organizations.filter(item => {
            const name = state.details.get(item.orgId)?.chapterName || '';
            return (!elements.country.value || item.countryCode === elements.country.value)
                && (!elements.type.value || item.orgType === elements.type.value)
                && (!elements.detail.value || (state.statuses.get(item.orgId) || 'not_loaded') === elements.detail.value)
                && (!query || String(item.orgId).includes(query) || name.toLocaleLowerCase('de').includes(query));
        });
        return window.CrossChappSort.sort(filtered, sortState, sortFields);
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
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ items: [{ orgId: item.orgId }], reload: elements.reloadDetails.checked }),
                });
                const payload = await response.json(); const result = payload.results?.[0];
                if (response.ok && result?.status === 'loaded') {
                    Object.assign(item, result.details);
                    state.details.set(item.orgId, item); state.statuses.set(item.orgId, 'loaded');
                } else if (result?.status === 'skipped') {
                    state.statuses.set(item.orgId, item.detailStatus || 'not_loaded');
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
    function setAutomationMessage(text, type = '') { elements.automationMessage.textContent = text; elements.automationMessage.className = `message ${type}`; }
    function wait(milliseconds) { return new Promise(resolve => window.setTimeout(resolve, milliseconds)); }
})();
