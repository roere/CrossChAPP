<?php

declare(strict_types=1);

final class PushConfig
{
    public static function ready(): bool
    {
        return self::publicKey() !== '' && trim((string)getenv('CROSSCHAPP_VAPID_PRIVATE_KEY')) !== ''
            && preg_match('~^(mailto:[^\s@]+@[^\s@]+|https://[^\s]+)$~', (string)getenv('CROSSCHAPP_VAPID_SUBJECT')) === 1;
    }
    public static function publicKey(): string { return trim((string)getenv('CROSSCHAPP_VAPID_PUBLIC_KEY')); }
}
