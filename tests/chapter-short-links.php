<?php
declare(strict_types=1);
$app=is_file(__DIR__.'/../app/src/Database.php')?__DIR__.'/../app':'/var/www/html';foreach(['Database','ChapterShortLink','OrganizationRepository']as$class)require_once"$app/src/$class.php";
$check=static function(bool$c,string$m):void{if(!$c)throw new RuntimeException($m);};$db=(new Database(':memory:'))->connection();$now=gmdate('Y-m-d\TH:i:s\Z');
$insert=$db->prepare("INSERT INTO organizations(org_id,org_type,chapter_name,latitude,longitude,meeting_day,meeting_time,detail_status,created_at,updated_at)VALUES(?,'CHAPTER',?,50.95,7.3,'Freitag','07:00','loaded',?,?)");
$insert->execute([44628,'Königsforst BNI (Overath)',$now,$now]);$insert->execute([5725,'BNI Mozart (Köln)',$now,$now]);$insert->execute([99999,'Mozart BNI',$now,$now]);$insert->execute([77777,'Wilhelm Röntgen BNI',$now,$now]);
$check(ChapterShortLink::backfill($db)===4,'Backfill versorgt nicht alle bestehenden Chapter.');$slugs=$db->query('SELECT org_id,short_link_slug FROM organizations')->fetchAll(PDO::FETCH_KEY_PAIR);
$check($slugs[44628]==='bni_koenigsforst'&&$slugs[5725]==='bni_mozart'&&$slugs[99999]==='bni_mozart_99999'&&$slugs[77777]==='bni_wilhelm_roentgen','Transliteration oder deterministische Kollision fehlerhaft.');
$repository=new OrganizationRepository($db);foreach(['manual','usage_search','usage_detail','automatic']as$trigger)$repository->saveDetails(44628,['chapterName'=>'Königsforst Premium BNI (Overath)','description'=>$trigger]);$repository->upsertMapOrganizations([['orgId'=>44628,'orgType'=>'CHAPTER','chapterName'=>'Königsforst Grunddaten Neu','countryCode'=>'DE','latitude'=>51.0,'longitude'=>7.4]]);
$check($repository->find(44628)['shortLinkSlug']==='bni_koenigsforst','Detail- oder Grunddatenupdate hat bestehenden Slug verändert.');$check($repository->findByShortLinkSlug('bni_koenigsforst')['orgId']===44628&&$repository->findByShortLinkSlug('../etc/passwd')===null,'Shortlinkauflösung ist nicht exakt oder nicht sicher.');
$db->exec("INSERT INTO organizations(org_id,org_type,chapter_name,created_at,updated_at)VALUES(12,'CORE_GROUP','Mozart BNI','$now','$now')");$check(ChapterShortLink::ensure($db,12)===null,'Nicht-Chapter erhielt einen Slug.');
echo "PASS Chapter-Shortlinks: Backfill, Transliteration, Kollision, Stabilität, sichere Auflösung\n";
