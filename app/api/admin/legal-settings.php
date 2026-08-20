<?php
declare(strict_types=1);
foreach(['Auth','Database','JsonResponse','LegalSettingsRepository']as$file)require_once dirname(__DIR__,2).'/src/'.$file.'.php';
$user=Auth::user();if($user===null)JsonResponse::send(['error'=>'Admin-Anmeldung erforderlich.'],401);if(($user['role']??null)!=='admin')JsonResponse::send(['error'=>'Admin-Berechtigung erforderlich.'],403);
$repository=new LegalSettingsRepository((new Database())->connection());
if($_SERVER['REQUEST_METHOD']==='GET')JsonResponse::send(['settings'=>$repository->settings()]);
if($_SERVER['REQUEST_METHOD']!=='POST')JsonResponse::send(['error'=>'Nur GET und POST sind erlaubt.'],405);Auth::requireCsrfJson();
try{$payload=json_decode(file_get_contents('php://input')?:'',true,8,JSON_THROW_ON_ERROR);$repository->save((string)($payload['imprintText']??''),(string)($payload['privacyText']??''));JsonResponse::send(['settings'=>$repository->settings(),'message'=>'Rechtliche Texte gespeichert.']);}
catch(JsonException|InvalidArgumentException $exception){JsonResponse::send(['error'=>$exception->getMessage()],400);}
