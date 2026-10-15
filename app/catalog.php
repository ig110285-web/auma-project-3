<?php
declare(strict_types=1);

/**
 * Дерево хранения: группы и папки внутри них.
 *
 * Это единственное место, где описан состав групп — из него же
 * заполняется таблица folders и строится меню. Чтобы добавить папку,
 * достаточно дописать её сюда.
 *
 * Порядок папок здесь — это порядок пунктов в меню. На распределение
 * документов он не влияет: folder_from_article() сортирует приставки
 * по длине, поэтому SAREx всегда попадает в свою папку, а не в SA.
 *
 * prefixes — по каким приставкам артикула документ попадает в папку.
 * Если список не указан, берётся сам код папки. Приставки вроде BSA
 * позволяют отнести к папке родственные обозначения.
 */
return [
    [
        'code'    => 'multi',
        'title'   => 'Многооборотные приводы',
        'folders' => [
            ['code' => 'SA',     'title' => 'SA',     'description' => 'Приводы SA',
             'prefixes' => ['SA', 'BSA']],
            ['code' => 'SAR',    'title' => 'SAR',    'description' => 'Приводы SAR'],
            ['code' => 'SAEx',   'title' => 'SAEx',   'description' => 'Приводы SAEx'],
            ['code' => 'SAREx',  'title' => 'SAREx',  'description' => 'Приводы SAREx'],
            ['code' => 'SAV',    'title' => 'SAV',    'description' => 'Приводы SAV'],
            ['code' => 'SARV',   'title' => 'SARV',   'description' => 'Приводы SARV'],
            ['code' => 'SEVEN',  'title' => 'SEVEN',  'description' => 'Приводы SEVEN'],
            ['code' => 'SAVEx',  'title' => 'SAVEx',  'description' => 'Приводы SAVEx'],
            ['code' => 'SARVEx', 'title' => 'SARVEx', 'description' => 'Приводы SARVEx'],
            ['code' => 'SA-UW',  'title' => 'SA-UW',  'description' => 'Приводы SA-UW'],
            ['code' => 'SAR-UW', 'title' => 'SAR-UW', 'description' => 'Приводы SAR-UW'],
        ],
    ],
    [
        'code'    => 'part',
        'title'   => 'Неполнооборотные приводы',
        'folders' => [
            ['code' => 'SQ',     'title' => 'SQ',     'description' => 'Приводы SQ'],
            ['code' => 'SQR',    'title' => 'SQR',    'description' => 'Приводы SQR'],
            ['code' => 'SQEx',   'title' => 'SQEx',   'description' => 'Приводы SQEx'],
            ['code' => 'SQREx',  'title' => 'SQREx',  'description' => 'Приводы SQREx'],
            ['code' => 'SG',     'title' => 'SG',     'description' => 'Приводы SG'],
            ['code' => 'SGR',    'title' => 'SGR',    'description' => 'Приводы SGR'],
            ['code' => 'SGExC',  'title' => 'SGExC',  'description' => 'Приводы SGExC'],
            ['code' => 'SQV',    'title' => 'SQV',    'description' => 'Приводы SQV'],
            ['code' => 'SQRV',   'title' => 'SQRV',   'description' => 'Приводы SQRV'],
            ['code' => 'SQVEx',  'title' => 'SQVEx',  'description' => 'Приводы SQVEx'],
            ['code' => 'SQRVEx', 'title' => 'SQRVEx', 'description' => 'Приводы SQRVEx'],
        ],
    ],
    [
        'code'    => 'controls',
        'title'   => 'Блоки управления',
        'folders' => [
            ['code' => 'AC',        'title' => 'AC',        'description' => 'Блоки управления AC'],
            ['code' => 'ACExC',     'title' => 'ACExC',     'description' => 'Блоки управления ACExC'],
            ['code' => 'AM',        'title' => 'AM',        'description' => 'Блоки управления AM'],
            ['code' => 'AMExC',     'title' => 'AMExC',     'description' => 'Блоки управления AMExC'],
            ['code' => 'ACV',       'title' => 'ACV',       'description' => 'Блоки управления ACV'],
            ['code' => 'AC-SIL',    'title' => 'AC-SIL',    'description' => 'Блоки AC-SIL'],
            ['code' => 'ACExC-SIL', 'title' => 'ACExC-SIL', 'description' => 'Блоки ACExC-SIL'],
        ],
    ],
    [
        'code'    => 'gearbox',
        'title'   => 'Редукторы',
        'folders' => [
            ['code' => 'GK',  'title' => 'GK',  'description' => 'Редукторы GK'],
            ['code' => 'GS',  'title' => 'GS',  'description' => 'Редукторы GS'],
            ['code' => 'GHT', 'title' => 'GHT', 'description' => 'Редукторы GHT'],
            ['code' => 'GP',  'title' => 'GP',  'description' => 'Редукторы GP'],
            ['code' => 'GST', 'title' => 'GST', 'description' => 'Редукторы GST'],
            ['code' => 'LE',  'title' => 'LE',  'description' => 'Редукторы LE'],
            ['code' => 'GHE', 'title' => 'GHE', 'description' => 'Редукторы GHE'],
        ],
    ],
];
