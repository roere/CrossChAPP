<?php
declare(strict_types=1);
if(getenv('CROSSCHAPP_ALLOW_LIVE_BNI')!=='1')throw new RuntimeException('Live-BNI-Test ist nicht ausdrücklich freigegeben.');
$root=is_file(__DIR__.'/../app/src/Database.php')?__DIR__.'/../app':'/var/www/html';foreach(['Database','HttpException','BniRequestPolicy','BniMemberListClient','BniMemberDirectoryService']as$class)require_once"$root/src/$class.php";
$db=(new Database(':memory:'))->connection();$now=gmdate('Y-m-d\TH:i:s\Z');
$db->prepare("INSERT INTO organizations(org_id,country_code,org_type,chapter_name,created_at,updated_at)VALUES(44628,'DE','CHAPTER','Königsforst BNI (Overath)',:now,:now)")->execute([':now'=>$now]);
$languages='{"availableLanguages":[{"type":"published","url":"http://bni-rheinruhr.de/koenigsforst/de/memberlist","descriptionKey":"Deutsch","id":18,"localeCode":"de"}],"activeLanguage":{"id":18,"localeCode":"de","descriptionKey":"Deutsch","cookieBotCode":"de"}}';$settings='[]';
$db->prepare("INSERT INTO bni_member_directory_configs(org_id,endpoint,parameters,languages,website_type,website_id,mapped_widget_settings,referer,updated_at)VALUES(44628,'https://bni-rheinruhr.de/bnicms/v3/frontend/memberlist/display','chapterName=44628&regionIds=11805,5843,9614,5925,5921,5939,11553&chapterWebsite=1',:languages,'3','27966',:settings,'https://bni-rheinruhr.de/koenigsforst/de/memberlist',:now)")->execute([':languages'=>$languages,':settings'=>$settings,':now'=>$now]);
$result=(new BniMemberDirectoryService($db))->match('René','Röderstein',44628);echo json_encode($result,JSON_UNESCAPED_UNICODE),"\n";
if(($result['status']??null)!=='match')exit(in_array($result['status']??'', ['rate_limited','forbidden'],true)?2:1);
