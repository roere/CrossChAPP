<?php

declare(strict_types=1);
final class RepresentationCleanupService
{
    public function __construct(private readonly PDO $database) {}

    /** @return array{requests:int,offerDates:int,offers:int} */
    public function runCleanup(): array
    {
        return ['requests'=>0,'offerDates'=>0,'offers'=>0];
    }
}
