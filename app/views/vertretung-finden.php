<?php if ($currentUser === null): ?>
<section class="page-hero representation-hero"><div class="shell"><p class="eyebrow">Vertretung finden</p><h1>Vertretung für dein Chapter finden</h1></div></section>
<main class="shell content"><section class="panel representation-access-hint"><p>Um Vertretungsangebote für dein Heimatchapter zu sehen, musst du angemeldet sein.</p><a class="button-link" href="/?view=login">Anmelden</a></section></main>
<?php elseif (!$hasRepresentationHomeChapter): ?>
<section class="page-hero representation-hero"><div class="shell"><p class="eyebrow">Vertretung finden</p><h1>Vertretung für dein Chapter finden</h1></div></section>
<main class="shell content"><section class="panel representation-access-hint"><p>Für dein Benutzerkonto ist noch kein Heimatchapter hinterlegt.</p><p>Ein Heimatchapter ist erforderlich, um passende Vertretungsangebote anzuzeigen.</p></section></main>
<?php else: ?>
<section class="page-hero representation-hero"><div class="shell">
    <p class="eyebrow">Vertretung finden</p><h1>Vertretung für dein Chapter finden</h1>
    <p id="representation-home-chapter">Dein Heimatchapter wird geladen …</p>
</div></section>
<main class="shell content">
    <section class="panel" aria-labelledby="available-representations-heading">
        <h2 id="available-representations-heading">Aktuelle Vertretungsangebote</h2>
        <div id="available-representations" class="representation-offer-grid"><p>Vertretungsangebote werden geladen …</p></div>
    </section>
</main>
<?php endif; ?>
