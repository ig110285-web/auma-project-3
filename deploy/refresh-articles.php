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
    $stored = (string) $row['article'];
    $article = $stored;

    // Артикул, полученный при загрузке, перечитывать из файла нельзя.
    // У PDF текста не видно, и тогда в ход идёт htm-версия заказа, а в ней
    // стоит артикул первой позиции — у вырезанных позиций он будет неверным.
    // Поэтому у документов с уже известным артикулом пересчитываем только
    // папку, а из файла читаем лишь когда артикула нет.
    if ($stored === '') {
        if (is_file($path)) {
            $article = auma_extract_article((string) file_get_contents($path, false, null, 0, 300000));
        }
        if ($article === '' && (string) $row['order_no'] !== '') {
            $article = auma_lookup_article((string) $row['order_no']);
            if ($article !== '') {
                echo "  id={$id}: артикул взят из htm-версии\n";
            }
        }
    }

    if ($article === '') {
        echo "  id={$id} {$row['filename']}: артикул не найден\n";
        continue;
    }

    // папку пересчитываем всегда: дерево папок могло измениться,
    // даже если сам артикул остался прежним
    $folder = folder_from_article($article);
    $articleChanged = $article !== $stored;
    $folderChanged = $folder !== (string) $row['folder_code'];

    if (!$articleChanged && !$folderChanged) {
        echo "  id={$id} {$row['filename']}: без изменений ({$article})\n";
        continue;
    }

    db_exec(
        'UPDATE documents SET article = ?, folder_code = ?, updated_at = ? WHERE id = ?',
        [$article, $folder, now(), $id]
    );

    $was = (string) $row['article'] === '' ? '—' : (string) $row['article'];
    $folderNote = $folder !== ''
        ? " (папка {$folder})"
        : ((string) $row['folder_code'] !== '' ? ' (папка снята)' : '');
    echo "  id={$id} {$row['filename']}: {$was} -> {$article}{$folderNote}\n";
    $fixed++;
}

echo "проверено: {$checked}, обновлено: {$fixed}\n";
