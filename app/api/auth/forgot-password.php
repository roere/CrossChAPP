<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/AccountFactory.php'; require_once dirname(__DIR__, 2) . '/src/AccountService.php'; require_once dirname(__DIR__, 2) . '/src/Auth.php'; require_once dirname(__DIR__, 2) . '/src/ClientIp.php'; require_once dirname(__DIR__, 2) . '/src/Database.php'; require_once dirname(__DIR__, 2) . '/src/JsonResponse.php'; require_once dirname(__DIR__, 2) . '/src/MailService.php'; require_once dirname(__DIR__, 2) . '/src/MailSettingsRepository.php'; require_once dirname(__DIR__, 2) . '/src/UserRepository.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405); Auth::requireCsrfJson();
$neutral = 'Wenn ein Konto mit dieser E-Mail-Adresse existiert, wurde ein Link zum Zurücksetzen des Passworts versendet.';
try { $payload = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR); $email = strtolower(trim((string) ($payload['email'] ?? ''))); $account = AccountFactory::create(); $ip = ClientIp::address(); if (!$account['users']->rateLimited('password_reset', $email, $ip, 5, 3600)) { $account['users']->recordAttempt('password_reset', $email, $ip, false); $account['service']->requestPasswordReset($email); } } catch (Throwable) {}
JsonResponse::send(['message' => $neutral]);
