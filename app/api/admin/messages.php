<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/UserFacingErrorLogger.php';

Auth::requireAdminJson();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') JsonResponse::send(['error' => 'Nur GET ist erlaubt.'], 405);
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 100;
JsonResponse::send(['messages' => (new UserFacingErrorLogger((new Database())->connection()))->latest($limit)]);
