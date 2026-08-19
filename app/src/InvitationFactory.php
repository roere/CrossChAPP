<?php

declare(strict_types=1);

final class InvitationFactory
{
    /** @return array{repository:InvitationRepository,service:InvitationService,users:UserRepository,mailSettings:MailSettingsRepository} */
    public static function create(?Closure $transport=null):array
    {
        $database=(new Database())->connection();$repository=new InvitationRepository($database);$users=new UserRepository($database);$settings=new MailSettingsRepository($database);
        return['repository'=>$repository,'users'=>$users,'mailSettings'=>$settings,'service'=>new InvitationService($repository,$users,$settings,new MailService($settings,$transport))];
    }
}
