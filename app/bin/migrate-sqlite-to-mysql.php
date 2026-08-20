#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieses Werkzeug darf nur über die Kommandozeile gestartet werden.\n");
    exit(2);
}

$sourcePath = trim((string) (getenv('CROSSCHAPP_SQLITE_SOURCE') ?: ($argv[1] ?? '')));
if ($sourcePath === '' || !is_file($sourcePath) || !is_readable($sourcePath)) {
    fwrite(STDERR, "CROSSCHAPP_SQLITE_SOURCE muss auf eine lesbare SQLite-Datei zeigen.\n");
    exit(2);
}
if (strtolower((string) getenv('CROSSCHAPP_DB_DRIVER')) !== 'mysql') {
    fwrite(STDERR, "CROSSCHAPP_DB_DRIVER=mysql ist für das Ziel zwingend erforderlich.\n");
    exit(2);
}

/** Parents precede children; this is also the canonical migration inventory. */
$tables = [
    'organizations', 'automation_settings', 'chapter_refresh_log', 'chapter_refresh_locks',
    'automation_runtime', 'map_refresh_log', 'users', 'email_verification_tokens',
    'password_reset_tokens', 'mail_settings', 'email_templates', 'auth_attempts',
    'bni_member_check_attempts', 'bni_member_directory_configs', 'bni_member_check_lock',
    'user_invitations', 'representation_offers', 'representation_offer_chapters',
    'representation_offer_dates', 'representation_settings', 'representation_contact_log',
    'representation_requests', 'representation_request_contact_log',
    'representation_anonymous_request_contact_log',
];

try {
    $source = new PDO('sqlite:file:' . $sourcePath . '?mode=ro', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    putenv('CROSSCHAPP_DB_SKIP_SEED=1');
    $target = (new Database())->connection();

    foreach ($tables as $table) {
        if ((int) $target->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() !== 0) {
            throw new RuntimeException("Zieltabelle {$table} ist nicht leer; es wurden keine Daten verändert.");
        }
    }

    $sourceTables = array_fill_keys($source->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN), true);
    $sourceCounts = [];
    $knownUserIds = array_fill_keys(array_map('intval', $source->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN)), true);
    $neutralizedTokenOwners = 0;
    $target->beginTransaction();
    try {
        foreach ($tables as $table) {
            if (!isset($sourceTables[$table])) {
                $sourceCounts[$table] = 0;
                continue;
            }
            $sourceColumns = array_map(static fn (array $row): string => (string) $row['name'], $source->query('PRAGMA table_info(`' . $table . '`)')->fetchAll());
            $targetColumns = array_fill_keys(array_map(static fn (array $row): string => (string) $row['Field'], $target->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll()), true);
            $columns = array_values(array_filter($sourceColumns, static fn (string $column): bool => isset($targetColumns[$column])));
            if ($columns === []) {
                throw new RuntimeException("Keine gemeinsamen Spalten für {$table} gefunden.");
            }
            $quoted = implode(',', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
            $placeholders = implode(',', array_fill(0, count($columns), '?'));
            $insert = $target->prepare("INSERT INTO `{$table}` ({$quoted}) VALUES ({$placeholders})");
            $read = $source->query("SELECT {$quoted} FROM `{$table}`");
            $count = 0;
            while (($row = $read->fetch()) !== false) {
                if (in_array($table, ['email_verification_tokens', 'password_reset_tokens'], true)
                    && isset($row['user_id']) && !isset($knownUserIds[(int) $row['user_id']])) {
                    $row['user_id'] = null;
                    $neutralizedTokenOwners++;
                }
                $insert->execute(array_values($row));
                $count++;
            }
            $sourceCounts[$table] = $count;
            printf("%-48s %8d\n", $table, $count);
        }

        foreach ($sourceCounts as $table => $expected) {
            $actual = (int) $target->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
            if ($actual !== $expected) {
                throw new RuntimeException("Anzahlabweichung in {$table}: SQLite={$expected}, MariaDB={$actual}.");
            }
        }
        $target->commit();
    } catch (Throwable $exception) {
        if ($target->inTransaction()) {
            $target->rollBack();
        }
        throw $exception;
    }

    $koenigsforst = $target->query("SELECT chapter_name FROM organizations WHERE org_id=44628")->fetchColumn();
    foreach ($tables as $table) {
        $columns = $target->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll();
        $idColumn = array_values(array_filter($columns, static fn (array $column): bool => $column['Field'] === 'id' && str_contains((string) $column['Extra'], 'auto_increment')));
        if ($idColumn === []) {
            continue;
        }
        $maximum = (int) $target->query('SELECT COALESCE(MAX(id),0) FROM `' . $table . '`')->fetchColumn();
        $status = $target->query("SHOW TABLE STATUS LIKE " . $target->quote($table))->fetch();
        $next = (int) ($status['Auto_increment'] ?? 0);
        if ($next <= $maximum) {
            throw new RuntimeException("AUTO_INCREMENT für {$table} ist nicht größer als MAX(id). ");
        }
    }
    echo "Königsforst (44628): " . ($koenigsforst === false ? 'nicht vorhanden' : $koenigsforst) . "\n";
    echo "Neutralisierte verwaiste Token-Benutzerreferenzen: {$neutralizedTokenOwners}\n";
    echo "Migration und COUNT-Vergleich erfolgreich.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration fehlgeschlagen: ' . $exception->getMessage() . "\n");
    exit(1);
}
