<?php

declare(strict_types=1);

require_once '/var/www/html/src/Database.php';
require_once '/var/www/html/src/MysqlSchema.php';

$db = (new Database())->connection();
if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    echo "SKIP MariaDB schema v4 migration\n";
    return;
}

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$method = new ReflectionMethod(MysqlSchema::class, 'ensureUserRoleConstraint');
$tables = [];

try {
    $definitions = [
        'check_schema_v4_auto' => "role VARCHAR(20) NOT NULL DEFAULT 'user' CHECK(role IN ('user','admin'))",
        'check_schema_v4_role' => "role VARCHAR(20) NOT NULL DEFAULT 'user', CONSTRAINT role CHECK(role IN ('user','admin'))",
        'check_schema_v4_named' => "role VARCHAR(20) NOT NULL DEFAULT 'user', CONSTRAINT CONSTRAINT_1 CHECK(role IN ('user','admin'))",
        'check_schema_v4_none' => "role VARCHAR(20) NOT NULL DEFAULT 'user'",
        'check_schema_v4_current' => "role VARCHAR(20) NOT NULL DEFAULT 'user', CONSTRAINT chk_users_role CHECK(role IN ('user','user_manager','admin'))",
    ];
    foreach ($definitions as $table => $definition) {
        $tables[] = $table;
        $db->exec("DROP TABLE IF EXISTS `{$table}`");
        $db->exec("CREATE TABLE `{$table}`(id BIGINT AUTO_INCREMENT PRIMARY KEY,{$definition}) ENGINE=InnoDB");
        $db->exec("INSERT INTO `{$table}`(role) VALUES('admin'),('user')");
        $method->invoke(null, $db, $table);
        $method->invoke(null, $db, $table);

        $rows = $db->query("SELECT id,role FROM `{$table}` ORDER BY id")->fetchAll();
        $check(count($rows) === 2 && $rows[0]['role'] === 'admin' && $rows[1]['role'] === 'user', "{$table}: existing rows changed");
        $db->exec("INSERT INTO `{$table}`(role) VALUES('user_manager')");
        try {
            $db->exec("INSERT INTO `{$table}`(role) VALUES('invalid')");
            throw new RuntimeException("{$table}: invalid role accepted");
        } catch (PDOException $exception) {
            $check($exception->getCode() === '23000', "{$table}: unexpected invalid-role error");
        }

        $statement = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND CONSTRAINT_TYPE='CHECK' AND CONSTRAINT_NAME='chk_users_role'");
        $statement->execute([':table_name' => $table]);
        $check((int) $statement->fetchColumn() === 1, "{$table}: stable constraint missing");
    }

    $check((int) $db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() === MysqlSchema::LATEST_VERSION, 'LATEST_VERSION mismatch');
    echo "PASS MariaDB schema v4: inline/named/missing/current role constraints and data preservation\n";
} finally {
    foreach ($tables as $table) {
        $db->exec("DROP TABLE IF EXISTS `{$table}`");
    }
}
