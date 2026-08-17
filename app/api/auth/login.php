<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405);
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    JsonResponse::send(['error' => 'Der Request enthält kein valides JSON.'], 400);
}

$username = is_array($payload) ? trim((string) ($payload['username'] ?? '')) : '';
$password = is_array($payload) ? (string) ($payload['password'] ?? '') : '';

if (!Auth::login($username, $password)) {
    JsonResponse::send(['error' => 'Benutzername oder Passwort ist nicht korrekt.'], 401);
}

JsonResponse::send(['authenticated' => true]);
