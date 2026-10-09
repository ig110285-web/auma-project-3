<?php
declare(strict_types=1);

/**
 * Единственная точка входа: страницы и API.
 */

define('BASE_DIR', dirname(__DIR__));

require BASE_DIR . '/app/helpers.php';
require BASE_DIR . '/app/db.php';
require BASE_DIR . '/app/auth.php';
require BASE_DIR . '/app/auma.php';
require BASE_DIR . '/app/api.php';

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// API отдаёт JSON и завершает работу сам
if (str_starts_with($path, '/api/')) {
    api_handle($method, $path);
    exit;
}

auth_boot();

switch ($path) {
    case '/login':
        // next нужен, когда человека привели сюда по ссылке на документ
        $next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
        if (auth_user()) {
            header('Location: ' . safe_next($next));
            exit;
        }
        if ($method === 'POST') {
            $user = auth_attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
            if ($user) {
                header('Location: ' . safe_next($next));
                exit;
            }
            $error = 'Неверный email или пароль';
        }
        require BASE_DIR . '/app/views/auth.php';
        break;

    case '/register':
        $next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
        if (auth_user()) {
            header('Location: ' . safe_next($next));
            exit;
        }
        if ($method === 'POST') {
            $res = auth_register(
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['name'] ?? '')
            );
            if ($res['ok']) {
                header('Location: ' . safe_next($next));
                exit;
            }
            $error = $res['error'] ?? 'Не удалось зарегистрироваться';
        }
        $registerMode = true;
        require BASE_DIR . '/app/views/auth.php';
        break;

    case '/logout':
        auth_logout();
        header('Location: ' . base_path('/login'));
        exit;

    case '/':
        $user = auth_user();
        if (!$user) {
            header('Location: ' . base_path('/login'));
            exit;
        }
        require BASE_DIR . '/app/views/dashboard.php';
        break;

    case '/view':
        // Просмотр документа внутри страницы сайта: HTML-файлы некоторые
        // клиенты не показывают, если отдать их как самостоятельную страницу.
        $docId = (int) ($_GET['id'] ?? 0);
        if (!auth_user()) {
            header('Location: ' . base_path('/login') . '?next=' . rawurlencode('/view?id=' . $docId));
            exit;
        }
        $doc = db_one('SELECT * FROM documents WHERE id = ?', [$docId]);
        if (!$doc) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>404</title>'
               . '<p style="font:16px system-ui;padding:40px">Документ не найден. '
               . '<a href="' . e(base_path('/')) . '">На главную</a></p>';
            exit;
        }
        $isHtml = str_starts_with((string) $doc['mime'], 'text/html');
        $embed = '';
        if ($isHtml) {
            $stored = upload_dir() . '/' . basename((string) $doc['stored_name']);
            if (is_file($stored)) {
                // документ чужой — вычищаем из него всё исполняемое
                $embed = sanitize_html((string) file_get_contents($stored));
            }
        }
        require BASE_DIR . '/app/views/viewer.php';
        break;

    default:
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>404</title>'
           . '<p style="font:16px system-ui;padding:40px">Страница не найдена. '
           . '<a href="' . e(base_path('/')) . '">На главную</a></p>';
        break;
}
