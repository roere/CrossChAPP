<?php

declare(strict_types=1);

require_once __DIR__ . '/BniGlobalThrottle.php';

final class BniMemberListClient
{
    public function __construct(private readonly PDO $database, private readonly ?Closure $transport=null, private readonly ?Closure $delay=null, private ?BniGlobalThrottle $throttle=null, private readonly bool $transportIsExternal=false) {}

    /** @return array{status:string,body:string,httpStatus:int} */
    public function fetch(int $orgId): array
    {
        $statement=$this->database->prepare('SELECT * FROM bni_member_directory_configs WHERE org_id=:org');$statement->execute([':org'=>$orgId]);$config=$statement->fetch();
        if(!is_array($config))return['status'=>'unavailable','body'=>'','httpStatus'=>0];
        $this->awaitRealRequest();
        if(str_contains((string)$config['endpoint'],'/chapterdetail/display')){parse_str((string)$config['parameters'],$data);$data['website_type']=$config['website_type'];$data['website_id']=$config['website_id'];$data['mappedWidgetSettings']=$config['mapped_widget_settings'];}
        else $data=['parameters'=>$config['parameters'],'languages'=>$config['languages'],'cmsv3'=>'true','website_type'=>$config['website_type'],'website_id'=>$config['website_id'],'mappedWidgetSettings'=>$config['mapped_widget_settings'],'pageMode'=>'Live_Site'];
        $headers=['Content-Type: application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With: XMLHttpRequest','Referer: '.$config['referer'],'User-Agent: Mozilla/5.0'];
        if($this->transport!==null)$result=($this->transport)('POST',(string)$config['endpoint'],$data,$headers);
        else{$context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>http_build_query($data),'ignore_errors'=>true,'timeout'=>25]]);$body=@file_get_contents((string)$config['endpoint'],false,$context);$responseHeaders=$http_response_header??[];$status=0;foreach(array_reverse($responseHeaders)as$header)if(preg_match('/^HTTP\/\S+\s+(\d{3})/',$header,$match)){ $status=(int)$match[1];break;}$result=['status'=>$status,'body'=>$body===false?'':$body,'headers'=>$responseHeaders];}
        $http=(int)($result['status']??0);$body=(string)($result['body']??'');$validBody=str_contains($body,'memberdetails')||str_contains($body,'listtables')||str_contains($body,'id="members"')||str_contains($body,"id='members'");$status=match(true){$http>=200&&$http<300&&$validBody=>'ok',$http>=200&&$http<300=>'upstream_error',$http===429=>'rate_limited',$http===403=>'forbidden',default=>'upstream_error'};
        return['status'=>$status,'body'=>$body,'httpStatus'=>$http];
    }

    private function awaitRealRequest():void
    {
        if($this->transport!==null&&!$this->transportIsExternal)return;
        if(getenv('CROSSCHAPP_DISABLE_EXTERNAL_HTTP')==='1')throw new RuntimeException('Externer HTTP-Zugriff ist im sicheren Testmodus deaktiviert.');
        $this->throttle??=new BniGlobalThrottle($this->database);
        $this->throttle->awaitStartSlot('member_list');
    }
}
