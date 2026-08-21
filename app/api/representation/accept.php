<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/Auth.php';require_once dirname(__DIR__,2).'/src/Database.php';require_once dirname(__DIR__,2).'/src/JsonResponse.php';require_once dirname(__DIR__,2).'/src/MailSettingsRepository.php';require_once dirname(__DIR__,2).'/src/MailService.php';require_once dirname(__DIR__,2).'/src/RepresentationAssignmentService.php';
Auth::start();$raw=(string)($_SESSION['representation_acceptance_token']??'');
if($raw==='')JsonResponse::send(['error'=>'Dieser Link ist nicht mehr gültig.','code'=>'invalid_token'],400);
try{$db=(new Database())->connection();$settings=new MailSettingsRepository($db);$service=new RepresentationAssignmentService($db,$settings,new MailService($settings));$identity=Auth::user();
if($_SERVER['REQUEST_METHOD']==='GET'){
    if($identity===null)JsonResponse::send(['requiresLogin'=>true]);
    $context=$service->inspect($raw);if((int)$context['requiredUserId']!==(int)$identity['user_id'])JsonResponse::send(['error'=>'Dieser Link ist für ein anderes Benutzerkonto bestimmt.','code'=>'wrong_user'],403);
    JsonResponse::send(['requiresLogin'=>false,'context'=>$context]);
}
if($_SERVER['REQUEST_METHOD']!=='POST')JsonResponse::send(['error'=>'Methode nicht erlaubt.'],405);
$identity=Auth::requireUserJson();Auth::requireCsrfJson();$assignment=$service->accept($raw,(int)$identity['user_id']);unset($_SESSION['representation_acceptance_token']);JsonResponse::send(['message'=>'Die Vertretung wurde vereinbart.','assignment'=>$assignment]);
}catch(DomainException $e){JsonResponse::send(['error'=>$e->getMessage(),'code'=>'acceptance_unavailable'],400);}catch(Throwable){JsonResponse::send(['error'=>'Die Vertretung konnte nicht vereinbart werden.'],500);}
