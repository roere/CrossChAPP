<?php

declare(strict_types=1);

final class InvitationService
{
    public function __construct(private readonly InvitationRepository $invitations,private readonly UserRepository $users,private readonly MailSettingsRepository $settings,private readonly MailService $mailer,private readonly ?HomeChapterVerificationService $verification=null){}
    /** @param array<string,mixed> $input */
    public function invite(array $input,int $adminId,bool $verificationApproved=false,string $ip=''):array
    {
        $first=trim((string)($input['first_name']??''));$last=trim((string)($input['last_name']??''));$email=strtolower(trim((string)($input['email']??'')));$chapter=filter_var($input['home_chapter_org_id']??null,FILTER_VALIDATE_INT);
        if($first===''||strlen($first)>120||$last===''||strlen($last)>120)throw new InvalidArgumentException('Vorname und Nachname sind erforderlich.');
        if(filter_var($email,FILTER_VALIDATE_EMAIL)===false||strlen($email)>254)throw new InvalidArgumentException('Bitte gib eine gültige E-Mail-Adresse ein.');
        if($chapter===false||!$this->users->isValidHomeChapter((int)$chapter))throw new InvalidArgumentException('Bitte wähle ein gültiges Chapter.');
        if(!$verificationApproved){
            if($this->verification===null)throw new HomeChapterVerificationException('technical_unavailable','Die Chapter-Prüfung konnte derzeit nicht durchgeführt werden.',true,'unavailable');
            $this->verification->verify($first,$last,(int)$chapter,false,$ip);
        }
        $record=$this->invitations->create($first,$last,$email,(int)$chapter,$adminId);$home=$this->users->homeChapter((int)$chapter);$base=$this->settings->settings()['baseUrl'];$link=$base.'/?invite='.rawurlencode($record['token']);
        $mail=$this->settings->render('user_invitation',['first_name'=>$first,'last_name'=>$last,'email'=>$email,'chapter'=>(string)($home['chapter_name']??''),'invitation_link'=>$link,'app_name'=>'CrossChAPP']);
        try{$this->mailer->send($email,trim($first.' '.$last),$mail['subject'],$mail['body']);$this->invitations->markSent((int)$record['id']);}catch(Throwable $e){$this->invitations->cancelUnsent((int)$record['id']);throw new RuntimeException('Die Einladung konnte nicht versendet werden.');}
        return ['id'=>$record['id'],'expiresAt'=>$record['expiresAt']];
    }
    public function inspect(string $token):array{$row=$this->invitations->byToken($token);if($row===null)return['status'=>'invalid'];if($row['status']==='accepted')return['status'=>'used'];if($row['status']!=='pending')return['status'=>$row['status']==='expired'?'expired':'invalid'];if(strtotime((string)$row['expires_at'])<=time())return['status'=>'expired'];return['status'=>'valid','invitation'=>['firstName'=>$row['first_name'],'lastName'=>$row['last_name'],'email'=>$row['email'],'chapterName'=>$row['chapter_name']]];}
    public function resend(int $invitationId):array
    {
        if(!$this->mailer->isReady())throw new RuntimeException('Der E-Mail-Versand ist noch nicht konfiguriert.');
        $row=$this->invitations->beginResend($invitationId);$token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$expires=gmdate('Y-m-d\TH:i:s\Z',time()+604800);
        $base=$this->settings->settings()['baseUrl'];$link=$base.'/?invite='.rawurlencode($token);
        $mail=$this->settings->render('user_invitation',['first_name'=>$row['first_name'],'last_name'=>$row['last_name'],'email'=>$row['email'],'chapter'=>$row['chapter_name'],'invitation_link'=>$link,'app_name'=>'CrossChAPP']);
        try{$this->mailer->send((string)$row['email'],trim((string)$row['first_name'].' '.(string)$row['last_name']),$mail['subject'],$mail['body']);$this->invitations->finishResend($invitationId,hash('sha256',$token),$expires);}
        catch(Throwable $exception){$this->invitations->rollbackResend();if($exception instanceof DomainException)throw$exception;throw new RuntimeException('Die Einladung konnte nicht erneut gesendet werden.');}
        return['id'=>$invitationId,'expiresAt'=>$expires];
    }
    public function accept(string $token,string $password,string $confirmation):array{if(strlen($password)<8)throw new InvalidArgumentException('Das Passwort muss mindestens 8 Zeichen lang sein.');if(!hash_equals($password,$confirmation))throw new InvalidArgumentException('Die Passwörter stimmen nicht überein.');return$this->invitations->accept($token,password_hash($password,PASSWORD_DEFAULT));}
}
