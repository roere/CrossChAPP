(() => {
    'use strict';

    if (!document.querySelector('#users-panel')) return;

    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const FULL_ADMIN = document.querySelector('meta[name="auth-role"]')?.content === 'admin';

    const state = {
        organizations: [], selected: new Set(), expanded: new Set(), details: new Map(), statuses: new Map(),
        batchRunning: false, batchStopRequested: false, users: [], selectedAdminUserId: null, pendingRoleChange: null,
        textTemplates: [], selectedTextTemplateKey: null, textTemplateDirty: false, pendingTextTemplateKey: null, pendingInvitationOverride: null, pendingInvitationResend: null,
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
        mailSettingsMessage: document.querySelector('#mail-settings-message'), templatesForm: document.querySelector('#email-templates-form'), templatesMessage: document.querySelector('#email-templates-message'), templateSelection: document.querySelector('.text-template-selection'), templateSearch: document.querySelector('#text-template-search'), templateList: document.querySelector('#text-template-list'), templateCategory: document.querySelector('#text-template-category'), templateHeading: document.querySelector('#text-template-editor-heading'), templateKey: document.querySelector('#text-template-editor-key'), templateSubjectLabel: document.querySelector('#text-template-subject-label'), templateSubject: document.querySelector('#text-template-subject'), templateBody: document.querySelector('#text-template-body'), templatePlaceholders: document.querySelector('#text-template-placeholders'), templateDirty: document.querySelector('#text-template-dirty'), templateDirtyDialog: document.querySelector('#text-template-dirty-dialog'), templateSaveSwitch: document.querySelector('#text-template-save-switch'), templateDiscardSwitch: document.querySelector('#text-template-discard-switch'), templateCancelSwitch: document.querySelector('#text-template-cancel-switch'),
        legalSettingsForm: document.querySelector('#legal-settings-form'), legalSettingsMessage: document.querySelector('#legal-settings-message'),
        reportsPanel: document.querySelector('#reports-panel'), userErrorTableWrap:document.querySelector('#user-error-table-wrap'),userErrorList:document.querySelector('#user-error-list'),userErrorEmpty:document.querySelector('#user-error-empty'),userErrorMessage:document.querySelector('#user-error-message'), performanceWindow: document.querySelector('#bni-performance-window'), performanceStats: document.querySelector('#bni-performance-stats'), performanceChart: document.querySelector('#bni-performance-chart'), performanceLine: document.querySelector('#bni-performance-line'), performanceYMax: document.querySelector('#bni-performance-y-max'), performanceStart: document.querySelector('#bni-performance-start'), performanceEnd: document.querySelector('#bni-performance-end'), performanceMessage: document.querySelector('#bni-performance-message'),
        testMailAddress: document.querySelector('#test-mail-address'), sendTestMail: document.querySelector('#send-test-mail'), testMailMessage: document.querySelector('#test-mail-message'),
        usersPanel: document.querySelector('#users-panel'), usersStats: document.querySelector('#users-stats'), usersSearch: document.querySelector('#users-search'), usersStatus: document.querySelector('#users-status-filter'), usersVerification: document.querySelector('#users-verification-filter'), usersChapter: document.querySelector('#users-chapter-filter'), usersMessage: document.querySelector('#users-message'), usersTableWrap: document.querySelector('#users-table-wrap'), usersList: document.querySelector('#users-list'), usersActions: document.querySelector('#users-actions'), usersSelectionHint: document.querySelector('#users-selection-hint'), resetSelectedUser: document.querySelector('#reset-selected-user'), verifySelectedUser: document.querySelector('#verify-selected-user'), deleteSelectedUser: document.querySelector('#delete-selected-user'), resetUserDialog: document.querySelector('#admin-reset-password-dialog'), resetUserConfirmation: document.querySelector('#admin-reset-password-confirmation'), resetUserMessage: document.querySelector('#admin-reset-password-message'), confirmResetUser: document.querySelector('#confirm-admin-reset-password'), cancelResetUser: document.querySelector('#cancel-admin-reset-password'), verifyUserDialog: document.querySelector('#admin-verify-user-dialog'), verifyUserConfirmation: document.querySelector('#admin-verify-user-confirmation'), verifyUserMessage: document.querySelector('#admin-verify-user-message'), confirmVerifyUser: document.querySelector('#confirm-admin-verify-user'), cancelVerifyUser: document.querySelector('#cancel-admin-verify-user'), roleDialog: document.querySelector('#admin-user-role-dialog'), roleHeading: document.querySelector('#admin-user-role-heading'), roleConfirmation: document.querySelector('#admin-user-role-confirmation'), roleMessage: document.querySelector('#admin-user-role-message'), confirmRole: document.querySelector('#confirm-admin-user-role'), cancelRole: document.querySelector('#cancel-admin-user-role'), deleteUserDialog: document.querySelector('#admin-delete-user-dialog'), deleteUserConfirmation: document.querySelector('#admin-delete-user-confirmation'), deleteUserMessage: document.querySelector('#admin-delete-user-message'), confirmDeleteUser: document.querySelector('#confirm-admin-delete-user'), cancelDeleteUser: document.querySelector('#cancel-admin-delete-user'),
        invitationsPanel: document.querySelector('#invitations-panel'), invitationForm: document.querySelector('#invitation-form'), invitationMessage: document.querySelector('#invitation-message'), invitationList: document.querySelector('#invitation-list'), invitationResults: document.querySelector('#invitation-chapter-results'), invitationTemplateForm: document.querySelector('#invitation-template-form'), invitationTemplateMessage: document.querySelector('#invitation-template-message'), invitationVerificationDialog: document.querySelector('#invitation-verification-dialog'), invitationVerificationHeading: document.querySelector('#invitation-verification-heading'), invitationVerificationDetail: document.querySelector('#invitation-verification-detail'), confirmInvitationOverride: document.querySelector('#confirm-invitation-override'), cancelInvitationOverride: document.querySelector('#cancel-invitation-override'), cancelInvitationDialog: document.querySelector('#cancel-invitation-dialog'), confirmCancelInvitation: document.querySelector('#confirm-cancel-invitation'), cancelInvitationMessage: document.querySelector('#cancel-invitation-message'), resendInvitationDialog: document.querySelector('#resend-invitation-dialog'), resendInvitationConfirmation: document.querySelector('#resend-invitation-confirmation'), resendInvitationMessage: document.querySelector('#resend-invitation-message'), confirmResendInvitation: document.querySelector('#confirm-resend-invitation'), cancelResendInvitation: document.querySelector('#cancel-resend-invitation'),
    };
    const sortState = FULL_ADMIN ? window.CrossChappSort.bind(document.querySelector('#admin-organization-table'), render) : { key: '', direction: 'asc' };
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

    if(FULL_ADMIN){
        elements.form.addEventListener('submit', loadMap);
        [elements.country, elements.type, elements.detail, elements.text].forEach(element => element.addEventListener('input', render));
        elements.selectVisible.addEventListener('click', () => { visibleOrganizations().forEach(item => state.selected.add(item.orgId)); render(); });
        elements.clearSelection.addEventListener('click', () => { state.selected.clear(); render(); });
        elements.loadDetails.addEventListener('click', loadSelectedDetails);elements.list.addEventListener('change', handleSelection);elements.list.addEventListener('click', handleToggle);elements.batchSizeOptions.addEventListener('click', selectBatchSize);elements.startBatch.addEventListener('click', startDetailBatch);
        elements.stopBatch.addEventListener('click', () => {state.batchStopRequested = true;elements.stopBatch.disabled = true;elements.batchMessage.textContent = 'Der Import stoppt nach dem aktuellen Chapter.';});
        elements.automationForm.addEventListener('submit', saveAutomationSettings);elements.automationPanel.addEventListener('toggle', () => { if (elements.automationPanel.open) loadAutomationStats(); });elements.miscPanel.addEventListener('toggle', () => { if (elements.miscPanel.open) loadMailConfiguration(); });elements.reportsPanel.addEventListener('toggle',()=>{if(elements.reportsPanel.open){loadUserErrors();loadBniPerformance();}});elements.performanceWindow.addEventListener('change',loadBniPerformance);elements.mailSettingsForm.addEventListener('submit', saveMailSettings);elements.templatesForm.addEventListener('submit', saveEmailTemplates);elements.templateSearch.addEventListener('input',renderTextTemplateList);elements.templateList.addEventListener('click',event=>{const option=event.target.closest('[data-template-key]');if(option)requestTextTemplate(option.dataset.templateKey);});elements.templateSubject.addEventListener('input',markTextTemplateDirty);elements.templateBody.addEventListener('input',markTextTemplateDirty);elements.templatePlaceholders.addEventListener('click',insertTextTemplatePlaceholder);elements.templateSaveSwitch.addEventListener('click',saveAndSwitchTextTemplate);elements.templateDiscardSwitch.addEventListener('click',discardAndSwitchTextTemplate);elements.templateCancelSwitch.addEventListener('click',cancelTextTemplateSwitch);elements.templateDirtyDialog.addEventListener('cancel',event=>{event.preventDefault();cancelTextTemplateSwitch();});elements.legalSettingsForm.addEventListener('submit', saveLegalSettings);elements.sendTestMail.addEventListener('click', sendTestMail);
        elements.resetSelectedUser.addEventListener('click', event => openResetUserDialog(event.currentTarget));elements.deleteSelectedUser.addEventListener('click', event => openDeleteUserDialog(event.currentTarget));elements.confirmResetUser.addEventListener('click', sendAdminPasswordReset); elements.cancelResetUser.addEventListener('click', closeResetUserDialog); document.querySelector('#close-admin-reset-password-icon').addEventListener('click', closeResetUserDialog); elements.resetUserDialog.addEventListener('cancel',event=>{event.preventDefault();closeResetUserDialog();});elements.confirmRole.addEventListener('click',confirmRoleChange);elements.cancelRole.addEventListener('click',closeRoleDialog);document.querySelector('#close-admin-user-role-icon').addEventListener('click',closeRoleDialog);elements.roleDialog.addEventListener('cancel',event=>{event.preventDefault();closeRoleDialog();});elements.confirmDeleteUser.addEventListener('click', deleteAdminUser); elements.cancelDeleteUser.addEventListener('click', closeDeleteUserDialog); document.querySelector('#close-admin-delete-user-icon').addEventListener('click', closeDeleteUserDialog); elements.deleteUserDialog.addEventListener('cancel',event=>{event.preventDefault();closeDeleteUserDialog();});elements.confirmCancelInvitation.addEventListener('click', confirmCancelInvitation);
        loadLocal();loadAutomationSettings();
    }
    elements.usersPanel.addEventListener('toggle', () => { if (elements.usersPanel.open && !elements.usersPanel.dataset.loaded) loadUsers(); });
    elements.invitationList.addEventListener('click',handleInvitationAction);elements.confirmResendInvitation.addEventListener('click',confirmResendInvitation);elements.cancelResendInvitation.addEventListener('click',closeResendInvitation);elements.resendInvitationDialog.addEventListener('cancel',event=>{event.preventDefault();closeResendInvitation();});
    [elements.usersSearch, elements.usersStatus, elements.usersVerification, elements.usersChapter].forEach(input => input.addEventListener('input', renderUsers));
    elements.usersList.addEventListener('change', event=>{selectAdminUser(event);changeAdminUserRole(event);});
    elements.verifySelectedUser.addEventListener('click', event => openVerifyUserDialog(event.currentTarget));
    elements.confirmVerifyUser.addEventListener('click', verifyAdminUser); elements.cancelVerifyUser.addEventListener('click', closeVerifyUserDialog); document.querySelector('#close-admin-verify-user-icon').addEventListener('click', closeVerifyUserDialog); elements.verifyUserDialog.addEventListener('cancel',event=>{event.preventDefault();closeVerifyUserDialog();});
    elements.invitationsPanel.addEventListener('toggle', () => { if (elements.invitationsPanel.open && !elements.invitationsPanel.dataset.loaded) loadInvitations(); });
    elements.invitationForm.addEventListener('submit', sendInvitation);
    elements.confirmInvitationOverride.addEventListener('click',confirmInvitationOverride);elements.cancelInvitationOverride.addEventListener('click',cancelInvitationOverride);elements.invitationVerificationDialog.addEventListener('cancel',event=>{event.preventDefault();cancelInvitationOverride();});
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

    async function loadBniPerformance(){const windowMinutes=Number(elements.performanceWindow.value);elements.performanceWindow.disabled=true;elements.performanceMessage.textContent='Leistungsdaten werden geladen …';try{const response=await fetch(`/api/admin/bni-performance.php?window_minutes=${windowMinutes}`),payload=await response.json();if(!response.ok)throw new Error(payload.error||'Leistungsdaten konnten nicht geladen werden.');renderBniPerformance(payload);elements.performanceMessage.textContent='';}catch(error){elements.performanceMessage.textContent=error.message;elements.performanceMessage.className='message error';}finally{elements.performanceWindow.disabled=false;}}
    async function loadUserErrors(){elements.userErrorMessage.textContent='Meldungen werden geladen …';try{const response=await fetch('/api/admin/messages.php?limit=100'),payload=await response.json();if(!response.ok)throw new Error(payload.error||'Meldungen konnten nicht geladen werden.');const messages=Array.isArray(payload.messages)?payload.messages:[];elements.userErrorList.replaceChildren(...messages.map(item=>{const row=document.createElement('tr'),time=document.createElement('td'),user=document.createElement('td'),technical=document.createElement('td'),meta=document.createElement('td');time.textContent=new Intl.DateTimeFormat('de-DE',{timeZone:'Europe/Berlin',dateStyle:'short',timeStyle:'medium'}).format(new Date(item.created_at));user.textContent=item.user_message;technical.textContent=item.technical_message;meta.textContent=[item.error_code,item.context].filter(Boolean).join(' / ')||'—';row.append(time,user,technical,meta);return row;}));elements.userErrorTableWrap.hidden=messages.length===0;elements.userErrorEmpty.hidden=messages.length!==0;elements.userErrorMessage.textContent='';}catch(error){elements.userErrorMessage.textContent=error.message;elements.userErrorMessage.className='message error';}}
    function renderBniPerformance(payload){const peak=payload.peak_30d||{},peakTime=peak.minute?new Intl.DateTimeFormat('de-DE',{timeZone:'Europe/Berlin',dateStyle:'medium',timeStyle:'short'}).format(new Date(peak.minute)):'—',fields=[['Requests letzte 24 Stunden',payload.requests_24h||0],['Requests letzte 60 Minuten',payload.requests_60m||0],['Höchste Anfragenlast innerhalb einer Minute',`${peak.count||0} Anfragen / Minute`],['Peak letzte 30 Tage',peak.minute?`am ${peakTime} Uhr`:'Kein Request']];elements.performanceStats.replaceChildren(...fields.map(([label,value])=>{const box=document.createElement('div'),strong=document.createElement('strong'),span=document.createElement('span');strong.className='stat-value';span.className='stat-label';strong.textContent=value;span.textContent=label;box.append(strong,document.createTextNode(' '),span);return box;}));const points=Array.isArray(payload.last_24h)?payload.last_24h:[],maximum=Math.max(1,...points.map(point=>Number(point.count)||0)),width=666,height=178;elements.performanceLine.setAttribute('points',points.map((point,index)=>`${42+(points.length<2?0:index/(points.length-1)*width)},${190-(Number(point.count)||0)/maximum*height}`).join(' '));elements.performanceYMax.textContent=String(maximum);const timeFormat=new Intl.DateTimeFormat('de-DE',{timeZone:'Europe/Berlin',hour:'2-digit',minute:'2-digit'});elements.performanceStart.textContent=points.length?timeFormat.format(new Date(points[0].minute)):'--:--';elements.performanceEnd.textContent=points.length?timeFormat.format(new Date(points[points.length-1].minute)):'--:--';elements.performanceChart.querySelector('desc').textContent=`Rollierende Anzahl der BNI-Anfragen in jeweils ${payload.window_minutes} Minuten. Maximum ${maximum}.`;}

    async function loadMailConfiguration() {
        try {
            const [settingsResponse, templatesResponse, legalResponse] = await Promise.all([fetch('/api/admin/mail-settings.php'), fetch('/api/admin/email-templates.php'), fetch('/api/admin/legal-settings.php')]);
            const settingsPayload = await settingsResponse.json(); const templatesPayload = await templatesResponse.json(); const legalPayload = await legalResponse.json();
            if (!settingsResponse.ok || !templatesResponse.ok || !legalResponse.ok) throw new Error('Die Konfiguration konnte nicht geladen werden.');
            const form = elements.mailSettingsForm; const settings = settingsPayload.settings;
            ['smtpHost', 'smtpPort', 'smtpUsername', 'encryption', 'senderEmail', 'senderName', 'baseUrl'].forEach(name => { form.elements[name].value = settings[name] ?? ''; });
            form.elements.smtpPassword.value = ''; form.elements.smtpPassword.placeholder = settings.hasSmtpPassword ? '••••••••' : '';
            state.textTemplates=Array.isArray(templatesPayload.templates)?templatesPayload.templates:[];renderTextTemplateList();if(state.textTemplates.length)applyTextTemplate(state.textTemplates[0].key);
            elements.legalSettingsForm.elements.imprintText.value = legalPayload.settings.imprintText || ''; elements.legalSettingsForm.elements.privacyText.value = legalPayload.settings.privacyText || '';
        } catch (error) { elements.mailSettingsMessage.textContent = error.message; elements.mailSettingsMessage.className = 'message error'; }
    }

    async function saveMailSettings(event) {
        event.preventDefault(); const form = event.currentTarget;
        try { const values = Object.fromEntries(new FormData(form)); values.smtpPort = Number(values.smtpPort); const response = await fetch('/api/admin/mail-settings.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN }, body: JSON.stringify(values) }); const payload = await response.json(); if (!response.ok) throw new Error(payload.error); elements.mailSettingsMessage.textContent = 'E-Mail-Einstellungen gespeichert.'; elements.mailSettingsMessage.className = 'message success'; form.elements.smtpPassword.value = ''; form.elements.smtpPassword.placeholder = payload.settings.hasSmtpPassword ? '••••••••' : ''; }
        catch (error) { elements.mailSettingsMessage.textContent = error.message; elements.mailSettingsMessage.className = 'message error'; }
    }

    function currentTextTemplate(){return state.textTemplates.find(item=>item.key===state.selectedTextTemplateKey)||null;}
    function renderTextTemplateList(){const scrollTop=elements.templateSelection?.scrollTop||0,query=elements.templateSearch.value.trim().toLocaleLowerCase('de'),items=state.textTemplates.filter(item=>`${item.key} ${item.label} ${item.description} ${item.placeholderName||''}`.toLocaleLowerCase('de').includes(query));elements.templateList.replaceChildren(...items.map(item=>{const button=document.createElement('button'),key=document.createElement('span'),keyName=document.createElement('span'),description=document.createElement('span');button.type='button';button.className='text-template-option';button.dataset.templateKey=item.key;button.setAttribute('role','option');button.setAttribute('aria-selected',String(item.key===state.selectedTextTemplateKey));button.setAttribute('aria-describedby',`text-template-description-${item.key}`);key.className='text-template-option-key';keyName.className='text-template-option-key-name';keyName.textContent=item.key;key.append(keyName);if(item.placeholderName){const placeholder=document.createElement('span');placeholder.className='text-template-option-placeholder';placeholder.textContent=item.placeholderName;key.append(placeholder);}description.className='text-template-option-description';description.id=`text-template-description-${item.key}`;description.textContent=item.description;button.append(key,description);return button;}));if(elements.templateSelection){elements.templateSelection.scrollTop=scrollTop;elements.templateList.querySelector('[aria-selected=true]')?.scrollIntoView({block:'nearest'});}}
    function applyTextTemplate(key){const item=state.textTemplates.find(template=>template.key===key);if(!item)return;state.selectedTextTemplateKey=key;state.textTemplateDirty=false;state.pendingTextTemplateKey=null;elements.templateCategory.textContent=item.category;elements.templateHeading.textContent=item.label;elements.templateKey.textContent=item.key;elements.templateSubjectLabel.hidden=!item.subjectSupported;elements.templateSubject.value=item.subject||'';elements.templateBody.value=item.body||'';elements.templatePlaceholders.replaceChildren(...item.allowedPlaceholders.map(name=>{const button=document.createElement('button');button.type='button';button.className='secondary text-template-placeholder';button.dataset.placeholder=name;button.textContent=`{{${name}}}`;return button;}));if(!item.allowedPlaceholders.length)elements.templatePlaceholders.append(Object.assign(document.createElement('span'),{textContent:'Keine Platzhalter'}));elements.templateDirty.hidden=true;elements.templatesMessage.textContent='';renderTextTemplateList();}
    function requestTextTemplate(key){if(key===state.selectedTextTemplateKey)return;if(state.textTemplateDirty){state.pendingTextTemplateKey=key;elements.templateDirtyDialog.showModal();elements.templateSaveSwitch.focus();return;}applyTextTemplate(key);}
    function markTextTemplateDirty(){if(!state.selectedTextTemplateKey)return;state.textTemplateDirty=true;elements.templateDirty.hidden=false;elements.templatesMessage.textContent='';}
    async function saveCurrentTextTemplate(){const item=currentTextTemplate();if(!item)return false;const body={key:item.key,subject:item.subjectSupported?elements.templateSubject.value:null,body:elements.templateBody.value};try{const response=await fetch('/api/admin/email-templates.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},body:JSON.stringify(body)}),payload=await response.json();if(!response.ok)throw new Error(payload.message||payload.error);const index=state.textTemplates.findIndex(template=>template.key===item.key);if(index>=0&&payload.template)state.textTemplates[index]=payload.template;state.textTemplateDirty=false;elements.templateDirty.hidden=true;elements.templatesMessage.textContent='Textbaustein gespeichert.';elements.templatesMessage.className='message success';renderTextTemplateList();return true;}catch(error){elements.templatesMessage.textContent=error.message;elements.templatesMessage.className='message error';return false;}}
    async function saveEmailTemplates(event){event.preventDefault();await saveCurrentTextTemplate();}
    function insertTextTemplatePlaceholder(event){const button=event.target.closest('[data-placeholder]');if(!button)return;const value=`{{${button.dataset.placeholder}}}`,active=document.activeElement,target=active===elements.templateSubject||active===elements.templateBody?active:elements.templateBody,start=target.selectionStart??target.value.length,end=target.selectionEnd??start;target.setRangeText(value,start,end,'end');target.focus();markTextTemplateDirty();}
    async function saveAndSwitchTextTemplate(){const target=state.pendingTextTemplateKey;if(await saveCurrentTextTemplate()){elements.templateDirtyDialog.close();if(target)applyTextTemplate(target);}}
    function discardAndSwitchTextTemplate(){const target=state.pendingTextTemplateKey;elements.templateDirtyDialog.close();if(target)applyTextTemplate(target);}
    function cancelTextTemplateSwitch(){state.pendingTextTemplateKey=null;elements.templateDirtyDialog.close();document.querySelector(`[data-template-key="${CSS.escape(state.selectedTextTemplateKey||'')}"]`)?.focus();}

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

    async function loadInvitations(){if(elements.invitationsPanel.dataset.loading)return;elements.invitationsPanel.dataset.loading='true';try{const [response,chaptersResponse]=await Promise.all([fetch('/api/admin/invitations.php'),fetch('/api/auth/chapters.php')]),payload=await response.json(),chaptersPayload=await chaptersResponse.json();if(!response.ok||!chaptersResponse.ok)throw new Error(payload.error||chaptersPayload.error);invitationChapterPicker.setChapters(chaptersPayload.chapters||[]);renderInvitations(payload.invitations||[]);elements.invitationsPanel.dataset.loaded='true';}catch(error){setInvitationMessage(error.message,'error');}finally{delete elements.invitationsPanel.dataset.loading;}}
    async function loadUsers(){if(elements.usersPanel.dataset.loading)return;elements.usersPanel.dataset.loading='true';elements.usersMessage.textContent='Anwender werden geladen …';try{const response=await fetch('/api/admin/users.php'),payload=await response.json();if(!response.ok)throw new Error(payload.error);state.users=payload.users||[];if(!state.users.some(user=>user.userId===state.selectedAdminUserId))state.selectedAdminUserId=null;renderUserStats(payload.stats||{});renderUsers();elements.usersPanel.dataset.loaded='true';}catch(error){elements.usersMessage.textContent=error.message;elements.usersMessage.className='message error';}finally{delete elements.usersPanel.dataset.loading;}}
    function renderUserStats(stats){const values=[['Anwender',stats.total||0],['aktive Anwender',stats.active||0],['verifiziert',stats.verified||0],['mit Heimatchapter',stats.withHomeChapter||0]];elements.usersStats.replaceChildren(...values.map(([label,value])=>{const box=document.createElement('div'),strong=document.createElement('strong'),span=document.createElement('span');box.className='stat user-stat';strong.className='stat-value';span.className='stat-label';strong.textContent=value;span.textContent=label;box.append(strong,document.createTextNode(' '),span);return box;}));}
    function visibleUsers(){const query=elements.usersSearch.value.trim().toLocaleLowerCase('de');const filtered=state.users.filter(user=>{const text=`${user.firstName} ${user.lastName} ${user.email} ${user.homeChapterName||''}`.toLocaleLowerCase('de');return(!query||text.includes(query))&&(!elements.usersStatus.value||user.status===elements.usersStatus.value)&&(!elements.usersVerification.value||user.verificationStatus===elements.usersVerification.value)&&(!elements.usersChapter.value||(elements.usersChapter.value==='yes')===(user.homeChapterName!==null));});return window.CrossChappSort.sort(filtered,usersSortState,usersSortFields);}
    function selectedAdminUser(){return state.users.find(user=>user.userId===state.selectedAdminUserId)||null;}
    function updateUserActions(){const selected=selectedAdminUser(),normalUser=selected?.role==='user',alreadyVerified=selected?.verificationStatus==='manual_verified',missingChapter=selected&&selected.homeChapterOrgId===null;if(FULL_ADMIN){elements.resetSelectedUser.disabled=!selected;elements.deleteSelectedUser.disabled=!selected;}elements.verifySelectedUser.disabled=!selected||!normalUser||alreadyVerified||missingChapter;elements.verifySelectedUser.title=!selected?'':!normalUser?'Nur normale Anwender können verifiziert werden.':alreadyVerified?'Anwender ist bereits verifiziert.':missingChapter?'Dieser Anwender benötigt zuerst ein Heimatchapter.':'';elements.usersSelectionHint.hidden=!!selected;}
    function appendUserNameCell(row,user,selected){const cell=document.createElement('td'),name=document.createElement('span'),select=document.createElement('select');cell.className='admin-user-name-cell';name.className='admin-user-name';name.textContent=`${user.firstName} ${user.lastName}`;select.className='admin-user-role-select';select.dataset.userRole=String(user.userId);select.setAttribute('aria-label',`Rolle von ${user.firstName} ${user.lastName}`);[['user','Anwender'],['user_manager','Anwenderbetreuer']].forEach(([value,label])=>{const option=document.createElement('option');option.value=value;option.textContent=label;select.append(option);});select.value=user.role;select.disabled=!FULL_ADMIN||!selected||!['user','user_manager'].includes(user.role);cell.append(name,select);row.append(cell);}
    function renderUsers(){const users=visibleUsers();elements.usersList.replaceChildren(...users.map(user=>{const row=document.createElement('tr'),selected=user.userId===state.selectedAdminUserId,selectCell=document.createElement('td'),radio=document.createElement('input');row.classList.toggle('is-selected',selected);selectCell.className='users-select-cell';radio.type='radio';radio.name='selected_admin_user';radio.value=user.userId;radio.checked=selected;radio.setAttribute('aria-label',`${user.firstName} ${user.lastName} auswählen`);selectCell.append(radio);row.append(selectCell);appendUserNameCell(row,user,selected);appendCell(row,user.email);appendCell(row,user.homeChapterName);appendCell(row,userStatusLabels[user.status]||user.status);appendCell(row,verificationLabels[user.verificationStatus]||user.verificationStatus);appendCell(row,user.currentOffers,'users-column-number users-number');appendCell(row,user.currentRequests,'users-column-number users-number');appendCell(row,user.contacts30Days,'users-column-contacts users-number');appendCell(row,user.emailVerified?'Ja':'Nein','users-column-verified');appendCell(row,formatTimestamp(user.createdAt),'users-column-created');return row;}));elements.usersTableWrap.hidden=users.length===0;elements.usersActions.hidden=state.users.length===0;elements.usersMessage.textContent=users.length===0?'Noch keine Anwender vorhanden.':`${users.length} von ${state.users.length} Anwendern sichtbar.`;elements.usersMessage.className='message';updateUserActions();}
    function selectAdminUser(event){const radio=event.target.closest('input[name=selected_admin_user]');if(!radio)return;state.selectedAdminUserId=Number(radio.value);renderUsers();}
    function changeAdminUserRole(event){const select=event.target.closest('select[data-user-role]');if(!select||!FULL_ADMIN)return;const user=state.users.find(item=>item.userId===Number(select.dataset.userRole));if(!user||user.userId!==state.selectedAdminUserId||user.role===select.value){renderUsers();return;}state.pendingRoleChange={userId:user.userId,role:select.value};const name=`${user.firstName} ${user.lastName}`;elements.roleHeading.textContent=select.value==='user_manager'?'Anwender zum Anwenderbetreuer machen?':'Anwenderbetreuer zurückstufen?';elements.roleConfirmation.querySelector('p').textContent=select.value==='user_manager'?`Möchtest Du ${name} die Rolle Anwenderbetreuer zuweisen?`:`Möchtest Du ${name} wieder die Rolle Anwender zuweisen?`;elements.roleMessage.textContent='';elements.roleDialog.showModal();elements.confirmRole.focus();}
    function closeRoleDialog(){state.pendingRoleChange=null;elements.roleDialog.close();renderUsers();}
    async function confirmRoleChange(){const change=state.pendingRoleChange;if(!change)return;elements.confirmRole.disabled=true;try{const response=await fetch('/api/admin/user-role.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},body:JSON.stringify({user_id:change.userId,role:change.role})}),payload=await response.json();if(!response.ok)throw new Error(payload.message||payload.error);state.pendingRoleChange=null;elements.roleDialog.close();delete elements.usersPanel.dataset.loaded;await loadUsers();}catch(error){elements.roleMessage.textContent=error.message;elements.roleMessage.className='message error';}finally{elements.confirmRole.disabled=false;}}
    function openResetUserDialog(trigger){const user=selectedAdminUser();if(!user)return;elements.resetUserDialog.dataset.triggerId=user.userId;elements.resetUserConfirmation.hidden=false;elements.resetUserConfirmation.querySelector('[data-admin-user-name]').textContent=`${user.firstName} ${user.lastName}`;elements.resetUserConfirmation.querySelector('[data-admin-user-email]').textContent=user.email;elements.resetUserMessage.textContent='';elements.confirmResetUser.hidden=false;elements.cancelResetUser.textContent='Abbrechen';elements.resetUserDialog.showModal();elements.confirmResetUser.focus();elements.resetUserDialog._trigger=trigger;}
    function closeResetUserDialog(){elements.resetUserDialog.close();elements.resetUserDialog._trigger?.focus();}
    async function sendAdminPasswordReset(){const user=selectedAdminUser();if(!user)return;elements.confirmResetUser.disabled=true;try{const response=await fetch('/api/admin/user-password-reset.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},body:JSON.stringify({userId:user.userId})}),payload=await response.json();if(!response.ok)throw new Error(payload.message||payload.error);elements.resetUserConfirmation.hidden=true;elements.resetUserMessage.textContent=payload.message;elements.resetUserMessage.className='message success';elements.confirmResetUser.hidden=true;elements.cancelResetUser.textContent='Schließen';}catch(error){elements.resetUserMessage.textContent=error.message;elements.resetUserMessage.className='message error';}finally{elements.confirmResetUser.disabled=false;}}
    function openVerifyUserDialog(trigger){const user=selectedAdminUser();if(!user||elements.verifySelectedUser.disabled)return;elements.verifyUserConfirmation.hidden=false;elements.verifyUserConfirmation.querySelector('[data-admin-user-name]').textContent=`${user.firstName} ${user.lastName}`;elements.verifyUserMessage.textContent='';elements.confirmVerifyUser.hidden=false;elements.cancelVerifyUser.textContent='Abbrechen';elements.verifyUserDialog.showModal();elements.confirmVerifyUser.focus();elements.verifyUserDialog._trigger=trigger;}
    function closeVerifyUserDialog(){elements.verifyUserDialog.close();elements.verifyUserDialog._trigger?.focus();}
    async function verifyAdminUser(){const user=selectedAdminUser();if(!user)return;elements.confirmVerifyUser.disabled=true;try{const response=await fetch('/api/admin/user-verify.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},body:JSON.stringify({userId:user.userId})}),payload=await response.json();if(!response.ok)throw new Error(payload.message||payload.error);elements.verifyUserConfirmation.hidden=true;elements.verifyUserMessage.textContent=payload.message;elements.verifyUserMessage.className='message success';elements.confirmVerifyUser.hidden=true;elements.cancelVerifyUser.textContent='Schließen';delete elements.usersPanel.dataset.loaded;await loadUsers();}catch(error){elements.verifyUserMessage.textContent=error.message;elements.verifyUserMessage.className='message error';}finally{elements.confirmVerifyUser.disabled=false;}}
    function openDeleteUserDialog(trigger){const user=selectedAdminUser();if(!user)return;elements.deleteUserConfirmation.hidden=false;elements.deleteUserConfirmation.querySelector('[data-admin-user-name]').textContent=`${user.firstName} ${user.lastName}`;elements.deleteUserMessage.textContent='';elements.confirmDeleteUser.hidden=false;elements.cancelDeleteUser.textContent='Abbrechen';elements.deleteUserDialog.showModal();elements.confirmDeleteUser.focus();elements.deleteUserDialog._trigger=trigger;}
    function closeDeleteUserDialog(){elements.deleteUserDialog.close();elements.deleteUserDialog._trigger?.focus();}
    async function deleteAdminUser(){const user=selectedAdminUser();if(!user)return;elements.confirmDeleteUser.disabled=true;try{const response=await fetch('/api/admin/users.php',{method:'DELETE',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},body:JSON.stringify({userId:user.userId})}),payload=await response.json();if(!response.ok)throw new Error(payload.error);state.selectedAdminUserId=null;elements.deleteUserConfirmation.hidden=true;elements.deleteUserMessage.textContent=payload.message;elements.deleteUserMessage.className='message success';elements.confirmDeleteUser.hidden=true;elements.cancelDeleteUser.textContent='Schließen';delete elements.usersPanel.dataset.loaded;await loadUsers();}catch(error){elements.deleteUserMessage.textContent=error.message;elements.deleteUserMessage.className='message error';}finally{elements.confirmDeleteUser.disabled=false;}}
    async function sendInvitation(event){event.preventDefault();const values=Object.fromEntries(new FormData(event.currentTarget));if(!values.home_chapter_org_id){setInvitationMessage('Bitte wähle ein Chapter.','error');return;}await submitInvitation(values);}
    async function submitInvitation(values,overrideToken=''){try{const response=await fetch('/api/admin/invitations.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},body:JSON.stringify({...values,...(overrideToken?{verification_override_token:overrideToken}:{})})}),payload=await response.json();if(!response.ok){if(payload.canOverride&&payload.overrideToken){state.pendingInvitationOverride={values,token:payload.overrideToken};elements.invitationVerificationHeading.textContent=payload.code==='chapter_member_not_found'?'Name nicht im Chapter gefunden':payload.code==='ambiguous'?'BNI-Eintrag nicht eindeutig':'Chapter-Prüfung nicht möglich';elements.invitationVerificationDetail.textContent=payload.message;elements.invitationVerificationDialog.showModal();elements.cancelInvitationOverride.focus();return;}throw new Error(payload.error);}state.pendingInvitationOverride=null;elements.invitationVerificationDialog.close();elements.invitationForm.reset();invitationChapterPicker.clear();setInvitationMessage('Einladung wurde versendet.','success');delete elements.invitationsPanel.dataset.loaded;loadInvitations();}catch(error){setInvitationMessage(error.message,'error');}}
    async function confirmInvitationOverride(){const pending=state.pendingInvitationOverride;if(!pending)return;elements.confirmInvitationOverride.disabled=true;try{await submitInvitation(pending.values,pending.token);}finally{elements.confirmInvitationOverride.disabled=false;}}
    function cancelInvitationOverride(){state.pendingInvitationOverride=null;elements.invitationVerificationDialog.close();elements.invitationForm.querySelector('button[type=submit]')?.focus();}
    function handleInvitationAction(event){const resend=event.target.closest('button[data-resend-invitation]');if(resend){state.pendingInvitationResend={id:Number(resend.dataset.resendInvitation),name:resend.dataset.invitationName,trigger:resend};elements.resendInvitationConfirmation.textContent=`Möchtest Du die Einladung an ${state.pendingInvitationResend.name} erneut per E-Mail senden?`;elements.resendInvitationMessage.textContent='';elements.resendInvitationDialog.showModal();elements.confirmResendInvitation.focus();return;}const button=event.target.closest('button[data-cancel-invitation]');if(!button||!elements.cancelInvitationDialog)return;elements.cancelInvitationDialog.dataset.invitationId=button.dataset.cancelInvitation;elements.cancelInvitationMessage.textContent='';elements.cancelInvitationDialog.showModal();}
    function closeResendInvitation(){const trigger=state.pendingInvitationResend?.trigger;state.pendingInvitationResend=null;elements.resendInvitationDialog.close();trigger?.focus();}
    async function confirmResendInvitation(){const pending=state.pendingInvitationResend;if(!pending)return;elements.confirmResendInvitation.disabled=true;pending.trigger.disabled=true;const original=elements.confirmResendInvitation.textContent;elements.confirmResendInvitation.textContent='Wird gesendet …';try{const response=await fetch('/api/admin/invitations.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},body:JSON.stringify({action:'resend',invitation_id:pending.id})}),payload=await response.json();if(!response.ok)throw new Error(payload.error);elements.resendInvitationDialog.close();state.pendingInvitationResend=null;setInvitationMessage(payload.message||'Einladung erneut gesendet.','success');delete elements.invitationsPanel.dataset.loaded;await loadInvitations();}catch(error){elements.resendInvitationMessage.textContent=error.message;elements.resendInvitationMessage.className='message error';pending.trigger.disabled=false;}finally{elements.confirmResendInvitation.disabled=false;elements.confirmResendInvitation.textContent=original;}}
    async function confirmCancelInvitation(){const id=Number(elements.cancelInvitationDialog.dataset.invitationId);elements.confirmCancelInvitation.disabled=true;try{const response=await fetch('/api/admin/invitations.php',{method:'DELETE',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},body:JSON.stringify({id})}),payload=await response.json();if(!response.ok)throw new Error(payload.error);elements.cancelInvitationDialog.close();loadInvitations();}catch(error){elements.cancelInvitationMessage.textContent=error.message;elements.cancelInvitationMessage.className='message error';}finally{elements.confirmCancelInvitation.disabled=false;}}
    function renderInvitations(items){elements.invitationList.replaceChildren(...items.map(item=>{const row=document.createElement('tr'),name=`${item.firstName} ${item.lastName}`;[name,item.email,item.chapterName,formatTimestamp(item.sentAt),formatTimestamp(item.expiresAt),item.status].forEach(value=>appendCell(row,value));const cell=document.createElement('td'),resend=document.createElement('button');resend.type='button';resend.className='secondary icon-button';resend.dataset.resendInvitation=item.id;resend.dataset.invitationName=name;resend.title='Einladung erneut senden';resend.setAttribute('aria-label',`Einladung an ${name} erneut senden`);resend.textContent='✉';cell.append(resend);if(FULL_ADMIN){const cancel=document.createElement('button');cancel.type='button';cancel.className='secondary';cancel.dataset.cancelInvitation=item.id;cancel.textContent='Widerrufen';cell.append(document.createTextNode(' '),cancel);}row.append(cell);return row;}));}
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
