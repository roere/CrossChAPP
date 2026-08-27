<?php

declare(strict_types=1);

require_once __DIR__ . '/AutomationRepository.php';
require_once __DIR__ . '/BniClient.php';
require_once __DIR__ . '/BniRequestPolicy.php';
require_once __DIR__ . '/BniRequestNotStartedException.php';
require_once __DIR__ . '/OrganizationRepository.php';

final class ChapterRefreshService
{
    private const TRIGGERS = ['manual', 'usage_search', 'usage_detail', 'automatic'];

    public function __construct(
        private readonly OrganizationRepository $organizations,
        private readonly AutomationRepository $automation,
        private readonly BniClient $client = new BniClient(),
    ) {
    }

    /** @return array<string, mixed> */
    public function refresh(int $orgId, string $triggerType, ?int $staleDays = null, bool $force = false): array
    {
        if (!in_array($triggerType, self::TRIGGERS, true)) {
            throw new InvalidArgumentException('Ungültiger Aktualisierungsauslöser.');
        }
        $settings = $this->automation->settings();
        if (in_array($triggerType, ['usage_search', 'usage_detail'], true) && !$settings['usageRefreshEnabled']) {
            return ['orgId' => $orgId, 'status' => 'skipped', 'reason' => 'disabled'];
        }
        if ($triggerType === 'automatic' && !$settings['automaticRefreshEnabled']) {
            return ['orgId' => $orgId, 'status' => 'skipped', 'reason' => 'disabled'];
        }
        $organization = $this->organizations->find($orgId);
        if ($organization === null) {
            return ['orgId' => $orgId, 'status' => 'skipped', 'reason' => 'not_found'];
        }
        if (!is_string($organization['cmsSecurityHash'] ?? null) || trim($organization['cmsSecurityHash']) === '') {
            return ['orgId' => $orgId, 'status' => 'skipped', 'reason' => 'missing_hash'];
        }
        $isDue = $triggerType === 'automatic'
            ? ($staleDays !== null && $this->organizations->isAutomaticDueChapter($orgId, $staleDays))
            : ($staleDays !== null && $this->organizations->isStaleLoadedChapter($orgId, $staleDays));
        if (!$force && !$isDue) {
            return ['orgId' => $orgId, 'status' => 'skipped', 'reason' => 'fresh'];
        }

        $ownerToken = bin2hex(random_bytes(16));
        if (!$this->automation->acquireLock($orgId, $ownerToken)) {
            $this->automation->recordSkipped($orgId, $triggerType, 'locked');
            return ['orgId' => $orgId, 'status' => 'skipped', 'reason' => 'locked'];
        }

        $logId = null;
        try {
            $details = $this->client->getChapterDetails((string) $organization['cmsSecurityHash'], function () use (&$logId, $orgId, $triggerType, $settings): void {
                $logId = $triggerType === 'manual'
                    ? $this->automation->startLog($orgId, $triggerType)
                    : $this->automation->startLimitedLog($orgId, $triggerType, (int) $settings['automaticRefreshDailyLimit']);
                if ($logId === null) throw new BniRequestNotStartedException('daily_limit');
            });
            if (($details['orgId'] ?? null) !== $orgId) {
                throw new RuntimeException('Die BNI-Detailantwort gehört zu einer anderen Organisation.');
            }
            $this->organizations->saveDetails($orgId, $details);
            $this->automation->finishLog($logId, 'success');
            return ['orgId' => $orgId, 'status' => 'success', 'details' => $this->organizations->find($orgId)];
        } catch (BniRequestNotStartedException) {
            return ['orgId' => $orgId, 'status' => 'skipped', 'reason' => 'daily_limit'];
        } catch (HttpException $exception) {
            $stopReason = BniRequestPolicy::stopReason($exception->statusCode);
            if ($stopReason !== null) {
                if ($logId !== null) $this->automation->finishLog($logId, $stopReason, $exception->statusCode, $stopReason);
                error_log(sprintf('CrossChAPP refresh stopped: org_id=%d http_status=%d reason=%s trigger=%s', $orgId, $exception->statusCode, $stopReason, $triggerType));
                return ['orgId' => $orgId, 'status' => $stopReason, 'retryAfter' => $exception->retryAfterSeconds];
            }
            $this->organizations->preserveLoadedOrMarkError($orgId);
            if ($logId !== null) $this->automation->finishLog($logId, 'error', $exception->statusCode, 'temporary_http_error');
            error_log(sprintf('CrossChAPP refresh failed: org_id=%d http_status=%d trigger=%s', $orgId, $exception->statusCode, $triggerType));
            return ['orgId' => $orgId, 'status' => 'error'];
        } catch (Throwable) {
            $this->organizations->preserveLoadedOrMarkError($orgId);
            if ($logId !== null) $this->automation->finishLog($logId, 'error', null, 'network_or_response_error');
            error_log(sprintf('CrossChAPP refresh failed: org_id=%d http_status=0 trigger=%s', $orgId, $triggerType));
            return ['orgId' => $orgId, 'status' => 'error'];
        } finally {
            $this->automation->releaseLock($orgId, $ownerToken);
        }
    }
}
