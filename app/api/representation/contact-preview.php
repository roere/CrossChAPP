<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/Auth.php'; require_once dirname(__DIR__, 2) . '/src/Database.php'; require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/RepresentationOfferRepository.php'; require_once dirname(__DIR__, 2) . '/src/RepresentationContactService.php'; require_once dirname(__DIR__, 2) . '/src/MailSettingsRepository.php'; require_once dirname(__DIR__, 2) . '/src/MailService.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405);
$identity = Auth::requireUserJson(); if (($identity['role'] ?? null) !== 'user') JsonResponse::send(['error' => 'Ein Benutzerkonto ist erforderlich.'], 403); Auth::requireCsrfJson();
try { $payload = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR); $database = (new Database())->connection(); $settings = new MailSettingsRepository($database);
    $preview = (new RepresentationContactService($database, new RepresentationOfferRepository($database), $settings, new MailService($settings)))->preview((int) $identity['user_id'], (int) ($payload['offer_id'] ?? 0), (string) ($payload['requested_date'] ?? ''), (string) ($payload['custom_message'] ?? ''));
    JsonResponse::send(['preview' => $preview, 'hint' => $settings->contactHint()]);
} catch (JsonException|InvalidArgumentException|DomainException $e) { JsonResponse::send(['error' => $e->getMessage()], 400); }
catch (Throwable) { JsonResponse::send(['error' => 'Die Vorschau konnte nicht geladen werden.'], 500); }
