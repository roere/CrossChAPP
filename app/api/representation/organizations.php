<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/OrganizationRepository.php';
require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/UserRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    JsonResponse::send(['error' => 'Nur GET ist erlaubt.'], 405);
}

try {
    $database = (new Database())->connection();
    $organizations = (new OrganizationRepository($database))->representationOrganizations();
    $identity = Auth::user();
    $user = $identity === null ? null : (new UserRepository($database))->findById((int) $identity['user_id']);
    JsonResponse::send(['count' => count($organizations), 'organizations' => $organizations, 'viewer' => [
        'authenticated' => $user !== null, 'role' => $user['role'] ?? null,
        'homeChapterOrgId' => isset($user['home_chapter_org_id']) ? (int) $user['home_chapter_org_id'] : null,
    ]]);
} catch (Throwable) {
    JsonResponse::send(['error' => 'Die lokale Chapterliste konnte nicht gelesen werden.'], 500);
}
