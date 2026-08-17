<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    JsonResponse::send(['error' => 'Nur GET ist erlaubt.'], 405);
}

$user = Auth::user();
JsonResponse::send(['authenticated' => $user !== null, 'user' => $user, 'isAdmin' => Auth::isAdmin(), 'csrfToken' => Auth::csrfToken()]);
