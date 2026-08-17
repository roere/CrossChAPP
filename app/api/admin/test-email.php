<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/Auth.php'; require_once dirname(__DIR__, 2) . '/src/Database.php'; require_once dirname(__DIR__, 2) . '/src/JsonResponse.php'; require_once dirname(__DIR__, 2) . '/src/MailService.php'; require_once dirname(__DIR__, 2) . '/src/MailSettingsRepository.php';
Auth::requireAdminJson(); if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405); Auth::requireCsrfJson();
try { $payload = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR); $email = trim((string) ($payload['email'] ?? '')); if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new InvalidArgumentException('Bitte gib eine gültige Test-E-Mail-Adresse an.'); $settings = new MailSettingsRepository((new Database())->connection()); (new MailService($settings))->send($email, 'CrossChAPP Test', 'CrossChAPP Test-E-Mail', "Der SMTP-Versand von CrossChAPP wurde erfolgreich getestet."); JsonResponse::send(['message' => 'Test-E-Mail erfolgreich versendet.']); }
catch (JsonException|InvalidArgumentException $e) { JsonResponse::send(['error' => $e->getMessage()], 400); }
catch (Throwable) { JsonResponse::send(['error' => 'Die Test-E-Mail konnte nicht versendet werden. Bitte prüfe die SMTP-Einstellungen.'], 502); }
