<?php

declare(strict_types=1);

require_once __DIR__ . '/AutomationRepository.php';
require_once __DIR__ . '/BniClient.php';
require_once __DIR__ . '/HttpException.php';
require_once __DIR__ . '/OrganizationRepository.php';

final class MapRefreshService
{
    public function __construct(
        private readonly OrganizationRepository $organizations,
        private readonly AutomationRepository $automation,
        private readonly BniClient $client = new BniClient(),
    ) {}

    /** @return array<string,mixed> */
    public function refresh(string $trigger): array
    {
        if (!in_array($trigger, ['map_manual', 'map_automatic'], true)) throw new InvalidArgumentException('Ungültiger Grunddatentrigger.');
        $token = bin2hex(random_bytes(16));
        if (!$this->automation->acquireMapLock($token)) return ['status' => 'skipped', 'reason' => 'locked'];
        $logId = $this->automation->startMapLog($trigger);
        try {
            $organizations = $this->client->getMapOrganizations();
            $this->organizations->upsertMapOrganizations($organizations);
            $this->automation->markMapRefreshSuccess(); $this->automation->finishMapLog($logId, 'success', 200);
            return ['status' => 'success', 'count' => count($organizations)];
        } catch (HttpException $exception) {
            $status = $exception->statusCode === 429 ? 'rate_limited' : ($exception->statusCode === 403 ? 'forbidden' : 'error');
            if ($exception->statusCode === 429) $this->automation->setMapRetryAfter($exception->retryAfterSeconds);
            $this->automation->finishMapLog($logId, $status, $exception->statusCode, $status);
            error_log(sprintf('CrossChAPP map refresh failed: trigger=%s http_status=%d category=%s', $trigger, $exception->statusCode, $status));
            return ['status' => $status, 'httpStatus' => $exception->statusCode, 'retryAfter' => $exception->retryAfterSeconds];
        } catch (Throwable $exception) {
            $this->automation->finishMapLog($logId, 'error', null, 'network_or_payload');
            error_log(sprintf('CrossChAPP map refresh failed: trigger=%s category=network_or_payload', $trigger));
            return ['status' => 'error'];
        } finally {
            $this->automation->releaseMapLock($token);
        }
    }
}
