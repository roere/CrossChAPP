<main class="shell content no-hero-content guide-page">
    <header class="guide-intro">
        <h1 class="page-intro-title">Was ist CrossChAPP?</h1>
        <p>CrossChAPP verbindet BNI-Mitglieder chapterübergreifend. Finde passende Chaptertreffen, biete Vertretungen an oder finde eine Vertretung für dein eigenes Chapter.</p>
        <p class="section-kicker">So funktioniert CrossChAPP:</p>
    </header>

    <div class="guide-steps">
        <section class="guide-step">
            <div class="guide-step-copy"><h2>1. Passende Chapter finden</h2><p>Mit CrossChAPPtern findest du passende BNI-Chapter nach Ort, Wochentag und Uhrzeit – egal, wo du gerade bist: zu Hause, auf Geschäftsreise oder im Urlaub. Offene Vertretungsgesuche werden direkt in den Suchergebnissen angezeigt.</p></div>
            <button class="guide-image-button" type="button" data-guide-image="/assets/images/guide/crosschaptern-finden.png" data-guide-alt="CrossChAPPtern-Suche nach passenden BNI-Chaptern" aria-label="Screenshot zur CrossChAPPtern-Suche vergrößern"><img src="/assets/images/guide/crosschaptern-finden.png" alt="CrossChAPPtern-Suche nach passenden BNI-Chaptern"></button>
        </section>
        <section class="guide-step">
            <div class="guide-step-copy"><h2>2. Vertretung anbieten</h2><p>Wähle einen oder mehrere Termine und die Chapter aus, bei denen du als Vertretung zur Verfügung stehst. Du kannst deine Angebote jederzeit wieder löschen.</p></div>
            <button class="guide-image-button" type="button" data-guide-image="/assets/images/guide/vertretung-anbieten.png" data-guide-alt="Vertretungsangebot für ein BNI-Chapter erstellen" aria-label="Screenshot zum Vertretungsangebot vergrößern"><img src="/assets/images/guide/vertretung-anbieten.png" alt="Vertretungsangebot für ein BNI-Chapter erstellen"></button>
        </section>
        <section class="guide-step">
            <div class="guide-step-copy"><h2>3. Vertretung für dein Chapter finden</h2><p>Lege für dein Heimatchapter ein Vertretungsgesuch an. CrossChAPP zeigt dir passende Vertretungsangebote und ermöglicht die direkte Kontaktaufnahme.</p></div>
            <button class="guide-image-button" type="button" data-guide-image="/assets/images/guide/vertretung-finden.png" data-guide-alt="Vertretung für das eigene BNI-Chapter finden" aria-label="Screenshot zur Vertretungssuche vergrößern"><img src="/assets/images/guide/vertretung-finden.png" alt="Vertretung für das eigene BNI-Chapter finden"></button>
        </section>
        <section class="guide-step">
            <div class="guide-step-copy"><h2>4. Verifiziertes Benutzerkonto</h2><p>Verifizierte BNI-Mitglieder werden in CrossChAPP entsprechend gekennzeichnet. Deinen Verifikationsstatus und dein Heimatchapter findest du unter ‚Mein Konto‘.</p></div>
            <button class="guide-image-button" type="button" data-guide-image="/assets/images/guide/konto-verifiziert.png" data-guide-alt="Verifiziertes Benutzerkonto in CrossChAPP" aria-label="Screenshot zum verifizierten Benutzerkonto vergrößern"><img src="/assets/images/guide/konto-verifiziert.png" alt="Verifiziertes Benutzerkonto in CrossChAPP"></button>
        </section>
    </div>

    <section class="panel guide-cta" aria-labelledby="guide-cta-heading">
        <h2 id="guide-cta-heading">Bereit für CrossChAPP?</h2>
        <?php if ($currentUser === null): ?>
            <p>Finde passende Chaptertreffen – egal, ob in deiner Nähe, auf Geschäftsreise oder im Urlaub. Für Vertretungsangebote und eigene Vertretungsgesuche benötigst du ein Benutzerkonto.</p>
            <div class="guide-cta-actions"><a class="button-link" href="/?view=crosschaptern">CrossChAPPtern öffnen</a><a class="button-link secondary-link" href="/?view=login">Anmelden</a></div>
        <?php else: ?>
            <p>Finde passende Chaptertreffen – egal, wo du gerade bist. Biete Vertretungen an oder finde eine Vertretung für dein Heimatchapter.</p>
            <div class="guide-cta-actions"><a class="button-link" href="/?view=crosschaptern">CrossChAPPtern</a><a class="button-link secondary-link" href="/?view=vertretung">Vertretung anbieten</a><a class="button-link secondary-link" href="/?view=vertretung-finden">Vertretung finden</a></div>
        <?php endif; ?>
    </section>
</main>

<dialog id="guide-image-dialog" class="account-dialog guide-image-dialog" aria-modal="true" aria-labelledby="guide-image-heading">
    <div class="account-dialog-card guide-image-dialog-card">
        <button id="close-guide-image" type="button" class="dialog-close" aria-label="Vergrößerte Ansicht schließen">×</button>
        <h2 id="guide-image-heading" class="sr-only">Vergrößerte Screenshot-Ansicht</h2>
        <img src="" alt="">
    </div>
</dialog>
