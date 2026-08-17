<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';

Auth::start();
$isAdmin = Auth::isAdmin();
$viewParameter = (string) ($_GET['view'] ?? '');
$requestedView = match ($viewParameter) {
    'admin' => 'admin',
    'vertretung' => 'vertretung',
    default => 'crosschaptern',
};
$pageTitle = match ($requestedView) {
    'admin' => 'Admin | CrossChAPP',
    'vertretung' => 'Vertretung anbieten | CrossChAPP',
    default => 'Crosschaptern | CrossChAPP',
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='3' fill='%23cf2030'/%3E%3Cpath d='M21 10a8 8 0 1 0 0 12l-3-3a4 4 0 1 1 0-6z' fill='white'/%3E%3C/svg%3E">
    <?php if ($requestedView === 'crosschaptern'): ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="" defer></script>
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/site.js" defer></script>
    <?php if ($requestedView === 'vertretung'): ?><script src="/assets/representation.js" defer></script><?php endif; ?>
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
                        <?php if ($isAdmin): ?>
                            <a class="<?= $requestedView === 'admin' ? 'active' : '' ?>" href="/?view=admin">Admin</a>
                        <?php endif; ?>
                    </nav>
                    <div class="account-actions">
                        <?php if ($isAdmin): ?>
                            <button id="logout-button" type="button" class="header-button secondary">Logout</button>
                        <?php else: ?>
                            <a class="header-button" href="/?view=admin">Login</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <?php
    if ($requestedView === 'admin') {
        require $isAdmin ? __DIR__ . '/views/admin.php' : __DIR__ . '/views/login.php';
    } elseif ($requestedView === 'vertretung') {
        require __DIR__ . '/views/vertretung.php';
    } else {
        require __DIR__ . '/views/search.php';
    }
    ?>
</body>
</html>
