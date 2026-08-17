<section class="page-hero login-hero">
    <div class="shell">
        <p class="eyebrow">Geschützter Bereich</p>
        <h1>Admin-Anmeldung</h1>
        <p>Import und Datenpflege stehen nur angemeldeten Administratoren zur Verfügung.</p>
    </div>
</section>
<main class="shell content auth-content">
    <section class="panel login-panel" aria-labelledby="login-heading">
        <p class="section-kicker">CrossChAPP Admin</p>
        <h2 id="login-heading">Anmelden</h2>
        <form id="login-form">
            <label for="login-username">Benutzername<input id="login-username" name="username" autocomplete="username" required></label>
            <label for="login-password">Passwort<input id="login-password" name="password" type="password" autocomplete="current-password" required></label>
            <button type="submit">Anmelden</button>
        </form>
        <div id="login-message" class="message" role="alert" aria-live="polite"></div>
    </section>
</main>
