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

/**
 * Артикул изделия. В разметке AUMA он разбит тегами и пробелами:
 * "SQEX 07.2", "SQEX-07.2", "SQEX07.2" — приводим к виду SQEX-07.2.
 */
function auma_extract_article(string $html): string
{
    $text = auma_text($html);
    if (preg_match('/\b(S[QA]EX?)\s*[-–—]?\s*(\d+(?:[.,]\d+)?)\b/ui', $text, $m)) {
        return strtoupper($m[1]) . '-' . str_replace(',', '.', $m[2]);
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
