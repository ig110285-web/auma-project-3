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

/**
 * Характеристики: получить файл по номеру заказа.
 *
 * @param string $order  номер заказа, например 23080383
 * @param string $format htm | pdf
 * @return array{filename:string, content:string, mime:string, url:string}
 */
function auma_fetch_characteristics(string $order, string $format = 'htm'): array
{
    $format = in_array($format, ['htm', 'pdf'], true) ? $format : 'htm';
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
        return [
            'filename' => basename(parse_url($link, PHP_URL_PATH) ?: ('TDS' . $order . '.' . $format)),
            'content'  => $r3['body'],
            'mime'     => $format === 'pdf' ? 'application/pdf' : 'text/html; charset=utf-8',
            'url'      => $link,
        ];
    } finally {
        @unlink($jar);
    }
}

/**
 * Схема подключения по номеру заказа.
 *
 * @return array{filename:string, content:string, mime:string, url:string, article:string}
 */
function auma_fetch_scheme(string $order): array
{
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
        return [
            'filename' => rawurldecode(basename(parse_url($pdfUrl, PHP_URL_PATH) ?: 'scheme.pdf')),
            'content'  => $r4['body'],
            'mime'     => 'application/pdf',
            'url'      => $pdfUrl,
            'article'  => $article,
        ];
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

        // первая позиция остаётся в полном файле, остальные — отдельно
        if ($i === 0 || $fragment === '') {
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

        // первая позиция остаётся в полном файле, остальные вырезаем
        $count = count($positions);
        $stem = substr($tmp, 0, -4);
        for ($i = 1; $i < $count; $i++) {
            $from = $positions[$i]['page'];
            $to = ($i + 1 < $count) ? $positions[$i + 1]['page'] - 1 : $outline['pages'];
            if ($to < $from) {
                $to = $from;
            }
            $partFile = $stem . '_pos' . $i . '.pdf';
            if (!pdf_extract_pages($tmp, $from, $to, $partFile)) {
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
