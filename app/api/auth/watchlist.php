<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/src/Auth.php';
require_once dirname(__DIR__,2).'/src/Database.php';
require_once dirname(__DIR__,2).'/src/JsonResponse.php';
require_once dirname(__DIR__,2).'/src/WatchlistRepository.php';

if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST','DELETE','PATCH'],true))JsonResponse::send(['error'=>'Nur GET, POST, DELETE und PATCH sind erlaubt.'],405);
$identity=Auth::requireUserJson();$userId=(int)$identity['user_id'];$repository=new WatchlistRepository((new Database())->connection());
if($_SERVER['REQUEST_METHOD']==='GET')JsonResponse::send($repository->accountData($userId));
Auth::requireCsrfJson();
try{$payload=json_decode(file_get_contents('php://input')?:'{}',true,8,JSON_THROW_ON_ERROR);if(!is_array($payload))throw new JsonException();if(array_intersect(['user_id','userId','id'],array_keys($payload))!==[])JsonResponse::send(['error'=>'Eine Benutzerwahl ist nicht erlaubt.'],400);
    if($_SERVER['REQUEST_METHOD']==='PATCH'){if(!array_key_exists('emailNotifications',$payload)||!is_bool($payload['emailNotifications']))throw new InvalidArgumentException('Die Benachrichtigungseinstellung ist ungültig.');$repository->setEmailNotifications($userId,$payload['emailNotifications']);JsonResponse::send($repository->accountData($userId));}
    $organizationId=filter_var($payload['organization_id']??null,FILTER_VALIDATE_INT);if($organizationId===false||$organizationId<1)throw new InvalidArgumentException('Die Chapter-ID ist ungültig.');
    if($_SERVER['REQUEST_METHOD']==='POST'){$repository->add($userId,(int)$organizationId);JsonResponse::send(['watched'=>true,'organizationId'=>(int)$organizationId,'organizationIds'=>$repository->organizationIds($userId)],201);}
    $repository->remove($userId,(int)$organizationId);JsonResponse::send(['watched'=>false,'organizationId'=>(int)$organizationId,'organizationIds'=>$repository->organizationIds($userId)]);
}catch(JsonException|InvalidArgumentException$exception){JsonResponse::send(['error'=>$exception->getMessage()?:'Ungültige Anfrage.'],400);}catch(Throwable){JsonResponse::send(['error'=>'Die Beobachtungsliste konnte nicht gespeichert werden.'],500);}
