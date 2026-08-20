<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/AccountFactory.php'; require_once dirname(__DIR__, 2) . '/src/AccountService.php'; require_once dirname(__DIR__, 2) . '/src/Auth.php'; require_once dirname(__DIR__, 2) . '/src/ClientIp.php'; require_once dirname(__DIR__, 2) . '/src/Database.php'; require_once dirname(__DIR__, 2) . '/src/JsonResponse.php'; require_once dirname(__DIR__, 2) . '/src/MailService.php'; require_once dirname(__DIR__, 2) . '/src/MailSettingsRepository.php'; require_once dirname(__DIR__, 2) . '/src/UserRepository.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405); Auth::requireCsrfJson();
try { $payload = json_decode(file_get_contents('php://input') ?: '', true, 32, JSON_THROW_ON_ERROR); AccountFactory::create()['service']->register(is_array($payload) ? $payload : [], ClientIp::address()); JsonResponse::send(['registered' => true, 'mailSent' => true, 'message' => 'Bitte bestätige deine E-Mail-Adresse.']); }
catch (JsonException|InvalidArgumentException $e) { JsonResponse::send(['error' => $e->getMessage()], 400); }
catch (DomainException $e) { JsonResponse::send(['error' => $e->getMessage()], str_starts_with($e->getMessage(), 'Zu viele BNI-Mitgliederprüfungen') ? 429 : 409); }
catch (RegistrationException $e) { JsonResponse::send(['error' => $e->getMessage(), 'code' => $e->reason], 503); }
catch (RuntimeException $e) { $public=str_starts_with($e->getMessage(),'Die BNI-Mitgliederprüfung')?$e->getMessage():'Das Konto konnte nicht angelegt werden.';JsonResponse::send(['error'=>$public],503); }
catch (Throwable) { JsonResponse::send(['error' => 'Das Konto konnte nicht angelegt werden.'], 500); }
