<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/AccountFactory.php';
require_once dirname(__DIR__, 2) . '/src/AccountService.php';
require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/MailService.php';
require_once dirname(__DIR__, 2) . '/src/MailSettingsRepository.php';
require_once dirname(__DIR__, 2) . '/src/UserRepository.php';
require_once dirname(__DIR__, 2) . '/src/ClientIp.php';
require_once dirname(__DIR__, 2) . '/src/UserFacingErrorLogger.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'PATCH', 'DELETE'], true)) JsonResponse::send(['error' => 'Nur GET, PATCH und DELETE sind erlaubt.'], 405);
$identity = Auth::requireUserJson();
$account = AccountFactory::create();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try { JsonResponse::send(['account' => $account['service']->account((int) $identity['user_id'])]); }
    catch (DomainException) { JsonResponse::send(['error' => 'Das Benutzerkonto wurde nicht gefunden.'], 404); }
    catch (Throwable) { JsonResponse::send(['error' => 'Die Kontodaten konnten nicht geladen werden.'], 500); }
}

Auth::requireCsrfJson();
try {
    $raw = file_get_contents('php://input') ?: '';
    $payload = $raw === '' ? [] : json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) throw new JsonException('Ungültige Anfrage.');
    if (array_intersect(['id', 'user_id', 'userId'], array_keys($payload)) !== []) JsonResponse::send(['error' => 'Eine Benutzerwahl ist nicht erlaubt.'], 400);
    if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        if (!array_key_exists('home_chapter_org_id', $payload) || !array_key_exists('skip_chapter_verification', $payload)) throw new JsonException('Ungültige Anfrage.');
        $home=(int)($payload['home_chapter_org_id']??0);if($payload['skip_chapter_verification']===true&&(int)($_SESSION['account_chapter_skip_org_id']??0)!==$home)JsonResponse::send(['error'=>'Die Chapter-Prüfung kann nur nach einem technischen Prüffehler übersprungen werden.','code'=>'skip_not_allowed'],409);
        $result=$account['service']->updateHomeChapter((int)$identity['user_id'],$payload['home_chapter_org_id'],$payload['skip_chapter_verification'],ClientIp::address());
        unset($_SESSION['account_chapter_skip_org_id']);
        JsonResponse::send(['updated'=>true]+$result);
    }
    if (!$account['service']->deleteAccount((int) $identity['user_id'])) JsonResponse::send(['error' => 'Das Konto konnte nicht gelöscht werden.'], 404);
    Auth::logout();
    JsonResponse::send(['deleted' => true]);
} catch (JsonException) {
    JsonResponse::send(['error' => 'Ungültige Anfrage.'], 400);
} catch (HomeChapterVerificationException $exception) {
    if($exception->canSkip&&isset($payload)&&is_array($payload))$_SESSION['account_chapter_skip_org_id']=(int)($payload['home_chapter_org_id']??0);else unset($_SESSION['account_chapter_skip_org_id']);
    if(in_array($exception->reason,['ambiguous','technical_unavailable'],true)){$home=(int)($payload['home_chapter_org_id']??0);$technical=$exception->reason==='ambiguous'?"Multiple member matches for chapter org_id={$home}":'BNI member verification failed: '.($exception->technicalReason??$exception->reason);$db=(new Database())->connection();$chapter=$db->prepare('SELECT chapter_name FROM organizations WHERE org_id=:org');$chapter->execute([':org'=>$home]);$user=$account['users']->findById((int)$identity['user_id']);(new UserFacingErrorLogger($db))->log($exception->getMessage(),$technical,$exception->reason,'account_change',(int)$identity['user_id'],'/api/auth/account.php',$exception->reason==='ambiguous'?['firstName'=>(string)($user['first_name']??''),'lastName'=>(string)($user['last_name']??''),'orgId'=>$home,'chapterName'=>(string)($chapter->fetchColumn()?:''),'matchCount'=>count($exception->diagnosticMatches),'matches'=>$exception->diagnosticMatches]:[]);}
    JsonResponse::send(['error'=>$exception->getMessage(),'message'=>$exception->getMessage(),'code'=>$exception->reason,'technicalReason'=>$exception->technicalReason,'canSkip'=>$exception->canSkip],$exception->reason==='technical_unavailable'?503:422);
} catch (InvalidArgumentException $exception) {
    JsonResponse::send(['error'=>$exception->getMessage()],400);
} catch (DomainException) {
    JsonResponse::send(['error' => 'Administratorkonten können nicht gelöscht werden.'], 403);
} catch (Throwable) {
    JsonResponse::send(['error' => 'Das Konto konnte nicht gelöscht werden.'], 500);
}
