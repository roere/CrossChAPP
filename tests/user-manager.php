<?php
declare(strict_types=1);
$root=is_file(__DIR__.'/../app/src/Database.php')?__DIR__.'/../app':'/var/www/html';
require_once $root.'/src/Database.php';require_once $root.'/src/UserRepository.php';
$check=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$path=sys_get_temp_dir().'/crosschapp-user-manager-'.bin2hex(random_bytes(5)).'.sqlite';
try{
    $legacy=new PDO('sqlite:'.$path);$legacy->exec("CREATE TABLE users(id INTEGER PRIMARY KEY AUTOINCREMENT,first_name TEXT NOT NULL,last_name TEXT NOT NULL,username TEXT,email TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,home_chapter_org_id INTEGER,role TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('user','admin')),status TEXT NOT NULL DEFAULT 'pending',email_verified_at TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,last_login_at TEXT)");
    $now=gmdate('Y-m-d\TH:i:s\Z');$legacy->prepare("INSERT INTO users(first_name,last_name,email,password_hash,role,status,created_at,updated_at)VALUES('Alt','User','legacy@example.test','hash','user','active',:now,:now)")->execute([':now'=>$now]);unset($legacy);
    $db=(new Database($path))->connection();$schema=(string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();$check(str_contains($schema,"'user_manager'"),'SQLite-Rollenconstraint additiv migriert.');
    $db->prepare("INSERT INTO users(first_name,last_name,email,password_hash,role,status,created_at,updated_at)VALUES('Mara','Manager','manager-schema@example.test','hash','user_manager','active',:now,:now)")->execute([':now'=>$now]);
    $columns=array_column($db->query('PRAGMA table_info(user_invitations)')->fetchAll(),'name');$check(in_array('verification_grant',$columns,true),'SQLite-Einladungsgrant additiv vorhanden.');
    $check((int)$db->query("SELECT COUNT(*) FROM users WHERE email='legacy@example.test'")->fetchColumn()===1,'Bestehende SQLite-Benutzer bleiben erhalten.');
    echo "PASS Anwenderbetreuer-Schema: SQLite-Rolle, additive Migration und Einladungsgrant\n";
}finally{foreach([$path,$path.'-wal',$path.'-shm']as$file)if(is_file($file))unlink($file);}
