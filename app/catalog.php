<?php
declare(strict_types=1);

/**
 * Дерево хранения: группы и папки внутри них.
 *
 * Это единственное место, где описан состав групп — из него же
 * заполняется таблица folders и строится меню. Чтобы добавить папку,
 * достаточно дописать её сюда.
 *
 * prefixes — по каким приставкам артикула документ попадает в папку.
 * Если список не указан, берётся сам код папки. Совпадение ищется по
 * самой длинной приставке, поэтому SAREx не попадёт в SA, а SQRV —
 * в SQ. Порядок перечисления на это не влияет, но для читаемости
 * длинные названия идут раньше коротких.
 */
return [
    [
        'code'    => 'multi',
        'title'   => 'Многооборотные приводы',
        'folders' => [
            ['code' => 'SARVEx', 'title' => 'SARVEx', 'description' => 'Приводы SARVEx'],
            ['code' => 'SAVEx',  'title' => 'SAVEx',  'description' => 'Приводы SAVEx'],
            ['code' => 'SAREx',  'title' => 'SAREx',  'description' => 'Приводы SAREx'],
            ['code' => 'SARV',   'title' => 'SARV',   'description' => 'Приводы SARV'],
            ['code' => 'SAV',    'title' => 'SAV',    'description' => 'Приводы SAV'],
            ['code' => 'SAR',    'title' => 'SAR',    'description' => 'Приводы SAR'],
            ['code' => 'SAR-UW', 'title' => 'SAR-UW', 'description' => 'Приводы SAR-UW'],
            ['code' => 'SA-UW',  'title' => 'SA-UW',  'description' => 'Приводы SA-UW'],
            ['code' => 'SAEx',   'title' => 'SAEx',   'description' => 'Приводы SAEx'],
            ['code' => 'SEVEN',  'title' => 'SEVEN',  'description' => 'Приводы SEVEN'],
            ['code' => 'SA',     'title' => 'SA',     'description' => 'Приводы SA',
             'prefixes' => ['SA', 'BSA']],
        ],
    ],
    [
        'code'    => 'part',
        'title'   => 'Неполнооборотные приводы',
        'folders' => [
            ['code' => 'SQRVEx', 'title' => 'SQRVEx', 'description' => 'Приводы SQRVEx'],
            ['code' => 'SQVEx',  'title' => 'SQVEx',  'description' => 'Приводы SQVEx'],
            ['code' => 'SGExC',  'title' => 'SGExC',  'description' => 'Приводы SGExC'],
            ['code' => 'SQREx',  'title' => 'SQREx',  'description' => 'Приводы SQREx'],
            ['code' => 'SQEx',   'title' => 'SQEx',   'description' => 'Приводы SQEx'],
            ['code' => 'SQRV',   'title' => 'SQRV',   'description' => 'Приводы SQRV'],
            ['code' => 'SQV',    'title' => 'SQV',    'description' => 'Приводы SQV'],
            ['code' => 'SQR',    'title' => 'SQR',    'description' => 'Приводы SQR'],
            ['code' => 'SGR',    'title' => 'SGR',    'description' => 'Приводы SGR'],
            ['code' => 'SQ',     'title' => 'SQ',     'description' => 'Приводы SQ'],
            ['code' => 'SG',     'title' => 'SG',     'description' => 'Приводы SG'],
        ],
    ],
    [
        'code'    => 'controls',
        'title'   => 'Блоки управления',
        'folders' => [
            ['code' => 'ACExC-SIL', 'title' => 'ACExC-SIL', 'description' => 'Блоки ACExC-SIL'],
            ['code' => 'AC-SIL',    'title' => 'AC-SIL',    'description' => 'Блоки AC-SIL'],
            ['code' => 'ACExC',     'title' => 'ACExC',     'description' => 'Блоки управления ACExC'],
            ['code' => 'AMExC',     'title' => 'AMExC',     'description' => 'Блоки управления AMExC'],
            ['code' => 'ACV',       'title' => 'ACV',       'description' => 'Блоки управления ACV'],
            ['code' => 'AC',        'title' => 'AC',        'description' => 'Блоки управления AC'],
            ['code' => 'AM',        'title' => 'AM',        'description' => 'Блоки управления AM'],
        ],
    ],
    [
        'code'    => 'gearbox',
        'title'   => 'Редукторы',
        'folders' => [
            ['code' => 'GHT', 'title' => 'GHT', 'description' => 'Редукторы GHT'],
            ['code' => 'GST', 'title' => 'GST', 'description' => 'Редукторы GST'],
            ['code' => 'GHE', 'title' => 'GHE', 'description' => 'Редукторы GHE'],
            ['code' => 'GK',  'title' => 'GK',  'description' => 'Редукторы GK'],
            ['code' => 'GP',  'title' => 'GP',  'description' => 'Редукторы GP'],
            ['code' => 'GS',  'title' => 'GS',  'description' => 'Редукторы GS'],
            ['code' => 'LE',  'title' => 'LE',  'description' => 'Редукторы LE'],
        ],
    ],
];
