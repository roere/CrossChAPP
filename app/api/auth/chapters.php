<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/Database.php'; require_once dirname(__DIR__, 2) . '/src/JsonResponse.php'; require_once dirname(__DIR__, 2) . '/src/UserRepository.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') JsonResponse::send(['error' => 'Nur GET ist erlaubt.'], 405);
$chapters = (new UserRepository((new Database())->connection()))->homeChapters(); JsonResponse::send(['count' => count($chapters), 'chapters' => $chapters]);
