<?php

declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/Auth.php';
require_once dirname(__DIR__,2).'/src/Database.php';
require_once dirname(__DIR__,2).'/src/JsonResponse.php';
require_once dirname(__DIR__,2).'/src/PushConfig.php';
require_once dirname(__DIR__,2).'/src/PushSubscriptionRepository.php';
$identity=Auth::requireUserJson();
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']==='GET') JsonResponse::send(['configured'=>PushConfig::ready(),'publicKey'=>PushConfig::ready()?PushConfig::publicKey():null]);
if (!in_array($_SERVER['REQUEST_METHOD'],['POST','DELETE'],true)) JsonResponse::send(['error'=>'Nur GET, POST und DELETE sind erlaubt.'],405);
Auth::requireCsrfJson();
try {
    $payload=json_decode(file_get_contents('php://input')?:'{}',true,8,JSON_THROW_ON_ERROR);
    if (!is_array($payload) || array_intersect(['user_id','userId','id'],array_keys($payload))) throw new InvalidArgumentException('Eine Benutzerwahl ist nicht erlaubt.');
    $repo=new PushSubscriptionRepository((new Database())->connection());$userId=(int)$identity['user_id'];
    if ($_SERVER['REQUEST_METHOD']==='DELETE') {$repo->remove($userId,PushSubscriptionRepository::endpoint($payload['endpoint']??null));JsonResponse::send(['enabled'=>false]);}
    if (($payload['action']??null)==='status') JsonResponse::send(['enabled'=>$repo->contains($userId,PushSubscriptionRepository::endpoint($payload['endpoint']??null))]);
    if (!PushConfig::ready()) JsonResponse::send(['error'=>'Push-Benachrichtigungen sind auf diesem Server noch nicht eingerichtet.'],503);
    $repo->save($userId,$payload);JsonResponse::send(['enabled'=>true],201);
} catch (JsonException|InvalidArgumentException $error) {
    JsonResponse::send(['error'=>$error instanceof JsonException?'Ungültige Anfrage.':$error->getMessage()],400);
} catch (Throwable) { JsonResponse::send(['error'=>'Die Push-Einstellung konnte nicht gespeichert werden.'],500); }
