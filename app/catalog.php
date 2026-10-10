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
 * Если список не указан, берётся сам код папки. Приставки вроде BSA
 * позволяют отнести к папке родственные обозначения.
 * Совпадение ищется по самой длинной приставке, поэтому ACExC не
 * попадёт в AC, а SAEx — в SA.
 */
return [
    [
        'code'    => 'drive',
        'title'   => 'Привод',
        'folders' => [
            ['code' => 'SA',   'title' => 'SA',   'description' => 'Приводы серии SA',
             'prefixes' => ['SA', 'BSA']],
            ['code' => 'SAEx', 'title' => 'SAEx', 'description' => 'Приводы серии SAEx'],
            ['code' => 'SQ',   'title' => 'SQ',   'description' => 'Приводы серии SQ'],
            ['code' => 'SQEx', 'title' => 'SQEx', 'description' => 'Приводы серии SQEx'],
        ],
    ],
    [
        'code'    => 'controls',
        'title'   => 'Блоки управления',
        'folders' => [
            ['code' => 'AC',    'title' => 'AC',    'description' => 'Блоки управления AC'],
            ['code' => 'ACExC', 'title' => 'ACExC', 'description' => 'Блоки управления ACExC'],
            ['code' => 'AM',    'title' => 'AM',    'description' => 'Блоки управления AM'],
            ['code' => 'AMExC', 'title' => 'AMExC', 'description' => 'Блоки управления AMExC'],
        ],
    ],
    [
        'code'    => 'gearbox',
        'title'   => 'Редуктор',
        'folders' => [
            ['code' => 'GS', 'title' => 'GS', 'description' => 'Редукторы серии GS'],
        ],
    ],
];
