<?php

declare(strict_types=1);

require_once __DIR__ . '/BniGlobalThrottle.php';
require_once __DIR__ . '/BniRequestEventRepository.php';

final class BniMemberDirectoryConfigResolver
{
    public function __construct(private readonly PDO $database,private readonly ?Closure $transport=null,private readonly ?Closure $delay=null,private ?BniGlobalThrottle $throttle=null,private readonly bool $transportIsExternal=false,private ?BniRequestEventRepository $events=null){}

    /** @return array{status:string} */
    public function resolve(int $orgId):array
    {
        $configured=$this->database->prepare('SELECT COUNT(*) FROM bni_member_directory_configs WHERE org_id=:org');$configured->execute([':org'=>$orgId]);
        if((int)$configured->fetchColumn()===1)return['status'=>'configured'];
        $chapter=$this->database->prepare("SELECT org_id,chapter_url,cms_security_hash FROM organizations WHERE org_id=:org AND org_type='CHAPTER'");$chapter->execute([':org'=>$orgId]);$row=$chapter->fetch();
        if(!is_array($row))return['status'=>'unavailable'];
        $url=$this->validatedChapterUrl((string)($row['chapter_url']??''));$chapterId=rawurldecode(trim((string)($row['cms_security_hash']??'')));
        if($url===null||$chapterId==='')return['status'=>'unavailable'];
        $this->awaitRealRequest();$external=$this->transport===null||$this->transportIsExternal;$eventId=null;if($external){$this->events??=new BniRequestEventRepository($this->database);$eventId=$this->events->start('member_discovery','verification');}try{$response=$this->request($url);}catch(Throwable $exception){if($eventId!==null)$this->events?->finish($eventId,null,'network_error');throw$exception;}$http=(int)($response['status']??0);if($eventId!==null)$this->events?->finish($eventId,$http?:null,$http===0?'network_error':($http>=200&&$http<300?'success':($http===429?'rate_limited':($http===403?'forbidden':'http_error'))));
        if($http===403)return['status'=>'forbidden'];if($http===429)return['status'=>'rate_limited'];if($http<200||$http>=300)return['status'=>'upstream_error'];
        $body=(string)($response['body']??'');
        if(!preg_match('/id=["\']website_type["\'][^>]*value=["\']([^"\']+)["\']/i',$body,$type)||!preg_match('/id=["\']website_id["\'][^>]*value=["\']([0-9]+)["\']/i',$body,$website)||!preg_match('/var\s+mappedWidgetSettings\s*=\s*(["\'])(.*?)\1\s*;/s',$body,$mapped))return['status'=>'unavailable'];
        $widget=html_entity_decode(stripcslashes($mapped[2]),ENT_QUOTES|ENT_HTML5,'UTF-8');if(!is_array(json_decode($widget,true)))return['status'=>'unavailable'];
        $parts=parse_url($url);$endpoint='https://'.strtolower((string)$parts['host']).'/bnicms/v3/frontend/chapterdetail/display';
        $parameters=http_build_query(['chapterId'=>$chapterId,'languageLocaleCode'=>'de','pageMode'=>'Live_Site','planyourvisit'=>'y']);$now=gmdate('Y-m-d\TH:i:s\Z');
        try{$insert=$this->database->prepare('INSERT INTO bni_member_directory_configs(org_id,endpoint,parameters,languages,website_type,website_id,mapped_widget_settings,referer,updated_at)VALUES(:org,:endpoint,:parameters,:languages,:type,:website,:mapped,:referer,:updated)');$insert->execute([':org'=>$orgId,':endpoint'=>$endpoint,':parameters'=>$parameters,':languages'=>'{}',':type'=>$type[1],':website'=>$website[1],':mapped'=>$widget,':referer'=>$url,':updated'=>$now]);}catch(PDOException $exception){$configured->execute([':org'=>$orgId]);if((int)$configured->fetchColumn()!==1)throw $exception;}
        return['status'=>'configured'];
    }

    /** @return array{status:int,body:string,headers:array} */
    private function request(string $url):array
    {
        if($this->transport!==null)return($this->transport)('GET',$url,[],['User-Agent: Mozilla/5.0']);
        if(getenv('CROSSCHAPP_DISABLE_EXTERNAL_HTTP')==='1')throw new RuntimeException('Externer HTTP-Zugriff ist im sicheren Testmodus deaktiviert.');
        $context=stream_context_create(['http'=>['method'=>'GET','header'=>'User-Agent: Mozilla/5.0','ignore_errors'=>true,'timeout'=>25]]);$body=@file_get_contents($url,false,$context);$headers=$http_response_header??[];$status=0;foreach(array_reverse($headers)as$header)if(preg_match('/^HTTP\/\S+\s+(\d{3})/',$header,$match)){$status=(int)$match[1];break;}return['status'=>$status,'body'=>$body===false?'':$body,'headers'=>$headers];
    }
    private function validatedChapterUrl(string $url):?string{$parts=parse_url(trim($url));$host=strtolower(rtrim((string)($parts['host']??''),'.'));if(!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)||$host===''||isset($parts['user'])||isset($parts['pass'])||!preg_match('/(^|\.)bni[^.]*\.(de|at|com|hamburg)$/',$host))return null;$parts['scheme']='https';return'https://'.$host.(string)($parts['path']??'/').(isset($parts['query'])?'?'.$parts['query']:'');}
    private function awaitRealRequest():void
    {
        if($this->transport!==null&&!$this->transportIsExternal)return;
        if(getenv('CROSSCHAPP_DISABLE_EXTERNAL_HTTP')==='1')throw new RuntimeException('Externer HTTP-Zugriff ist im sicheren Testmodus deaktiviert.');
        $this->throttle??=new BniGlobalThrottle($this->database);
        $this->throttle->awaitStartSlot('member_directory_discovery');
    }
}
