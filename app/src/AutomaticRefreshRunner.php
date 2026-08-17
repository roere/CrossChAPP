<?php

declare(strict_types=1);

require_once __DIR__ . '/BniRequestPolicy.php';
require_once __DIR__ . '/ChapterRefreshService.php';
require_once __DIR__ . '/OrganizationRepository.php';

final class AutomaticRefreshRunner
{
    public function __construct(
        private readonly OrganizationRepository $organizations,
        private readonly ChapterRefreshService $service,
        private readonly ?Closure $delay = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function run(int $staleDays, int $limit): array
    {
        $chapters = $this->organizations->findAutomaticDueChapters($staleDays, min(10, $limit));
        $results = [];
        foreach ($chapters as $index => $chapter) {
            $result = $this->service->refresh($chapter['orgId'], 'automatic', $staleDays);
            $results[] = $result;
            if (in_array($result['status'] ?? '', ['rate_limited', 'forbidden'], true)
                || (($result['status'] ?? '') === 'skipped' && in_array(($result['reason'] ?? ''), ['daily_limit', 'disabled'], true))) {
                break;
            }
            if ($index < count($chapters) - 1) {
                $this->pause();
            }
        }
        return $results;
    }

    private function pause(): void
    {
        if ($this->delay !== null) {
            ($this->delay)(BniRequestPolicy::DETAIL_DELAY_MS);
            return;
        }
        usleep(BniRequestPolicy::DETAIL_DELAY_MS * 1000);
    }
}
