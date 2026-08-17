<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/AccountFactory.php'; require_once dirname(__DIR__, 2) . '/src/AccountService.php'; require_once dirname(__DIR__, 2) . '/src/Auth.php'; require_once dirname(__DIR__, 2) . '/src/Database.php'; require_once dirname(__DIR__, 2) . '/src/JsonResponse.php'; require_once dirname(__DIR__, 2) . '/src/MailService.php'; require_once dirname(__DIR__, 2) . '/src/MailSettingsRepository.php'; require_once dirname(__DIR__, 2) . '/src/UserRepository.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405); Auth::requireCsrfJson();
$message = 'Wenn ein unbestätigtes Konto existiert, wurde eine neue Bestätigungs-E-Mail versendet.';
try { $payload = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR); $email = strtolower(trim((string) ($payload['email'] ?? ''))); $account = AccountFactory::create(); $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'); if (!$account['users']->rateLimited('resend_verification', $email, $ip, 3, 3600)) { $account['users']->recordAttempt('resend_verification', $email, $ip, false); $user = $account['users']->findByLogin($email); if ($user !== null && $user['status'] === 'pending') $account['service']->sendVerification($user); } } catch (Throwable) {}
JsonResponse::send(['message' => $message]);
