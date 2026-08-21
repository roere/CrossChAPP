<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/Auth.php';require_once dirname(__DIR__,2).'/src/Database.php';require_once dirname(__DIR__,2).'/src/JsonResponse.php';require_once dirname(__DIR__,2).'/src/MailSettingsRepository.php';require_once dirname(__DIR__,2).'/src/MailService.php';require_once dirname(__DIR__,2).'/src/RepresentationAssignmentService.php';
$identity=Auth::requireUserJson();if(($identity['role']??null)!=='user')JsonResponse::send(['error'=>'Ein Benutzerkonto ist erforderlich.'],403);
try{$db=(new Database())->connection();$settings=new MailSettingsRepository($db);$service=new RepresentationAssignmentService($db,$settings,new MailService($settings));
if($_SERVER['REQUEST_METHOD']==='GET')JsonResponse::send(['assignments'=>$service->forUser((int)$identity['user_id'])]);
if($_SERVER['REQUEST_METHOD']!=='POST')JsonResponse::send(['error'=>'Methode nicht erlaubt.'],405);Auth::requireCsrfJson();$payload=json_decode(file_get_contents('php://input')?:'',true,8,JSON_THROW_ON_ERROR);$id=filter_var($payload['assignment_id']??null,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException('Die Vereinbarung ist ungültig.');$service->cancel((int)$id,(int)$identity['user_id']);JsonResponse::send(['message'=>'Die Vertretung wurde storniert.']);
}catch(JsonException|InvalidArgumentException|DomainException $e){JsonResponse::send(['error'=>$e->getMessage()],400);}catch(Throwable){JsonResponse::send(['error'=>'Die Vereinbarung konnte nicht geändert werden.'],500);}
