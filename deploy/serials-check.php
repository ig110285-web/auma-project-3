<?php
define('BASE_DIR', '/var/www/auma');
require '/var/www/auma/app/config.php';
require '/var/www/auma/app/helpers.php';
require '/var/www/auma/app/db.php';
require '/var/www/auma/app/auma.php';

foreach (['23059102', '13405414', '13338299'] as $order) {
    $res = auma_fetch_characteristics($order, 'htm');
    $s = auma_serials((string) ($res['content'] ?? ''));
    printf("заказ %s\n", $order);
    echo '  моторы : ' . (implode(', ', $s['m']) ?: '—') . PHP_EOL;
    echo '  приводы: ' . (implode(', ', $s['p']) ?: '—') . PHP_EOL;
    echo '  всего  : ' . count($s['all']) . PHP_EOL;
}
