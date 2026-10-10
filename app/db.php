<?php
declare(strict_types=1);

/**
 * Подключение к MySQL и мелкие помощники уровня данных.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = config('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['host'], $cfg['port'], $cfg['name']);
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

function db_query(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_exec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

function db_insert(string $sql, array $params = []): int
{
    db_exec($sql, $params);
    return (int) db()->lastInsertId();
}

/** Применить схему из sql/schema.sql (идемпотентно). */
function db_init_schema(): void
{
    $file = BASE_DIR . '/sql/schema.sql';
    if (!is_file($file)) {
        throw new RuntimeException('Не найден sql/schema.sql');
    }
    db()->exec(file_get_contents($file));
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Определить папку по артикулу.
 *
 * Папки берутся из дерева в app/catalog.php, поэтому добавление новой
 * группы не требует правок здесь. Сначала проверяются длинные обозначения:
 * иначе ACExC попал бы в AC, а SAEx — в SA.
 */
function folder_from_article(string $article): string
{
    $a = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $article) ?? '');
    if ($a === '') {
        return '';
    }

    $codes = catalog_folder_codes();
    usort($codes, static fn(string $x, string $y): int => strlen($y) <=> strlen($x));

    foreach ($codes as $code) {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        if ($prefix !== '' && str_starts_with($a, $prefix)) {
            return $code;
        }
    }
    return '';
}

function human_size(int $bytes): string
{
    $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    $value = (float) $bytes;
    $i = 0;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return $i === 0 ? sprintf('%d %s', $value, $units[$i]) : sprintf('%.1f %s', $value, $units[$i]);
}

function log_event(?int $userId, string $action, string $entity = '', ?int $entityId = null,
                   string $meta = ''): void
{
    try {
        db_exec('INSERT INTO events (user_id, action, entity, entity_id, meta, created_at)
                 VALUES (?,?,?,?,?,?)',
            [$userId, $action, $entity, $entityId, mb_substr($meta, 0, 500), now()]);
    } catch (Throwable $e) {
        // журнал не должен ломать основную операцию
    }
}
