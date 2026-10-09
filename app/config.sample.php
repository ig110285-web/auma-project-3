<?php
/**
 * Образец конфигурации. Скопируйте в config.php и заполните.
 * config.php в репозиторий не попадает.
 */
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'auma',
        'user' => 'auma',
        'pass' => 'ЗАМЕНИТЕ_ПАРОЛЬ',
    ],

    'app' => [
        'name'       => 'AUMA Documentation',
        // Каталог для скачанных документов. Должен быть доступен на запись PHP.
        'upload_dir' => dirname(__DIR__) . '/public/uploads',
        // Префикс, если сайт живёт в подпапке. Для корня оставить пустым.
        'base_path'  => '',
    ],

    // Отправка на почту. Пока выключена — письма только пишутся в журнал email_log.
    'mail' => [
        'enabled'   => false,
        'host'      => '',
        'port'      => 587,
        'user'      => '',
        'pass'      => '',
        'from'      => 'noreply@localhost',
        'from_name' => 'AUMA Documentation',
    ],
];
