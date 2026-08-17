<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/OrganizationRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    JsonResponse::send(['error' => 'Nur GET ist erlaubt.'], 405);
}

try {
    $repository = new OrganizationRepository((new Database())->connection());
    JsonResponse::send([
        ...$repository->statistics(),
        'organizations' => $repository->all(),
    ]);
} catch (Throwable $exception) {
    JsonResponse::send(['error' => 'Die lokale Datenbank konnte nicht gelesen werden.'], 500);
}
