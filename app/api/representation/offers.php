<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/UserRepository.php';
require_once dirname(__DIR__, 2) . '/src/RepresentationOfferRepository.php';
require_once dirname(__DIR__, 2) . '/src/RepresentationCleanupService.php';

$identity = Auth::requireUserJson();
$database = (new Database())->connection();
(new RepresentationCleanupService($database))->runCleanup();
$users = new UserRepository($database);
$user = $users->findById((int) $identity['user_id']);
if ($user === null || $user['status'] !== 'active') JsonResponse::send(['error' => 'Anmeldung erforderlich.'], 401);
if ($user['role'] !== 'user') JsonResponse::send(['error' => 'Vertretungsangebote sind normalen Benutzerkonten vorbehalten.'], 403);
$repository = new RepresentationOfferRepository($database);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    JsonResponse::send(['offers' => $repository->forUser((int) $user['id'])]);
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'], true)) JsonResponse::send(['error' => 'Methode nicht erlaubt.'], 405);
Auth::requireCsrfJson();
$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) JsonResponse::send(['error' => 'Ungültige Anfrage.'], 400);

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $offerId = filter_var($payload['offerId'] ?? null, FILTER_VALIDATE_INT);
    if (!$offerId || !$repository->deleteForUser((int) $offerId, (int) $user['id'])) JsonResponse::send(['error' => 'Vertretungsangebot wurde nicht gefunden.'], 404);
    JsonResponse::send(['success' => true]);
}

$orgIds = array_values(array_unique(array_filter(array_map(static fn ($value): int => (int) $value, is_array($payload['orgIds'] ?? null) ? $payload['orgIds'] : []), static fn (int $id): bool => $id > 0)));
$allDates = ($payload['allDates'] ?? false) === true;
$dates = array_values(array_unique(is_array($payload['dates'] ?? null) ? $payload['dates'] : []));
if ($orgIds === []) JsonResponse::send(['error' => 'Bitte wähle mindestens ein Chapter aus.'], 400);
if (!$allDates && $dates === []) JsonResponse::send(['error' => 'Bitte wähle mindestens einen Termin oder Alle Daten aus.'], 400);
if ($user['home_chapter_org_id'] !== null && in_array((int) $user['home_chapter_org_id'], $orgIds, true)) JsonResponse::send(['error' => 'Für dein eigenes Chapter kannst du kein Vertretungsangebot anlegen.'], 400);

$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
foreach ($dates as $date) {
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) JsonResponse::send(['error' => 'Ein Termin ist ungültig.'], 400);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Berlin'));
    if (!$parsed || $parsed->format('Y-m-d') !== $date || $parsed < $today) JsonResponse::send(['error' => 'Vergangene oder ungültige Termine sind nicht erlaubt.'], 400);
}
$placeholders = implode(',', array_fill(0, count($orgIds), '?'));
$statement = $database->prepare("SELECT org_id FROM organizations WHERE org_id IN ($placeholders)");
$statement->execute($orgIds);
$validIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
sort($validIds); $requestedIds = $orgIds; sort($requestedIds);
if ($validIds !== $requestedIds) JsonResponse::send(['error' => 'Mindestens eine Organisation ist nicht vorhanden.'], 400);

try {
    $offerIds = $repository->createMany((int) $user['id'], $orgIds, $allDates, $dates);
} catch (DomainException $exception) {
    if ($exception->getMessage() === 'duplicate_offer') JsonResponse::send(['error' => 'Für dieses Chapter besteht bereits ein identisches Vertretungsangebot.'], 409);
    throw $exception;
}
JsonResponse::send(['success' => true, 'offerIds' => $offerIds, 'count' => count($offerIds)], 201);
