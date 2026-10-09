<?php
declare(strict_types=1);

/**
 * Разовое обслуживание: перечитать артикулы у уже загруженных документов.
 *
 * Запуск на сервере:  php /var/www/auma/deploy/refresh-articles.php
 *
 * Нужен был после того, как разбор артикула научился понимать любые
 * обозначения, а не только серии SA/SQ: у ранее загруженных документов
 * поле Article осталось пустым.
 */

define('BASE_DIR', dirname(__DIR__));

require BASE_DIR . '/app/helpers.php';
require BASE_DIR . '/app/db.php';
require BASE_DIR . '/app/auma.php';

$onlyEmpty = in_array('--all', $argv ?? [], true) ? false : true;

$rows = db_query(
    'SELECT id, order_no, filename, stored_name, mime, article, folder_code
       FROM documents ORDER BY id'
);

$checked = 0;
$fixed = 0;

foreach ($rows as $row) {
    $checked++;
    $id = (int) $row['id'];

    if ($onlyEmpty && (string) $row['article'] !== '') {
        continue;
    }

    $path = upload_dir() . '/' . basename((string) $row['stored_name']);
    $article = '';

    if (is_file($path)) {
        $head = (string) file_get_contents($path, false, null, 0, 300000);
        $article = auma_extract_article($head);
    }

    // В PDF текста не видно — спрашиваем htm-версию у AUMA
    if ($article === '' && (string) $row['order_no'] !== '') {
        $article = auma_lookup_article((string) $row['order_no']);
        if ($article !== '') {
            echo "  id={$id}: артикул взят из htm-версии\n";
        }
    }

    if ($article === '') {
        echo "  id={$id} {$row['filename']}: артикул не найден\n";
        continue;
    }

    if ($article === (string) $row['article']) {
        echo "  id={$id} {$row['filename']}: без изменений ({$article})\n";
        continue;
    }

    $folder = folder_from_article($article);
    db_exec(
        'UPDATE documents SET article = ?, folder_code = ?, updated_at = ? WHERE id = ?',
        [$article, $folder, now(), $id]
    );

    $was = (string) $row['article'] === '' ? '—' : (string) $row['article'];
    echo "  id={$id} {$row['filename']}: {$was} -> {$article}"
        . ($folder !== '' ? " (папка {$folder})" : '') . "\n";
    $fixed++;
}

echo "проверено: {$checked}, обновлено: {$fixed}\n";
