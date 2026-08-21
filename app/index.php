<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/UserRepository.php';
require_once __DIR__ . '/src/MailSettingsRepository.php';
require_once __DIR__ . '/src/MailService.php';
require_once __DIR__ . '/src/AccountService.php';
require_once __DIR__ . '/src/AccountFactory.php';
require_once __DIR__ . '/src/InvitationRepository.php';
require_once __DIR__ . '/src/InvitationService.php';
require_once __DIR__ . '/src/InvitationFactory.php';
require_once __DIR__ . '/src/LegalSettingsRepository.php';

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
$returnViewParameter = (string) ($_GET['return_view'] ?? '');
$returnView = in_array($returnViewParameter, ['crosschaptern', 'vertretung', 'vertretung-finden'], true) ? $returnViewParameter : '';
$requestedView = isset($_GET['invite']) || isset($_GET['reset']) ? 'auth' : match ($viewParameter) {
    'about' => 'about',
    'impressum' => 'impressum',
    'datenschutz' => 'datenschutz',
    'admin' => 'admin',
    'login', 'register', 'forgot', 'reset', 'verified', 'invite' => 'auth',
    'vertretung' => 'vertretung',
    'vertretung-finden' => 'vertretung-finden',
    default => 'crosschaptern',
};
$authMode = isset($_GET['invite']) ? 'invite' : (isset($_GET['reset']) ? 'reset' : (in_array($viewParameter, ['register', 'forgot', 'verified'], true) ? $viewParameter : 'login'));
$authToken = (string) ($_GET['reset'] ?? '');
$invitationToken = (string) ($_GET['invite'] ?? '');
$invitationResult = $authMode === 'invite' ? InvitationFactory::create()['service']->inspect($invitationToken) : ['status' => 'invalid'];
$verificationResult = $authMode === 'verified' ? (string) ($_SESSION['verification_result'] ?? 'invalid') : '';
if ($authMode === 'verified') unset($_SESSION['verification_result']);
$pageTitle = match ($requestedView) {
    'about' => 'Was ist CrossChAPP? | CrossChAPP',
    'impressum' => 'Impressum | CrossChAPP',
    'datenschutz' => 'Datenschutzerklärung | CrossChAPP',
    'admin' => 'Admin | CrossChAPP',
    'vertretung' => 'Vertretung anbieten | CrossChAPP',
    'vertretung-finden' => 'Vertretung finden | CrossChAPP',
    default => 'CrossChAPPtern | CrossChAPP',
};
$legalSettings=(new LegalSettingsRepository((new Database())->connection()))->settings();
$assetVersion = static fn (string $asset): string => (string) (filemtime(__DIR__ . '/assets/' . $asset) ?: 1);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <meta name="auth-status" content="<?= $currentUser !== null ? 'authenticated' : 'anonymous' ?>">
    <?php if (getenv('CROSSCHAPP_TEST_MODE') === '1'): ?><meta name="crosschapp-test-mode" content="1"><?php endif; ?>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='3' fill='%23cf2030'/%3E%3Cpath d='M21 10a8 8 0 1 0 0 12l-3-3a4 4 0 1 1 0-6z' fill='white'/%3E%3C/svg%3E">
    <?php if ($requestedView === 'crosschaptern' || $requestedView === 'vertretung'): ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="" defer></script>
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/app.css?v=<?= $assetVersion('app.css') ?>">
    <script src="/assets/chapter-picker.js?v=<?= $assetVersion('chapter-picker.js') ?>" defer></script>
    <?php if ($requestedView === 'crosschaptern' || $requestedView === 'vertretung'): ?><script src="/assets/map.js?v=<?= $assetVersion('map.js') ?>" defer></script><?php endif; ?>
    <script src="/assets/site.js?v=<?= $assetVersion('site.js') ?>" defer></script>
    <?php if ($requestedView === 'vertretung' || $requestedView === 'vertretung-finden'): ?><script src="/assets/date-picker.js?v=<?= $assetVersion('date-picker.js') ?>" defer></script><?php endif; ?>
    <?php if ($requestedView === 'vertretung' || ($requestedView === 'admin' && $isAdmin)): ?><script src="/assets/sort-utils.js?v=<?= $assetVersion('sort-utils.js') ?>" defer></script><?php endif; ?>
    <?php if ($requestedView === 'vertretung'): ?><script src="/assets/representation.js?v=<?= $assetVersion('representation.js') ?>" defer></script><?php endif; ?>
    <?php if ($requestedView === 'vertretung-finden'): ?><script src="/assets/representation-find.js?v=<?= $assetVersion('representation-find.js') ?>" defer></script><?php endif; ?>
    <?php if ($requestedView === 'admin' && $isAdmin): ?><script src="/assets/app.js?v=<?= $assetVersion('app.js') ?>" defer></script><?php endif; ?>
</head>
<body>
    <header class="site-header">
        <div class="topbar">
            <div class="shell topbar-inner"><strong>CrossChAPP</strong></div>
        </div>
        <div class="main-header">
            <div class="shell header-inner">
                <a class="wordmark" href="/" aria-label="CrossChAPP Startseite">
                    <span class="wordmark-main">CrossChAPP</span>
                    <span class="wordmark-tagline">Chaptertreffen finden</span>
                </a>
                <div class="header-navigation">
                    <nav aria-label="Hauptnavigation">
                        <a class="<?= $requestedView === 'about' ? 'active' : '' ?>" href="/?view=about">Was ist CrossChAPP?</a>
                        <a class="<?= $requestedView === 'crosschaptern' ? 'active' : '' ?>" href="/?view=crosschaptern">CrossChAPPtern</a>
                        <a class="<?= $requestedView === 'vertretung' ? 'active' : '' ?>" href="/?view=vertretung">Vertretung anbieten</a>
                        <a class="<?= $requestedView === 'vertretung-finden' ? 'active' : '' ?>" href="/?view=vertretung-finden">Vertretung finden</a>
                        <?php if ($isAdmin): ?>
                            <a class="<?= $requestedView === 'admin' ? 'active' : '' ?>" href="/?view=admin">Admin</a>
                        <?php endif; ?>
                    </nav>
                    <div class="account-actions">
                        <?php if ($currentUser !== null): ?>
                            <div id="account-menu" class="account-menu">
                                <button id="account-menu-trigger" type="button" class="account-menu-trigger" aria-haspopup="menu" aria-expanded="false" aria-controls="account-dropdown"><span class="account-menu-name"><?= htmlspecialchars($accountDisplayName, ENT_QUOTES, 'UTF-8') ?></span><?php if (($currentDatabaseUser['bni_verification_status'] ?? '') === 'manual_verified'): ?><span class="verification-badge" role="img" aria-label="Verifiziert" title="Verifiziert"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 1.7 12.2 4l3-.3.4 3 2.6 1.5-1.5 2.6.8 2.9-2.9.8-1.5 2.6-2.6-1.5-2.6 1.5-1.5-2.6-2.9-.8.8-2.9-1.5-2.6 2.6-1.5.4-3 3 .3z"/><path class="verification-badge-check" d="m6.5 10 2.2 2.1 4.5-4.5"/></svg></span><?php endif; ?><span aria-hidden="true">▼</span></button>
                                <div id="account-dropdown" class="account-dropdown" role="menu" hidden>
                                    <button id="open-my-account" type="button" role="menuitem">Mein Konto</button>
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
        <dialog id="my-account-dialog" class="account-dialog" aria-modal="true" aria-labelledby="my-account-heading">
            <div class="account-dialog-card">
                <button id="close-my-account-icon" type="button" class="dialog-close" aria-label="Kontoansicht schließen">×</button>
                <h2 id="my-account-heading">Mein Konto</h2>
                <dl id="my-account-details" class="account-details" aria-live="polite">
                    <div><dt>Vorname</dt><dd data-account-field="firstName">Wird geladen …</dd></div>
                    <div><dt>Nachname</dt><dd data-account-field="lastName">Wird geladen …</dd></div>
                    <div><dt>E-Mail-Adresse</dt><dd data-account-field="email">Wird geladen …</dd></div>
                    <div><dt>Heimatchapter</dt><dd data-account-field="homeChapterName">Wird geladen …</dd></div>
                    <div><dt>Verifikation</dt><dd data-account-field="verificationStatus">Wird geladen …</dd></div>
                </dl>
                <?php if (!$isAdmin): ?>
                    <section id="my-account-chapter-editor" hidden>
                        <?php $chapterPicker = ['legend'=>'Heimatchapter','optional'=>true,'inputId'=>'account-home-chapter-id','inputName'=>'home_chapter_org_id','countryId'=>'account-home-chapter-country','searchId'=>'account-home-chapter-search','locationId'=>'account-home-chapter-location','clearId'=>'clear-account-home-chapter','clearLabel'=>'Heimatchapter entfernen','selectedId'=>'account-selected-home-chapter','resultsId'=>'account-home-chapter-results','emptyLabel'=>'Kein Heimatchapter ausgewählt.','ariaLabel'=>'Heimatchapter auswählen']; require __DIR__ . '/views/partials/chapter-picker.php'; ?>
                        <div id="account-skip-chapter-verification-option" class="skip-chapter-verification" hidden>
                            <label><input name="account_skip_chapter_verification" type="checkbox" value="1" aria-describedby="account-skip-chapter-verification-tooltip"> <span>Chapter-Prüfung überspringen</span></label>
                            <span class="field-tooltip"><button type="button" aria-label="Hinweis zur Chapter-Prüfung" aria-describedby="account-skip-chapter-verification-tooltip">i</button><span id="account-skip-chapter-verification-tooltip" role="tooltip">Es wird nicht geprüft, ob der Name in der Mitgliederliste des Chapters steht.</span></span>
                        </div>
                        <div class="registration-actions"><button id="save-account-home-chapter" type="button">Speichern</button><button id="cancel-account-edit" type="button" class="secondary">Abbrechen</button></div>
                    </section>
                <?php endif; ?>
                <div id="my-account-message" class="message" role="alert" aria-live="polite"></div>
                <div id="my-account-actions" class="registration-actions">
                    <?php if (!$isAdmin): ?><button id="edit-my-account" type="button">Bearbeiten</button><?php endif; ?>
                    <button id="close-my-account" type="button" class="secondary">Schließen</button>
                    <?php if (!$isAdmin): ?><button id="open-delete-account" type="button" class="danger">Konto löschen</button><?php endif; ?>
                </div>
            </div>
        </dialog>
        <?php if (!$isAdmin): ?>
            <dialog id="delete-account-dialog" class="account-dialog" aria-modal="true" aria-labelledby="delete-account-heading">
                <div class="account-dialog-card">
                    <button id="close-delete-account-icon" type="button" class="dialog-close" aria-label="Kontolöschung abbrechen">×</button>
                    <h2 id="delete-account-heading">Konto wirklich löschen?</h2>
                    <p>Dein Benutzerkonto wird dauerhaft gelöscht. Diese Aktion kann nicht rückgängig gemacht werden.</p>
                    <div id="delete-account-message" class="message" role="alert" aria-live="polite"></div>
                    <div class="registration-actions">
                        <button id="confirm-delete-account" type="button" class="danger">Konto endgültig löschen</button>
                        <button id="cancel-delete-account" type="button" class="secondary">Abbrechen</button>
                    </div>
                </div>
            </dialog>
        <?php endif; ?>
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
    if ($requestedView === 'about') {
        require __DIR__ . '/views/about.php';
    } elseif ($requestedView === 'impressum' || $requestedView === 'datenschutz') {
        $legalHeading=$requestedView==='impressum'?'Impressum':'Datenschutzerklärung';
        $legalContent=$requestedView==='impressum'?$legalSettings['imprintText']:$legalSettings['privacyText'];
        require __DIR__ . '/views/legal.php';
    } elseif ($requestedView === 'admin') {
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
    <footer class="site-footer"><div class="shell"><nav aria-label="Rechtliche Informationen"><a href="/?view=impressum">Impressum</a><span aria-hidden="true">·</span><a href="/?view=datenschutz">Datenschutzerklärung</a></nav></div></footer>
</body>
</html>
