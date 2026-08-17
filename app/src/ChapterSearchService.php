<?php

declare(strict_types=1);

final class ChapterSearchService
{
    private const EARLY_CUTOFF_MINUTES = 9 * 60;
    public function __construct(private readonly OrganizationRepository $repository)
    {
    }

    /**
     * @param list<string> $days
     * @return array{results: list<array<string, mixed>>, totalMatching: int}
     */
    public function search(
        float $latitude,
        float $longitude,
        array $days,
        string $timeFilter,
        string $sort,
        ?int $limit = 10,
    ): array
    {
        $results = [];
        foreach ($this->repository->searchableChapters() as $chapter) {
            if ($days !== [] && !in_array($chapter['meetingDay'], $days, true)) {
                continue;
            }

            $meetingMinutes = self::timeToMinutes((string) $chapter['meetingTime']);
            if ($timeFilter === 'early' && ($meetingMinutes === null || $meetingMinutes >= self::EARLY_CUTOFF_MINUTES)) {
                continue;
            }
            if ($timeFilter === 'late' && ($meetingMinutes === null || $meetingMinutes < self::EARLY_CUTOFF_MINUTES)) {
                continue;
            }

            $chapter['distanceKm'] = self::haversine(
                $latitude,
                $longitude,
                (float) $chapter['latitude'],
                (float) $chapter['longitude'],
            );
            $chapter['meetingMinutes'] = $meetingMinutes;
            $results[] = $chapter;
        }

        usort($results, match ($sort) {
            'time' => fn (array $a, array $b) => ($a['meetingMinutes'] ?? PHP_INT_MAX) <=> ($b['meetingMinutes'] ?? PHP_INT_MAX)
                ?: $a['distanceKm'] <=> $b['distanceKm'],
            'members' => fn (array $a, array $b) => ($b['memberCount'] ?? -1) <=> ($a['memberCount'] ?? -1)
                ?: $a['distanceKm'] <=> $b['distanceKm'],
            default => fn (array $a, array $b) => $a['distanceKm'] <=> $b['distanceKm'],
        });

        return [
            'results' => $limit === null ? $results : array_slice($results, 0, $limit),
            'totalMatching' => count($results),
        ];
    }

    public function dataBasisCount(): int
    {
        return $this->repository->searchableChapterCount();
    }

    public static function haversine(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $earthRadiusKm = 6371.0088;
        $latitudeDelta = deg2rad($latitudeB - $latitudeA);
        $longitudeDelta = deg2rad($longitudeB - $longitudeA);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($longitudeDelta / 2) ** 2;
        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public static function timeToMinutes(string $time): ?int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $matches) !== 1) {
            return null;
        }
        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        return $hours <= 23 && $minutes <= 59 ? ($hours * 60) + $minutes : null;
    }
}
