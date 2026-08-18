<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/UserRepository.php';
require_once dirname(__DIR__, 2) . '/src/RepresentationOfferRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') JsonResponse::send(['error' => 'Nur GET ist erlaubt.'], 405);
$identity = Auth::requireUserJson();
$database = (new Database())->connection();
$user = (new UserRepository($database))->findById((int) $identity['user_id']);
if ($user === null || $user['status'] !== 'active' || $user['home_chapter_org_id'] === null) JsonResponse::send(['error' => 'Ein Heimatchapter ist erforderlich.'], 403);
$chapter = $database->prepare('SELECT chapter_name FROM organizations WHERE org_id = :org_id');
$chapter->execute([':org_id' => $user['home_chapter_org_id']]);
$chapterName = $chapter->fetchColumn();
$today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
$offers = (new RepresentationOfferRepository($database))->findForHomeChapter((int) $user['home_chapter_org_id'], (int) $user['id'], $today);
JsonResponse::send(['homeChapterName' => is_string($chapterName) ? $chapterName : '—', 'offers' => $offers]);
