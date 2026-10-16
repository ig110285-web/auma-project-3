<?php
declare(strict_types=1);

/**
 * Интеграция с сайтом AUMA: получение характеристик и схем подключения
 * по номеру заказа.
 *
 * Разобрано на живом сайте:
 *
 *  1) Характеристики (Technical Data Sheet)
 *     GET  www4.auma.com/webservices/TechnicalDataSheetPublic/request.aspx?lang=en
 *     POST TypeDdl=Order, numberTB=<заказ>, LanguageDdl=E, FormatDdl=htm|pdf
 *     → в ответе ссылка .../AumaWebService/Data/TDS<заказ>_en-<метка>.<ext>
 *
 *  2) Схема подключения (Schaltplan)
 *     GET  www4.auma.com/Schaltplan/AuftragsForm.aspx?Lang=en
 *     POST OrderOrSerialNo=<заказ>, Button1=execute
 *     POST RequestBttn=Request wiring diagram
 *     → редирект Redirector.aspx?goto=temp%2fTPA....pdf
 *
 * ВАЖНО: AUMA отдаёт HTTP 500, если запрос не похож на браузерный.
 * Поэтому заголовки Accept / Accept-Language / Sec-Fetch-* обязательны.
 */

const AUMA_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
    . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

const AUMA_TDS_FORM = 'https://www4.auma.com/webservices/TechnicalDataSheetPublic/request.aspx?lang=en';
const AUMA_SP_FORM  = 'https://www4.auma.com/Schaltplan/AuftragsForm.aspx?Lang=en';
const AUMA_SP_BASE  = 'https://www4.auma.com/Schaltplan/';
const AUMA_PAGE_TDS = 'https://www.auma.com/ru_RU/Downloads/auftragsdatenblatt';
const AUMA_PAGE_SP  = 'https://www.auma.com/ru_RU/Downloads/schaltplaene/auftragsnummer';

class AumaError extends RuntimeException
{
}

/** Заголовки, без которых AUMA отвечает 500. */
function auma_headers(string $referer, string $dest = 'iframe'): array
{
    return [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Fetch-Dest: ' . $dest,
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: cross-site',
        'Referer: ' . $referer,
    ];
}

/**
 * Один HTTP-запрос с общим cookie-файлом.
 * @return array{status:int, body:string, url:string, type:string}
 */
function auma_http(string $url, ?array $post, string $jar, array $headers): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 6,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_USERAGENT      => AUMA_UA,
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($post !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new AumaError('Не удалось связаться с AUMA: ' . $err);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $final  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $type   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return ['status' => $status, 'body' => (string) $body, 'url' => $final, 'type' => $type];
}

/** Достать значение скрытого поля ASP.NET. */
function auma_field(string $html, string $name): string
{
    $n = preg_quote($name, '/');
    if (preg_match('/name="' . $n . '"[^>]*value="([^"]*)"/i', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES);
    }
    if (preg_match('/value="([^"]*)"[^>]*name="' . $n . '"/i', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES);
    }
    return '';
}

/** Все скрытые поля формы — их нужно вернуть обратно при POST. */
function auma_hidden(string $html): array
{
    $out = [];
    if (preg_match_all('/<input[^>]*type="hidden"[^>]*>/i', $html, $tags)) {
        foreach ($tags[0] as $tag) {
            if (preg_match('/name="([^"]+)"/i', $tag, $n)) {
                $out[$n[1]] = auma_field($html, $n[1]);
            }
        }
    }
    return $out;
}

function auma_temp_jar(): string
{
    $jar = tempnam(sys_get_temp_dir(), 'auma');
    if ($jar === false) {
        throw new AumaError('Не удалось создать файл cookie');
    }
    return $jar;
}

/* ------------------------------------------------------------------ */
/* Кеш ответов AUMA                                                    */
/* ------------------------------------------------------------------ */

/**
 * Заказ оформлен, его характеристики на стороне AUMA не меняются.
 * Но каждый запрос — это два-три обращения по сети и скачивание файла,
 * то есть секунды. Поэтому ответ кладётся в базу, и все следующие
 * обращения берут его оттуда.
 *
 * Если база недоступна, кеш просто не работает — запрос идёт на AUMA,
 * как раньше. Ошибка кеша загрузку не ломает.
 */

/**
 * Кеш ответов AUMA. false — работать без него, как раньше: каждый запрос
 * идёт на AUMA. Переключатель нужен, чтобы сравнить поведение и скорость;
 * данные в таблице auma_cache при этом остаются нетронутыми.
 */
const AUMA_CACHE_ENABLED = false;

function auma_cache_ready(): bool
{
    return AUMA_CACHE_ENABLED
        && function_exists('db_query') && function_exists('db_exec');
}

function auma_cache_ensure(): void
{
    static $done = false;
    if ($done || !auma_cache_ready()) {
        return;
    }
    $done = true;
    try {
        db_exec('CREATE TABLE IF NOT EXISTS auma_cache (
            id       INT AUTO_INCREMENT PRIMARY KEY,
            order_no CHAR(8) NOT NULL,
            fmt      VARCHAR(8) NOT NULL,
            filename VARCHAR(255) NULL,
            mime     VARCHAR(64) NULL,
            url      VARCHAR(512) NULL,
            article  VARCHAR(64) NULL,
            content  MEDIUMBLOB,
            size     INT NULL,
            fetched  DATETIME NULL,
            UNIQUE KEY uk_order_fmt (order_no, fmt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } catch (Throwable $e) {
        // нет прав или базы — работаем без кеша
    }
}

/** Ответ из кеша или null, если его там нет. */
function auma_cache_get(string $order, string $format, bool $withContent = true): ?array
{
    if (!auma_cache_ready()) {
        return null;
    }
    try {
        auma_cache_ensure();
        $cols = $withContent ? '*' : 'order_no, fmt, filename, mime, url, article, size, fetched';
        $row = db_one('SELECT ' . $cols . ' FROM auma_cache WHERE order_no = ? AND fmt = ?',
                      [$order, $format]);
    } catch (Throwable $e) {
        return null;
    }
    if (!$row) {
        return null;
    }
    $out = [
        'filename' => (string) $row['filename'],
        'mime'     => (string) $row['mime'],
        'url'      => (string) $row['url'],
        'cached'   => true,
    ];
    if ($withContent) {
        $out['content'] = (string) $row['content'];
    }
    if (isset($row['article'])) {
        $out['article'] = (string) $row['article'];
    }
    return $out;
}

/** Положить ответ в кеш. */
function auma_cache_put(string $order, string $format, array $res): void
{
    if (!auma_cache_ready()) {
        return;
    }
    try {
        auma_cache_ensure();
        db_exec('INSERT INTO auma_cache
                 (order_no, fmt, filename, mime, url, article, content, size, fetched)
                 VALUES (?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE filename = VALUES(filename),
                 mime = VALUES(mime), url = VALUES(url), article = VALUES(article),
                 content = VALUES(content), size = VALUES(size), fetched = VALUES(fetched)',
            [
                $order,
                $format,
                (string) ($res['filename'] ?? ''),
                (string) ($res['mime'] ?? ''),
                (string) ($res['url'] ?? ''),
                (string) ($res['article'] ?? ''),
                (string) ($res['content'] ?? ''),
                strlen((string) ($res['content'] ?? '')),
                now(),
            ]);
    } catch (Throwable $e) {
        // не смогли сохранить — не страшно, просто запросим в следующий раз
    }
}

/** Убрать заказ из кеша — на случай, если он изменился на стороне AUMA. */
function auma_cache_forget(string $order): void
{
    if (!auma_cache_ready()) {
        return;
    }
    try {
        db_exec('DELETE FROM auma_cache WHERE order_no = ?', [$order]);
    } catch (Throwable $e) {
    }
}

/* ------------------------------------------------------------------ */
/* Запросы к AUMA                                                      */
/* ------------------------------------------------------------------ */

/**
 * Характеристики: получить файл по номеру заказа.
 *
 * @param string $order  номер заказа, например 23080383
 * @param string $format htm | pdf
 * @param bool   $fresh  true — запросить заново, мимо кеша
 * @return array{filename:string, content:string, mime:string, url:string}
 */
function auma_fetch_characteristics(string $order, string $format = 'htm',
                                    bool $fresh = false): array
{
    $format = in_array($format, ['htm', 'pdf'], true) ? $format : 'htm';

    if (!$fresh) {
        $cached = auma_cache_get($order, $format);
        if ($cached !== null && ($cached['content'] ?? '') !== '') {
            return $cached;
        }
    }

    $jar = auma_temp_jar();
    try {
        $r = auma_http(AUMA_TDS_FORM, null, $jar, auma_headers(AUMA_PAGE_TDS));
        if ($r['status'] !== 200) {
            throw new AumaError('Форма характеристик недоступна (HTTP ' . $r['status'] . ')');
        }
        $post = auma_hidden($r['body']);
        $post['TypeDdl']     = 'Order';       // искать по номеру заказа
        $post['numberTB']    = $order;
        $post['LanguageDdl'] = 'E';           // English, как в задании
        $post['FormatDdl']   = $format;
        $post['FetchBtn']    = 'Search';

        $r2 = auma_http(AUMA_TDS_FORM, $post, $jar, auma_headers(AUMA_PAGE_TDS));
        if (!preg_match('#https://www4\.auma\.com/AumaWebService/Data/[^"\'\s<>]+#i', $r2['body'], $m)) {
            throw new AumaError('AUMA не вернула ссылку на файл. Проверьте номер заказа: ' . $order);
        }
        $link = html_entity_decode($m[0], ENT_QUOTES);

        $r3 = auma_http($link, null, $jar, auma_headers(AUMA_PAGE_TDS, 'document'));
        if ($r3['status'] !== 200 || $r3['body'] === '') {
            throw new AumaError('Не удалось скачать файл характеристик (HTTP ' . $r3['status'] . ')');
        }
        $out = [
            'filename' => basename(parse_url($link, PHP_URL_PATH) ?: ('TDS' . $order . '.' . $format)),
            'content'  => $r3['body'],
            'mime'     => $format === 'pdf' ? 'application/pdf' : 'text/html; charset=utf-8',
            'url'      => $link,
        ];
        auma_cache_put($order, $format, $out);
        return $out;
    } finally {
        @unlink($jar);
    }
}

/**
 * Схема подключения по номеру заказа.
 *
 * @return array{filename:string, content:string, mime:string, url:string, article:string}
 */
function auma_fetch_scheme(string $order, bool $fresh = false): array
{
    if (!$fresh) {
        $cached = auma_cache_get($order, 'scheme');
        if ($cached !== null && ($cached['content'] ?? '') !== '') {
            return $cached;
        }
    }

    $jar = auma_temp_jar();
    try {
        $r = auma_http(AUMA_SP_FORM, null, $jar, auma_headers(AUMA_PAGE_SP));
        if ($r['status'] !== 200) {
            throw new AumaError('Форма схем недоступна (HTTP ' . $r['status'] . ')');
        }

        // шаг 1: execute
        $post = auma_hidden($r['body']);
        $post['OrderOrSerialNo'] = $order;
        $post['Button1']         = 'execute';
        $r2 = auma_http(AUMA_SP_FORM, $post, $jar, auma_headers(AUMA_PAGE_SP));
        if ($r2['status'] !== 200) {
            throw new AumaError('AUMA отклонила номер заказа (HTTP ' . $r2['status'] . ')');
        }

        // артикул изделия — сразу из таблицы, до скачивания файла
        $article = auma_extract_article($r2['body']);

        // шаг 2: Request wiring diagram
        $post2 = auma_hidden($r2['body']);
        $post2['OrderOrSerialNo'] = $order;
        $post2['RequestBttn']     = 'Request wiring diagram';
        $r3 = auma_http(AUMA_SP_FORM, $post2, $jar, auma_headers(AUMA_PAGE_SP));

        // ссылка на PDF приходит либо в адресе Redirector.aspx?goto=..., либо в теле
        $pdfUrl = '';
        foreach ([$r3['url'], $r3['body']] as $hay) {
            if (preg_match('#Redirector\.aspx\?goto=([^"\'\s&]+)#i', $hay, $gm)) {
                $rel = urldecode($gm[1]);
                $pdfUrl = AUMA_SP_BASE . ltrim($rel, '/');
                break;
            }
            if (preg_match('#(?:https://www4\.auma\.com)?/Schaltplan/(temp/[^"\'\s<>]+\.pdf)#i', $hay, $gm)) {
                $pdfUrl = AUMA_SP_BASE . $gm[1];
                break;
            }
        }
        if ($pdfUrl === '') {
            throw new AumaError('AUMA не вернула ссылку на схему подключения для заказа ' . $order);
        }

        $r4 = auma_http($pdfUrl, null, $jar, auma_headers(AUMA_PAGE_SP, 'document'));
        if ($r4['status'] !== 200 || $r4['body'] === '') {
            throw new AumaError('Не удалось скачать схему (HTTP ' . $r4['status'] . ')');
        }
        $out = [
            'filename' => rawurldecode(basename(parse_url($pdfUrl, PHP_URL_PATH) ?: 'scheme.pdf')),
            'content'  => $r4['body'],
            'mime'     => 'application/pdf',
            'url'      => $pdfUrl,
            'article'  => $article,
        ];
        auma_cache_put($order, 'scheme', $out);
        return $out;
    } finally {
        @unlink($jar);
    }
}

/** Превратить HTML в плоский текст: убрать теги, раскрыть сущности, сжать пробелы. */
function auma_text(string $html): string
{
    $t = preg_replace('/<(script|style)\b.*?<\/\1>/is', ' ', $html) ?? $html;
    $t = preg_replace('/<[^>]*>/', ' ', $t) ?? $t;
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // неразрывные и тонкие пробелы — в обычные
    $t = str_replace(["\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89"], ' ', $t);
    return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
}

/** Привести артикул к единому виду: «BSA 10.2» -> «BSA-10.2». */
function auma_normalize_article(string $raw): string
{
    $v = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
    $v = trim($v, " \t\n\r\0\x0B.,;:");
    // пробел между буквами и цифрами заменяем на дефис
    $v = preg_replace('/^([A-Za-z][A-Za-z0-9]*)\s+(\d)/u', '$1-$2', $v) ?? $v;
    return mb_strtoupper($v);
}

/** Ячейки одной строки таблицы. */
function auma_row_cells(string $rowHtml): array
{
    // пустые ячейки-распорки вида <td width="20px" /> убираем,
    // иначе они ломают разбор
    $rowHtml = preg_replace('#<t[dh][^>]*/>#i', '', $rowHtml) ?? $rowHtml;
    if (!preg_match_all('#<t[dh][^>]*>(.*?)</t[dh]>#is', $rowHtml, $m)) {
        return [];
    }
    $out = [];
    foreach ($m[1] as $cell) {
        $v = html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $out[] = trim(preg_replace('/\s+/u', ' ', $v) ?? $v);
    }
    return $out;
}

/** Похоже ли значение на артикул: буквы и цифры, без пробелов внутри. */
function auma_looks_like_article(string $value): bool
{
    return $value !== ''
        && mb_strlen($value) <= 40
        && preg_match('/\d/', $value) === 1
        && preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $value) === 1;
}

/** Все строки таблиц документа. */
function auma_rows(string $html): array
{
    return preg_match_all('#<tr[^>]*>(.*?)</tr>#is', $html, $m) ? $m[1] : [];
}

/**
 * Артикул изделия из документа AUMA.
 *
 * В паспорте заказа он лежит в той же строке, что и подпись «Article»:
 *   <tr><td>Article</td><td width="20px" /><td bgcolor="#ffff99">BSA-10.2</td></tr>
 *
 * В форме схем это таблица с шапкой «Position | Article no. | Article name |
 * Amount», поэтому там значение берётся из столбца с заголовком «Article no.».
 *
 * Артикул может быть любым (SQEX-07.2, BSA-10.2, AM-01.1), привязки
 * к конкретным сериям нет.
 */
function auma_extract_article(string $html): string
{
    $rows = auma_rows($html);

    // 0. Первая позиция заказа: значение берём из строки «Type».
    //
    // В части отчётов строка «Article» для первой позиции заполнена неверно.
    // Пример — заказ 13405414:
    //     Article || SAEX-14.2      <- так писать нельзя
    //     Type    || SAREX-14.2     <- верное значение
    // Разница в букве R: SAREx — привод для регулирующего режима, SAEx —
    // обычный. Значение в «Type» всегда соответствует позиции Pos. 1.0,
    // поэтому оно и берётся первым, а «Article» остаётся запасным.
    foreach ($rows as $rowHtml) {
        $cells = auma_row_cells($rowHtml);
        foreach ($cells as $i => $cell) {
            if (!preg_match('/^Type$/iu', $cell)) {
                continue;
            }
            foreach (array_slice($cells, $i + 1) as $value) {
                if (auma_looks_like_article($value)
                    && preg_match('/^[A-Za-z]/', trim($value))) {
                    return auma_normalize_article($value);
                }
            }
        }
    }

    // 1. Значение в той же строке, где стоит подпись «Article».
    foreach ($rows as $rowHtml) {
        $cells = auma_row_cells($rowHtml);
        foreach ($cells as $i => $cell) {
            if (!preg_match('/^Article$/iu', $cell)) {
                continue;
            }
            foreach (array_slice($cells, $i + 1) as $value) {
                if (auma_looks_like_article($value)) {
                    return auma_normalize_article($value);
                }
            }
        }
    }

    // 2. Столбец «Article no.»: берём значение из той же колонки ниже шапки.
    foreach ($rows as $rowHtml) {
        $cells = auma_row_cells($rowHtml);
        foreach ($cells as $i => $cell) {
            if (!preg_match('/^Article\s*no\.?$/iu', $cell)) {
                continue;
            }
            foreach ($rows as $dataRow) {
                $data = auma_row_cells($dataRow);
                if (isset($data[$i]) && auma_looks_like_article($data[$i])) {
                    return auma_normalize_article($data[$i]);
                }
            }
        }
    }

    // 3. По плоскому тексту: «Article BSA-10.2 Customer article BSA-10.2»
    $text = auma_text($html);
    if (preg_match('/(?<!Customer )Article\s+([A-Za-z0-9][\w.\/-]{1,30})/u', $text, $m)
        && auma_looks_like_article($m[1])) {
        return auma_normalize_article($m[1]);
    }

    // 4. Запасной вариант — привычные серии
    if (preg_match('/\b(S[QA]EX?)\s*[-–—]?\s*(\d+(?:[.,]\d+)?)\b/ui', $text, $m)) {
        return mb_strtoupper($m[1]) . '-' . str_replace(',', '.', $m[2]);
    }

    return '';
}

/**
 * Серийные номера из строки «Serials:» — по видам изделий.
 *
 * Вид определяется буквенной парой в номере, например 1515NM94475:
 * четыре цифры, две буквы, дальше цифры.
 *
 *   MD, NS  -> приводы            p
 *   MA      -> блоки управления   b
 *   MM, NM  -> моторы             m
 *   CG, MK  -> редукторы          r
 *
 * Номера с незнакомой парой попадают только в общий список all:
 * отнести их не к чему, а гадать не стоит.
 *
 * @return array{m:string[],p:string[],b:string[],r:string[],all:string[]}
 */
function auma_serials(string $html): array
{
    $out = ['m' => [], 'p' => [], 'b' => [], 'r' => [], 'all' => []];
    $text = auma_text($html);
    // Двоеточие обязательно, а «s» в конце — нет. Иначе шаблон цепляется
    // за подсказку AUMA «Serial number for the search» и захватывает
    // совершенно другой кусок текста.
    // Флаг u здесь тоже нельзя: .htm приходит не в UTF-8, и preg_match
    // с ним молча возвращает false вместо совпадения.
    // Строк «Serials:» в отчёте может быть несколько — по одной на позицию:
    // сначала список привода, дальше список блока управления. Читаем все,
    // иначе номера второй позиции теряются.
    if (!preg_match_all('/Serials?\s*:\s*(.{4,400})/is', $text, $mm)) {
        return $out;
    }

    $all = [];
    foreach ($mm[1] as $chunk) {
        if (!preg_match_all('/\d{4}[A-Z]{2}\d{4,}/i', $chunk, $nums)) {
            continue;
        }
        foreach ($nums[0] as $n) {
            $n = strtoupper($n);
            if (!in_array($n, $all, true)) {
                $all[] = $n;
            }
        }
    }
    $out['all'] = $all;

    $byPair = [
        'MD' => 'p', 'NS' => 'p',
        'MA' => 'b',
        'MM' => 'm', 'NM' => 'm',
        'CG' => 'r', 'MK' => 'r',
    ];
    foreach ($all as $n) {
        if (!preg_match('/^\d{4}([A-Z]{2})\d+$/', $n, $p)) {
            continue;
        }
        $kind = $byPair[$p[1]] ?? '';
        if ($kind !== '') {
            $out[$kind][] = $n;
        }
    }
    return $out;
}

/** Попытаться вытащить артикул из уже скачанного файла характеристик. */
function auma_article_from_content(string $content): string
{
    return auma_extract_article(substr($content, 0, 200000));
}

/**
 * Узнать артикул по номеру заказа, если из самого файла его достать не удалось.
 * PDF сжат, текста в нём не видно, поэтому запрашиваем ещё и htm-версию —
 * она маленькая и содержит артикул в читаемом виде.
 */
function auma_lookup_article(string $order): string
{
    try {
        $file = auma_fetch_characteristics($order, 'htm');
        return auma_article_from_content($file['content']);
    } catch (AumaError $e) {
        return '';
    }
}

/* ------------------------------------------------------------------ */
/* Разбор HTML по позициям                                             */
/* ------------------------------------------------------------------ */

/**
 * Разложить HTML-документ AUMA по позициям.
 *
 * Каждая позиция начинается с <b>Pos.N.0</b>, следом идёт таблица
 * с её артикулом. Позиция занимает всё до следующей пометки Pos.
 *
 * Страниц в HTML нет, поэтому «вырезать» можно только разметку:
 * дополнительные позиции сохраняются отдельными файлами со своей
 * обёрткой — заголовком, стилем AUMA и строкой заказа.
 *
 * @return array{articles:string[], parts:array<int, array{pos:string, article:string, content:string}>}
 */
function htm_split_positions(string $content): array
{
    $result = ['articles' => [], 'parts' => []];
    if ($content === '') {
        return $result;
    }

    if (!preg_match_all('#<b>\s*Pos\.\s*(\d+(?:\.\d+)?)\s*</b>#iu', $content, $m, PREG_OFFSET_CAPTURE)) {
        return $result;
    }

    $markers = [];
    foreach ($m[0] as $i => $hit) {
        $markers[] = ['pos' => (string) $m[1][$i][0], 'start' => (int) $hit[1]];
    }
    if (count($markers) < 2) {
        return $result;
    }

    // строка заказа и подключение стилей — чтобы вырезанный файл
    // выглядел так же, как исходный
    $orderLine = '';
    if (preg_match('#<span[^>]*>\s*<b>\s*Order:.*?</span>#is', $content, $om)) {
        $orderLine = $om[0];
    }
    $styleLink = '';
    if (preg_match('#<LINK\b[^>]*>#i', $content, $sm)) {
        $styleLink = $sm[0];
    }

    $total = strlen($content);
    $count = count($markers);

    for ($i = 0; $i < $count; $i++) {
        $end = ($i + 1 < $count) ? $markers[$i + 1]['start'] : $total;
        $fragment = substr($content, $markers[$i]['start'], $end - $markers[$i]['start']);

        // у последней позиции обрезаем подвал с копирайтом
        $cut = stripos($fragment, '<hr color=');
        if ($cut !== false) {
            $fragment = substr($fragment, 0, $cut);
        }
        $fragment = trim($fragment);

        $article = auma_extract_article($fragment);
        if ($article !== '' && !in_array($article, $result['articles'], true)) {
            $result['articles'][] = $article;
        }

        // каждая позиция сохраняется отдельным файлом, включая первую
        if ($fragment === '') {
            continue;
        }

        $result['parts'][] = [
            'pos'     => $markers[$i]['pos'],
            'article' => $article,
            'content' => '<html><head><meta charset="utf-8">'
                . '<title>Pos. ' . $markers[$i]['pos'] . '</title>'
                . $styleLink
                . '</head><body text="#000000" bgColor="#ffffff" leftMargin="6" topMargin="0">'
                . $orderLine
                . '<span style="display:block; margin:15px">' . $fragment . '</span>'
                . '</body></html>',
        ];
    }

    return $result;
}

/* ------------------------------------------------------------------ */
/* Деление отчётов другого вида по изделиям                            */
/* ------------------------------------------------------------------ */

/**
 * Страницы, с которых начинаются изделия, когда пометок Pos. нет.
 *
 * В отчётах другого вида заказ перечисляет изделия блоками: у каждого
 * своя строка «article» (со строчной буквы), и блок начинается
 * с новой страницы. Строку «Article number :» пропускаем.
 *
 * @return array<int, array{page:int, article:string}>
 */
function pdf_article_pages(string $path): array
{
    if (!pdf_tools_available() || !is_file($path)) {
        return [];
    }
    $text = (string) @shell_exec('pdftotext -layout ' . escapeshellarg($path) . ' - 2>/dev/null');
    if (trim($text) === '') {
        return [];
    }

    $out = [];
    foreach (explode("\f", $text) as $i => $page) {
        if (preg_match('/^[ \t]*article[ \t]+(?!number\b)(.+?)[ \t]*$/im', $page, $m)) {
            $article = trim($m[1]);
            if ($article !== '') {
                $out[] = ['page' => $i + 1, 'article' => $article];
            }
        }
    }
    return $out;
}

/**
 * Где именно на страницах стоят строки «article» — с координатой.
 *
 * Нужна та же аккуратность, что и с позициями: блок изделия может
 * перейти на следующую страницу, и тогда на одной странице окажется
 * хвост одного изделия и начало другого. Режем по координате строки.
 *
 * Ищем слово «article» именно в нижнем регистре: строка «Article
 * number :» из раздела двигателя набрана с заглавной и не подходит.
 *
 * @return array{pages:array<int, array{w:float, h:float}>, marks:array<int, array{page:int, y:float, article:string}>}
 */
function pdf_article_marks(string $path): array
{
    $out = ['pages' => [], 'marks' => []];
    if (!pdf_tools_available() || !is_file($path)) {
        return $out;
    }
    $xml = (string) @shell_exec('pdftotext -bbox ' . escapeshellarg($path) . ' - 2>/dev/null');
    if (trim($xml) === '') {
        return $out;
    }
    if (!preg_match_all('#<page width="([\d.]+)" height="([\d.]+)">(.*?)</page>#s', $xml, $pages, PREG_SET_ORDER)) {
        return $out;
    }

    foreach ($pages as $i => $page) {
        $no = $i + 1;
        $out['pages'][$no] = ['w' => (float) $page[1], 'h' => (float) $page[2]];

        // слова страницы по порядку
        if (!preg_match_all(
            '#<word xMin="[\d.]+" yMin="([\d.]+)" xMax="[\d.]+" yMax="[\d.]+">([^<]*)</word>#',
            $page[3],
            $words,
            PREG_SET_ORDER
        )) {
            continue;
        }
        $count = count($words);
        $markY = null;
        $article = '';

        for ($k = 0; $k < $count; $k++) {
            $word = $words[$k][2];

            // строка-маркер выглядит как «pos 2»: слово pos и число за ним
            if ($markY === null && mb_strtolower($word, 'UTF-8') === 'pos') {
                $num = $words[$k + 1][2] ?? '';
                if (preg_match('/^\d+$/', $num)) {
                    $markY = (float) $words[$k][1];
                }
                continue;
            }

            // артикул — в строке «article», она идёт сразу за маркером
            if ($article === '' && $word === 'article') {
                $next = $words[$k + 1][2] ?? '';
                if ($next !== '' && mb_strtolower($next, 'UTF-8') !== 'number') {
                    $article = trim($next);
                    for ($m = $k + 2; $m < $count && $m < $k + 5; $m++) {
                        $candidate = trim($article . ' ' . $words[$m][2]);
                        if (mb_strlen($candidate, 'UTF-8') > 24) {
                            break;
                        }
                        $article = $candidate;
                        if (preg_match('/\d/u', $words[$m][2])) {
                            break;
                        }
                    }
                }
            }
        }

        if ($markY !== null || $article !== '') {
            $out['marks'][] = [
                'page'    => $no,
                'y'       => $markY,
                'article' => $article,
            ];
        }
    }
    return $out;
}

/** Хвост имени файла из артикула. */
function article_suffix(string $article): string
{
    return trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $article), '_');
}

/**
 * Разложить PDF по изделиям: каждому достаются его страницы.
 *
 * Хвост предыдущего изделия отрезается, начало поднимается к верхнему
 * краю — так же, как при делении по позициям.
 *
 * @return array{articles:string[], parts:array<int, array{suffix:string, article:string, content:string}>}
 */
function pdf_split_articles(string $content): array
{
    $result = ['articles' => [], 'parts' => []];
    if (!pdf_tools_available() || $content === '') {
        return $result;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'auma_art_');
    if ($tmp === false) {
        return $result;
    }
    @unlink($tmp);
    $tmp .= '.pdf';
    file_put_contents($tmp, $content);

    try {
        $geometry = pdf_article_marks($tmp);
        $marks = $geometry['marks'];
        if (count($marks) < 2) {
            return $result;   // одно изделие — делить нечего
        }

        $info = pdf_outline($tmp);
        $total = max(1, (int) ($info['pages'] ?? 0));
        $count = count($marks);
        $stem = substr($tmp, 0, -4);

        foreach ($marks as $i => $mark) {
            $article = (string) $mark['article'];
            if ($article !== '' && !in_array($article, $result['articles'], true)) {
                $result['articles'][] = $article;
            }

            $from = (int) $mark['page'];
            $next = $marks[$i + 1] ?? null;
            $to = $next !== null ? (int) $next['page'] : $total;
            if ($to < $from) {
                $to = $from;
            }

            // 3 точки запаса, чтобы не срезать саму строку «article»
            $headCut = $i > 0 ? max(0.0, (float) $mark['y'] - 3) : null;
            $tailCut = ($next !== null && (int) $next['page'] === $to)
                ? max(0.0, (float) $next['y'] - 3)
                : null;

            $partFile = $stem . '_art' . $i . '.pdf';
            if (!pdf_assemble_pages($tmp, $from, $to, $headCut, $tailCut, $geometry, $partFile)) {
                continue;
            }
            $result['parts'][] = [
                'suffix'  => article_suffix($article),
                'article' => $article,
                'content' => (string) file_get_contents($partFile),
            ];
            @unlink($partFile);
        }
    } finally {
        @unlink($tmp);
    }

    return $result;
}

/**
 * Разложить htm по изделиям: блок начинается со строки «article».
 *
 * @return array{articles:string[], parts:array<int, array{suffix:string, article:string, content:string}>}
 */
function htm_split_articles(string $content): array
{
    $result = ['articles' => [], 'parts' => []];
    if ($content === '') {
        return $result;
    }

    if (!preg_match_all('#<tr[^>]*>(.*?)</tr>#is', $content, $rows, PREG_OFFSET_CAPTURE)) {
        return $result;
    }

    $marks = [];
    $prevIsPos = false;
    foreach ($rows[1] as $row) {
        if (!preg_match_all('#<td[^>]*>(.*?)(?=<td|</tr>|$)#is', $row[0], $cells)) {
            continue;
        }
        $vals = [];
        foreach ($cells[1] as $cell) {
            $v = html_entity_decode((string) preg_replace('#<[^>]+>#', ' ', $cell), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $v = trim((string) preg_replace('/\s+/u', ' ', $v));
            if ($v !== '') {
                $vals[] = $v;
            }
        }
        if ($vals === []) {
            continue;
        }

        // строка-маркер «pos 1» идёт перед строкой «article» и относится
        // к следующему изделию, поэтому блок начинается с неё
        if (preg_match('/^pos\s+\d+$/i', $vals[0])) {
            $prevIsPos = true;
            continue;
        }

        if (count($vals) >= 2 && mb_strtolower($vals[0], 'UTF-8') === 'article') {
            // По умолчанию блок начинается со своей строки article,
            // то есть с открывающего тега этой строки.
            $rowStart = (int) $row[1];
            $start = $rowStart;
            $back = strrpos(substr($content, 0, $rowStart), '<tr');
            if ($back !== false) {
                $start = $back;
            }

            // Строка-маркер «pos 2» относится к следующему изделию и набрана
            // жирным в своей обёртке: <span ...><b>pos   2</b>... Блок
            // начинается с неё — и на этом граница заканчивается, больше
            // назад отступать нельзя, иначе в кусок попадёт последняя
            // строка предыдущего изделия.
            $headFrom = max(0, $rowStart - 900);
            $head = substr($content, $headFrom, min(900, $rowStart));
            if (preg_match_all('#<b>\s*pos\s+\d+\s*</b>#i', $head, $pm, PREG_OFFSET_CAPTURE)) {
                $last = end($pm[0]);
                $span = strrpos(substr($head, 0, (int) $last[1]), '<span');
                if ($span !== false) {
                    $start = $headFrom + $span;
                }
            }
            $marks[] = ['article' => $vals[1], 'start' => $start];
        }
        $prevIsPos = false;
    }
    if (count($marks) < 2) {
        return $result;
    }

    $styleLink = '';
    if (preg_match('#<LINK\b[^>]*>#i', $content, $sm)) {
        $styleLink = $sm[0];
    }
    $orderLine = '';
    if (preg_match('#<span[^>]*>\s*<b>\s*Order:.*?</span>#is', $content, $om)) {
        $orderLine = $om[0];
    }

    $total = strlen($content);
    $count = count($marks);

    foreach ($marks as $i => $mark) {
        $article = (string) $mark['article'];
        if ($article !== '' && !in_array($article, $result['articles'], true)) {
            $result['articles'][] = $article;
        }

        $end = ($i + 1 < $count) ? (int) $marks[$i + 1]['start'] : $total;
        // start уже посчитан при разборе строк: либо обёртка вокруг
        // «pos N», либо тег строки article. Второй раз отступать нельзя.
        $start = (int) $mark['start'];
        $fragment = trim(substr($content, $start, $end - $start));
        if ($fragment === '') {
            continue;
        }

        $result['parts'][] = [
            'suffix'  => article_suffix($article),
            'article' => $article,
            'content' => '<html><head><meta charset="utf-8">'
                . '<title>' . htmlspecialchars($article, ENT_QUOTES, 'UTF-8') . '</title>'
                . $styleLink
                . '</head><body text="#000000" bgColor="#ffffff" leftMargin="6" topMargin="0">'
                . $orderLine
                . '<span style="display:block; margin:15px">' . $fragment . '</span>'
                . '</body></html>',
        ];
    }

    return $result;
}

/* ------------------------------------------------------------------ */
/* Разбор PDF по позициям                                              */
/* ------------------------------------------------------------------ */

/** Есть ли на сервере инструменты для работы с PDF. */
function pdf_tools_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = false;
        if (function_exists('shell_exec')) {
            // проверяем каждый инструмент отдельно: command -v с несколькими
            // аргументами в dash сообщает только о первом
            $pdftotext = trim((string) @shell_exec('command -v pdftotext 2>/dev/null'));
            $qpdf = trim((string) @shell_exec('command -v qpdf 2>/dev/null'));
            $ok = $pdftotext !== '' && $qpdf !== '';
        }
    }
    return $ok;
}

/**
 * Разобрать PDF: сколько в нём страниц и какие позиции на них приходятся.
 *
 * В паспорте заказа каждая позиция начинается со строки «Pos. N.0»,
 * под которой стоит «Article <артикул>». Позиция может занимать
 * несколько страниц — тогда продолжение идёт без новой пометки.
 *
 * @return array{pages:int, positions:array<int, array{pos:string, article:string, page:int}>}
 */
function pdf_outline(string $path): array
{
    $empty = ['pages' => 0, 'positions' => []];
    if (!pdf_tools_available() || !is_file($path)) {
        return $empty;
    }
    $text = (string) @shell_exec('pdftotext -layout ' . escapeshellarg($path) . ' - 2>/dev/null');
    if (trim($text) === '') {
        return $empty;
    }

    $pages = explode("\f", $text);
    // последний кусок после завершающего перевода страницы пустой
    if ($pages !== [] && trim((string) end($pages)) === '') {
        array_pop($pages);
    }

    $positions = [];
    foreach ($pages as $i => $page) {
        if (!preg_match('/Pos\.\s*(\d+(?:\.\d+)?)/u', $page, $m)) {
            continue;
        }
        $article = '';
        if (preg_match('/Article\s+([A-Za-z0-9][A-Za-z0-9._\/-]*)/u', $page, $a)) {
            $article = auma_normalize_article($a[1]);
        }
        $positions[] = [
            'pos'     => $m[1],
            'article' => $article,
            'page'    => $i + 1,
        ];
    }

    return ['pages' => count($pages), 'positions' => $positions];
}

/** Вырезать страницы from..to в отдельный файл. */
function pdf_extract_pages(string $src, int $from, int $to, string $dst): bool
{
    if (!pdf_tools_available()) {
        return false;
    }
    $range = $from === $to ? (string) $from : $from . '-' . $to;
    @shell_exec(
        'qpdf --empty --pages ' . escapeshellarg($src) . ' ' . escapeshellarg($range)
        . ' -- ' . escapeshellarg($dst) . ' 2>/dev/null'
    );
    return is_file($dst) && filesize($dst) > 0;
}

/**
 * Где именно на страницах стоят пометки «Pos.».
 *
 * Нужно, чтобы отрезать от страницы чужие данные: таблица позиции
 * часто переходит на следующую страницу, и на одной странице
 * оказывается хвост одной позиции и начало другой.
 * Координаты — в точках от верхнего края страницы.
 *
 * @return array{pages:array<int, array{w:float, h:float}>, marks:array<int, array{page:int, y:float}>}
 */
function pdf_page_markers(string $path): array
{
    $out = ['pages' => [], 'marks' => []];
    if (!pdf_tools_available() || !is_file($path)) {
        return $out;
    }
    $xml = (string) @shell_exec('pdftotext -bbox ' . escapeshellarg($path) . ' - 2>/dev/null');
    if (trim($xml) === '') {
        return $out;
    }
    if (!preg_match_all('#<page width="([\d.]+)" height="([\d.]+)">(.*?)</page>#s', $xml, $pages, PREG_SET_ORDER)) {
        return $out;
    }

    foreach ($pages as $i => $page) {
        $no = $i + 1;
        $out['pages'][$no] = ['w' => (float) $page[1], 'h' => (float) $page[2]];
        if (preg_match_all('#<word[^>]*yMin="([\d.]+)"[^>]*>\s*Pos\.?\s*</word>#', $page[3], $m)) {
            foreach ($m[1] as $y) {
                $out['marks'][] = ['page' => $no, 'y' => (float) $y];
            }
        }
    }
    return $out;
}

/** Умеет ли ghostscript сдвигать содержимое страницы. */
function pdf_can_shift(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = function_exists('shell_exec')
            && trim((string) @shell_exec('command -v gs 2>/dev/null')) !== '';
    }
    return $ok;
}

/** Поднять содержимое страницы на $dy точек — к верхнему краю листа. */
function pdf_lift(string $src, float $dy, string $dst): bool
{
    if (!pdf_can_shift() || !is_file($src)) {
        return false;
    }
    @shell_exec(
        'gs -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite -sOutputFile='
        . escapeshellarg($dst)
        . ' -c "<</PageOffset [0 ' . round($dy, 2) . ']>> setpagedevice"'
        . ' -f ' . escapeshellarg($src) . ' 2>/dev/null'
    );
    return is_file($dst) && filesize($dst) > 0;
}

/**
 * Собрать файл одной позиции из страниц from..to.
 *
 * $headCut — с какой точки сверху начинать содержимое (отрезает данные
 * предыдущей позиции), $tailCut — где заканчивать (отрезает данные
 * следующей). null означает «не резать».
 *
 * Лист остаётся своего размера: содержимое за пределами области убирает
 * pdftocairo, но сам лист не уменьшает. Он же прижимает оставшееся
 * к нижнему краю, поэтому затем содержимое поднимается ghostscript'ом —
 * иначе середина или низ страницы остались бы пустыми.
 */
function pdf_assemble_pages(
    string $src,
    int $from,
    int $to,
    ?float $headCut,
    ?float $tailCut,
    array $geometry,
    string $dst
): bool {
    if (!pdf_tools_available()) {
        return false;
    }

    $parts = [];
    $seq = 0;
    for ($page = $from; $page <= $to; $page++) {
        $size = $geometry['pages'][$page] ?? null;
        if ($size === null) {
            foreach ($parts as $f) {
                @unlink($f);
            }
            return false;
        }

        $top = ($page === $from && $headCut !== null) ? max(0.0, $headCut) : 0.0;
        $bottom = ($page === $to && $tailCut !== null) ? min($size['h'], $tailCut) : $size['h'];
        $height = $bottom - $top;

        $out = sys_get_temp_dir() . '/auma_pg_' . getmypid() . '_' . (++$seq) . '.pdf';

        if ($top <= 0.5 && $height >= $size['h'] - 0.5) {
            // страница нужна целиком
            @shell_exec(
                'qpdf --empty --pages ' . escapeshellarg($src) . ' ' . $page
                . ' -- ' . escapeshellarg($out) . ' 2>/dev/null'
            );
        } else {
            if ($height < 5) {
                $height = 5;   // защита от вырожденного диапазона
            }
            $shift = $size['h'] - $height;
            $canLift = $shift > 0.5 && pdf_can_shift();
            $cropped = $out . '.crop.pdf';

            // -r 72: одна точка PDF равна одному пикселю, координаты совпадают.
            // С -nocenter область прижимается к низу листа — её потом поднимаем.
            @shell_exec(
                'pdftocairo -pdf -r 72' . ($canLift ? ' -nocenter' : '')
                . ' -x 0 -y ' . (int) round($top)
                . ' -W ' . (int) round($size['w'])
                . ' -H ' . (int) round($height)
                . ' -f ' . $page . ' -l ' . $page . ' '
                . escapeshellarg($src) . ' ' . escapeshellarg($cropped) . ' 2>/dev/null'
            );

            if ($canLift && pdf_lift($cropped, $shift, $out)) {
                @unlink($cropped);
            } else {
                @rename($cropped, $out);
            }
        }

        if (!is_file($out) || filesize($out) === 0) {
            foreach ($parts as $f) {
                @unlink($f);
            }
            @unlink($out);
            return false;
        }
        $parts[] = $out;
    }

    $cmd = 'qpdf --empty --pages '
        . implode(' ', array_map('escapeshellarg', $parts))
        . ' -- ' . escapeshellarg($dst) . ' 2>/dev/null';
    @shell_exec($cmd);

    foreach ($parts as $f) {
        @unlink($f);
    }
    return is_file($dst) && filesize($dst) > 0;
}

/**
 * Разложить скачанный PDF по позициям.
 *
 * Если позиций больше одной, возвращает:
 *   articles — все артикулы по порядку страниц (для колонки Article
 *              полного файла, склеиваются через подчёркивание);
 *   parts    — страницы дополнительных позиций, каждая отдельным файлом.
 *
 * Если инструментов нет или позиция одна, оба списка пустые и документ
 * сохраняется как раньше, целиком.
 *
 * @return array{articles:string[], parts:array<int, array{pos:string, article:string, content:string}>}
 */
function pdf_split_positions(string $content): array
{
    $result = ['articles' => [], 'parts' => []];
    if (!pdf_tools_available() || $content === '') {
        return $result;
    }

    // В Ubuntu у qpdf есть профиль AppArmor: читать он может не всё.
    // Проверено на живом сервере — файлы из /var/www и временные файлы
    // без расширения .pdf получают отказ, а /tmp/*.pdf читаются.
    // Поэтому работаем только во временном каталоге и с расширением .pdf.
    $base = tempnam(sys_get_temp_dir(), 'auma_tds_');
    if ($base === false) {
        return $result;
    }
    @unlink($base);
    $tmp = $base . '.pdf';

    if (file_put_contents($tmp, $content) === false) {
        return $result;
    }

    try {
        $outline = pdf_outline($tmp);
        $positions = $outline['positions'];

        if (count($positions) < 2) {
            return $result;
        }

        foreach ($positions as $p) {
            if ($p['article'] !== '' && !in_array($p['article'], $result['articles'], true)) {
                $result['articles'][] = $p['article'];
            }
        }

        // Каждая позиция сохраняется отдельным файлом, включая первую.
        //
        // Таблица позиции часто переходит на следующую страницу, поэтому
        // на одной странице оказывается хвост предыдущей позиции и начало
        // следующей. По координатам пометки «Pos.» лишнее отрезается:
        // у первой страницы позиции — всё, что выше её пометки,
        // у последней — всё, что ниже пометки следующей позиции.
        $count = count($positions);
        $stem = substr($tmp, 0, -4);
        $geometry = pdf_page_markers($tmp);
        $marks = $geometry['marks'];

        for ($i = 0; $i < $count; $i++) {
            $mark = $marks[$i] ?? null;
            if ($mark === null) {
                continue;   // без координат пометки резать нечего
            }
            $nextMark = $marks[$i + 1] ?? null;

            $from = $mark['page'];
            $to = $nextMark !== null ? $nextMark['page'] : ($outline['pages'] ?: $from);
            if ($to < $from) {
                $to = $from;
            }

            // 3 точки запаса, чтобы не срезать саму строку пометки
            $headCut = $i > 0 ? max(0.0, $mark['y'] - 3) : null;
            $tailCut = ($nextMark !== null && $nextMark['page'] === $to)
                ? max(0.0, $nextMark['y'] - 3)
                : null;

            $partFile = $stem . '_pos' . $i . '.pdf';
            if (!pdf_assemble_pages($tmp, $from, $to, $headCut, $tailCut, $geometry, $partFile)) {
                continue;
            }
            $result['parts'][] = [
                'pos'     => $positions[$i]['pos'],
                'article' => $positions[$i]['article'],
                'content' => (string) file_get_contents($partFile),
            ];
            @unlink($partFile);
        }
    } finally {
        @unlink($tmp);
    }

    return $result;
}
