<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/BniGlobalThrottle.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/BniRequestNotStartedException.php';
require_once __DIR__ . '/BniRequestEventRepository.php';

final class BniClient
{
    private const MAP_ENDPOINT = 'https://bni.de/web/open/getMapData';
    private const DETAIL_ENDPOINT = 'https://bni.de/bnicms/v3/frontend/consume/chapterInfo/';

    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
        private ?BniGlobalThrottle $throttle = null,
        private ?BniRequestEventRepository $events = null,
        private readonly bool $transportIsExternal = false,
    )
    {
    }

    public static function assertAllowedPageUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (
            $scheme !== 'https'
            || $host === ''
            || !($host === 'bni.de' || str_ends_with($host, '.bni.de'))
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new InvalidArgumentException('Erlaubt sind ausschließlich HTTPS-Links zu bni.de oder Subdomains von bni.de.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function getMapOrganizations(?Closure $beforeRequest = null,?string $triggerType=null): array
    {
        $query = http_build_query([
            'orgIds' => '5723,5768',
            'domain' => 'bni.de',
            'localeString' => 'de',
            'hideFileExtension' => 'true',
            'cmsv3' => 'true',
            'isOldVersion' => 'false',
        ]);
        $payload = $this->getJson(self::MAP_ENDPOINT . '?' . $query, 'map', $beforeRequest,$triggerType);
        $organizations = $payload['orgMaps'] ?? null;

        if (!is_array($organizations)) {
            throw new RuntimeException('Die BNI-Kartenantwort enthält keine Organisationsliste.');
        }

        $result = [];
        foreach ($organizations as $organization) {
            if (!is_array($organization)) {
                continue;
            }

            $coordinates = explode(',', (string) ($organization['coordinates'] ?? ''), 2);
            $longitude = $this->coordinate($coordinates[0] ?? null);
            $latitude = $this->coordinate($coordinates[1] ?? null);

            $result[] = [
                'orgId' => isset($organization['orgId']) ? (int) $organization['orgId'] : null,
                'cmsSecurityHash' => $this->nullableString($organization['cmsSecurityHash'] ?? null),
                'countryCode' => $this->nullableString($organization['countryCode'] ?? null),
                'orgType' => $this->nullableString($organization['orgType'] ?? null),
                'longitude' => $longitude,
                'latitude' => $latitude,
                'windowHtml' => $this->nullableString($organization['windowHtml'] ?? null),
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function getChapterDetails(string $encodedChapterId, ?Closure $beforeRequest = null,?string $triggerType=null): array
    {
        if ($encodedChapterId === '' || strlen($encodedChapterId) > 512) {
            throw new InvalidArgumentException('Die Detail-ID fehlt oder ist ungültig.');
        }

        // getMapData liefert den Base64-Hash bereits URL-kodiert. Vor dem
        // erneuten Aufbau der Query genau einmal dekodieren, damit % nicht
        // als %25 doppelt kodiert wird.
        $encodedChapterId = rawurldecode($encodedChapterId);

        $url = self::DETAIL_ENDPOINT . '?' . http_build_query([
            'encodedChapterId' => $encodedChapterId,
            'locale' => 'de',
        ]);
        $payload = $this->getJson($url, 'chapter_detail', $beforeRequest,$triggerType);
        $content = $payload['content'] ?? null;
        $details = is_array($content) ? ($content['chapterDetails'] ?? null) : null;

        if (!is_array($content) || !is_array($details)) {
            throw new RuntimeException('Die BNI-Detailantwort hat ein unerwartetes Format.');
        }

        $timezone = is_array($details['timezone'] ?? null) ? $details['timezone'] : [];

        return [
            'orgId' => isset($content['orgId']) ? (int) $content['orgId'] : null,
            'chapterName' => $this->nullableString($details['name'] ?? null),
            'region' => $this->nullableString($details['regionName'] ?? null),
            'regionId' => isset($details['region']) ? (int) $details['region'] : null,
            'city' => $this->nullableString($details['city'] ?? null),
            'postalCode' => $this->nullableString($details['postalCode'] ?? null),
            'street' => $this->nullableString($details['addressLine1'] ?? null),
            'venue' => $this->nullableString($details['locationName'] ?? null),
            'meetingDay' => $this->nullableString($details['meetingDay'] ?? null),
            'meetingTime' => $this->nullableString($details['meetingTime'] ?? null),
            'meetingType' => $this->nullableString($details['meetingTypeText'] ?? $details['meetingType'] ?? null),
            'meetingDuration' => isset($details['meetingDuration']) ? (int) $details['meetingDuration'] : null,
            'memberCount' => isset($details['totalMemberCount']) ? (int) $details['totalMemberCount'] : null,
            'chapterUrl' => $this->nullableString($details['chapterUrl'] ?? null),
            'visitorRegistrationUrl' => $this->nullableString($details['visitChapterUrl'] ?? null),
            'onlineMeetingUrl' => $this->nullableString($details['onlineMeetingLink'] ?? null),
            'timezone' => $this->nullableString($timezone['zoneId'] ?? null),
            'status' => $this->nullableString($details['chapterType'] ?? $content['orgType'] ?? null),
            'description' => $this->nullableString($details['chapterText'] ?? null),
        ];
    }

    private function coordinate(mixed $value): ?float
    {
        $value = is_string($value) ? trim($value) : $value;
        return is_numeric($value) ? (float) $value : null;
    }

    /** @return array<string, mixed> */
    private function getJson(string $url, string $requestType, ?Closure $beforeRequest,?string $triggerType): array
    {
        $external=$this->http->usesExternalTransport()||$this->transportIsExternal;
        if ($external) {
            $this->http->assertExternalAllowed();
            if($this->throttle===null||$this->events===null){$database=(new Database())->connection();$this->throttle??=new BniGlobalThrottle($database);$this->events??=new BniRequestEventRepository($database);}
            $this->throttle->awaitStartSlot($requestType);
        }
        if ($beforeRequest !== null) {
            $beforeRequest();
        }
        $eventId=$external?$this->events?->start($requestType,$triggerType):null;
        try{$result=$this->http->getJson($url);if($eventId!==null)$this->events?->finish($eventId,200,'success');return$result;}
        catch(HttpException $exception){if($eventId!==null)$this->events?->finish($eventId,$exception->statusCode,$exception->statusCode===429?'rate_limited':($exception->statusCode===403?'forbidden':'http_error'));throw$exception;}
        catch(Throwable $exception){if($eventId!==null)$this->events?->finish($eventId,null,'network_error');throw$exception;}
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
