<?php
declare(strict_types=1);
foreach(['Auth','Database','JsonResponse','RepresentationAdminHistoryRepository']as$file)require_once dirname(__DIR__,2).'/src/'.$file.'.php';
Auth::requireAdminJson();if($_SERVER['REQUEST_METHOD']!=='GET')JsonResponse::send(['error'=>'Nur GET ist erlaubt.'],405);JsonResponse::send((new RepresentationAdminHistoryRepository((new Database())->connection()))->history());
