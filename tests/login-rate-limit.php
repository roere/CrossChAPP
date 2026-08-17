<?php

declare(strict_types=1);

$root = is_file(__DIR__ . '/../app/src/Database.php') ? __DIR__ . '/../app' : '/var/www/html';
foreach (['Database', 'UserRepository', 'MailSettingsRepository', 'MailService', 'AccountService'] as $class) require_once $root . '/src/' . $class . '.php';
$check = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$pdo = (new Database(':memory:'))->connection(); $users = new UserRepository($pdo); $settings = new MailSettingsRepository($pdo);
$service = new AccountService($users, $settings, new MailService($settings, static function (): void {}));
$user = $users->create('Rate', 'User', 'rate-user@example.test', password_hash('user-richtig', PASSWORD_DEFAULT), null);
$userTwo = $users->create('Rate', 'Two', 'rate-two@example.test', password_hash('user-zwei-richtig', PASSWORD_DEFAULT), null);
$activate = $pdo->prepare("UPDATE users SET status='active', email_verified_at=CURRENT_TIMESTAMP WHERE id=:id");
$activate->execute([':id' => $user['id']]); $activate->execute([':id' => $userTwo['id']]);
$ip = '192.0.2.10';

$login = static function (string $identifier, string $password) use ($users, $service, $ip): string {
    if ($users->rateLimited('login', $identifier, $ip, 5, 900)) return 'rate_limited';
    $result = $service->authenticate($identifier, $password);
    $users->recordAttempt('login', $identifier, $ip, $result['status'] === 'success');
    return $result['status'];
};

for ($attempt = 0; $attempt < 5; $attempt++) $check($login('rate-user@example.test', 'falsch') === 'invalid', 'Fünf Fehlversuche werden neutral abgewiesen.');
$check($login('rate-user@example.test', 'user-richtig') === 'rate_limited', 'User ist nach fünf Fehlversuchen begrenzt.');
$check($login('admin', 'admin') === 'success', 'Admin derselben IP bleibt beim ersten korrekten Versuch erreichbar.');

for ($attempt = 0; $attempt < 5; $attempt++) $check($login('admin', 'falsch') === 'invalid', 'Admin-Fehlversuch wird im Adminbucket gezählt.');
$check($login('admin', 'admin') === 'rate_limited', 'Adminbucket ist nach fünf Fehlversuchen begrenzt.');
$check($login('rate-two@example.test', 'user-zwei-richtig') === 'success', 'Adminbucket sperrt einen normalen Benutzer derselben IP nicht.');

echo "PASS Login-Rate-Limit: Identifier+IP trennt Benutzer, Admin und unbekannte Identitäten\n";
