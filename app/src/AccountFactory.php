<?php

declare(strict_types=1);

final class AccountFactory
{
    /** @return array{users:UserRepository,mailSettings:MailSettingsRepository,service:AccountService} */
    public static function create(?Closure $transport = null, ?Closure $directoryTransport = null, ?Closure $directoryDelay = null): array
    {
        $database = (new Database())->connection(); $users = new UserRepository($database); $mailSettings = new MailSettingsRepository($database);
        require_once __DIR__ . '/BniMemberDirectoryService.php';
        return ['users' => $users, 'mailSettings' => $mailSettings, 'service' => new AccountService($users, $mailSettings, new MailService($mailSettings, $transport), new BniMemberDirectoryService($database, $directoryTransport, $directoryDelay))];
    }
}
