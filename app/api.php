<?php
declare(strict_types=1);

/**
 * JSON API. Все маршруты начинаются с /api/.
 */

function api_handle(string $method, string $path): void
{
    $routes = [
        ['POST',   '#^/api/register$#',                    'api_register'],
        ['POST',   '#^/api/login$#',                       'api_login'],
        ['POST',   '#^/api/logout$#',                      'api_logout'],
        ['GET',    '#^/api/me$#',                          'api_me'],
        ['GET',    '#^/api/bootstrap$#',                   'api_bootstrap'],
        ['GET',    '#^/api/documents$#',                   'api_documents'],
        ['POST',   '#^/api/fetch/characteristics$#',       'api_fetch_characteristics'],
        ['POST',   '#^/api/fetch/scheme$#',                'api_fetch_scheme'],
        ['GET',    '#^/api/documents/(\d+)/download$#',    'api_download'],
        ['POST',   '#^/api/documents/(\d+)/favorite$#',    'api_favorite'],
        ['POST',   '#^/api/documents/(\d+)/email$#',       'api_email'],
        ['DELETE', '#^/api/documents/(\d+)$#',             'api_delete'],
        ['GET',    '#^/api/stats$#',                       'api_stats'],
    ];

    foreach ($routes as [$verb, $pattern, $handler]) {
        if (preg_match($pattern, $path, $m) && $verb === $method) {
            array_shift($m);
            $handler(...array_map('intval', $m));
            return;
        }
    }
    json_error('Маршрут не найден: ' . $method . ' ' . $path, 404);
}

function api_require_user(): array
{
    $u = auth_user();
    if (!$u) {
        json_error('Требуется вход в систему', 401);
    }
    return $u;
}

/* ------------------------------------------------------------------ */
/* Авторизация                                                         */
/* ------------------------------------------------------------------ */

function api_register(): void
{
    $in = json_input();
    $res = auth_register(
        (string) ($in['email'] ?? ''),
        (string) ($in['password'] ?? ''),
        (string) ($in['name'] ?? '')
    );
    if (!$res['ok']) {
        json_error($res['error'] ?? 'Не удалось зарегистрироваться', 400);
    }
    json_out(['user' => $res['user']]);
}

function api_login(): void
{
    $in = json_input();
    $user = auth_attempt((string) ($in['email'] ?? ''), (string) ($in['password'] ?? ''));
    if (!$user) {
        json_error('Неверный email или пароль', 401);
    }
    json_out(['user' => $user]);
}

function api_logout(): void
{
    auth_logout();
    json_out(['ok' => true]);
}

function api_me(): void
{
    $u = auth_user();
    json_out(['user' => $u]);
}

/* ------------------------------------------------------------------ */
/* Справочные данные                                                   */
/* ------------------------------------------------------------------ */

function api_bootstrap(): void
{
    $u = api_require_user();
    json_out([
        'user'    => $u,
        'folders' => db_query(
            'SELECT f.code, f.title, f.description,
                    (SELECT COUNT(*) FROM documents d
                      WHERE d.folder_code = f.code AND d.deleted = 0) AS docs
               FROM folders f ORDER BY f.sort_order, f.code'
        ),
        'doc_types' => [
            ['code' => 'characteristics', 'title' => 'Характеристики'],
            ['code' => 'scheme',          'title' => 'Схема подключения'],
            ['code' => 'other',           'title' => 'Прочее'],
        ],
        'formats' => ['eng', 'de', 'web', 'pdf'],
        'mail_enabled' => (bool) config('mail.enabled', false),
        'stats'   => api_stats_payload(),
    ]);
}

function api_stats_payload(): array
{
    $docs = db_one('SELECT COUNT(*) AS n, COALESCE(SUM(size),0) AS b FROM documents WHERE deleted = 0')
        ?? ['n' => 0, 'b' => 0];
    $trash = db_one('SELECT COUNT(*) AS n FROM documents WHERE deleted = 1') ?? ['n' => 0];
    $users = db_one('SELECT COUNT(*) AS n FROM users') ?? ['n' => 0];

    $free = @disk_free_space(upload_dir());
    $total = @disk_total_space(upload_dir());
    $free = $free === false ? 0 : (int) $free;
    $total = $total === false ? 0 : (int) $total;
    $used = max(0, $total - $free);

    return [
        'documents'      => (int) $docs['n'],
        'documents_size' => (int) $docs['b'],
        'documents_human'=> human_size((int) $docs['b']),
        'trash'          => (int) $trash['n'],
        'users'          => (int) $users['n'],
        'disk_total'     => $total,
        'disk_used'      => $used,
        'disk_free'      => $free,
        'disk_percent'   => $total > 0 ? (int) round($used / $total * 100) : 0,
        'disk_used_human'=> human_size($used),
        'disk_total_human'=> human_size($total),
    ];
}

function api_stats(): void
{
    api_require_user();
    json_out(api_stats_payload());
}

/* ------------------------------------------------------------------ */
/* Документы                                                           */
/* ------------------------------------------------------------------ */

function doc_public(array $r): array
{
    return [
        'id'          => (int) $r['id'],
        'order_no'    => $r['order_no'],
        'article'     => $r['article'],
        'folder_code' => $r['folder_code'],
        'doc_type'    => $r['doc_type'],
        'doc_type_title' => ['characteristics' => 'Характеристики',
                             'scheme' => 'Схема подключения',
                             'other' => 'Прочее'][$r['doc_type']] ?? $r['doc_type'],
        'lang'        => $r['lang'],
        'format'      => $r['format'],
        'title'       => $r['title'],
        'filename'    => $r['filename'],
        'size'        => (int) $r['size'],
        'size_human'  => human_size((int) $r['size']),
        'mime'        => $r['mime'],
        'source_url'  => $r['source_url'],
        'owner_email' => $r['owner_email'] ?? '',
        'owner_name'  => $r['owner_name'] ?? '',
        'favorite'    => (bool) $r['favorite'],
        'created_at'  => $r['created_at'],
        'created_human' => date('d/m/Y', strtotime((string) $r['created_at'])),
        'download_url'=> base_path('/api/documents/' . (int) $r['id'] . '/download'),
    ];
}

/**
 * Список документов. Фильтр есть у каждой колонки таблицы:
 *   file, date_from/date_to, article, order, size_min/size_max, owner
 */
function api_documents(): void
{
    api_require_user();

    $where = ['d.deleted = ?'];
    $params = [(($_GET['deleted'] ?? '') === '1') ? 1 : 0];

    $q = trim((string) ($_GET['file'] ?? ''));
    if ($q !== '') {
        $where[] = '(d.filename LIKE ? OR d.title LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
    if ($dateFrom !== '') {
        $where[] = 'd.created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }
    $dateTo = trim((string) ($_GET['date_to'] ?? ''));
    if ($dateTo !== '') {
        $where[] = 'd.created_at <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }
    $article = trim((string) ($_GET['article'] ?? ''));
    if ($article !== '') {
        $where[] = 'd.article LIKE ?';
        $params[] = '%' . $article . '%';
    }
    $order = trim((string) ($_GET['order'] ?? ''));
    if ($order !== '') {
        $where[] = 'd.order_no LIKE ?';
        $params[] = '%' . $order . '%';
    }
    $owner = trim((string) ($_GET['owner'] ?? ''));
    if ($owner !== '') {
        $where[] = '(u.email LIKE ? OR u.name LIKE ?)';
        $params[] = '%' . $owner . '%';
        $params[] = '%' . $owner . '%';
    }
    if (($_GET['favorite'] ?? '') === '1') {
        $where[] = 'd.favorite = 1';
    }
    $folder = trim((string) ($_GET['folder'] ?? ''));
    if ($folder !== '') {
        $where[] = 'd.folder_code = ?';
        $params[] = $folder;
    }
    $type = trim((string) ($_GET['type'] ?? ''));
    if ($type !== '') {
        $where[] = 'd.doc_type = ?';
        $params[] = $type;
    }

    $orderBy = [
        'date_asc'  => 'd.created_at ASC',
        'size_desc' => 'd.size DESC',
        'size_asc'  => 'd.size ASC',
        'file'      => 'd.filename ASC',
        'article'   => 'd.article ASC',
        'order'     => 'd.order_no ASC',
    ][(string) ($_GET['sort'] ?? '')] ?? 'd.created_at DESC';

    $limit  = min(max((int) ($_GET['limit'] ?? 200), 1), 1000);
    $offset = max((int) ($_GET['offset'] ?? 0), 0);
    $sqlWhere = implode(' AND ', $where);

    $rows = db_query(
        "SELECT d.*, u.email AS owner_email, u.name AS owner_name
           FROM documents d LEFT JOIN users u ON u.id = d.owner_id
          WHERE $sqlWhere ORDER BY $orderBy LIMIT $limit OFFSET $offset",
        $params
    );
    $total = db_one(
        "SELECT COUNT(*) AS n FROM documents d LEFT JOIN users u ON u.id = d.owner_id
          WHERE $sqlWhere",
        $params
    );

    json_out([
        'items' => array_map('doc_public', $rows),
        'total' => (int) ($total['n'] ?? 0),
    ]);
}

/** Сохранить скачанный файл в хранилище и в базу. */
function doc_store(array $user, string $content, string $filename, array $meta): array
{
    $filename = clean_filename($filename);
    $stored = safe_stored_name($filename);
    $path = upload_dir() . '/' . $stored;

    if (file_put_contents($path, $content) === false) {
        json_error('Не удалось сохранить файл на диск', 500);
    }

    $article = (string) ($meta['article'] ?? '');
    $folder = folder_from_article($article);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    $id = db_insert(
        'INSERT INTO documents
            (order_no, article, folder_code, doc_type, lang, format, title, filename,
             stored_name, size, mime, source_url, owner_id, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            (string) ($meta['order_no'] ?? ''),
            $article,
            $folder,
            (string) ($meta['doc_type'] ?? 'other'),
            (string) ($meta['lang'] ?? 'en'),
            $ext,
            (string) ($meta['title'] ?? $filename),
            $filename,
            $stored,
            strlen($content),
            (string) ($meta['mime'] ?? 'application/octet-stream'),
            (string) ($meta['source_url'] ?? ''),
            (int) $user['id'],
            now(),
            now(),
        ]
    );
    log_event((int) $user['id'], 'upload', 'document', $id, $filename);
    $row = db_one('SELECT d.*, u.email AS owner_email, u.name AS owner_name
                     FROM documents d LEFT JOIN users u ON u.id = d.owner_id WHERE d.id = ?', [$id]);
    return doc_public($row ?? []);
}

/**
 * Артикул нужен для раскладки по папкам. Если из файла достать не удалось
 * (например, PDF сжат), берём его из ранее загруженных документов этого
 * заказа, а в крайнем случае спрашиваем htm-версию у AUMA.
 */
function resolve_article(string $order, string $article): string
{
    if ($article !== '') {
        return $article;
    }
    $row = db_one(
        "SELECT article FROM documents WHERE order_no = ? AND article <> '' LIMIT 1",
        [$order]
    );
    if ($row && $row['article'] !== '') {
        return (string) $row['article'];
    }
    return auma_lookup_article($order);
}

/* --- загрузка характеристик ---------------------------------------- */

function api_fetch_characteristics(): void
{
    $user = api_require_user();
    set_time_limit(300);   // AUMA отвечает не мгновенно
    $in = json_input();
    $order = trim((string) ($in['order'] ?? ''));
    if (!preg_match('/^\d{4,12}$/', $order)) {
        json_error('Номер заказа должен состоять из 4–12 цифр');
    }

    // выбор формата в интерфейсе: web -> htm, pdf -> pdf
    $choice = strtolower(trim((string) ($in['format'] ?? 'pdf')));
    $format = $choice === 'web' ? 'htm' : 'pdf';

    try {
        $file = auma_fetch_characteristics($order, $format);
    } catch (AumaError $e) {
        json_error($e->getMessage(), 502);
    }

    $article = resolve_article($order, auma_article_from_content($file['content']));
    $doc = doc_store($user, $file['content'], $file['filename'], [
        'order_no'   => $order,
        'article'    => $article,
        'doc_type'   => 'characteristics',
        'lang'       => 'en',
        'mime'       => $file['mime'],
        'source_url' => $file['url'],
        'title'      => 'Характеристики ' . $order,
    ]);

    json_out(['item' => $doc, 'folder' => folder_from_article($article)], 201);
}

/* --- загрузка схемы подключения ------------------------------------ */

function api_fetch_scheme(): void
{
    $user = api_require_user();
    set_time_limit(300);   // схема формируется на стороне AUMA
    $in = json_input();
    $order = trim((string) ($in['order'] ?? ''));
    if (!preg_match('/^\d{4,12}$/', $order)) {
        json_error('Номер заказа должен состоять из 4–12 цифр');
    }

    try {
        $file = auma_fetch_scheme($order);
    } catch (AumaError $e) {
        json_error($e->getMessage(), 502);
    }

    $article = resolve_article($order, $file['article']);

    $doc = doc_store($user, $file['content'], $file['filename'], [
        'order_no'   => $order,
        'article'    => $article,
        'doc_type'   => 'scheme',
        'lang'       => 'en',
        'mime'       => $file['mime'],
        'source_url' => $file['url'],
        'title'      => 'Схема подключения ' . $order,
    ]);

    json_out(['item' => $doc, 'article' => $article,
              'folder' => folder_from_article($article)], 201);
}

/* --- скачивание ------------------------------------------------------ */

/**
 * Отдача файла.
 *   ?mode=view  — открыть в браузере (просмотр без скачивания)
 *   без mode    — скачать
 */
function api_download(int $id): void
{
    if (!auth_user()) {
        // Ссылку на документ открыли без входа. Сухой JSON выглядит как
        // «страница не открывается», поэтому ведём на форму входа
        // и после неё возвращаем человека обратно к файлу.
        $next = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . base_path('/login') . '?next=' . rawurlencode($next));
        exit;
    }
    $row = db_one('SELECT * FROM documents WHERE id = ?', [$id]);
    if (!$row) {
        json_error('Документ не найден', 404);
    }
    $path = upload_dir() . '/' . basename((string) $row['stored_name']);
    if (!is_file($path)) {
        json_error('Файл отсутствует в хранилище', 404);
    }

    $view = (($_GET['mode'] ?? '') === 'view');
    $name = (string) $row['filename'];
    $mime = (string) ($row['mime'] ?: 'application/octet-stream');

    // часть браузеров понимает только простой filename=, поэтому отдаём оба
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?: 'file';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header(sprintf(
        "Content-Disposition: %s; filename=\"%s\"; filename*=UTF-8''%s",
        $view ? 'inline' : 'attachment',
        addcslashes($ascii, '"\\'),
        rawurlencode($name)
    ));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');

    if ($view && str_starts_with($mime, 'text/html')) {
        // Документ пришёл с чужого сайта: показываем в песочнице,
        // чтобы его скрипты не могли ничего сделать от имени нашего домена.
        header('Content-Security-Policy: sandbox');
    }
    readfile($path);
    exit;
}

function api_favorite(int $id): void
{
    api_require_user();
    $in = json_input();
    $value = !empty($in['value']) ? 1 : 0;
    $n = db_exec('UPDATE documents SET favorite = ?, updated_at = ? WHERE id = ?', [$value, now(), $id]);
    if ($n === 0 && !db_one('SELECT id FROM documents WHERE id = ?', [$id])) {
        json_error('Документ не найден', 404);
    }
    json_out(['ok' => true, 'favorite' => (bool) $value]);
}

function api_delete(int $id): void
{
    $user = api_require_user();
    $row = db_one('SELECT * FROM documents WHERE id = ?', [$id]);
    if (!$row) {
        json_error('Документ не найден', 404);
    }
    $path = upload_dir() . '/' . basename((string) $row['stored_name']);
    if (is_file($path)) {
        @unlink($path);
    }
    db_exec('DELETE FROM documents WHERE id = ?', [$id]);
    log_event((int) $user['id'], 'delete', 'document', $id, (string) $row['filename']);
    json_out(['ok' => true]);
}

/**
 * Отправка на почту. Реальная отправка выключена в настройках —
 * письмо записывается в журнал email_log со статусом stub.
 */
function api_email(int $id): void
{
    $user = api_require_user();
    $row = db_one('SELECT * FROM documents WHERE id = ?', [$id]);
    if (!$row) {
        json_error('Документ не найден', 404);
    }
    $in = json_input();
    $to = trim((string) ($in['to'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        json_error('Укажите корректный адрес получателя');
    }

    $enabled = (bool) config('mail.enabled', false);
    $subject = 'Документ AUMA: ' . $row['filename'];
    $body = "Документ: {$row['filename']}\n"
          . "Заказ: {$row['order_no']}\n"
          . "Артикул: {$row['article']}\n"
          . "Тип: {$row['doc_type']}\n"
          . "Размер: " . human_size((int) $row['size']) . "\n";

    $status = 'stub';
    $error = '';
    if ($enabled) {
        // Реальная отправка подключится здесь, когда появятся настройки SMTP.
        $status = 'stub';
        $error = 'SMTP не настроен';
    }

    $logId = db_insert(
        'INSERT INTO email_log (document_id, user_id, to_email, subject, body, status, error, created_at)
         VALUES (?,?,?,?,?,?,?,?)',
        [$id, (int) $user['id'], $to, $subject, $body, $status, $error, now()]
    );
    log_event((int) $user['id'], 'email', 'document', $id, $to);

    json_out([
        'ok'      => true,
        'queued'  => true,
        'sent'    => false,
        'log_id'  => $logId,
        'message' => 'Отправка на почту пока отключена. Письмо сохранено в журнал (#' . $logId . ').',
    ]);
}
