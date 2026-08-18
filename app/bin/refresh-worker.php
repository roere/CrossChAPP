<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/AutomationRepository.php';
require_once dirname(__DIR__) . '/src/AutomaticRefreshRunner.php';
require_once dirname(__DIR__) . '/src/BniRequestPolicy.php';
require_once dirname(__DIR__) . '/src/ChapterRefreshService.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/OrganizationRepository.php';
require_once dirname(__DIR__) . '/src/MapRefreshService.php';
require_once dirname(__DIR__) . '/src/RepresentationCleanupService.php';

$runOnce = in_array('--once', $argv, true);
$requestedLimit = null;
foreach ($argv as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $matches) === 1) {
        $requestedLimit = (int) $matches[1];
    }
}

do {
    $database = (new Database())->connection();
    $automation = new AutomationRepository($database);
    $organizations = new OrganizationRepository($database);
    $settings = $automation->settings();
    $interval = $settings['automaticRefreshIntervalMinutes'];
    $automation->updateWorkerRuntime($interval);
    (new RepresentationCleanupService($database))->runCleanup();

    if ($settings['mapRefreshEnabled'] && $automation->mapRefreshDue($settings['mapRefreshDays'])) {
        (new MapRefreshService($organizations, $automation))->refresh('map_automatic');
    }

    if ($settings['automaticRefreshEnabled']) {
        $limit = $settings['automaticRefreshBatchSize'];
        if ($requestedLimit !== null) {
            $limit = max(1, min($limit, $requestedLimit));
        }
        $service = new ChapterRefreshService($organizations, $automation);
        (new AutomaticRefreshRunner($organizations, $service))->run($settings['automaticRefreshDays'], $limit);
    }

    if (!$runOnce) {
        sleep($interval * 60);
    }
} while (!$runOnce);
