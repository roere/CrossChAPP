<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/BniClient.php';
require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/OrganizationRepository.php';

Auth::requireAdminJson();
Auth::requireCsrfJson();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    JsonResponse::send(['error' => 'Nur GET ist erlaubt.'], 405);
}

$pageUrl = trim((string) ($_GET['url'] ?? ''));

try {
    BniClient::assertAllowedPageUrl($pageUrl);
    $organizations = (new BniClient())->getMapOrganizations();
    $repository = new OrganizationRepository((new Database())->connection());
    $repository->upsertMapOrganizations($organizations);
    $statistics = $repository->statistics();
    JsonResponse::send([
        ...$statistics,
        'organizations' => $repository->all(),
    ]);
} catch (InvalidArgumentException $exception) {
    JsonResponse::send(['error' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    JsonResponse::send(['error' => $exception->getMessage()], 502);
}
