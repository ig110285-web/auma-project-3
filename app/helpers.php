<?php
declare(strict_types=1);

/**
 * Мелкие помощники: конфиг, ответы, экранирование.
 */

function config(?string $key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = BASE_DIR . '/app/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('Нет app/config.php — скопируйте config.sample.php и заполните.');
        }
        $cfg = require $file;
    }
    if ($key === null) {
        return $cfg;
    }
    $node = $cfg;
    foreach (explode('.', $key) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return $default;
        }
        $node = $node[$part];
    }
    return $node;
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_out(['error' => $message], $status);
}

function json_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return $_POST ?: [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ($_POST ?: []);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(string $path = '/'): string
{
    $base = (string) config('app.base_path', '');
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

/**
 * Куда вернуть пользователя после входа. Принимаем только собственные
 * пути — иначе получится открытый редирект на чужой сайт.
 */
function safe_next(string $next): string
{
    $next = trim($next);
    if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
        return base_path('/');
    }
    return $next;
}

function upload_dir(): string
{
    $dir = (string) config('app.upload_dir');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return rtrim($dir, '/');
}

/**
 * Ссылка на статику с версией по времени изменения файла.
 * Без этого браузер держит старые app.css и app.js в кэше,
 * и правки не доходят до пользователя.
 */
function asset(string $path): string
{
    $file = BASE_DIR . '/public/' . ltrim($path, '/');
    $ver = is_file($file) ? filemtime($file) : null;
    return base_path($path) . ($ver ? '?v=' . $ver : '');
}

/** Безопасное имя файла для хранения на диске. */
function safe_stored_name(string $original): string
{
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $ext = preg_match('/^[a-z0-9]{1,8}$/', $ext) ? '.' . $ext : '';
    return bin2hex(random_bytes(16)) . $ext;
}

/** Убрать из имени файла то, что ломает заголовки и файловые системы. */
function clean_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F"\\\\:*?<>|]+/u', '_', $name) ?? 'file';
    return mb_substr(trim($name), 0, 200) ?: 'file';
}
