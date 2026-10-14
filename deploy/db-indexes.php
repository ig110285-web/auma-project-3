<?php
define('BASE_DIR', '/var/www/auma');
require '/var/www/auma/app/config.php';
require '/var/www/auma/app/helpers.php';
require '/var/www/auma/app/db.php';

echo "=== индексы в documents до правки ===\n";
foreach (db_query('SHOW INDEX FROM documents') as $r) {
    printf("  %-24s %s\n", $r['Key_name'], $r['Column_name']);
}

$want = [
    'idx_docs_order'   => 'order_no',
    'idx_docs_folder'  => 'folder_code',
    'idx_docs_deleted' => 'deleted, created_at',
    'idx_docs_article' => 'article',
];

$have = [];
foreach (db_query('SHOW INDEX FROM documents') as $r) {
    $have[$r['Key_name']] = true;
}

echo "\n=== добавляем недостающие ===\n";
foreach ($want as $name => $cols) {
    if (isset($have[$name])) {
        echo "  $name — уже есть\n";
        continue;
    }
    try {
        db_exec("CREATE INDEX $name ON documents ($cols)");
        echo "  $name ($cols) — создан\n";
    } catch (Throwable $e) {
        echo "  $name — не удалось: " . $e->getMessage() . "\n";
    }
}

echo "\n=== стало ===\n";
foreach (db_query('SHOW INDEX FROM documents') as $r) {
    printf("  %-24s %s\n", $r['Key_name'], $r['Column_name']);
}

// замер: сколько занимает поиск по заказу
$t = microtime(true);
for ($i = 0; $i < 50; $i++) {
    db_query('SELECT * FROM documents WHERE order_no = ? AND deleted = 0', ['23059102']);
}
printf("\n50 запросов по заказу: %.1f мс\n", (microtime(true) - $t) * 1000);
