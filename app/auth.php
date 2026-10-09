<?php
declare(strict_types=1);

/**
 * Регистрация и вход по email + пароль. Сессия — стандартная PHP.
 */

/** Срок жизни сессии — неделя. */
const SESSION_LIFETIME = 604800;

function auth_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // PHP по умолчанию держит сессию 1440 секунд (24 минуты), а systemd-таймер
    // phpsessionclean по этому же значению удаляет файлы сессий. Из-за этого
    // человек вылетал из аккаунта после короткого простоя.
    @ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'httponly' => true,
        'samesite' => 'Lax',
        'path'     => '/',
    ]);
    session_start();
}

function auth_user(): ?array
{
    auth_boot();
    static $cached = null;
    if ($cached !== null) {
        return $cached ?: null;
    }
    $id = $_SESSION['uid'] ?? null;
    if (!$id) {
        $cached = false;
        return null;
    }
    $u = db_one('SELECT id, email, name, role, created_at FROM users WHERE id = ?', [(int) $id]);
    $cached = $u ?: false;
    return $u ?: null;
}

function auth_attempt(string $email, string $password): ?array
{
    $email = mb_strtolower(trim($email));
    $u = db_one('SELECT * FROM users WHERE email = ?', [$email]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        return null;
    }
    auth_boot();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    log_event((int) $u['id'], 'login', 'user', (int) $u['id']);
    return ['id' => (int) $u['id'], 'email' => $u['email'], 'name' => $u['name'], 'role' => $u['role']];
}

/**
 * @return array{ok:bool, error?:string, user?:array}
 */
function auth_register(string $email, string $password, string $name): array
{
    $email = mb_strtolower(trim($email));
    $name  = trim($name);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Укажите корректный email'];
    }
    if (mb_strlen($password) < 6) {
        return ['ok' => false, 'error' => 'Пароль должен быть не короче 6 символов'];
    }
    if (db_one('SELECT id FROM users WHERE email = ?', [$email])) {
        return ['ok' => false, 'error' => 'Пользователь с таким email уже зарегистрирован'];
    }
    if ($name === '') {
        $name = strstr($email, '@', true) ?: $email;
    }

    try {
        $id = db_insert(
            'INSERT INTO users (email, name, password_hash, role, created_at) VALUES (?,?,?,?,?)',
            [$email, $name, password_hash($password, PASSWORD_DEFAULT), 'user', now()]
        );
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => 'Не удалось создать пользователя'];
    }

    auth_boot();
    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
    log_event($id, 'register', 'user', $id, $email);

    return ['ok' => true, 'user' => ['id' => $id, 'email' => $email, 'name' => $name, 'role' => 'user']];
}

function auth_logout(): void
{
    auth_boot();
    $uid = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    if ($uid) {
        log_event($uid, 'logout', 'user', $uid);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'],
            (bool) $p['httponly']);
    }
    session_destroy();
}
