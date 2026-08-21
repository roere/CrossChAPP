<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/UserRepository.php';
require_once dirname(__DIR__, 2) . '/src/RepresentationOfferRepository.php'; require_once dirname(__DIR__, 2) . '/src/RepresentationRequestRepository.php'; require_once dirname(__DIR__, 2) . '/src/MailSettingsRepository.php'; require_once dirname(__DIR__, 2) . '/src/RepresentationCleanupService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') JsonResponse::send(['error' => 'Nur GET ist erlaubt.'], 405);
$identity = Auth::requireUserJson();
$database = (new Database())->connection();
(new RepresentationCleanupService($database))->runCleanup();
$user = (new UserRepository($database))->findById((int) $identity['user_id']);
if ($user === null || $user['status'] !== 'active' || $user['home_chapter_org_id'] === null) JsonResponse::send(['error' => 'Ein Heimatchapter ist erforderlich.'], 403);
$requestRepository = new RepresentationRequestRepository($database); $chapterRow = $requestRepository->chapterForUser((int) $user['id']);
$zone = new DateTimeZone('Europe/Berlin'); try { if (!empty($chapterRow['timezone'])) $zone = new DateTimeZone((string) $chapterRow['timezone']); } catch (Throwable) {}
$today = (new DateTimeImmutable('today', $zone))->format('Y-m-d');
$requests = $requestRepository->forUser((int) $user['id'], $today);
$offerRepository = new RepresentationOfferRepository($database);
$offers = $offerRepository->findForHomeChapter((int) $user['home_chapter_org_id'], (int) $user['id'], $today);
$overview = $offerRepository->overviewForHomeChapter((int) $user['home_chapter_org_id'], (int) $user['id'], $today);
$assignedSlots=$database->prepare("SELECT offer_id,representation_date FROM representation_assignments WHERE requester_user_id=:user AND status='active' AND offer_id IS NOT NULL");$assignedSlots->execute([':user'=>$user['id']]);$assignedOfferSlots=array_map(static fn(array $row):string=>(int)$row['offer_id'].':'.(string)$row['representation_date'],$assignedSlots->fetchAll());
JsonResponse::send(['chapter' => ['chapterName' => is_array($chapterRow) ? $chapterRow['chapter_name'] : '—', 'meetingDay' => is_array($chapterRow) ? $chapterRow['meeting_day'] : null],
    'requests' => $requests, 'today' => $today, 'datedOffers' => $offers['datedOffers'], 'allDatesOffers' => $offers['allDatesOffers'], 'offers' => $overview, 'assignedOfferSlots'=>$assignedOfferSlots, 'contactHint' => (new MailSettingsRepository($database))->contactHint()]);
