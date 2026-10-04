<?php

declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/Auth.php';
require_once dirname(__DIR__,2).'/src/Database.php';
require_once dirname(__DIR__,2).'/src/JsonResponse.php';
require_once dirname(__DIR__,2).'/src/ServerLoadRepository.php';
Auth::requireAdminJson();
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']!=='GET') JsonResponse::send(['error'=>'Nur GET ist erlaubt.'],405);
JsonResponse::send((new ServerLoadRepository((new Database())->connection()))->overview());
