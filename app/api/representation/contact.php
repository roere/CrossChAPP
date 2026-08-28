<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/Auth.php'; require_once dirname(__DIR__, 2) . '/src/Database.php'; require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/RepresentationOfferRepository.php'; require_once dirname(__DIR__, 2) . '/src/RepresentationContactService.php'; require_once dirname(__DIR__, 2) . '/src/MailSettingsRepository.php'; require_once dirname(__DIR__, 2) . '/src/MailService.php';
require_once dirname(__DIR__, 2) . '/src/RepresentationAssignmentService.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405);
$identity = Auth::requireApplicationUserJson(); Auth::requireCsrfJson();
try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR); $database = (new Database())->connection(); $settings = new MailSettingsRepository($database);
    $mailer=new MailService($settings);$assignments=new RepresentationAssignmentService($database,$settings,$mailer);
    (new RepresentationContactService($database, new RepresentationOfferRepository($database), $settings, $mailer,$assignments))->send((int) $identity['user_id'], (int) ($payload['offer_id'] ?? 0), (string) ($payload['requested_date'] ?? ''), (string) ($payload['custom_message'] ?? ''));
    JsonResponse::send(['message' => 'Anfrage wurde gesendet.']);
} catch (JsonException|InvalidArgumentException|DomainException $e) { JsonResponse::send(['error' => $e->getMessage()], 400); }
catch (Throwable) { JsonResponse::send(['error' => 'Die Anfrage konnte nicht gesendet werden.'], 500); }
