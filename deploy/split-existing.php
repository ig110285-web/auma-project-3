<?php
declare(strict_types=1);

/**
 * Разовое обслуживание: разложить по позициям уже загруженные документы.
 *
 * Нужен после того, как вырезание позиций стало сохранять отдельный файл
 * и для первой позиции: у заказов, загруженных раньше, его нет, а артикул
 * полного файла может быть без остальных изделий.
 *
 * Запуск на сервере:
 *   php /var/www/auma/deploy/split-existing.php          # только показать
 *   php /var/www/auma/deploy/split-existing.php --write  # применить
 */

define('BASE_DIR', dirname(__DIR__));

require BASE_DIR . '/app/helpers.php';
require BASE_DIR . '/app/db.php';
require BASE_DIR . '/app/auth.php';
require BASE_DIR . '/app/auma.php';
// doc_store, которой сохраняются документы, объявлена в API
require BASE_DIR . '/app/api.php';

$write = in_array('--write', $argv ?? [], true);

$docs = db_query(
    "SELECT * FROM documents
      WHERE deleted = 0
        AND doc_type = 'characteristics'
        AND filename NOT LIKE '%\_pos%'
      ORDER BY id"
);

printf("полных документов: %d, режим: %s\n\n", count($docs), $write ? 'запись' : 'просмотр');

$created = 0;
$updated = 0;
$repaired = 0;

foreach ($docs as $doc) {
    $id = (int) $doc['id'];
    $path = upload_dir() . '/' . basename((string) $doc['stored_name']);
    if (!is_file($path)) {
        echo "  id={$id} {$doc['filename']}: файла нет\n";
        continue;
    }

    $content = (string) file_get_contents($path);
    $split = (string) $doc['format'] === 'pdf'
        ? pdf_split_positions($content)
        : htm_split_positions($content);

    if ($split['parts'] === []) {
        continue;   // одна позиция или нечего делить
    }

    echo "  id={$id} заказ {$doc['order_no']} ({$doc['format']}): позиций " . count($split['parts']) . "\n";

    // артикул полного файла должен перечислять все изделия
    $joined = implode('_', $split['articles']);
    if ($joined !== '' && $joined !== (string) $doc['article']) {
        echo "     артикул: {$doc['article']} -> {$joined}\n";
        if ($write) {
            db_exec(
                'UPDATE documents SET article = ?, folder_code = ?, updated_at = ? WHERE id = ?',
                [$joined, folder_from_article($joined), now(), $id]
            );
        }
        $updated++;
    }

    $stem = (string) pathinfo((string) $doc['filename'], PATHINFO_FILENAME);
    $ext = (string) pathinfo((string) $doc['filename'], PATHINFO_EXTENSION);
    $ext = $ext !== '' ? '.' . $ext : '';

    foreach ($split['parts'] as $part) {
        $name = $stem . '_pos' . str_replace('.', '_', $part['pos']) . $ext;
        $exists = db_one(
            'SELECT id, article FROM documents WHERE order_no = ? AND filename = ?',
            [(string) $doc['order_no'], $name]
        );

        if ($exists) {
            // файл уже есть, но артикул мог быть испорчен прежним пересчётом
            // (у PDF он брался из htm-версии заказа, то есть от первой позиции)
            if ($part['article'] !== '' && (string) $exists['article'] !== $part['article']) {
                echo "     ~ {$name}: артикул {$exists['article']} -> {$part['article']}\n";
                if ($write) {
                    db_exec(
                        'UPDATE documents SET article = ?, folder_code = ?, updated_at = ? WHERE id = ?',
                        [$part['article'], folder_from_article($part['article']), now(), (int) $exists['id']]
                    );
                }
                $repaired++;
            }
            continue;
        }

        echo "     + {$name} (Article {$part['article']})\n";
        if ($write) {
            doc_store(
                ['id' => (int) $doc['owner_id']],
                $part['content'],
                $name,
                [
                    'order_no'   => (string) $doc['order_no'],
                    'article'    => $part['article'],
                    'doc_type'   => 'characteristics',
                    'lang'       => (string) $doc['lang'],
                    'mime'       => (string) $doc['mime'],
                    'source_url' => (string) $doc['source_url'],
                    'title'      => 'Характеристики ' . $doc['order_no'] . ' — Pos. ' . $part['pos'],
                ]
            );
        }
        $created++;
    }
}

printf("\nсоздано файлов: %d, исправлено артикулов: %d, обновлено артикулов полных файлов: %d\n",
    $created, $repaired, $updated);
if (!$write && ($created > 0 || $updated > 0 || $repaired > 0)) {
    echo "это был просмотр — запустите с ключом --write, чтобы применить\n";
}
