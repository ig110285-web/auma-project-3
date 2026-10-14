<?php
define('BASE_DIR', '/var/www/auma');
require '/var/www/auma/app/config.php';
require '/var/www/auma/app/helpers.php';
require '/var/www/auma/app/db.php';

// Выбранный серийный номер мотора и привода, плюс полные списки из отчёта,
// чтобы окно выбора открывалось без обращения к AUMA.
$cols = [
    'serial_m'     => "VARCHAR(32) NULL COMMENT 'выбранный серийный номер мотора'",
    'serial_p'     => "VARCHAR(32) NULL COMMENT 'выбранный серийный номер привода'",
    'serials_json' => "TEXT NULL COMMENT 'списки номеров из отчёта: моторы и приводы'",
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

echo "\n=== колонки documents ===\n";
foreach (db_query('SHOW COLUMNS FROM documents') as $r) {
    printf("  %-16s %s\n", $r['Field'], $r['Type']);
}
