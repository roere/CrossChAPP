<?php

declare(strict_types=1);

$root=is_file(__DIR__.'/../app/src/Database.php')?__DIR__.'/../app':'/var/www/html';
require_once $root.'/src/Database.php';
require_once $root.'/src/UserRepository.php';

$check=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$db=(new Database(':memory:'))->connection();
$users=new UserRepository($db);
$user=$users->create('Rollen','Test','rollen@example.test',password_hash('password-123',PASSWORD_DEFAULT),null);
$id=(int)$user['id'];

$users->updateRole($id,'user_manager');
$check($users->findById($id)['role']==='user_manager','user -> user_manager fehlgeschlagen.');
$users->updateRole($id,'user');
$check($users->findById($id)['role']==='user','user_manager -> user fehlgeschlagen.');
$check((int)$users->findById($id)['id']===$id,'ID wurde beim Rollenwechsel verändert.');

foreach(['admin','invalid'] as $role){try{$users->updateRole($id,$role);throw new RuntimeException("Rolle {$role} wurde akzeptiert.");}catch(AdminUserRoleException $exception){$check($exception->reason==='invalid_role','Falscher Fehler für unzulässige Zielrolle.');}}
$adminId=(int)$db->query("SELECT id FROM users WHERE role='admin'")->fetchColumn();
try{$users->updateRole($adminId,'user');throw new RuntimeException('Adminrolle wurde geändert.');}catch(AdminUserRoleException $exception){$check($exception->reason==='protected_role','Adminschutz liefert falschen Fehler.');}

echo "PASS Admin-Rollenwechsel: user/user_manager, Zielrollenvalidierung und Adminschutz\n";
