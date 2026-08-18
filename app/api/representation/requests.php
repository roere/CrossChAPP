<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/Auth.php'; require_once dirname(__DIR__, 2) . '/src/Database.php'; require_once dirname(__DIR__, 2) . '/src/JsonResponse.php'; require_once dirname(__DIR__, 2) . '/src/RepresentationRequestRepository.php'; require_once dirname(__DIR__, 2) . '/src/RepresentationCleanupService.php';
$identity = Auth::requireUserJson(); $database = (new Database())->connection(); (new RepresentationCleanupService($database))->runCleanup(); $repository = new RepresentationRequestRepository($database); $userId = (int) $identity['user_id'];
$chapter = $repository->chapterForUser($userId); if ($chapter === null) JsonResponse::send(['error' => 'Ein Heimatchapter ist erforderlich.'], 403);
$zone = new DateTimeZone('Europe/Berlin'); try { if (!empty($chapter['timezone'])) $zone = new DateTimeZone((string) $chapter['timezone']); } catch (Throwable) {}
$today = (new DateTimeImmutable('today', $zone))->format('Y-m-d');
if ($_SERVER['REQUEST_METHOD'] === 'GET') JsonResponse::send(['requests' => $repository->forUser($userId, $today), 'meetingDay' => $chapter['meeting_day'], 'timezone' => $zone->getName()]);
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'], true)) JsonResponse::send(['error' => 'Nur GET, POST und DELETE sind erlaubt.'], 405); Auth::requireCsrfJson();
try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') JsonResponse::send(['request' => $repository->create($userId, (string) ($payload['request_date'] ?? ''))], 201);
    if (!$repository->deleteForUser((int) ($payload['request_id'] ?? 0), $userId)) JsonResponse::send(['error' => 'Das Vertretungsgesuch wurde nicht gefunden.'], 404);
    JsonResponse::send(['message' => 'Vertretungsgesuch gelöscht.']);
} catch (JsonException|InvalidArgumentException|DomainException $exception) { JsonResponse::send(['error' => $exception->getMessage()], 400); }
