(() => {
    'use strict';

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const authenticated = document.querySelector('meta[name="auth-status"]')?.content === 'authenticated';
    const authRole = document.querySelector('meta[name="auth-role"]')?.content || '';
    const jsonHeaders = { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken };
    window.CrossChappVerificationBadge = () => document.querySelector('#verification-badge-template')?.content.firstElementChild?.cloneNode(true) || null;
    let assignmentPromise = null;
    const updateAssignmentCounts = assignments => {
        for (const role of ['requester', 'representative']) {
            const badge = document.querySelector(`[data-assignment-nav-count="${role}"]`), link = badge?.closest('a'); if (!badge || !link) continue;
            const total = assignments.filter(item => item.role === role).length, title = role === 'requester' ? 'Vertreter finden' : 'Vertretung anbieten';
            const description = role === 'requester' ? (total === 1 ? 'gefundener Vertreter' : 'gefundene Vertreter') : (total === 1 ? 'angenommene Vertretung' : 'angenommene Vertretungen');
            badge.hidden = total === 0; badge.textContent = total === 0 ? '' : total > 99 ? '99+' : String(total); link.setAttribute('aria-label', total ? `${title}, ${total} ${description}` : title);
        }
    };
    const clearAssignmentCounts = () => { assignmentPromise = null; updateAssignmentCounts([]); };
    const loadAssignments = async (force = false) => {
        if (!authenticated || authRole !== 'user') { clearAssignmentCounts(); return []; }
        if (force) assignmentPromise = null;
        if (!assignmentPromise) assignmentPromise = fetch('/api/representation/assignments.php').then(async response => { const data = await response.json(); if (!response.ok) throw new Error(data.error || 'Vereinbarungen konnten nicht geladen werden.'); return data.assignments || []; });
        try { const assignments = await assignmentPromise; updateAssignmentCounts(assignments); return assignments; }
        catch { clearAssignmentCounts(); return []; }
    };
    window.CrossChappAssignments = { load: loadAssignments, clear: clearAssignmentCounts, updateCounts: updateAssignmentCounts };
    if (authenticated && authRole === 'user') loadAssignments(); else clearAssignmentCounts();

    document.querySelector('#login-form')?.addEventListener('submit', login);
    const registerForm = document.querySelector('#register-form');
    registerForm?.addEventListener('submit', register);
    initializeRegistrationValidation(registerForm);
    document.querySelector('#cancel-registration')?.addEventListener('click', cancelRegistration);
    document.querySelector('#forgot-password-form')?.addEventListener('submit', forgotPassword);
    document.querySelector('#reset-password-form')?.addEventListener('submit', resetPassword);
    document.querySelector('#logout-button')?.addEventListener('click', logout);
    initializeAccountMenu();
    initializeMyAccount();
    initializePasswordChange();
    initializeGuideImages();
    document.querySelector('#chapter-search-form')?.addEventListener('submit', searchChapters);

    const searchState = {
        payload: null, expanded: new Set(), map: null, markerLayer: null,
        refreshQueue: [], refreshQueued: new Set(), refreshCompleted: new Set(), refreshRunning: false, refreshGeneration: 0,
    };
    document.querySelector('#result-list')?.addEventListener('click', toggleResultDetails);
    document.querySelector('#map-toggle')?.addEventListener('click', toggleMap);
    document.querySelector('.result-limit-options')?.addEventListener('click', selectResultLimit);
    initializeRequestContact();

    if (document.querySelector('#home-chapter-results')) loadHomeChapters();
    const invitationForm = document.querySelector('#invitation-activation-form');
    if (invitationForm) setupInvitationActivation(invitationForm);

    function initializeGuideImages() {
        const dialog = document.querySelector('#guide-image-dialog');
        if (!dialog) return;
        const image = dialog.querySelector('img'); const close = dialog.querySelector('#close-guide-image'); let trigger = null;
        const closeDialog = () => { if (dialog.open) dialog.close(); trigger?.focus(); };
        document.querySelectorAll('[data-guide-image]').forEach(button => button.addEventListener('click', () => {
            trigger = button; image.src = button.dataset.guideImage; image.alt = button.dataset.guideAlt || ''; dialog.showModal(); close.focus();
        }));
        close.addEventListener('click', closeDialog);
        dialog.addEventListener('cancel', event => { event.preventDefault(); closeDialog(); });
        dialog.addEventListener('click', event => { if (event.target === dialog) closeDialog(); });
    }

    function selectResultLimit(event) {
        const button = event.target.closest('button[data-limit]');
        if (!button) return;
        const group = event.currentTarget;
        group.querySelectorAll('button[data-limit]').forEach(option => option.setAttribute('aria-pressed', String(option === button)));
        document.querySelector('#search-limit').value = button.dataset.limit;
    }

    async function login(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const message = document.querySelector('#login-message');
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true; message.textContent = '';
        try {
            const response = await fetch('/api/auth/login.php', {
                method: 'POST', headers: jsonHeaders,
                body: JSON.stringify({ login: form.login.value, password: form.password.value }),
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Anmeldung fehlgeschlagen.');
            const allowedReturnViews = new Set(['crosschaptern', 'vertretung', 'vertretung-finden', 'representation-accept']);
            const returnView = allowedReturnViews.has(form.return_view?.value) ? form.return_view.value : 'crosschaptern';
            window.location.assign(payload.role === 'admin' ? '/?view=admin' : `/?view=${encodeURIComponent(returnView)}`);
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
        clearAssignmentCounts();
        try {
            await fetch('/api/auth/logout.php', { method: 'POST', headers: { 'X-CSRF-Token': csrfToken } });
        } finally {
            window.location.assign('/');
        }
    }

    function initializeAccountMenu() {
        const menu = document.querySelector('#account-menu');
        const trigger = document.querySelector('#account-menu-trigger');
        const dropdown = document.querySelector('#account-dropdown');
        if (!menu || !trigger || !dropdown) return;
        let closeTimer = null;
        const open = () => { window.clearTimeout(closeTimer); dropdown.hidden = false; trigger.setAttribute('aria-expanded', 'true'); };
        const close = () => { dropdown.hidden = true; trigger.setAttribute('aria-expanded', 'false'); };
        menu.addEventListener('mouseenter', open);
        menu.addEventListener('mouseleave', () => { closeTimer = window.setTimeout(close, 120); });
        trigger.addEventListener('click', event => { event.stopPropagation(); dropdown.hidden ? open() : close(); });
        dropdown.addEventListener('click', close);
        document.addEventListener('click', event => { if (!menu.contains(event.target)) close(); });
        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape' || dropdown.hidden) return;
            close(); trigger.focus();
        });
    }

    function initializePasswordChange() {
        const dialog = document.querySelector('#change-password-dialog');
        const form = document.querySelector('#change-password-form');
        if (!dialog || !form) return;
        const password = form.password; const confirmation = form.password_confirmation;
        document.querySelector('#open-change-password')?.addEventListener('click', () => { resetPasswordChange(form); dialog.showModal(); window.setTimeout(() => password.focus(), 0); });
        document.querySelector('#cancel-change-password')?.addEventListener('click', () => { resetPasswordChange(form); dialog.close(); });
        document.querySelector('#close-change-password')?.addEventListener('click', () => { resetPasswordChange(form); dialog.close(); });
        dialog.addEventListener('close', () => { if (document.querySelector('#change-password-form')) resetPasswordChange(form); });
        password.addEventListener('blur', () => validateChangedPassword(password));
        confirmation.addEventListener('blur', () => { confirmation.dataset.touched = 'true'; validateChangedConfirmation(password, confirmation); });
        password.addEventListener('input', () => {
            if (confirmation.dataset.touched === 'true' || confirmation.value !== '') validateChangedConfirmation(password, confirmation);
        });
        form.addEventListener('submit', changePassword);
    }

    function initializeMyAccount() {
        const trigger = document.querySelector('#open-my-account');
        const dialog = document.querySelector('#my-account-dialog');
        if (!trigger || !dialog) return;
        const closeButton = document.querySelector('#close-my-account');
        const closeIcon = document.querySelector('#close-my-account-icon');
        const message = document.querySelector('#my-account-message');
        const deleteDialog = document.querySelector('#delete-account-dialog');
        const deleteButton = document.querySelector('#open-delete-account');
        const confirmDelete = document.querySelector('#confirm-delete-account');
        const cancelDelete = document.querySelector('#cancel-delete-account');
        const closeDeleteIcon = document.querySelector('#close-delete-account-icon');
        const deleteMessage = document.querySelector('#delete-account-message');
        const details = document.querySelector('#my-account-details');
        const editButton = document.querySelector('#edit-my-account');
        const editor = document.querySelector('#my-account-chapter-editor');
        const saveChapter = document.querySelector('#save-account-home-chapter');
        const cancelEdit = document.querySelector('#cancel-account-edit');
        const skipOption = document.querySelector('#account-skip-chapter-verification-option');
        const skipCheckbox = skipOption?.querySelector('input') || null;
        let accountData = null; let accountPicker = null; let chaptersLoaded = false; let accountEditActive = false;
        const setEditMode = active => {
            accountEditActive = active;
            if (editor) editor.hidden = !active;
            if (details) details.hidden = active;
            [editButton, closeButton, deleteButton, closeIcon].filter(Boolean).forEach(button => { button.disabled = active; });
            if (!active) { if (skipOption) skipOption.hidden = true; if (skipCheckbox) skipCheckbox.checked = false; }
        };
        const leaveEditMode = () => setEditMode(false);
        const closeAccount = () => { if (accountEditActive) return; dialog.close(); trigger.focus(); };
        const closeDelete = () => { deleteDialog?.close(); deleteButton?.focus(); };
        const renderAccount = account => {
            accountData = account;
            const verificationLabels = { manual_verified: 'Verifiziert', directory_match: 'BNI-Chapter gefunden', unverified: 'Nicht verifiziert' };
            for (const [key, value] of Object.entries(account)) {
                    const output = dialog.querySelector(`[data-account-field="${key}"]`);
                    if (!output) continue;
                    if (key === 'verificationStatus') {
                        output.replaceChildren();
                        if (value === 'manual_verified') {
                            const badge = window.CrossChappVerificationBadge();
                            output.append(document.createTextNode(`${verificationLabels[value]} `)); if (badge) output.append(badge);
                        } else output.textContent = verificationLabels[value] || 'Nicht verifiziert';
                    } else output.textContent = value === null || String(value).trim() === '' ? '—' : String(value);
            }
            const headerBadge = document.querySelector('#account-menu-trigger .verification-badge');
            if (account.verificationStatus !== 'manual_verified') headerBadge?.remove();
        };
        const loadAccount = async () => {
            const response = await fetch('/api/auth/account.php'); const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Die Kontodaten konnten nicht geladen werden.');
            renderAccount(payload.account); return payload.account;
        };
        trigger.addEventListener('click', async () => {
            message.textContent = ''; message.className = 'message'; leaveEditMode(); dialog.showModal();
            try {
                await loadAccount();
                closeButton.focus();
            } catch (error) { message.textContent = error.message; message.className = 'message error'; }
        });
        if (editor && editButton && saveChapter && cancelEdit) {
            accountPicker = window.CrossChappChapterPicker.create({
                list: document.querySelector('#account-home-chapter-results'),
                countryInput: document.querySelector('#account-home-chapter-country'),
                searchInput: document.querySelector('#account-home-chapter-search'),
                locationInput: document.querySelector('#account-home-chapter-location'),
                selectedInput: document.querySelector('#account-home-chapter-id'),
                selectedOutput: document.querySelector('#account-selected-home-chapter'),
                clearButton: document.querySelector('#clear-account-home-chapter'),
                radioName: 'account_home_chapter_choice', selectedLabelPrefix: 'Heimatchapter:',
                emptyLabel: 'Kein Heimatchapter ausgewählt.', showAllByDefault: false,
                maxResults: 20, collapseAfterSelect: true,
                onSelect: () => { if (skipOption) skipOption.hidden = true; if (skipCheckbox) skipCheckbox.checked = false; },
            });
            editButton.addEventListener('click', async () => {
                message.textContent = ''; message.className = 'message';
                setEditMode(true);
                try {
                    if (!chaptersLoaded) {
                        const response=await fetch('/api/auth/chapters.php'),payload=await response.json();
                        if(!response.ok)throw new Error(payload.error||'Die lokale Chapterliste konnte nicht geladen werden.');
                        accountPicker.setChapters(payload.chapters||[]);chaptersLoaded=true;
                    }
                    accountPicker.setSelectedOrgId(accountData?.homeChapterOrgId ?? null);
                    document.querySelector('#account-home-chapter-search')?.focus();
                } catch(error){message.textContent=error.message;message.className='message error';}
            });
            cancelEdit.addEventListener('click',()=>{accountPicker.setSelectedOrgId(accountData?.homeChapterOrgId??null);leaveEditMode();editButton.focus();});
            saveChapter.addEventListener('click',async()=>{
                saveChapter.disabled=true;message.textContent='';message.className='message';
                try{
                    const response=await fetch('/api/auth/account.php',{method:'PATCH',headers:jsonHeaders,body:JSON.stringify({home_chapter_org_id:document.querySelector('#account-home-chapter-id').value||null,skip_chapter_verification:skipCheckbox?.checked===true})});
                    const payload=await response.json();
                    if(!response.ok){if(payload.code==='technical_unavailable'&&payload.canSkip===true&&skipOption){skipOption.hidden=false;if(skipCheckbox)skipCheckbox.checked=false;}throw new Error(payload.message||payload.error||'Das Heimatchapter konnte nicht gespeichert werden.');}
                    renderAccount(payload.account);leaveEditMode();message.textContent=payload.result==='removed'?'Das Heimatchapter wurde entfernt.':'Das Heimatchapter wurde gespeichert.';message.className='message success';editButton.focus();
                }catch(error){message.textContent=error.message;message.className='message error';}finally{saveChapter.disabled=false;}
            });
        }
        closeButton.addEventListener('click', closeAccount); closeIcon.addEventListener('click', closeAccount);
        dialog.addEventListener('keydown', event => { if (accountEditActive && event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); } }, true);
        dialog.addEventListener('cancel', event => { event.preventDefault(); if (!accountEditActive) closeAccount(); });
        if (!deleteDialog || !deleteButton || !confirmDelete || !cancelDelete || !closeDeleteIcon || !deleteMessage) return;
        deleteButton.addEventListener('click', () => { deleteMessage.textContent = ''; deleteMessage.className = 'message'; deleteDialog.showModal(); confirmDelete.focus(); });
        cancelDelete.addEventListener('click', closeDelete); closeDeleteIcon.addEventListener('click', closeDelete);
        deleteDialog.addEventListener('cancel', event => { event.preventDefault(); closeDelete(); });
        confirmDelete.addEventListener('click', async () => {
            confirmDelete.disabled = true; deleteMessage.textContent = '';
            try {
                const response = await fetch('/api/auth/account.php', { method: 'DELETE', headers: jsonHeaders });
                const payload = await response.json(); if (!response.ok) throw new Error(payload.error || 'Das Konto konnte nicht gelöscht werden.');
                window.location.assign('/?account_deleted=1');
            } catch (error) {
                deleteMessage.textContent = 'Das Konto konnte nicht gelöscht werden.'; deleteMessage.className = 'message error'; confirmDelete.disabled = false;
            }
        });
    }

    async function changePassword(event) {
        event.preventDefault(); const form = event.currentTarget; const button = form.querySelector('button[type="submit"]');
        const passwordValid = validateChangedPassword(form.password);
        const confirmationValid = validateChangedConfirmation(form.password, form.password_confirmation);
        if (!passwordValid || !confirmationValid) { (passwordValid ? form.password_confirmation : form.password).focus(); return; }
        button.disabled = true;
        try {
            const response = await fetch('/api/auth/change-password.php', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ password: form.password.value, password_confirmation: form.password_confirmation.value }) });
            const payload = await response.json(); if (!response.ok) throw new Error(payload.error || 'Das Passwort konnte nicht geändert werden.');
            const content = document.querySelector('#change-password-content'); const success = document.createElement('p');
            success.className = 'password-change-success'; success.textContent = 'Passwort wurde erfolgreich geändert.'; content.replaceChildren(success);
        } catch (error) {
            const message = document.querySelector('#change-password-message'); message.textContent = error.message; message.className = 'message error';
        } finally { button.disabled = false; }
    }

    function validateChangedPassword(input) { return setFieldValidity(input, input.value.length >= 8, '#change-password-error'); }
    function validateChangedConfirmation(password, confirmation) { return setFieldValidity(confirmation, confirmation.value !== '' && confirmation.value === password.value, '#change-confirmation-error'); }
    function resetPasswordChange(form) {
        form.reset(); delete form.password_confirmation.dataset.touched;
        form.querySelectorAll('.field-invalid').forEach(input => input.classList.remove('field-invalid'));
        form.querySelectorAll('[aria-invalid]').forEach(input => input.removeAttribute('aria-invalid'));
        form.querySelectorAll('.field-error').forEach(error => { error.hidden = true; });
        const message = document.querySelector('#change-password-message'); if (message) { message.textContent = ''; message.className = 'message'; }
    }

    async function register(event) {
        event.preventDefault(); const form = event.currentTarget; const message = document.querySelector('#register-message'); const button = form.querySelector('button[type="submit"]');
        const invalidField = validateRegistration(form);
        if (invalidField) { invalidField.focus(); return; }
        if (!form.checkValidity()) { form.reportValidity(); return; }
        button.disabled = true;
        try {
            const registrationData = Object.fromEntries(new FormData(form));
            registrationData.skip_chapter_verification = form.skip_chapter_verification.checked;
            const response = await fetch('/api/auth/register.php', { method: 'POST', headers: jsonHeaders, body: JSON.stringify(registrationData) });
            const payload = await response.json();
            if (!response.ok) {
                const skipOption = document.querySelector('#skip-chapter-verification-option');
                if (payload.code === 'technical_unavailable' && payload.canSkip === true) {
                    if (skipOption) { skipOption.hidden = false; form.skip_chapter_verification.checked = false; }
                } else if (skipOption) { skipOption.hidden = true; form.skip_chapter_verification.checked = false; }
                throw new Error(payload.message || payload.error || 'Registrierung fehlgeschlagen.');
            }
            const panel = form.closest('.registration-panel');
            const confirmation = document.createElement('p'); confirmation.className = 'registration-confirmation'; confirmation.textContent = payload.message || 'Bitte bestätige deine E-Mail-Adresse.';
            panel.replaceChildren(confirmation);
        } catch (error) { message.textContent = error.message; message.className = 'message error'; }
        finally { button.disabled = false; }
    }

    function cancelRegistration() {
        const form = document.querySelector('#register-form');
        form?.reset();
        if (form) clearRegistrationValidation(form);
        window.location.assign('/');
    }

    function initializeRegistrationValidation(form) {
        if (!form) return;
        form.email.addEventListener('blur', () => validateRegistrationEmail(form.email));
        form.password.addEventListener('blur', () => validateRegistrationPassword(form.password));
        form.password_confirmation.addEventListener('blur', () => {
            form.password_confirmation.dataset.touched = 'true';
            validateRegistrationConfirmation(form.password, form.password_confirmation);
        });
        form.password.addEventListener('input', () => {
            if (form.password_confirmation.dataset.touched === 'true' || form.password_confirmation.value !== '') {
                validateRegistrationConfirmation(form.password, form.password_confirmation);
            }
        });
    }

    function validateRegistration(form) {
        const checks = [
            [form.email, validateRegistrationEmail(form.email)],
            [form.password, validateRegistrationPassword(form.password)],
            [form.password_confirmation, validateRegistrationConfirmation(form.password, form.password_confirmation)],
        ];
        return checks.find(([, valid]) => !valid)?.[0] || null;
    }

    function validateRegistrationEmail(input) {
        return setFieldValidity(input, input.value.trim() !== '' && input.validity.valid, '#register-email-error');
    }

    function validateRegistrationPassword(input) {
        return setFieldValidity(input, input.value.length >= 8, '#register-password-error');
    }

    function validateRegistrationConfirmation(password, confirmation) {
        return setFieldValidity(confirmation, confirmation.value !== '' && confirmation.value === password.value, '#register-confirmation-error');
    }

    function setFieldValidity(input, valid, errorSelector) {
        const error = document.querySelector(errorSelector);
        input.classList.toggle('field-invalid', !valid);
        input.setAttribute('aria-invalid', String(!valid));
        if (error) error.hidden = valid;
        return valid;
    }

    function clearRegistrationValidation(form) {
        form.querySelectorAll('.field-invalid').forEach(input => input.classList.remove('field-invalid'));
        form.querySelectorAll('[aria-invalid]').forEach(input => input.removeAttribute('aria-invalid'));
        form.querySelectorAll('.field-error').forEach(error => { error.hidden = true; });
        delete form.password_confirmation.dataset.touched;
    }

    async function forgotPassword(event) {
        event.preventDefault(); const form = event.currentTarget; const message = document.querySelector('#forgot-message');
        const response = await fetch('/api/auth/forgot-password.php', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ email: form.email.value }) });
        const payload = await response.json(); message.textContent = payload.message; message.className = 'message success';
    }

    async function resetPassword(event) {
        event.preventDefault(); const form = event.currentTarget; const message = document.querySelector('#reset-message');
        try { const response = await fetch('/api/auth/reset-password.php', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ token: form.dataset.token, password: form.password.value, password_confirmation: form.password_confirmation.value }) }); const payload = await response.json(); if (!response.ok) throw new Error(payload.error); form.remove(); message.className = 'message success'; message.replaceChildren(document.createTextNode(`${payload.message} `)); const link=document.createElement('a');link.href='/?view=login';link.className='button-link';link.textContent='Anmelden';message.append(link); }
        catch (error) { message.textContent = error.message; message.className = 'message error'; }
    }

    async function loadHomeChapters() {
        const result = document.querySelector('#home-chapter-results');
        const skipOption = document.querySelector('#skip-chapter-verification-option');
        const resetSkipOption = () => { if (skipOption) { skipOption.hidden = true; const checkbox=skipOption.querySelector('input');if(checkbox)checkbox.checked=false; } };
        const picker = window.CrossChappChapterPicker.create({
            list: result,
            countryInput: document.querySelector('#home-chapter-country'),
            searchInput: document.querySelector('#home-chapter-search'),
            locationInput: document.querySelector('#home-chapter-location'),
            selectedInput: document.querySelector('#home-chapter-id'),
            selectedOutput: document.querySelector('#selected-home-chapter'),
            clearButton: document.querySelector('#clear-home-chapter'),
            radioName: 'home_chapter_choice',
            selectedLabelPrefix: 'Heimatchapter:',
            emptyLabel: 'Kein Heimatchapter ausgewählt.',
            showAllByDefault: false,
            maxResults: 20,
            collapseAfterSelect: true,
            onSelect: resetSkipOption,
        });
        try { const response = await fetch('/api/auth/chapters.php'); const payload = await response.json(); if (!response.ok) throw new Error(payload.error); picker.setChapters(payload.chapters); }
        catch { result.textContent = 'Die lokale Chapterliste konnte nicht geladen werden.'; }
    }

    function setupInvitationActivation(form) {
        const password=form.elements.password,confirmation=form.elements.password_confirmation,passwordError=form.querySelector('[data-password-error]'),confirmationError=form.querySelector('[data-confirmation-error]');
        const validatePassword=()=>{const valid=password.value.length>=8;password.setAttribute('aria-invalid',String(!valid));passwordError.hidden=valid;return valid;};
        const validateConfirmation=()=>{const valid=confirmation.value!==''&&confirmation.value===password.value;confirmation.setAttribute('aria-invalid',String(!valid));confirmationError.hidden=valid;return valid;};
        password.addEventListener('blur',validatePassword);password.addEventListener('input',()=>{if(confirmation.value!=='')validateConfirmation();});confirmation.addEventListener('blur',validateConfirmation);
        form.addEventListener('submit',async event=>{event.preventDefault();const validPassword=validatePassword(),validConfirmation=validateConfirmation();if(!validPassword||!validConfirmation){(validPassword?confirmation:password).focus();return;}const message=document.querySelector('#invitation-activation-message');try{const response=await fetch('/api/auth/invitation.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({token:form.dataset.token,password:password.value,password_confirmation:confirmation.value})});const payload=await response.json();if(!response.ok)throw new Error(payload.error);form.remove();message.className='message success';message.replaceChildren(document.createTextNode('Dein Konto wurde aktiviert. '));const link=document.createElement('a');link.href='/?view=login';link.className='button-link';link.textContent='Anmelden';message.append(link);}catch(error){message.textContent=error.message;message.className='message error';}});
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
            limit: form.limit.value,
            hasRepresentationRequests: form.elements.has_representation_requests.checked,
        };
        message.textContent = 'Ort wird gesucht und Entfernung berechnet …'; message.className = 'message'; button.disabled = true;
        try {
            const response = await fetch('/api/search.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Die Suche konnte nicht ausgeführt werden.');
            renderResults(result);
            queueSearchRefreshes(result);
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
        searchState.payload = payload;
        searchState.expanded.clear();
        searchState.refreshGeneration += 1;
        searchState.refreshQueue = [];
        searchState.refreshQueued.clear();
        searchState.refreshCompleted.clear();
        section.hidden = false;
        heading.textContent = payload.result_count === 0
            ? 'Für diese Auswahl wurden keine passenden Chapter gefunden.'
            : payload.result_count < payload.total_matching
                ? `${payload.total_matching} passende Chapter gefunden. ${payload.result_count} werden angezeigt.`
                : `${payload.total_matching} passende Chapter gefunden.`;
        around.textContent = `Suche rund um ${payload.search_location.display_name}`;
        const fragment = document.createDocumentFragment();
        payload.results.forEach(chapter => fragment.append(resultCard(chapter)));
        list.replaceChildren(fragment);
        if (!document.querySelector('#results-map-panel').hidden) renderMap();
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function resultCard(chapter) {
        const article = document.createElement('article'); article.className = 'result-card'; article.id = `result-${chapter.orgId}`;
        const header = document.createElement('div'); header.className = 'result-card-header';
        const title = document.createElement('h3'); title.textContent = chapter.chapterName || '—';
        const distance = document.createElement('strong'); distance.className = 'distance'; distance.textContent = `${formatNumber(chapter.distanceKm)} km`;
        header.append(title, distance);

        const facts = document.createElement('dl'); facts.className = 'result-facts';
        [
            ['Ort', chapter.city], ['Wochentag', chapter.meetingDay],
            ['Uhrzeit', chapter.meetingTime ? `${chapter.meetingTime} Uhr` : null], ['Meetingtyp', chapter.meetingType],
            ['Mitglieder', chapter.memberCount],
        ].forEach(([label, value]) => appendFact(facts, label, value));

        const actions = document.createElement('div'); actions.className = 'result-actions';
        appendExternalAction(actions, chapter.visitorRegistrationUrl, 'Als Besucher anmelden', 'primary');
        const toggle = document.createElement('button'); toggle.type = 'button'; toggle.className = 'result-detail-toggle secondary';
        toggle.dataset.id = chapter.orgId; toggle.setAttribute('aria-expanded', 'false'); toggle.setAttribute('aria-controls', `result-details-${chapter.orgId}`);
        toggle.setAttribute('aria-label', `Details für ${chapter.chapterName || 'Chapter'} öffnen`); toggle.textContent = '▶ Details';
        actions.prepend(toggle);
        const details = resultDetailPanel(chapter); details.hidden = true;
        const refreshStatus = document.createElement('p'); refreshStatus.className = 'result-refresh-status'; refreshStatus.setAttribute('aria-live', 'polite');
        const requests = representationRequests(chapter);
        article.append(header, facts); if (requests) article.append(requests); article.append(actions, refreshStatus, details);
        return article;
    }

    function representationRequests(chapter) {
        const requests = chapter.representationRequests || []; if (!requests.length) return null;
        const section = document.createElement('section'); section.className = 'result-representation-requests';
        const heading = document.createElement('h4'); heading.textContent = 'Vertretung gesucht'; section.append(heading);
        const anonymousNumbers = new Map();
        requests.forEach(item => { const row = document.createElement('div'); row.className = 'result-representation-request'; const text = document.createElement('span');
            const currentNumber = (anonymousNumbers.get(item.requestDate) || 0) + 1; anonymousNumbers.set(item.requestDate, currentNumber);
            const person = authenticated ? item.displayName : `Gesuch ${currentNumber}`; text.textContent = `${formatDateOnly(item.requestDate)} · ${person}`; row.append(text);if(item.isVerified){const verified=document.createElement('span');verified.className='verified-badge';verified.textContent='Verifiziert';row.append(verified);}
            if (item.canContact && item.requestId) { const button = document.createElement('button'); button.type = 'button'; button.className = 'secondary request-contact-button'; button.textContent = 'Kontaktieren'; button.dataset.requestId = item.requestId; button.dataset.requestDate=item.requestDate; row.append(button); }
            else if (item.isOwn) { const own = document.createElement('span'); own.className = 'offer-meta'; own.textContent = 'Dein Gesuch'; row.append(own); }
            else if (item.isAssigned) { const assigned = document.createElement('span'); assigned.className = 'status-badge'; assigned.textContent = 'Vergeben'; row.append(assigned); }
            section.append(row);
        }); return section;
    }

    function initializeRequestContact() {
        const dialog = document.querySelector('#request-contact-dialog'); if (!dialog) return;
        const form = document.querySelector('#request-contact-form'), message = document.querySelector('#request-contact-message'), error = document.querySelector('#request-contact-error');
        const identityFields = document.querySelector('#anonymous-request-contact-fields'), firstName = document.querySelector('#request-contact-first-name'), lastName = document.querySelector('#request-contact-last-name'), email = document.querySelector('#request-contact-email'), fixedDate=document.querySelector('#request-contact-fixed-date'); let requestId = null; let previewTimer = null;
        identityFields.hidden = authenticated; [firstName,lastName,email].forEach(input => input.disabled = authenticated);
        const openContact = async button => { requestId = Number(button.dataset.requestId);fixedDate.textContent=`Termin: ${formatDateOnly(button.dataset.requestDate)}`; error.textContent = ''; dialog.showModal(); await loadPreview(); };
        document.querySelector('#result-list')?.addEventListener('click', async event => { const button = event.target.closest('.request-contact-button'); if (!button) return; await openContact(button); });
        const close = () => { if (dialog.open) dialog.close(); requestId = null; };
        document.querySelector('#close-request-contact').addEventListener('click', close); document.querySelector('#cancel-request-contact').addEventListener('click', close); dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
        const payload = action => ({ action, request_id:requestId, custom_message:message.value, ...(authenticated ? {} : { contact_first_name:firstName.value, contact_last_name:lastName.value, contact_email:email.value }) });
        async function loadPreview() { try { const response = await fetch('/api/representation/request-contact.php', { method:'POST', headers:jsonHeaders, body:JSON.stringify(payload('preview')) }); const data=await response.json(); if(!response.ok)throw new Error(data.error); const preview=data.preview; document.querySelector('#request-contact-hint').textContent=preview.hint; document.querySelector('#request-contact-subject').textContent=`Betreff: ${preview.subject}`; document.querySelector('#request-contact-before').textContent=preview.before; document.querySelector('#request-contact-after').textContent=preview.after; if(message.value==='')message.value=preview.customMessage; if(authenticated)message.focus(); } catch(cause){error.textContent=cause.message||'Die Vorschau konnte nicht geladen werden.';error.className='message error';} }
        const setFieldError = (input, id, text) => { input.setAttribute('aria-invalid', String(Boolean(text))); document.querySelector(`#${id}`).textContent = text; return !text; };
        const validateIdentity = () => { if(authenticated)return true; const firstOk=setFieldError(firstName,'request-contact-first-name-error',firstName.value.trim()?'':'Bitte gib deinen Vornamen ein.'); const lastOk=setFieldError(lastName,'request-contact-last-name-error',lastName.value.trim()?'':'Bitte gib deinen Nachnamen ein.'); const mailOk=setFieldError(email,'request-contact-email-error',/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())?'':'Bitte gib eine gültige E-Mail-Adresse ein.'); return firstOk&&lastOk&&mailOk; };
        [firstName,lastName,email].forEach(input => { input.addEventListener('blur', () => { validateIdentity(); window.clearTimeout(previewTimer); previewTimer=window.setTimeout(loadPreview,150); }); input.addEventListener('input', () => { window.clearTimeout(previewTimer); previewTimer=window.setTimeout(loadPreview,350); }); });
        form.addEventListener('submit', async event => { event.preventDefault(); if(!validateIdentity()){form.querySelector('[aria-invalid="true"]')?.focus();return;} const submit=form.querySelector('button[type=submit]');submit.disabled=true;error.textContent='';try{const response=await fetch('/api/representation/request-contact.php',{method:'POST',headers:jsonHeaders,body:JSON.stringify(payload('send'))});const data=await response.json();if(!response.ok)throw new Error(data.error);document.querySelector('#request-contact-content').replaceChildren(Object.assign(document.createElement('p'),{textContent:'Deine Rückmeldung wurde gesendet.'}));}catch(cause){error.textContent=cause instanceof SyntaxError?'Die Rückmeldung konnte nicht gesendet werden.':(cause.message||'Die Rückmeldung konnte nicht gesendet werden.');error.className='message error';submit.disabled=false;}});
    }

    function resultDetailPanel(chapter) {
        const panel = document.createElement('div'); panel.id = `result-details-${chapter.orgId}`; panel.className = 'result-detail-panel';
        const grid = document.createElement('div'); grid.className = 'detail-groups';
        grid.append(
            detailGroup('Chapter', [
                ['Chaptername', chapter.chapterName],
                ['Region', chapter.region], ['Regions-ID', chapter.regionId], ['Land', countryLabel(chapter.countryCode)],
            ]),
            detailGroup('Treffen', [
                ['Wochentag', chapter.meetingDay], ['Uhrzeit', chapter.meetingTime], ['Meetingtyp', chapter.meetingType],
                ['Meetingdauer', chapter.meetingDuration, value => `${value} Minuten`], ['Treffpunkt', chapter.venue],
            ]),
            detailGroup('Adresse', [['Straße', chapter.street], ['PLZ', chapter.postalCode], ['Ort', chapter.city]]),
            detailGroup('Netzwerk', [
                ['Mitgliederzahl', chapter.memberCount], ['Chapter-Webseite', chapter.chapterUrl, externalLink],
                ['Besucheranmeldung', chapter.visitorRegistrationUrl, externalLink], ['Online-Meeting-Link', chapter.onlineMeetingUrl, externalLink],
            ]),
            detailGroup('System', [
                ['Detailstatus', detailStatusLabel(chapter.detailStatus)], ['Zuletzt aktualisiert', formatTimestamp(chapter.detailsLoadedAt)],
                ['Zeitzone', chapter.timezone], ['Chapterstatus', chapter.status],
            ]),
            detailGroup('Entfernung', [['Entfernung zum Suchstandort', `${formatNumber(chapter.distanceKm)} km`]]),
        );
        panel.append(grid, detailGroup('Beschreibung', [['ChapterText', chapter.description]], 'description-group'));
        return panel;
    }

    function detailGroup(title, fields, extraClass = '') {
        const section = document.createElement('section'); section.className = `detail-group ${extraClass}`.trim();
        const heading = document.createElement('h3'); heading.textContent = title;
        const list = document.createElement('dl');
        fields.forEach(([label, rawValue, formatter]) => {
            const wrapper = document.createElement('div'); const term = document.createElement('dt'); const value = document.createElement('dd');
            term.textContent = label;
            if (rawValue === null || rawValue === undefined || rawValue === '') value.textContent = '—';
            else if (formatter) {
                const formatted = formatter(rawValue);
                value.append(formatted instanceof Node ? formatted : document.createTextNode(formatted));
            } else value.textContent = String(rawValue);
            wrapper.append(term, value); list.append(wrapper);
        });
        section.append(heading, list); return section;
    }

    function toggleResultDetails(event) {
        const button = event.target.closest('.result-detail-toggle');
        if (!button) return;
        const id = Number(button.dataset.id); const panel = document.querySelector(`#result-details-${id}`);
        const expanded = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', String(expanded)); button.textContent = `${expanded ? '▼' : '▶'} Details`;
        button.setAttribute('aria-label', `Details für Organisation ${id} ${expanded ? 'schließen' : 'öffnen'}`);
        panel.hidden = !expanded;
        expanded ? searchState.expanded.add(id) : searchState.expanded.delete(id);
        if (expanded) {
            const chapter = searchState.payload?.results.find(item => item.orgId === id);
            if (chapter) enqueueUsageRefresh(chapter, 'usage_detail');
        }
    }

    function queueSearchRefreshes(payload) {
        if (!payload.refresh_policy?.usage_enabled) return;
        payload.results.forEach(chapter => enqueueUsageRefresh(chapter, 'usage_search'));
    }

    function enqueueUsageRefresh(chapter, triggerType) {
        const policy = searchState.payload?.refresh_policy;
        if (!policy?.usage_enabled || !isStale(chapter.detailsLoadedAt, policy.usage_days)) return;
        if (searchState.refreshQueued.has(chapter.orgId) || searchState.refreshCompleted.has(chapter.orgId)) return;
        searchState.refreshQueued.add(chapter.orgId);
        searchState.refreshQueue.push({ orgId: chapter.orgId, triggerType });
        setChapterRefreshStatus(chapter.orgId, 'Daten werden aktualisiert …');
        runUsageRefreshQueue(searchState.refreshGeneration);
    }

    async function runUsageRefreshQueue(generation) {
        if (searchState.refreshRunning) return;
        searchState.refreshRunning = true;
        try {
            while (searchState.refreshQueue.length && generation === searchState.refreshGeneration) {
                const queued = searchState.refreshQueue.shift();
                let stopQueue = false;
                try {
                    const response = await fetch('/api/refresh/usage.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ org_id: queued.orgId, trigger_type: queued.triggerType }),
                    });
                    const payload = await response.json(); const result = payload.result;
                    if (response.ok && result?.status === 'success') {
                        const chapter = searchState.payload?.results.find(item => item.orgId === queued.orgId);
                        if (chapter && result.details) Object.assign(chapter, result.details);
                        setChapterRefreshStatus(queued.orgId, 'gerade aktualisiert');
                    } else if (result?.status === 'rate_limited' || result?.status === 'forbidden') {
                        stopQueue = true;
                        setChapterRefreshStatus(queued.orgId, 'Aktualisierung derzeit nicht möglich.');
                    } else if (result?.status === 'skipped' && result?.reason === 'daily_limit') {
                        stopQueue = true;
                        setChapterRefreshStatus(queued.orgId, 'Lokale Daten werden angezeigt.');
                    } else if (result?.status === 'skipped') {
                        setChapterRefreshStatus(queued.orgId, '');
                    } else {
                        setChapterRefreshStatus(queued.orgId, 'Lokale Daten werden weiterhin angezeigt.');
                    }
                } catch {
                    setChapterRefreshStatus(queued.orgId, 'Lokale Daten werden weiterhin angezeigt.');
                }
                searchState.refreshQueued.delete(queued.orgId);
                searchState.refreshCompleted.add(queued.orgId);
                if (stopQueue) {
                    searchState.refreshQueue.forEach(pending => setChapterRefreshStatus(pending.orgId, 'Aktualisierung zurückgestellt.'));
                    searchState.refreshQueue = [];
                    searchState.refreshQueued.clear();
                    break;
                }
                if (searchState.refreshQueue.length && generation === searchState.refreshGeneration) {
                    await new Promise(resolve => window.setTimeout(resolve, searchState.payload.refresh_policy.detail_delay_ms));
                }
            }
        } finally {
            searchState.refreshRunning = false;
            if (searchState.refreshQueue.length) runUsageRefreshQueue(searchState.refreshGeneration);
        }
    }

    function isStale(timestamp, days) {
        const loadedAt = Date.parse(timestamp || '');
        return !Number.isFinite(loadedAt) || loadedAt < Date.now() - (Number(days) * 86_400_000);
    }

    function setChapterRefreshStatus(orgId, text) {
        const element = document.querySelector(`#result-${orgId} .result-refresh-status`);
        if (element) element.textContent = text;
    }

    function toggleMap() {
        const button = document.querySelector('#map-toggle'); const panel = document.querySelector('#results-map-panel');
        const expanded = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', String(expanded)); button.textContent = expanded ? 'Karte ausblenden' : 'Karte anzeigen'; panel.hidden = !expanded;
        if (expanded) renderMap();
    }

    function renderMap() {
        if (!searchState.payload || !window.L) return;
        if (!searchState.map) searchState.map = window.CrossChappMap.create(document.querySelector('#results-map'));
        searchState.map.clear();
        const points = []; const location = searchState.payload.search_location;
        const locationPoint = [location.latitude, location.longitude]; points.push(locationPoint);
        searchState.map.start(locationPoint, popupContent('Suchstandort', location.display_name));
        searchState.payload.results.forEach(chapter => {
            if (!Number.isFinite(chapter.latitude) || !Number.isFinite(chapter.longitude)) return;
            const point = [chapter.latitude, chapter.longitude]; points.push(point);
            searchState.map.marker(point, chapterPopup(chapter));
        });
        searchState.map.fit(points);
    }

    function chapterPopup(chapter) {
        const wrapper = document.createElement('div'); wrapper.className = 'chapter-popup';
        const heading = document.createElement('strong'); heading.textContent = chapter.chapterName || '—';
        const facts = document.createElement('p'); facts.textContent = `${formatNumber(chapter.distanceKm)} km · ${chapter.city || '—'} · ${chapter.meetingDay || '—'} · ${chapter.meetingTime || '—'}`;
        const button = document.createElement('button'); button.type = 'button'; button.textContent = 'Zum Ergebnis';
        button.addEventListener('click', () => document.querySelector(`#result-${chapter.orgId}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
        wrapper.append(heading, facts, button); return wrapper;
    }

    function popupContent(title, text) {
        const wrapper = document.createElement('div'); const strong = document.createElement('strong'); const paragraph = document.createElement('p');
        strong.textContent = title; paragraph.textContent = text || '—'; wrapper.append(strong, paragraph); return wrapper;
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

    function externalLink(url) {
        try {
            const parsed = new URL(url);
            if (!['http:', 'https:'].includes(parsed.protocol)) throw new Error('unsupported');
            const anchor = document.createElement('a'); anchor.href = parsed.href; anchor.target = '_blank'; anchor.rel = 'noopener noreferrer'; anchor.textContent = 'Öffnen ↗';
            return anchor;
        } catch { return document.createTextNode(String(url)); }
    }

    function countryLabel(value) { return ({ DE: 'Deutschland', AT: 'Österreich', CH: 'Schweiz' })[value] || value || '—'; }
    function detailStatusLabel(value) { return value === 'loaded' ? 'geladen' : value === 'error' ? 'Fehler' : 'nicht geladen'; }
    function formatTimestamp(value) { return value ? new Intl.DateTimeFormat('de-DE', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '—'; }
    function formatDateOnly(value) { return new Intl.DateTimeFormat('de-DE', { weekday:'short', day:'2-digit', month:'2-digit', year:'numeric', timeZone:'Europe/Berlin' }).format(new Date(`${value}T12:00:00+02:00`)); }

    function formatNumber(value) {
        return new Intl.NumberFormat('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value);
    }
})();
