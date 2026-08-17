<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/AccountFactory.php'; require_once dirname(__DIR__, 2) . '/src/AccountService.php'; require_once dirname(__DIR__, 2) . '/src/Auth.php'; require_once dirname(__DIR__, 2) . '/src/Database.php'; require_once dirname(__DIR__, 2) . '/src/JsonResponse.php'; require_once dirname(__DIR__, 2) . '/src/MailService.php'; require_once dirname(__DIR__, 2) . '/src/MailSettingsRepository.php'; require_once dirname(__DIR__, 2) . '/src/UserRepository.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405); Auth::requireCsrfJson();
try { $payload = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR); $ok = AccountFactory::create()['service']->verify((string) ($payload['token'] ?? '')); if (!$ok) JsonResponse::send(['error' => 'Der Bestätigungslink ist ungültig oder abgelaufen.'], 400); JsonResponse::send(['verified' => true, 'message' => 'Deine E-Mail-Adresse wurde bestätigt. Du kannst dich jetzt anmelden.']); }
catch (JsonException) { JsonResponse::send(['error' => 'Ungültige Anfrage.'], 400); }
