<?php

declare(strict_types=1);

final class HomeChapterVerificationException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly bool $canSkip = false,
        public readonly ?string $technicalReason = null,
    ) {
        parent::__construct($message);
    }
}

final class HomeChapterVerificationService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ?BniMemberDirectoryService $directory,
    ) {}

    /** @return array{result:string,verificationStatus:string,externalRef:?string} */
    public function verify(string $firstName, string $lastName, int $orgId, bool $skip, string $ip = ''): array
    {
        if ($skip) {
            return ['result' => 'skipped', 'verificationStatus' => 'unverified', 'externalRef' => null];
        }
        if ($this->directory === null) {
            throw $this->technical('unavailable');
        }
        if ($this->users->memberCheckRateLimited($ip)) {
            throw $this->technical('rate_limited');
        }

        $this->users->recordMemberCheck($ip);
        try {
            $match = $this->directory->match($firstName, $lastName, $orgId);
        } catch (Throwable) {
            throw $this->technical('upstream_error');
        }

        return match ($match['status'] ?? '') {
            'match' => [
                'result' => 'match',
                'verificationStatus' => 'directory_match',
                'externalRef' => isset($match['externalRef']) ? (string) $match['externalRef'] : null,
            ],
            'not_found' => ['result' => 'not_found', 'verificationStatus' => 'unverified', 'externalRef' => null],
            'ambiguous' => throw new HomeChapterVerificationException(
                'ambiguous',
                'Der BNI-Eintrag konnte nicht eindeutig zugeordnet werden.',
            ),
            'unavailable', 'discovery_invalid', 'upstream_error', 'invalid_member_structure',
            'rate_limited', 'forbidden' => throw $this->technical((string) $match['status']),
            default => throw $this->technical('upstream_error'),
        };
    }

    private function technical(string $technicalReason): HomeChapterVerificationException
    {
        return new HomeChapterVerificationException(
            'technical_unavailable',
            'Die BNI-Mitgliederprüfung ist technisch momentan nicht möglich.',
            true,
            $technicalReason,
        );
    }
}
