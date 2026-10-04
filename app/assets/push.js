(() => {
    'use strict';
    const checkbox = document.querySelector('#watchlist-push-notifications');
    if (!checkbox) return;
    const message = document.querySelector('#watchlist-push-message');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const supported = window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    let configuration = null, busy = false;
    const report = (text, error = false) => { message.textContent = text; message.className = error ? 'message error' : 'message'; };
    const api = async (method, body) => {
        const response = await fetch('/api/auth/push.php', {method, headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}, ...(body ? {body: JSON.stringify(body)} : {})});
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Die Push-Einstellung konnte nicht gespeichert werden.');
        return data;
    };
    const applicationKey = encoded => Uint8Array.from(atob(encoded.replace(/-/g, '+').replace(/_/g, '/')), character => character.charCodeAt(0));
    const load = async () => {
        if (busy) return;
        checkbox.disabled = true; checkbox.checked = false;
        if (!supported) { report('Push-Benachrichtigungen sind in diesem Browser oder dieser Nutzungssituation nicht verfügbar. Auf iPhone/iPad kann eine Installation auf dem Home-Bildschirm erforderlich sein.'); return; }
        try {
            configuration = await api('GET');
            const registration = await navigator.serviceWorker.getRegistration('/');
            const subscription = await registration?.pushManager.getSubscription();
            if (subscription) checkbox.checked = (await api('POST', {action: 'status', endpoint: subscription.endpoint})).enabled === true;
            if (!configuration.configured) { checkbox.disabled = !checkbox.checked; report('Push-Benachrichtigungen sind auf diesem Server noch nicht eingerichtet.'); return; }
            report(Notification.permission === 'denied' ? 'Bitte erlaube Benachrichtigungen in den Einstellungen Deines Browsers.' : 'Die Einstellung gilt für dieses Gerät und diesen Browser.');
            checkbox.disabled = false;
        } catch { report('Die Push-Einstellung konnte nicht geladen werden. Bitte öffne „Mein Konto“ erneut.', true); }
    };
    document.querySelector('#open-my-account')?.addEventListener('click', load);
    checkbox.addEventListener('change', async () => {
        if (busy || !configuration || (!configuration.configured && checkbox.checked)) return;
        const intended = checkbox.checked;
        busy = true; checkbox.disabled = true;
        report('Wird gespeichert …');
        let createdSubscription = null;
        try {
            if (intended) {
                // This call is synchronous up to requestPermission: only a direct user action prompts.
                const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
                if (permission !== 'granted') throw new Error('Bitte erlaube Benachrichtigungen in den Einstellungen Deines Browsers.');
                await navigator.serviceWorker.register('/service-worker.js', {scope: '/'});
                const registration = await navigator.serviceWorker.ready;
                let subscription = await registration.pushManager.getSubscription();
                if (!subscription) { subscription = await registration.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: applicationKey(configuration.publicKey)}); createdSubscription = subscription; }
                await api('POST', subscription.toJSON());
            } else {
                const registration = await navigator.serviceWorker.getRegistration('/');
                const subscription = await registration?.pushManager.getSubscription();
                if (subscription) {
                    // Persist the opt-out first; a browser unsubscribe failure cannot enable server delivery.
                    await api('DELETE', {endpoint: subscription.endpoint});
                    try { await subscription.unsubscribe(); } catch { /* Local registration may be reused later. */ }
                }
            }
            checkbox.checked = intended; report('Änderung gespeichert.');
        } catch (error) {
            if (createdSubscription) { try { await createdSubscription.unsubscribe(); } catch {} }
            checkbox.checked = !intended;
            const text = error.name === 'NotAllowedError' ? 'Bitte erlaube Benachrichtigungen in den Einstellungen Deines Browsers.' : ['NotSupportedError','InvalidStateError','InvalidAccessError'].includes(error.name) ? 'Push-Benachrichtigungen sind auf diesem Gerät in der aktuellen Nutzungssituation nicht verfügbar. Auf iPhone/iPad kann eine Installation auf dem Home-Bildschirm erforderlich sein.' : error instanceof TypeError ? 'Push konnte auf diesem Gerät nicht gespeichert werden. Bitte prüfe Browserunterstützung und Internetverbindung.' : error.message;
            report(text, true);
        } finally { busy = false; checkbox.disabled = false; }
    });
})();
