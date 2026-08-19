<?php
declare(strict_types=1);
foreach(['Auth','Database','JsonResponse','UserRepository','MailSettingsRepository','MailService','InvitationRepository','InvitationService','InvitationFactory']as$file)require_once dirname(__DIR__,2).'/src/'.$file.'.php';
$factory=InvitationFactory::create();
if($_SERVER['REQUEST_METHOD']==='GET'){JsonResponse::send($factory['service']->inspect((string)($_GET['token']??'')));}
if($_SERVER['REQUEST_METHOD']!=='POST')JsonResponse::send(['error'=>'Nur GET und POST sind erlaubt.'],405);Auth::requireCsrfJson();
try{$payload=json_decode(file_get_contents('php://input')?:'',true,16,JSON_THROW_ON_ERROR);$result=$factory['service']->accept((string)($payload['token']??''),(string)($payload['password']??''),(string)($payload['password_confirmation']??''));$messages=['invalid'=>'Dieser Einladungslink ist ungültig.','expired'=>'Dieser Einladungslink ist abgelaufen.','used'=>'Diese Einladung wurde bereits verwendet.','email_exists'=>'Für diese E-Mail-Adresse existiert bereits ein Konto.'];if($result['status']!=='accepted')JsonResponse::send(['error'=>$messages[$result['status']]??'Die Einladung konnte nicht angenommen werden.'],400);JsonResponse::send(['activated'=>true,'message'=>'Dein Konto wurde aktiviert.']);}
catch(JsonException|InvalidArgumentException $e){JsonResponse::send(['error'=>$e->getMessage()],400);}catch(Throwable){JsonResponse::send(['error'=>'Das Konto konnte nicht aktiviert werden.'],500);}
