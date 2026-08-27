<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/src/Auth.php';
require_once dirname(__DIR__,2).'/src/Database.php';
require_once dirname(__DIR__,2).'/src/JsonResponse.php';
require_once dirname(__DIR__,2).'/src/BniRequestEventRepository.php';

Auth::requireAdminJson();
if($_SERVER['REQUEST_METHOD']!=='GET')JsonResponse::send(['error'=>'Nur GET ist erlaubt.'],405);
$window=filter_var($_GET['window_minutes']??5,FILTER_VALIDATE_INT);
if($window===false||!in_array((int)$window,[1,5,10,30,60],true))JsonResponse::send(['error'=>'Ungültiger Zeitraum.'],400);
JsonResponse::send((new BniRequestEventRepository((new Database())->connection()))->performance((int)$window));
