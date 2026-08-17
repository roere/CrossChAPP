<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/BniClient.php';
require_once dirname(__DIR__, 2) . '/src/BniRequestPolicy.php';
require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/OrganizationRepository.php';

Auth::requireAdminJson();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405);
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    JsonResponse::send(['error' => 'Der Request enthält kein valides JSON.'], 400);
}

$items = is_array($payload) ? ($payload['items'] ?? null) : null;
if (!is_array($items) || $items === []) {
    JsonResponse::send(['error' => 'Mindestens ein Datensatz muss ausgewählt sein.'], 400);
}

if (count($items) > 50) {
    JsonResponse::send(['error' => 'Maximal 50 Detaildatensätze pro Vorgang sind erlaubt.'], 400);
}

$client = new BniClient();
$repository = new OrganizationRepository((new Database())->connection());
$reload = ($payload['reload'] ?? false) === true;
$results = [];
$processed = [];
$stopBatch = false;

foreach ($items as $index => $item) {
    if (!is_array($item)) {
        $results[] = ['orgId' => null, 'status' => 'error', 'error' => 'Ungültiger Datensatz.'];
        continue;
    }

    $orgId = isset($item['orgId']) ? (int) $item['orgId'] : null;
    if ($orgId === null || $orgId <= 0 || isset($processed[$orgId])) {
        $results[] = ['orgId' => $orgId, 'status' => 'error', 'error' => 'Ungültige oder doppelte Organisations-ID.'];
        continue;
    }
    $processed[$orgId] = true;
    $organization = $repository->find($orgId);

    if ($organization === null) {
        $results[] = ['orgId' => $orgId, 'status' => 'error', 'error' => 'Die Organisation ist lokal nicht vorhanden.'];
        continue;
    }

    if ($organization['detailStatus'] === 'loaded' && !$reload) {
        $results[] = ['orgId' => $orgId, 'status' => 'loaded', 'skipped' => true, 'details' => $organization];
        continue;
    }

    try {
        $details = $client->getChapterDetails((string) $organization['cmsSecurityHash']);
        if (($details['orgId'] ?? null) !== $orgId) {
            throw new RuntimeException('Die BNI-Detailantwort gehört zu einer anderen Organisation.');
        }
        $repository->saveDetails($orgId, $details);
        $results[] = [
            'orgId' => $orgId,
            'status' => 'loaded',
            'skipped' => false,
            'details' => $repository->find($orgId),
        ];
    } catch (HttpException $exception) {
        $stopReason = BniRequestPolicy::stopReason($exception->statusCode);
        if ($stopReason !== null) {
            $stopBatch = true;
            $repository->markDetailNotLoaded($orgId);
            error_log(sprintf(
                'CrossChAPP BNI detail request stopped: org_id=%d http_status=%d reason=%s',
                $orgId,
                $exception->statusCode,
                $stopReason,
            ));
            $results[] = [
                'orgId' => $orgId,
                'status' => $stopReason,
                'retryAfter' => $exception->retryAfterSeconds,
            ];
        } else {
            $repository->markDetailError($orgId);
            error_log(sprintf('CrossChAPP BNI detail request failed: org_id=%d http_status=%d reason=temporary_http_error', $orgId, $exception->statusCode));
            $results[] = ['orgId' => $orgId, 'status' => 'error', 'error' => 'Die BNI-Detailanfrage ist vorübergehend fehlgeschlagen.'];
        }
    } catch (Throwable) {
        $repository->markDetailError($orgId);
        error_log(sprintf('CrossChAPP BNI detail request failed: org_id=%d http_status=0 reason=network_or_response_error', $orgId));
        $results[] = ['orgId' => $orgId, 'status' => 'error', 'error' => 'Die BNI-Detailanfrage ist vorübergehend fehlgeschlagen.'];
    }

    if ($stopBatch) {
        break;
    }

    if ($index < count($items) - 1) {
        usleep(BniRequestPolicy::DETAIL_DELAY_MS * 1000);
    }
}

JsonResponse::send(['results' => $results, 'detail_delay_ms' => BniRequestPolicy::DETAIL_DELAY_MS]);
