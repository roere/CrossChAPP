<?php

declare(strict_types=1);

$appRoot = is_file(__DIR__ . '/../app/src/Database.php') ? __DIR__ . '/../app' : '/var/www/html';
require_once $appRoot . '/src/Database.php';
require_once $appRoot . '/src/OrganizationRepository.php';

$repository = new OrganizationRepository((new Database(':memory:'))->connection());
$organizations = [];
for ($id = 1; $id <= 60; $id++) {
    $organizations[] = [
        'orgId' => $id,
        'cmsSecurityHash' => 'hash-' . $id,
        'countryCode' => 'DE',
        'orgType' => 'CHAPTER',
        'longitude' => 7.0,
        'latitude' => 50.0,
    ];
}
$organizations[] = [
    'orgId' => 1000,
    'cmsSecurityHash' => 'core',
    'countryCode' => 'DE',
    'orgType' => 'CORE_GROUP',
    'longitude' => 7.0,
    'latitude' => 50.0,
];
$repository->upsertMapOrganizations($organizations);
for ($id = 1; $id <= 5; $id++) {
    $repository->saveDetails($id, ['chapterName' => 'Geladen ' . $id]);
}
$repository->markDetailError(6);

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$statistics = $repository->chapterDetailStatistics();
$check($statistics === ['total' => 60, 'loaded' => 5, 'missing' => 55, 'error' => 1], 'Chapter-Detailstatistik.');

foreach ([10, 25, 50] as $limit) {
    $pending = $repository->findPendingChapters($limit);
    $check(count($pending) === $limit, $limit . 'er-Limit.');
    $check($pending[0]['orgId'] === 6, 'Stabile Reihenfolge und Fehler erneut berücksichtigen.');
    $check(!in_array(1000, array_column($pending, 'orgId'), true), 'CORE_GROUP ausschließen.');
    $check(array_intersect([1, 2, 3, 4, 5], array_column($pending, 'orgId')) === [], 'Geladene Chapter ausschließen.');
}

$rejected = false;
try {
    $repository->findPendingChapters(51);
} catch (InvalidArgumentException) {
    $rejected = true;
}
$check($rejected, 'Serverseitiges 50er-Maximum.');

echo "PASS Pending-Chapter: Statistik, Typfilter, Statusfilter, Reihenfolge und Limits\n";
