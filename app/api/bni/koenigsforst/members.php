<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/src/Database.php';
require_once dirname(__DIR__,3).'/src/BniMemberListClient.php';
header('Content-Type: application/json; charset=utf-8');
$response=(new BniMemberListClient((new Database())->connection()))->fetch(44628);
if($response['status']!=='ok'){http_response_code($response['status']==='rate_limited'?429:($response['status']==='forbidden'?403:502));echo json_encode(['error'=>'BNI request failed','status'=>$response['status']],JSON_UNESCAPED_UNICODE);exit;}
$html=$response['body'];

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
