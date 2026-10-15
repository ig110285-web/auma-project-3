<?php
define('BASE_DIR', '/var/www/auma');
require '/var/www/auma/app/config.php';
require '/var/www/auma/app/helpers.php';
require '/var/www/auma/app/db.php';

echo "=== новый каталог ===\n";
foreach (catalog() as $g) {
    printf("  %-26s %2d: %s\n", $g['title'], count($g['folders']),
           implode(', ', array_column($g['folders'], 'code')));
}

$write = in_array('--write', $argv, true);

echo "\n=== пересчёт папок у документов ===\n";
$rows = db_query('SELECT id, article, folder_code FROM documents WHERE deleted = 0 ORDER BY id');
$changed = 0;
$moved = [];
foreach ($rows as $r) {
    $new = folder_from_article((string) $r['article']);
    if ($new === (string) $r['folder_code']) {
        continue;
    }
    $changed++;
    $key = ($r['folder_code'] ?: '(нет)') . ' -> ' . ($new ?: '(нет)');
    $moved[$key] = ($moved[$key] ?? 0) + 1;
    if ($write) {
        db_exec('UPDATE documents SET folder_code = ? WHERE id = ?', [$new, (int) $r['id']]);
    }
}
printf("  документов: %d, требуется изменений: %d\n", count($rows), $changed);
foreach ($moved as $k => $n) {
    printf("    %-28s %d\n", $k, $n);
}
if (!$write) {
    echo "\n  это пробный прогон. Чтобы применить: php /tmp/refolder.php --write\n";
} else {
    echo "\n  изменения записаны\n";
}

echo "\n=== папки после пересчёта ===\n";
foreach (db_query("SELECT folder_code, COUNT(*) AS n FROM documents WHERE deleted = 0
                   GROUP BY folder_code ORDER BY n DESC") as $r) {
    printf("  %-10s %d\n", $r['folder_code'] ?: '(нет)', $r['n']);
}
