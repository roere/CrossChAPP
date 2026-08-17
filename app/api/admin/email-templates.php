<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/Auth.php'; require_once dirname(__DIR__, 2) . '/src/Database.php'; require_once dirname(__DIR__, 2) . '/src/JsonResponse.php'; require_once dirname(__DIR__, 2) . '/src/MailSettingsRepository.php';
Auth::requireAdminJson(); $repository = new MailSettingsRepository((new Database())->connection());
if ($_SERVER['REQUEST_METHOD'] === 'GET') JsonResponse::send(['templates' => $repository->templates()]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::send(['error' => 'Nur GET und POST sind erlaubt.'], 405); Auth::requireCsrfJson();
try { $payload = json_decode(file_get_contents('php://input') ?: '', true, 16, JSON_THROW_ON_ERROR); foreach (['verify_email', 'reset_password'] as $key) { $template = $payload[$key] ?? null; if (!is_array($template)) throw new InvalidArgumentException('Die E-Mail-Vorlagen sind unvollständig.'); $repository->saveTemplate($key, (string) ($template['subject'] ?? ''), (string) ($template['body'] ?? '')); } JsonResponse::send(['templates' => $repository->templates()]); }
catch (JsonException|InvalidArgumentException $e) { JsonResponse::send(['error' => $e->getMessage()], 400); }
