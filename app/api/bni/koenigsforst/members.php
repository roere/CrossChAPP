<?php

header('Content-Type: application/json; charset=utf-8');

$url = 'https://bni-rheinruhr.de/bnicms/v3/frontend/memberlist/display';

$data = [
    'parameters' => 'chapterName=44628&regionIds=11805,5843,9614,5925,5921,5939,11553&chapterWebsite=1',
    'languages' => '{"availableLanguages":[{"type":"published","url":"http://bni-rheinruhr.de/koenigsforst/de/memberlist","descriptionKey":"Deutsch","id":18,"localeCode":"de"}],"activeLanguage":{"id":18,"localeCode":"de","descriptionKey":"Deutsch","cookieBotCode":"de"}}',
    'cmsv3' => 'true',
    'website_type' => '3',
    'website_id' => '27966',
    'mappedWidgetSettings' => '[{"key":113,"name":"Member Names","value":"Namen der Mitglieder"},{"key":117,"name":"Profession/Specialty","value":"Wirtschaftszweig/Fachgebiet"},{"key":118,"name":"Company","value":"Unternehmen"},{"key":119,"name":"Showing","value":"Zeige"},{"key":120,"name":"to","value":"bis"},{"key":121,"name":"of","value":"von"},{"key":122,"name":"entries","value":"Einträgen"},{"key":304,"name":"Zero Records","value":"Keine Einträge gefunden"},{"key":343,"name":"Phone","value":"Telefon"},{"key":344,"name":"Send Mail","value":"Nachricht senden"}]',
    'pageMode' => 'Live_Site'
];

$options = [
    'http' => [
        'method' => 'POST',
        'header' =>
            "Content-Type: application/x-www-form-urlencoded; charset=UTF-8\r\n" .
            "X-Requested-With: XMLHttpRequest\r\n" .
            "Referer: https://bni-rheinruhr.de/koenigsforst/de/memberlist\r\n" .
            "User-Agent: Mozilla/5.0\r\n",
        'content' => http_build_query($data),
        'timeout' => 20
    ]
];

$context = stream_context_create($options);
$html = @file_get_contents($url, false, $context);

if ($html === false) {
    http_response_code(502);
    echo json_encode([
        'error' => 'BNI request failed'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

libxml_use_internal_errors(true);

$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="UTF-8">' . $html);

$xpath = new DOMXPath($dom);

$rows = $xpath->query('//table[contains(@class,"listtables")]/tbody/tr');

$members = [];

foreach ($rows as $row) {
    $cells = $xpath->query('./td', $row);

    if ($cells->length < 4) {
        continue;
    }

    $nameLink = $xpath->query('.//a[contains(@href,"memberdetails")]', $cells->item(0))->item(0);

    if (!$nameLink) {
        continue;
    }

    $name = trim($nameLink->textContent);
    $company = trim($cells->item(1)->textContent);
    $profession = trim($cells->item(2)->textContent);
    $phone = trim($cells->item(3)->textContent);

    $profileHref = html_entity_decode($nameLink->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if (!str_starts_with($profileHref, 'http')) {
        $profileUrl = 'https://bni-rheinruhr.de/koenigsforst/de/' . ltrim($profileHref, '/');
    } else {
        $profileUrl = $profileHref;
    }

    $messageLink = $xpath->query('.//a[contains(@href,"sendmessage")]', $row)->item(0);
    $messageUrl = null;

    if ($messageLink) {
        $messageHref = html_entity_decode($messageLink->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (!str_starts_with($messageHref, 'http')) {
            $messageUrl = 'https://bni-rheinruhr.de/koenigsforst/de/' . ltrim($messageHref, '/');
        } else {
            $messageUrl = $messageHref;
        }
    }

    $members[] = [
        'name' => $name,
        'company' => $company,
        'profession' => $profession,
        'phone' => $phone,
        'profile_url' => $profileUrl,
        'message_url' => $messageUrl
    ];
}

echo json_encode([
    'chapter' => 'Königsforst',
    'source' => 'https://bni-rheinruhr.de/koenigsforst/de/memberlist',
    'member_count' => count($members),
    'members' => $members
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
