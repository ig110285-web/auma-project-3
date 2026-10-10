<?php
declare(strict_types=1);

/**
 * Дерево хранения: группы и папки внутри них.
 *
 * Это единственное место, где описан состав групп — из него же
 * заполняется таблица folders и строится меню. Чтобы добавить папку,
 * достаточно дописать её сюда и запустить db_init_schema().
 *
 * Порядок папок внутри группы — тот, в котором они показываются.
 */
return [
    [
        'code'    => 'drive',
        'title'   => 'Привод',
        'folders' => [
            ['code' => 'SA',   'title' => 'SA',   'description' => 'Приводы серии SA'],
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
];
