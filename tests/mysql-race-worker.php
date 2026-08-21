<?php

declare(strict_types=1);

require_once '/var/www/html/src/Database.php';
require_once '/var/www/html/src/AutomationRepository.php';
require_once '/var/www/html/src/InvitationRepository.php';
require_once '/var/www/html/src/RepresentationOfferRepository.php';
require_once '/var/www/html/src/UserRepository.php';
require_once '/var/www/html/src/MailSettingsRepository.php';
require_once '/var/www/html/src/MailService.php';
require_once '/var/www/html/src/RepresentationAssignmentService.php';

$task = $argv[1] ?? '';
$db = (new Database())->connection();

try {
    $won = match ($task) {
        'daily-limit' => (new AutomationRepository($db))->startLimitedLog(910001, 'automatic', 3) !== null,
        'worker-lock' => (new AutomationRepository($db))->acquireLock(910001, bin2hex(random_bytes(8)), 60),
        'login-attempt' => (function () use ($db): bool {
            $users = new UserRepository($db);
            $users->findByLogin('check-a@example.test');
            $users->recordAttempt('login', 'mysql-race-login@example.test', '127.0.0.99', false);
            return true;
        })(),
        'offer' => (function () use ($db): bool {
            $userId = (int) $db->query("SELECT id FROM users WHERE email='check-c@example.test'")->fetchColumn();
            (new RepresentationOfferRepository($db))->createMany($userId, [910001], true, []);
            return true;
        })(),
        'invitation' => (function () use ($db): bool {
            $adminId = (int) $db->query("SELECT id FROM users WHERE role='admin'")->fetchColumn();
            (new InvitationRepository($db))->create('Race', 'Test', 'mysql-race@example.test', 910001, $adminId);
            return true;
        })(),
        'assignment' => (function () use ($db): bool {
            $userId=(int)$db->query("SELECT id FROM users WHERE email='check-b@example.test'")->fetchColumn();
            $settings=new MailSettingsRepository($db);
            (new RepresentationAssignmentService($db,$settings,new MailService($settings,static function():void{})))->accept(str_repeat('a',43),$userId);
            return true;
        })(),
        default => throw new InvalidArgumentException('Unbekannter Race-Test.'),
    };
    exit($won ? 0 : 10);
} catch (DomainException|PDOException) {
    exit(10);
}
