<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/OrganizationRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    JsonResponse::send(['error' => 'Nur GET ist erlaubt.'], 405);
}

try {
    $organizations = (new OrganizationRepository((new Database())->connection()))->representationOrganizations();
    JsonResponse::send(['count' => count($organizations), 'organizations' => $organizations]);
} catch (Throwable) {
    JsonResponse::send(['error' => 'Die lokale Chapterliste konnte nicht gelesen werden.'], 500);
}
