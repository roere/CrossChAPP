<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/src/Auth.php';
require_once dirname(__DIR__,2).'/src/Database.php';
require_once dirname(__DIR__,2).'/src/JsonResponse.php';
require_once dirname(__DIR__,2).'/src/MailSettingsRepository.php';

Auth::requireAdminJson();
$repository=new MailSettingsRepository((new Database())->connection());
if($_SERVER['REQUEST_METHOD']==='GET')JsonResponse::send(['templates'=>$repository->textBlocks()]);
if($_SERVER['REQUEST_METHOD']!=='POST')JsonResponse::send(['error'=>'Nur GET und POST sind erlaubt.'],405);
Auth::requireCsrfJson();

try{
    $payload=json_decode(file_get_contents('php://input')?:'',true,8,JSON_THROW_ON_ERROR);
    if(!is_array($payload)||!isset($payload['key'],$payload['body'])||!is_string($payload['key'])||!is_string($payload['body'])||array_diff(array_keys($payload),['key','subject','body'])!==[])throw new InvalidArgumentException('Ungültige Anfrage.');
    $subject=array_key_exists('subject',$payload)&&$payload['subject']!==null?(is_string($payload['subject'])?$payload['subject']:throw new InvalidArgumentException('Ungültige Anfrage.')):null;
    $repository->saveTextBlock($payload['key'],$subject,$payload['body']);
    $saved=array_values(array_filter($repository->textBlocks(),static fn(array $item):bool=>$item['key']===$payload['key']));
    JsonResponse::send(['template'=>$saved[0]??null,'message'=>'Textbaustein gespeichert.']);
}catch(JsonException|InvalidArgumentException $exception){JsonResponse::send(['error'=>'invalid_template','message'=>$exception->getMessage()],400);}
