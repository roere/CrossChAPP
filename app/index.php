<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/UserRepository.php';
require_once __DIR__ . '/src/MailSettingsRepository.php';
require_once __DIR__ . '/src/MailService.php';
require_once __DIR__ . '/src/AccountService.php';
require_once __DIR__ . '/src/AccountFactory.php';

Auth::start();
if (isset($_GET['verify'])) {
    try {
        $_SESSION['verification_result'] = AccountFactory::create()['service']->verifyResult((string) $_GET['verify']);
    } catch (Throwable) {
        $_SESSION['verification_result'] = 'invalid';
    }
    header('Location: /?view=verified', true, 303);
    exit;
}
$isAdmin = Auth::isAdmin();
$currentUser = Auth::user();
$currentDatabaseUser = null;
if ($currentUser !== null) {
    try { $currentDatabaseUser = (new UserRepository((new Database())->connection()))->findById((int) $currentUser['user_id']); } catch (Throwable) {}
}
$hasRepresentationHomeChapter = $currentDatabaseUser !== null && $currentDatabaseUser['home_chapter_org_id'] !== null;
$accountDisplayName = $currentUser === null ? '' : ((string) ($currentUser['username'] ?? '') !== ''
    ? (string) $currentUser['username']
    : trim((string) $currentUser['first_name'] . ' ' . (string) $currentUser['last_name']));
$viewParameter = (string) ($_GET['view'] ?? '');
$requestedView = match ($viewParameter) {
    'admin' => 'admin',
    'login', 'register', 'forgot', 'reset', 'verified' => 'auth',
    'vertretung' => 'vertretung',
    'vertretung-finden' => 'vertretung-finden',
    default => 'crosschaptern',
};
$authMode = isset($_GET['reset']) ? 'reset' : (in_array($viewParameter, ['register', 'forgot', 'verified'], true) ? $viewParameter : 'login');
$authToken = (string) ($_GET['reset'] ?? '');
$verificationResult = $authMode === 'verified' ? (string) ($_SESSION['verification_result'] ?? 'invalid') : '';
if ($authMode === 'verified') unset($_SESSION['verification_result']);
$pageTitle = match ($requestedView) {
    'admin' => 'Admin | CrossChAPP',
    'vertretung' => 'Vertretung anbieten | CrossChAPP',
    'vertretung-finden' => 'Vertretung finden | CrossChAPP',
    default => 'Crosschaptern | CrossChAPP',
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='3' fill='%23cf2030'/%3E%3Cpath d='M21 10a8 8 0 1 0 0 12l-3-3a4 4 0 1 1 0-6z' fill='white'/%3E%3C/svg%3E">
    <?php if ($requestedView === 'crosschaptern'): ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="" defer></script>
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/site.js" defer></script>
    <?php if ($requestedView === 'vertretung' || ($requestedView === 'admin' && $isAdmin)): ?><script src="/assets/sort-utils.js" defer></script><?php endif; ?>
    <?php if ($requestedView === 'vertretung'): ?><script src="/assets/representation.js" defer></script><?php endif; ?>
    <?php if ($requestedView === 'vertretung-finden'): ?><script src="/assets/representation-find.js" defer></script><?php endif; ?>
    <?php if ($requestedView === 'admin' && $isAdmin): ?><script src="/assets/app.js" defer></script><?php endif; ?>
</head>
<body>
    <header class="site-header">
        <div class="topbar">
            <div class="shell topbar-inner"><span>Lokale Anwendung</span><strong>CrossChAPP</strong></div>
        </div>
        <div class="main-header">
            <div class="shell header-inner">
                <a class="wordmark" href="/" aria-label="CrossChAPP Startseite">
                    <span class="wordmark-main">CrossChAPP</span>
                    <span class="wordmark-tagline">Chaptertreffen finden</span>
                </a>
                <div class="header-navigation">
                    <nav aria-label="Hauptnavigation">
                        <a class="<?= $requestedView === 'crosschaptern' ? 'active' : '' ?>" href="/?view=crosschaptern">Crosschaptern</a>
                        <a class="<?= $requestedView === 'vertretung' ? 'active' : '' ?>" href="/?view=vertretung">Vertretung anbieten</a>
                        <a class="<?= $requestedView === 'vertretung-finden' ? 'active' : '' ?>" href="/?view=vertretung-finden">Vertretung finden</a>
                        <?php if ($isAdmin): ?>
                            <a class="<?= $requestedView === 'admin' ? 'active' : '' ?>" href="/?view=admin">Admin</a>
                        <?php endif; ?>
                    </nav>
                    <div class="account-actions">
                        <?php if ($currentUser !== null): ?>
                            <div id="account-menu" class="account-menu">
                                <button id="account-menu-trigger" type="button" class="account-menu-trigger" aria-haspopup="menu" aria-expanded="false" aria-controls="account-dropdown"><?= htmlspecialchars($accountDisplayName, ENT_QUOTES, 'UTF-8') ?> <span aria-hidden="true">▼</span></button>
                                <div id="account-dropdown" class="account-dropdown" role="menu" hidden>
                                    <button id="open-change-password" type="button" role="menuitem">Passwort ändern</button>
                                </div>
                            </div>
                            <button id="logout-button" type="button" class="header-button secondary">Logout</button>
                        <?php else: ?>
                            <a class="header-button" href="/?view=login">Login</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <?php if ($currentUser !== null): ?>
        <dialog id="change-password-dialog" class="account-dialog" aria-labelledby="change-password-heading">
            <div class="account-dialog-card">
                <button id="close-change-password" type="button" class="dialog-close" aria-label="Passwortdialog schließen">×</button>
                <div id="change-password-content">
                    <h2 id="change-password-heading">Passwort ändern</h2>
                    <form id="change-password-form" novalidate>
                        <input class="sr-only" name="account_identifier" value="<?= htmlspecialchars($accountDisplayName, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" tabindex="-1" aria-hidden="true">
                        <div class="auth-name-row password-change-fields">
                            <label>Neues Passwort<input name="password" type="password" minlength="8" autocomplete="new-password" required aria-describedby="change-password-error"><span id="change-password-error" class="field-error" hidden>Das Passwort muss mindestens 8 Zeichen lang sein.</span></label>
                            <label>Neues Passwort wiederholen<input name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required aria-describedby="change-confirmation-error"><span id="change-confirmation-error" class="field-error" hidden>Die Passwörter stimmen nicht überein.</span></label>
                        </div>
                        <div class="registration-actions"><button type="submit">Passwort ändern</button><button id="cancel-change-password" type="button" class="secondary">Abbrechen</button></div>
                    </form>
                    <div id="change-password-message" class="message" role="alert" aria-live="polite"></div>
                </div>
            </div>
        </dialog>
    <?php endif; ?>

    <?php
    if ($requestedView === 'admin') {
        require $isAdmin ? __DIR__ . '/views/admin.php' : __DIR__ . '/views/login.php';
    } elseif ($requestedView === 'auth') {
        require __DIR__ . '/views/login.php';
    } elseif ($requestedView === 'vertretung') {
        require __DIR__ . '/views/vertretung.php';
    } elseif ($requestedView === 'vertretung-finden') {
        require __DIR__ . '/views/vertretung-finden.php';
    } else {
        require __DIR__ . '/views/search.php';
    }
    ?>
</body>
</html>
