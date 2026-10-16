<?php
define('BASE_DIR', '/var/www/auma');
require '/var/www/auma/app/config.php';
require '/var/www/auma/app/helpers.php';
require '/var/www/auma/app/db.php';

$cols = [
    'serial_b' => "VARCHAR(32) NULL COMMENT 'выбранный серийный номер блока управления'",
    'serial_r' => "VARCHAR(32) NULL COMMENT 'выбранный серийный номер редуктора'",
];

$have = [];
foreach (db_query('SHOW COLUMNS FROM documents') as $r) {
    $have[$r['Field']] = true;
}

foreach ($cols as $name => $def) {
    if (isset($have[$name])) {
        echo "$name — уже есть\n";
        continue;
    }
    try {
        db_exec("ALTER TABLE documents ADD COLUMN $name $def");
        echo "$name — добавлена\n";
    } catch (Throwable $e) {
        echo "$name — не удалось: " . $e->getMessage() . "\n";
    }
}

echo "\n=== колонки серийных номеров ===\n";
foreach (db_query("SHOW COLUMNS FROM documents LIKE 'serial%'") as $r) {
    printf("  %-10s %s\n", $r['Field'], $r['Type']);
}
