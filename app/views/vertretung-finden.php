<?php if ($currentUser === null): ?>
<main class="shell content no-hero-content"><h1 class="page-intro-title">Vertretung für Dein Chapter finden</h1><section class="panel representation-access-hint"><p>Um Vertretungsangebote für dein Heimatchapter zu sehen, musst du angemeldet sein.</p><a class="button-link" href="/?view=login">Anmelden</a></section></main>
<?php elseif (!$hasRepresentationHomeChapter): ?>
<main class="shell content no-hero-content"><h1 class="page-intro-title">Vertretung für Dein Chapter finden</h1><section class="panel representation-access-hint"><p>Für dein Benutzerkonto ist noch kein Heimatchapter hinterlegt.</p><p>Ein Heimatchapter ist erforderlich, um passende Vertretungsangebote anzuzeigen.</p></section></main>
<?php else: ?>
<main class="shell content no-hero-content"><h1 class="page-intro-title">Vertretung für Dein Chapter finden</h1>
    <p id="representation-home-chapter" class="page-meta" hidden></p>
    <section class="panel representation-requests" aria-labelledby="representation-requests-heading">
        <h2 id="representation-requests-heading">Meine Vertretungsgesuche</h2>
        <p>Termine auswählen</p>
        <?php $datePickerPrefix = 'representation-request'; require __DIR__ . '/partials/date-picker.php'; ?>
        <p id="representation-request-day-hint" class="automation-hint"></p><div id="representation-request-message" class="message" role="status" aria-live="polite"></div>
        <div id="representation-request-chips" class="date-chips" aria-label="Meine Vertretungsgesuche"><p>Vertretungsgesuche werden geladen …</p></div>
    </section>
    <section class="panel"><h2>Termine mit Vertretungsangeboten</h2><div id="dated-representations"><p>Vertretungsangebote werden geladen …</p></div></section>
    <section class="panel"><h2>Für alle Chaptertermine verfügbar</h2><p>Folgende Personen bieten sich für alle Chaptertermine als Vertretung an.</p><div id="all-date-representations" class="representation-offer-grid"></div></section>
    <section class="panel"><h2>Vertretungsangebote</h2><div id="representation-offers-overview" class="representation-offer-grid"><p>Vertretungsangebote werden geladen …</p></div></section>
</main>
<dialog id="representation-contact-dialog" class="account-dialog" role="dialog" aria-modal="true" aria-labelledby="representation-contact-heading"><div class="account-dialog-card">
 <button id="close-representation-contact" type="button" class="dialog-close" aria-label="Anfrage schließen">×</button><div id="representation-contact-content"><h2 id="representation-contact-heading">Anfrage senden</h2><p id="representation-contact-hint"></p>
 <form id="representation-contact-form"><label id="representation-contact-date-label">Termin<input id="representation-contact-date" type="date"></label><fieldset id="representation-contact-date-options" hidden><legend>Termin auswählen</legend><div></div></fieldset><p id="representation-contact-fixed-date"></p><p id="representation-contact-subject" class="mail-preview-subject"></p><div id="representation-contact-before" class="mail-preview-fixed"></div><label>Deine Nachricht<textarea id="representation-contact-message" rows="8" minlength="20" maxlength="3000" required></textarea></label><div id="representation-contact-after" class="mail-preview-fixed"></div>
 <div id="representation-contact-error" class="message" role="alert"></div><div class="registration-actions"><button type="submit">Anfrage senden</button><button id="cancel-representation-contact" type="button" class="secondary">Abbrechen</button></div></form></div>
</div></dialog>
<?php endif; ?>
