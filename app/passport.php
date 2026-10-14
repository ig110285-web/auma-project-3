<?php
declare(strict_types=1);

/**
 * Паспорт привода.
 *
 * По нажатию «Создать паспорт» берём характеристики из загруженного .htm,
 * считаем значения по правилам из AUMA-motor-rule.xlsx (часть значений
 * добирается из справочника AUMA-motor.xlsx) и подставляем их в шаблон
 * privod-sa-passport.pdf.
 *
 * В шаблоне значения стоят плейсхолдерами вида {{Order}} обычным текстом
 * в потоке страницы. Меняем их прямо там: qpdf переводит файл в
 * редактируемый вид, подстановка идёт по тексту, fix-qdf чинит ссылки.
 * Кириллические плейсхолдеры в шаблоне разбиты на куски разными шрифтами,
 * поэтому поиск идёт по «сухим» латинским частям — см. pdf_placeholder_parts.
 */

/* ------------------------------------------------------------------ */
/* Чтение xlsx                                                         */
/* ------------------------------------------------------------------ */

/**
 * Прочитать лист xlsx: массив строк, каждая — колонка => значение.
 * Читаем сами: на сервере нет ни composer, ни openpyxl, зато у PHP
 * есть расширения zip и dom, чего для xlsx достаточно.
 */
function xlsx_rows(string $path, int $sheet = 0): array
{
    static $cache = [];
    $key = $path . '#' . $sheet;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if (!is_file($path) || !class_exists('ZipArchive')) {
        return $cache[$key] = [];
    }

    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return $cache[$key] = [];
    }

    // общие строки книги
    $shared = [];
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml !== false) {
        $dom = new DOMDocument();
        if (@$dom->loadXML($xml)) {
            $xp = new DOMXPath($dom);
            $xp->registerNamespace('m', $ns);
            foreach ($xp->query('//m:si') as $si) {
                $t = '';
                foreach ($xp->query('.//m:t', $si) as $n) {
                    $t .= $n->textContent;
                }
                $shared[] = $t;
            }
        }
    }

    $xml = $zip->getFromName('xl/worksheets/sheet' . ($sheet + 1) . '.xml');
    $zip->close();
    if ($xml === false) {
        return $cache[$key] = [];
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml)) {
        return $cache[$key] = [];
    }
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('m', $ns);

    $rows = [];
    foreach ($xp->query('//m:sheetData/m:row') as $row) {
        $line = [];
        foreach ($xp->query('m:c', $row) as $c) {
            $ref = $c->getAttribute('r');
            $col = preg_replace('/\d+/', '', $ref);
            $type = $c->getAttribute('t');
            $v = $xp->query('m:v', $c)->item(0);
            if ($v === null) {
                $is = $xp->query('m:is//m:t', $c)->item(0);
                $val = $is ? $is->textContent : '';
            } elseif ($type === 's') {
                $val = $shared[(int) $v->textContent] ?? '';
            } else {
                $val = $v->textContent;
            }
            if ($val !== '') {
                $line[$col] = $val;
            }
        }
        $rows[] = $line;
    }
    return $cache[$key] = $rows;
}

/** Первая строка листа как «колонка => заголовок». */
function xlsx_header(array $rows): array
{
    return $rows[0] ?? [];
}

/* ------------------------------------------------------------------ */
/* Пути к справочным файлам                                            */
/* ------------------------------------------------------------------ */

function passport_dir(): string
{
    return dirname(__DIR__) . '/samples';
}

function passport_template(): string
{
    return passport_dir() . '/privod-sa-passport.pdf';
}

function passport_rule_file(): string
{
    return passport_dir() . '/AUMA-motor-rule.xlsx';
}

function passport_motor_file(): string
{
    return passport_dir() . '/AUMA-motor.xlsx';
}

/* ------------------------------------------------------------------ */
/* Правила и справочник двигателей                                     */
/* ------------------------------------------------------------------ */

/**
 * Правила из AUMA-motor-rule.xlsx.
 *
 * Колонки: плейсхолдер | откуда в .htm | какая колонка (Order code /
 * Description) | правило словами. Правило словами машина разобрать не
 * может, поэтому оно читается для справки, а само извлечение описано
 * в passport_specials() рядом с плейсхолдером.
 *
 * @return array<int, array{ph:string, source:string, column:string, rule:string}>
 */
function passport_rules(): array
{
    static $rules = null;
    if ($rules !== null) {
        return $rules;
    }

    $rules = [];
    foreach (xlsx_rows(passport_rule_file(), 0) as $i => $row) {
        if ($i === 0) {
            continue;   // заголовок
        }
        $ph = trim((string) ($row['A'] ?? ''));
        if ($ph === '' || !str_starts_with($ph, '{{')) {
            continue;
        }
        $rules[] = [
            'ph'     => $ph,
            'source' => trim((string) ($row['B'] ?? '')),
            // «первая колонка из .htm» — это Order code, вторая — Description
            'column' => trim((string) ($row['C'] ?? '')),
            'rule'   => trim(preg_replace('/\s+/u', ' ', (string) ($row['D'] ?? ''))),
        ];
    }
    return $rules;
}

/** Колонка справочника, из которой берётся значение: 'code' или 'desc'. */
function passport_source_column(string $column): string
{
    return stripos($column, 'Description') !== false ? 'desc' : 'code';
}

/**
 * Соответствие «как колонка называется в паспорте» => «как она названа
 * в новом справочнике двигателей». Новый файл назван по-английски:
 * nom.power.kvt вместо «P_N, кВт1» и так далее. Первое совпадение
 * выигрывает, поэтому своё имя колонки в списке идёт первым.
 */
function motor_aliases(): array
{
    return [
        'Тип'                          => ['tip', 'Тип'],
        'Выходная скорость, об/мин'    => ['v.speed'],
        'Макс. крутящий момент, Н·м'   => ['max.nm'],
        'Тип двигателя'                => ['motor'],
        'P_N, кВт'                     => ['nom.power.kvt'],
        'P_N, кВт1'                    => ['nom.power.kvt'],
        'Номинальная скорость, об/мин' => ['speed.rpm'],
        'I_N, A'                       => ['nom.tok.a'],
        'I_max, A'                     => ['max.tok.a'],
        'I_A, A'                       => ['pysk.tok.a'],
        'cos'                          => ['cos'],
        'Пусковой ток авт. выкл., A'   => ['tok.off.a'],
        'Контактор'                    => ['kontaktor'],
        'Тиристор'                     => ['tiristor'],
        'Режим работы1'                => ['operating.mode'],
        'nom speed'                    => ['speed.rpm'],
    ];
}

/**
 * Найти колонку по списку возможных названий (в порядке приоритета).
 * Принимает карту в любую сторону: «колонка => имя» или «имя => колонка».
 */
function motor_column(array $names, array $candidates)
{
    foreach ($candidates as $want) {
        if (isset($names[$want])) {
            return $names[$want];
        }
        $col = array_search($want, $names, true);
        if ($col !== false) {
            return $col;
        }
    }
    return null;
}

/** Справочник двигателей: ключ «мотор-скорость» => строка таблицы. */
function motor_index(): array
{
    static $index = null;
    if ($index !== null) {
        return $index;
    }

    $index = [];
    $rows = xlsx_rows(passport_motor_file(), 0);
    $header = xlsx_header($rows);
    $names = [];
    foreach ($header as $c => $name) {
        $names[$c] = trim((string) $name);
    }

    // Прежний справочник содержал готовую колонку «мотор-скорость»,
    // новый — отдельные motor и v.speed: ключ склеиваем сами, как это
    // делалось при сборке таблиц.
    $colKey = array_search('мотор-скорость', $names, true);
    $colMotor = motor_column($names, ['motor', 'Тип двигателя']);
    $colSpeed = motor_column($names, ['v.speed', 'Выходная скорость, об/мин']);

    foreach (array_slice($rows, 1, null, true) as $row) {
        if ($colKey !== false) {
            $key = trim((string) ($row[$colKey] ?? ''));
        } else {
            $motor = $colMotor === null ? '' : trim((string) ($row[$colMotor] ?? ''));
            $speed = $colSpeed === null ? '' : trim((string) ($row[$colSpeed] ?? ''));
            $key = $motor === '' ? '' : ($speed === '' ? $motor : $motor . '-' . $speed);
        }
        if ($key !== '') {
            $index[$key] = $row;
        }
    }
    return $index;
}

/** Значение колонки справочника двигателей по её названию. */
function motor_value(?array $row, string $columnName): string
{
    static $map = null;
    static $alias = null;
    if ($row === null) {
        return '';
    }
    if ($map === null) {
        $map = [];
        foreach (xlsx_header(xlsx_rows(passport_motor_file(), 0)) as $c => $name) {
            $map[trim((string) $name)] = $c;
        }
    }
    if ($alias === null) {
        $alias = [];
        foreach (motor_aliases() as $want => $candidates) {
            $col = motor_column($map, $candidates);
            if ($col !== null) {
                $alias[$want] = $col;
            }
        }
    }

    $name = trim($columnName);
    $col = $alias[$name] ?? ($map[$name] ?? null);
    if ($col === null) {
        return '';
    }

    // Числа из xlsx приходят то float, то строкой и печатаются с хвостом:
    // 1.1 превращается в 1.1000000000000001. Приводим к короткому виду.
    $value = $row[$col] ?? '';
    if (is_float($value) || (is_string($value) && is_numeric($value))) {
        $value = rtrim(rtrim(number_format((float) $value, 10, '.', ''), '0'), '.');
    }
    return trim((string) $value);
}

/* ------------------------------------------------------------------ */
/* Разбор .htm                                                         */
/* ------------------------------------------------------------------ */

/**
 * Характеристики из .htm: имя строки => ['code' => ..., 'desc' => ...].
 *
 * В документе AUMA две разные раскладки строк:
 *   шапка          — <td>Имя</td><td /> <td>значение</td>
 *   характеристики — <td /> <td>Имя</td><td /> <td>Order code</td><td /> <td>Description</td>
 * Первая ячейка бывает пустой распоркой, поэтому пустые ячейки
 * выбрасываем и смотрим на оставшиеся: две — имя и значение,
 * три — имя, Order code и Description.
 */
function htm_characteristics(string $html): array
{
    $out = [];

    if (!preg_match_all('#<tr[^>]*>(.*?)</tr>#is', $html, $rows)) {
        return $out;
    }

    foreach ($rows[1] as $row) {
        if (!preg_match_all('#<td[^>]*>(.*?)(?=<td|</tr>|$)#is', $row, $cells)) {
            continue;
        }
        $vals = [];
        foreach ($cells[1] as $cell) {
            $cell = preg_replace('#<[^>]+>#', ' ', $cell);
            $cell = html_entity_decode((string) $cell, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cell = trim(preg_replace('/\s+/u', ' ', $cell));
            if ($cell !== '') {
                $vals[] = $cell;
            }
        }

        $n = count($vals);
        if ($n < 2) {
            continue;
        }
        $name = $vals[0];
        if ($name === '' || isset($out[$name])) {
            continue;
        }
        if ($n === 2) {
            // одна колонка со значением — оно же и код, и описание
            $out[$name] = ['code' => $vals[1], 'desc' => $vals[1]];
        } else {
            $out[$name] = ['code' => $vals[1], 'desc' => $vals[2]];
        }
    }
    return $out;
}

/**
 * Серийные номера из документа по маске: MD — привод, MM — двигатель.
 * Ищем по тексту без разметки: строка «Serials:» и сами номера лежат
 * в разных строках таблицы и разделены тегами.
 */
function htm_serial_by_mask(string $plain, string $mask): array
{
    if (!preg_match_all('/[0-9]{4}' . preg_quote($mask, '/') . '[0-9A-Z]+/u', $plain, $m)) {
        return [];
    }
    return array_values(array_unique($m[0]));
}

/* ------------------------------------------------------------------ */
/* Значения плейсхолдеров                                              */
/* ------------------------------------------------------------------ */

/** Число в русской записи: точка становится запятой, лишние нули убираются. */
function ru_number(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (is_numeric($value)) {
        // в xlsx значения приходят как 2.2000000000000002 — округляем
        $s = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        return str_replace('.', ',', $s === '' ? '0' : $s);
    }
    return $value;
}

/** Число, поделённое пополам, в русской записи. */
function ru_half(string $value): string
{
    $n = (float) str_replace(',', '.', trim($value));
    $half = $n / 2;
    $s = rtrim(rtrim(number_format($half, 4, ',', ''), '0'), ',');
    return $s === '' ? '0' : $s;
}

/**
 * Собрать значения всех плейсхолдеров.
 *
 * @param array  $chars  характеристики из .htm
 * @param array  $rules  правила из AUMA-motor-rule.xlsx
 * @param string $order  номер заказа документа
 * @param string $html   исходный .htm (для поиска серийных номеров)
 * @return array<string, string>  имя плейсхолдера => значение
 */
function passport_values(array $chars, array $rules, string $order, string $html = ''): array
{
    // В отчётах другого вида названия строк пишут со строчной буквы
    // («article» вместо «Article»), поэтому добавляем каждой строке
    // псевдоним в нижнем регистре и дальше ищем по нему.
    foreach ($chars as $key => $cell) {
        $low = mb_strtolower((string) $key, 'UTF-8');
        if (!array_key_exists($low, $chars)) {
            $chars[$low] = $cell;
        }
    }

    // ключ для справочника двигателей: код мотора + код скорости
    $motorCode = $chars['Motor']['code'] ?? '';
    $speedCode = $chars['Output speed']['code'] ?? '';
    $motorKey = ($motorCode !== '' && $speedCode !== '')
        ? $motorCode . '-' . $speedCode
        : '';
    $motor = $motorKey !== '' ? (motor_index()[$motorKey] ?? null) : null;

    // напряжение: 380/50/3 -> 380 V 50 Hz 3-ph AC
    $volts = $chars['Mains voltage']['code'] ?? '';
    $voltsDesc = $chars['Mains voltage']['desc'] ?? '';
    $voltFirst = '';
    if (preg_match('/(\d+)/', $volts !== '' ? $volts : $voltsDesc, $m)) {
        $voltFirst = $m[1];
    }
    $freqAfterV = '';
    if (preg_match('/V\s+(\d+)/', $voltsDesc, $m)) {
        $freqAfterV = $m[1];
    }
    $typeOfCurrent = '';
    if (preg_match('/Hz\s+(.+)$/', $voltsDesc, $m)) {
        $typeOfCurrent = trim($m[1]);
    }

    // температура: L-60-40 -> минус 60, плюс 40
    $tempCode = $chars['Temperature version']['code'] ?? '';
    $temps = [];
    if (preg_match_all('/\d+/', $tempCode, $m)) {
        $temps = $m[0];
    }

    // противокоррозионная защита: KS-P1.001
    $corr = $chars['Corrosion protection']['code'] ?? '';
    $corrParts = array_map('trim', explode('-', $corr, 2));

    // обогреватель: берём до последнего « V», пробелы убираем
    $heaterDesc = $chars['Heater']['desc'] ?? '';
    $heater = '';
    if (($pos = strrpos($heaterDesc, ' V')) !== false) {
        $heater = str_replace(' ', '', substr($heaterDesc, 0, $pos + 2));
    }

    // датчик положения: первые три слова описания
    $posDesc = $chars['Position transmitter']['desc'] ?? '';
    $posWords = preg_split('/\s+/u', trim($posDesc)) ?: [];
    $posTransmitter = count($posWords) >= 3
        ? implode(' ', array_slice($posWords, 0, 3))
        : trim($posDesc);

    // моменты
    $close = $chars['Set to CLOSE (Nm)']['code'] ?? ($chars['Set to CLOSE (Nm)']['desc'] ?? '');
    $open = $chars['Set to OPEN (Nm)']['code'] ?? ($chars['Set to OPEN (Nm)']['desc'] ?? '');

    // серийные номера ищем в тексте без разметки
    $plain = html_entity_decode(strip_tags($html !== '' ? $html : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $serP = htm_serial_by_mask($plain, 'MD');
    $serM = htm_serial_by_mask($plain, 'MM');

    // Взрывозащита длинная и не помещается в ячейку. По правилу её часть
    // начиная с «RU» уходит в отдельный плейсхолдер ниже вместе с «RU»;
    // если делить нечего, второй плейсхолдер затирается пустым значением.
    $explosion = trim((string) ($chars['Explosion protection']['desc'] ?? ''));
    $explosionMain = $explosion;
    $explosionRest = '';
    $ruPos = mb_strpos($explosion, 'RU');
    if ($ruPos !== false && $ruPos > 0) {
        $explosionMain = trim(mb_substr($explosion, 0, $ruPos));
        $explosionRest = trim(mb_substr($explosion, $ruPos));
    }

    // скорость: 45 -> 45,0
    $speed = $speedCode;
    if ($speed !== '' && !str_contains($speed, ',') && !str_contains($speed, '.')) {
        $speed .= ',0';
    }

    // значения из справочника двигателей
    $fromMotor = static function (string $column) use ($motor): string {
        $v = motor_value($motor, $column);
        return $v === '' ? '' : ru_number($v);
    };

    // частные вычисления по плейсхолдеру
    $special = [
        '{{Order}}'                        => $order,
        '{{Mains voltage}}'                => $voltsDesc,
        '{{Mains voltage3}}'               => $typeOfCurrent,
        '{{Mains voltage380}}'             => $voltFirst,
        '{{Mains voltage50}}'              => $freqAfterV,
        '{{Terminal plan TPA}}'            => $chars['Terminal plan TPA']['code'] ?? '',
        '{{Valve attachment}}'             => $chars['Valve attachment']['code'] ?? '',
        '{{Output speed}}'                 => $speed,
        '{{Motor}}'                        => $motorCode,
        '{{Enclosure protection}}'         => $chars['Enclosure protection']['code'] ?? '',
        '{{Insulation class}}'             => $chars['Insulation class']['code'] ?? '',
        '{{Corrosion protection}}'         => $corr,
        '{{Colour}}'                       => $chars['Colour']['code'] ?? '',
        '{{Explosion protection}}'         => $explosionMain,
        '{{Explosion protection2}}'        => $explosionRest,
        '{{serial number-p}}'              => $serP[0] ?? '',
        '{{serial number-m}}'              => $serM[0] ?? '',
        '{{Set to CLOSE (Nm)}}'            => $close,
        '{{Set to OPEN (Nm)}}'             => $open,
        '{{(Nm-konc-z)}}'                  => $close !== '' ? ru_half($close) : '',
        '{{(Nm-konc-f)}}'                  => $open !== '' ? ru_half($open) : '',
        '{{T version-}}'                   => $temps[0] ?? '',
        '{{T version+}}'                   => $temps[1] ?? '',
        '{{Corr-protection-KS}}'           => $corrParts[0] ?? '',
        '{{Corr-protection-P}}'            => $corrParts[1] ?? '',
        '{{Heater}}'                       => $heater,
        '{{Pos transmitter}}'              => $posTransmitter,
        // из справочника двигателей. Слева — имя плейсхолдера в шаблоне
        // паспорта, справа — как называется колонка в справочнике
        // (см. motor_aliases).
        '{{Type}}'                 => $fromMotor('Тип'),
        '{{nom.power.kvt}}'        => $fromMotor('P_N, кВт1'),
        '{{Motor-kW}}'             => $fromMotor('P_N, кВт'),
        '{{operating.mode}}'       => $fromMotor('Режим работы1'),
        '{{Type of duty}}'         => $fromMotor('Режим работы1'),
        '{{nom.tok.a}}'            => $fromMotor('I_N, A'),
        '{{pysk.tok.a}}'           => $fromMotor('I_A, A'),
        '{{cos}}'                  => $fromMotor('cos') ?: $fromMotor('cos φ'),
        '{{speed.rpm}}'            => $fromMotor('Номинальная скорость, об/мин'),
        '{{high.v.test.kvt}}'      => $fromMotor('Высоковольтные испытания [kV]'),
        '{{tok.holost.hoda}}'      => $fromMotor('Холостой ток авт. выкл., A'),
        // в шаблоне этот плейсхолдер набран по-русски, а не английским
        // именем колонки — правило его не покрывает, заполняем кодом
        '{{Холостой ток авт. выкл., A}}' => $fromMotor('Холостой ток авт. выкл., A'),
    ];

    // плейсхолдеры, которых нет в списке выше, добираем по правилам:
    // источник — строка .htm, колонка — Order code или Description
    $values = [];
    foreach ($rules as $rule) {
        $ph = $rule['ph'];
        if (array_key_exists($ph, $special)) {
            $values[$ph] = (string) $special[$ph];
            continue;
        }
        $src = rtrim($rule['source'], ':');
        $cell = $chars[$src] ?? null;
        if ($cell === null) {
            $values[$ph] = '';
            continue;
        }
        $values[$ph] = trim((string) ($cell[passport_source_column($rule['column'])] ?? ''));
    }

    // на случай плейсхолдеров, которых нет в файле правил
    foreach ($special as $ph => $v) {
        if (!array_key_exists($ph, $values)) {
            $values[$ph] = (string) $v;
        }
    }

    return $values;
}

/* ------------------------------------------------------------------ */
/* Подстановка в PDF                                                   */
/* ------------------------------------------------------------------ */

/** Экранирование строки для PDF-литерала. */
function pdf_escape(string $text): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

/** Расэкранирование PDF-литерала. */
function pdf_unescape(string $text): string
{
    return preg_replace('/\\\\([()\\\\])/', '$1', $text) ?? $text;
}

/**
 * Найти в потоке все вхождения плейсхолдера.
 *
 * Кириллица в шаблоне набрана другим шрифтом, поэтому место плейсхолдера
 * выглядит как несколько латинских литералов, между которыми стоят глифы.
 * Причём «съедаются» не только сами буквы, но и пробелы внутри них:
 * у «{{Высоковольтные испытания [kV]}}» латиницей набраны только «{{»
 * и « [kV]}}». Поэтому куски ищутся по порядку внутри имени, а не по
 * вырезанным не-ASCII символам.
 *
 * @return array<int, array{parts:array<int, array{start:int, len:int, text:string}>}>
 */
function pdf_find_placeholder(string $stream, string $name): array
{
    if ($name === '') {
        return [];
    }

    // все текстовые литералы потока: ( ... ) с учётом экранирования.
    // В файле есть картинки, и одиночная скобка в бинарных данных иначе
    // «съедала» бы весь последующий текст, поэтому длину литерала
    // ограничиваем: настоящий текстовый литерал короткий.
    $literals = [];
    $len = strlen($stream);
    $maxLiteral = 4096;
    for ($i = 0; $i < $len; $i++) {
        if ($stream[$i] !== '(') {
            continue;
        }
        $depth = 1;
        $j = $i + 1;
        $limit = min($len, $i + $maxLiteral);
        while ($j < $limit && $depth > 0) {
            $ch = $stream[$j];
            if ($ch === '\\') {
                $j += 2;
                continue;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            }
            $j++;
        }
        if ($depth !== 0) {
            continue;   // это не литерал, идём дальше
        }
        $raw = substr($stream, $i + 1, ($j - 1) - ($i + 1));
        $literals[] = [
            'start' => $i + 1,
            'len'   => strlen($raw),
            'text'  => pdf_unescape($raw),
        ];
        $i = $j - 1;
    }

    $found = [];
    $count = count($literals);
    $nameLen = strlen($name);

    for ($i = 0; $i < $count; $i++) {
        // вариант 1: плейсхолдер целиком внутри одного литерала
        $at = strpos($literals[$i]['text'], $name);
        if ($at !== false) {
            $found[] = ['parts' => [[
                'start'  => $literals[$i]['start'],
                'len'    => $literals[$i]['len'],
                'text'   => $literals[$i]['text'],
                'inner'  => $at,
                'target' => $name,
            ]]];
            // внутри одного литерала плейсхолдер может встретиться дважды
            $rest = substr($literals[$i]['text'], $at + $nameLen);
            while (($again = strpos($rest, $name)) !== false) {
                $found[] = ['parts' => [[
                    'start'  => $literals[$i]['start'],
                    'len'    => $literals[$i]['len'],
                    'text'   => $literals[$i]['text'],
                    'inner'  => $at + $nameLen + $again,
                    'target' => $name,
                ]]];
                $rest = substr($rest, $again + $nameLen);
            }
            continue;
        }

        // вариант 2: плейсхолдер разбит на несколько литералов подряд.
        // Куски обязаны идти друг за другом: между ними стоят только глифы
        // кириллицы, а это не литералы. Пропуск внутри имени при этом
        // состоит из кириллицы и пробелов — если в нём встретилась
        // латиница или цифры, значит мы наткнулись на чужой текст.
        if (!str_starts_with($name, $literals[$i]['text'])) {
            continue;
        }
        $pos = strlen($literals[$i]['text']);
        $parts = [$literals[$i]];
        $lastIndex = $i;

        for ($k = $i + 1; $k < $count && $pos < $nameLen; $k++) {
            $text = $literals[$k]['text'];
            if ($text === '') {
                continue;   // пустые литералы пропускаем
            }
            $at = strpos($name, $text, $pos);
            // Пропуск в имени — это символы, набранные глифами. Обычно там
            // кириллица («{{Режим работы1}}»), но «{{Article}}» на обложке
            // паспорта собран по буквам, и между латинскими кусками
            // попадаются отдельные буквы. Поэтому пропуск допускаем, если
            // он короткий или не содержит латиницы с цифрами.
            if ($at === false) {
                $parts = [];
                break;
            }
            $gap = substr($name, $pos, $at - $pos);
            if (mb_strlen($gap, 'UTF-8') > 3 && preg_match('/[A-Za-z0-9]/', $gap)) {
                $parts = [];    // следующий кусок не подошёл — это не наш случай
                break;
            }
            $pos = $at + strlen($text);
            $parts[] = $literals[$k];
            $lastIndex = $k;
        }

        if ($parts !== [] && $pos === $nameLen) {
            $found[] = ['parts' => $parts];
            $i = $lastIndex;
        }
    }

    return $found;
}

/**
 * Шрифты-ресурсы документа: имя ресурса => карта gid => символ.
 *
 * Нужны, когда значение не удаётся записать шрифтом самого плейсхолдера:
 * у подмножества есть только символы, встречающиеся в шаблоне, а цифр
 * в нём может не оказаться. Тогда берём другой шрифт той же страницы.
 *
 * Файл большой (около 2 МБ) и с картинками, поэтому ищем строковыми
 * операциями: регулярные выражения с просмотром всего файла упираются
 * в ограничения PCRE и возвращают пустой результат.
 *
 * @return array<string, array<int, string>>
 */
function pdf_glyph_fonts(string $qdf): array
{
    static $cache = [];
    $key = md5($qdf);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $fonts = [];

    // все объекты, на которые ссылаются как на ToUnicode
    $toUni = [];
    $off = 0;
    while (($p = strpos($qdf, '/ToUnicode', $off)) !== false) {
        $off = $p + 10;
        if (preg_match('#^/ToUnicode\s+(\d+)\s+0\s+R#', substr($qdf, $p, 64), $m)) {
            $toUni[$m[1]] = true;
        }
    }

    foreach (array_keys($toUni) as $obj) {
        $map = pdf_cmap_by_object($qdf, (string) $obj);
        if ($map === []) {
            continue;
        }

        // шрифтовый объект — тот, что ссылается на эту карту
        $off2 = 0;
        while (($p = strpos($qdf, '/ToUnicode ' . $obj . ' 0 R', $off2)) !== false) {
            $off2 = $p + 1;
            $head = substr($qdf, max(0, $p - 400), min(400, $p));
            $at = strrpos($head, ' 0 obj');
            if ($at === false || !preg_match('#(\d+)\s*$#', substr($head, 0, $at), $m2)) {
                continue;
            }
            $fontNum = (string) $m2[1];

            // Identity-H значит, что строка набрана двухбайтовыми номерами
            // глифов; у обычного шрифта это просто коды символов, и туда
            // можно писать текст литералом
            $identity = false;
            $fpos = strpos($qdf, $fontNum . ' 0 obj');
            if ($fpos !== false) {
                $fend = strpos($qdf, 'endobj', $fpos);
                if ($fend !== false) {
                    $identity = str_contains(substr($qdf, $fpos, $fend - $fpos), 'Identity-H');
                }
            }

            // имена ресурсов, которые ссылаются на этот шрифтовой объект
            $off3 = 0;
            while (($q = strpos($qdf, $fontNum . ' 0 R', $off3)) !== false) {
                $off3 = $q + 1;
                $before = substr($qdf, max(0, $q - 40), min(40, $q));
                if (preg_match('#/([A-Za-z0-9]+)\s*$#', $before, $m3)) {
                    $fonts[$m3[1]] = ['map' => $map, 'identity' => $identity];
                }
            }
        }
    }
    return $cache[$key] = $fonts;
}

/** Карта ToUnicode конкретного объекта. */
function pdf_cmap_by_object(string $qdf, string $obj): array
{
    $needle = $obj . ' 0 obj';
    $off = 0;
    while (($p = strpos($qdf, $needle, $off)) !== false) {
        $off = $p + 1;
        // номер объекта должен стоять в начале строки
        if ($p > 0 && $qdf[$p - 1] !== "\n" && $qdf[$p - 1] !== "\r") {
            continue;
        }
        $end = strpos($qdf, 'endobj', $p);
        if ($end === false) {
            return [];
        }
        $body = substr($qdf, $p, $end - $p);
        $s = strpos($body, "stream\n");
        $skip = 7;
        if ($s === false) {
            $s = strpos($body, "stream\r\n");
            $skip = 8;
        }
        if ($s === false) {
            return [];
        }
        $e = strpos($body, 'endstream', $s);
        if ($e === false) {
            return [];
        }
        return pdf_parse_cmap(substr($body, $s + $skip, $e - $s - $skip));
    }
    return [];
}

/**
 * Найти в потоке текстовый объект с плейсхолдером, набранным глифами,
 * и подобрать шрифт, которым можно записать значение.
 *
 * @param array<int, array<int, string>> $maps карты gid => символ
 * @return array<int, array{start:int, len:int, text:string, tfStart?:int, tfLen?:int}>
 */
function pdf_find_glyph_placeholder(string $stream, string $name, array $maps): array
{
    if ($name === '' || $maps === []) {
        return [];
    }
    // в Identity-H на символ приходится 2 байта; считаем именно символы,
    // потому что «φ» в UTF-8 занимает два байта, а глиф один
    $need = mb_strlen($name, 'UTF-8');

    $found = [];
    if (!preg_match_all('#<([0-9A-Fa-f]+)>#', $stream, $all, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    foreach ($all[1] as $hit) {
        $hex = $hit[0];
        if (strlen($hex) !== $need * 4) {
            continue;   // не совпадает по длине — не наш случай
        }
        foreach ($maps as $map) {
            $decoded = '';
            for ($k = 0; $k < strlen($hex); $k += 4) {
                $decoded .= $map[(int) hexdec(substr($hex, $k, 4))] ?? '';
            }
            if ($decoded === $name) {
                $found[] = [
                    'start' => $hit[1],
                    'len'   => strlen($hex),
                    'text'  => $hex,
                    'map'   => $map,
                ];
                break;
            }
        }
    }
    return $found;
}

/**
 * Найти оператор Tf, задающий шрифт перед указанной позицией.
 *
 * @return array{start:int, len:int, name:string}|null
 */
function pdf_font_before(string $stream, int $pos): ?array
{
    $head = substr($stream, 0, $pos);
    if (!preg_match_all('#/([A-Za-z0-9]+)\s+([\d.]+)\s+Tf#', $head, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $last = count($m[0]) - 1;
    return [
        'start' => $m[0][$last][1],
        'len'   => strlen($m[0][$last][0]),
        'name'  => $m[1][$last][0],
        'size'  => $m[2][$last][0],
    ];
}

/** Карты ToUnicode всех шрифтов файла: gid => символ. */
function pdf_tounicode_maps(string $qdf): array
{
    static $cache = [];
    $key = md5($qdf);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $maps = [];
    $off = 0;
    while (($p = strpos($qdf, '/ToUnicode', $off)) !== false) {
        $off = $p + 10;
        if (preg_match('#^/ToUnicode\s+(\d+)\s+0\s+R#', substr($qdf, $p, 64), $m)) {
            $map = pdf_cmap_by_object($qdf, $m[1]);
            if ($map !== []) {
                $maps[] = $map;
            }
        }
    }
    return $cache[$key] = $maps;
}

/** Разобрать CMap: блоки bfchar и bfrange. */
function pdf_parse_cmap(string $cmap): array
{
    $map = [];

    if (preg_match_all('#beginbfchar(.*?)endbfchar#s', $cmap, $blocks)) {
        foreach ($blocks[1] as $block) {
            if (preg_match_all('#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>#', $block, $pairs, PREG_SET_ORDER)) {
                foreach ($pairs as $p) {
                    $map[(int) hexdec($p[1])] = pdf_hex_to_utf8($p[2]);
                }
            }
        }
    }

    if (preg_match_all('#beginbfrange(.*?)endbfrange#s', $cmap, $blocks)) {
        foreach ($blocks[1] as $block) {
            if (preg_match_all(
                '#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>#',
                $block,
                $ranges,
                PREG_SET_ORDER
            )) {
                foreach ($ranges as $r) {
                    $lo = (int) hexdec($r[1]);
                    $hi = (int) hexdec($r[2]);
                    $dst = (int) hexdec($r[3]);
                    for ($g = $lo; $g <= $hi && $g - $lo < 65536; $g++) {
                        $map[$g] = mb_chr($dst + ($g - $lo), 'UTF-8');
                    }
                }
            }
        }
    }
    return $map;
}

/** Шестнадцатеричная строка UTF-16BE в UTF-8. */
function pdf_hex_to_utf8(string $hex): string
{
    if (strlen($hex) % 2 !== 0) {
        $hex = '0' . $hex;
    }
    $bin = @hex2bin($hex);
    if ($bin === false || $bin === '') {
        return '';
    }
    $out = @mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
    return $out === false ? '' : $out;
}

/** Закодировать значение номерами глифов шрифта (обратная карта ToUnicode). */
function pdf_encode_glyphs(string $value, array $map): ?string
{
    $back = [];
    foreach ($map as $gid => $char) {
        if ($char !== '' && !isset($back[$char])) {
            $back[$char] = (int) $gid;
        }
    }
    $hex = '';
    $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($chars as $ch) {
        if (!isset($back[$ch])) {
            return null;   // в этом шрифте символа нет
        }
        $hex .= str_pad(strtoupper(dechex($back[$ch])), 4, '0', STR_PAD_LEFT);
    }
    return $hex;
}

/**
 * Подставить значения в один поток содержимого.
 *
 * @param array<string, string>          $values
 * @param array<string, int>             $found  счётчик найденных плейсхолдеров
 * @param array<int, array<int, string>> $maps   карты ToUnicode шрифтов
 * @param array<string, array<int, string>> $fonts шрифты-ресурсы: имя => карта
 */
function passport_replace_in_stream(
    string $stream,
    array $values,
    array &$found,
    array $maps = [],
    array $fonts = []
): string {
    $edits = [];

    foreach ($values as $name => $value) {
        $name = (string) $name;
        $hits = pdf_find_placeholder($stream, $name);

        if ($hits === []) {
            // плейсхолдер набран глифами — опознаём по карте ToUnicode
            foreach (pdf_find_glyph_placeholder($stream, $name, $maps) as $g) {
                $hex = pdf_encode_glyphs($value, $g['map']);

                if ($hex !== null) {
                    $found[$name] = ($found[$name] ?? 0) + 1;
                    $edits[] = ['start' => $g['start'], 'len' => $g['len'], 'text' => '<' . $hex . '>'];
                    continue;
                }

                // В шрифте плейсхолдера есть только символы самого шаблона,
                // цифр там может не быть. Берём другой шрифт, но обязательно
                // из этого же потока: ссылка на шрифт чужой страницы рвёт
                // вывод, и всё после неё пропадает.
                $tf = pdf_font_before($stream, $g['start']);
                if ($tf === null) {
                    continue;
                }
                $bodyStart = strrpos(substr($stream, 0, $g['start']), 'stream');
                $bodyEnd = strpos($stream, 'endstream', $g['start']);
                $body = ($bodyStart !== false && $bodyEnd !== false && $bodyEnd > $bodyStart)
                    ? substr($stream, $bodyStart, $bodyEnd - $bodyStart)
                    : $stream;

                // сначала пробуем обычные шрифты: в них значение пишется
                // текстом и отобразится при любом наборе глифов
                $candidates = [];
                foreach ($fonts as $fontName => $info) {
                    if ($fontName === $tf['name']
                        || !preg_match('#/' . preg_quote($fontName, '#') . '\s+[\d.]+\s+Tf#', $body)
                    ) {
                        continue;
                    }
                    $candidates[] = [$fontName, $info];
                }
                usort(
                    $candidates,
                    static fn(array $a, array $b): int
                        => (int) !empty($a[1]['identity']) <=> (int) !empty($b[1]['identity'])
                );

                foreach ($candidates as [$fontName, $info]) {
                    if (!empty($info['identity'])) {
                        // двухбайтовые номера глифов: цифр в подмножестве
                        // может и не быть
                        $other = pdf_encode_glyphs($value, $info['map'] ?? []);
                        if ($other === null) {
                            continue;
                        }
                        $text = '<' . $other . '>';
                    } else {
                        // обычный шрифт — пишем значение текстом
                        $text = '(' . pdf_escape($value) . ')';
                    }

                    $found[$name] = ($found[$name] ?? 0) + 1;
                    $edits[] = [
                        'start' => $tf['start'],
                        'len'   => $tf['len'],
                        'text'  => '/' . $fontName . ' ' . $tf['size'] . ' Tf',
                    ];
                    $edits[] = ['start' => $g['start'], 'len' => $g['len'], 'text' => $text];
                    break;
                }
            }
            continue;
        }

        $found[$name] = ($found[$name] ?? 0) + count($hits);

        foreach ($hits as $hit) {
            $allParts = $hit['parts'];
            $parts = $allParts;
            $first = array_shift($parts);

            if (isset($first['inner'])) {
                // плейсхолдер внутри литерала — меняем только его часть
                $text = $first['text'];
                $edits[] = [
                    'start' => $first['start'],
                    'len'   => $first['len'],
                    'text'  => pdf_escape(
                        substr($text, 0, $first['inner'])
                        . $value
                        . substr($text, $first['inner'] + strlen($first['target']))
                    ),
                ];
            } else {
                $edits[] = [
                    'start' => $first['start'],
                    'len'   => $first['len'],
                    'text'  => pdf_escape($value),
                ];
            }

            // остальные куски плейсхолдера — пустые
            foreach ($parts as $p) {
                $edits[] = ['start' => $p['start'], 'len' => $p['len'], 'text' => ''];
            }

            // Глифы кириллицы между кусками тоже убираем: иначе старое
            // слово остаётся и накладывается на подставленное значение.
            // Затираем только вплотную между кусками: если между ними
            // оказался большой промежуток, там лежит чужой текст.
            for ($k = 1; $k < count($allParts); $k++) {
                $from = $allParts[$k - 1]['start'] + $allParts[$k - 1]['len'] + 1;
                $to = $allParts[$k]['start'] - 1;
                if ($to <= $from || $to - $from > 400) {
                    continue;
                }
                $between = substr($stream, $from, $to - $from);
                if (preg_match_all('/<[0-9A-Fa-f]*>/', $between, $mm, PREG_OFFSET_CAPTURE)) {
                    foreach ($mm[0] as $h) {
                        if ($h[0] !== '<>') {
                            $edits[] = [
                                'start' => $from + $h[1],
                                'len'   => strlen($h[0]),
                                'text'  => '<>',
                            ];
                        }
                    }
                }
            }
        }
    }

    if ($edits === []) {
        return $stream;
    }

    // справа налево, чтобы не сбить смещения
    usort($edits, static fn(array $a, array $b): int => $b['start'] <=> $a['start']);
    foreach ($edits as $e) {
        $stream = substr($stream, 0, $e['start']) . $e['text']
            . substr($stream, $e['start'] + $e['len']);
    }
    return $stream;
}

/**
 * Заполнить шаблон значениями.
 *
 * Значение встаёт на место первого латинского куска плейсхолдера,
 * остальные куски (глифы кириллицы) становятся пустыми.
 *
 * @param array<string, string> $values
 */
function passport_fill(string $template, array $values, string $dst): array
{
    $found = [];
    $report = ['placed' => 0, 'hits' => 0, 'missing' => []];

    // карты шрифтов нужны до правки — считаем их по исходному файлу
    $maps = [];
    $fonts = [];

    if (!is_file($template) || !pdf_tools_available()) {
        return $report + ['error' => 'нет шаблона или инструментов'];
    }

    $dir = sys_get_temp_dir();
    $base = $dir . '/auma_pass_' . getmypid() . '_' . bin2hex(random_bytes(4));
    // AppArmor не даёт qpdf читать из /var/www, поэтому работаем в /tmp,
    // а готовый файл переносим на место обычным копированием
    $src = $base . '.src.pdf';
    $qdf = $base . '.qdf.pdf';
    $fixed = $base . '.fixed.pdf';
    $out = $base . '.out.pdf';

    if (!@copy($template, $src)) {
        return $report + ['error' => 'не удалось прочитать шаблон'];
    }

    // шаблон в редактируемый вид: потоки без сжатия, текст читается
    @shell_exec(
        'qpdf --qdf --object-streams=disable ' . escapeshellarg($src)
        . ' ' . escapeshellarg($qdf) . ' 2>/dev/null'
    );
    if (!is_file($qdf)) {
        @unlink($src);
        return $report + ['error' => 'qpdf не смог разобрать шаблон'];
    }

    $stream = (string) file_get_contents($qdf);
    $maps = pdf_tounicode_maps($stream);
    $fonts = pdf_glyph_fonts($stream);

    // Потоки разбираем по одному. В файле есть картинки, и поиск литералов
    // по всему файлу находил бы скобки в бинарных данных и затирал их.
    // Разбиваем строковыми операциями: регулярное выражение по файлу
    // в два мегабайта упирается в ограничения PCRE.
    $assembled = '';
    $pos = 0;
    $len = strlen($stream);
    while ($pos < $len) {
        $s = strpos($stream, 'stream', $pos);
        if ($s === false) {
            $assembled .= substr($stream, $pos);
            break;
        }
        // после слова stream идёт перевод строки
        $bodyStart = $s + 6;
        if (substr($stream, $bodyStart, 2) === "\r\n") {
            $bodyStart += 2;
        } elseif (substr($stream, $bodyStart, 1) === "\n" || substr($stream, $bodyStart, 1) === "\r") {
            $bodyStart += 1;
        } else {
            // это не начало потока — идём дальше
            $assembled .= substr($stream, $pos, $s - $pos + 6);
            $pos = $s + 6;
            continue;
        }

        $end = strpos($stream, 'endstream', $bodyStart);
        if ($end === false) {
            $assembled .= substr($stream, $pos);
            break;
        }

        $assembled .= substr($stream, $pos, $bodyStart - $pos);
        $assembled .= passport_replace_in_stream(
            substr($stream, $bodyStart, $end - $bodyStart),
            $values,
            $found,
            $maps,
            $fonts
        );
        $assembled .= 'endstream';
        $pos = $end + 9;
    }
    $stream = $assembled;

    $report['placed'] = count($found);
    $report['hits'] = array_sum($found);
    foreach ($values as $name => $value) {
        if (!isset($found[$name]) && $value !== '') {
            $report['missing'][] = (string) $name;
        }
    }

    if ($report['placed'] === 0) {
        @unlink($src);
        @unlink($qdf);
        return $report + ['error' => 'в шаблоне не найдено ни одного плейсхолдера'];
    }

    file_put_contents($qdf, $stream);

    // починить перекрёстные ссылки после правки и собрать обычный PDF
    @shell_exec('fix-qdf ' . escapeshellarg($qdf) . ' > ' . escapeshellarg($fixed) . ' 2>/dev/null');
    if (!is_file($fixed) || filesize($fixed) === 0) {
        @unlink($src);
        @unlink($qdf);
        return $report + ['error' => 'fix-qdf не справился'];
    }

    @shell_exec('qpdf ' . escapeshellarg($fixed) . ' ' . escapeshellarg($out) . ' 2>/dev/null');

    @unlink($src);
    @unlink($qdf);
    @unlink($fixed);

    if (!is_file($out) || filesize($out) === 0) {
        return $report + ['error' => 'не удалось собрать PDF'];
    }

    $to = str_starts_with($dst, '/') ? $dst : upload_dir() . '/' . basename($dst);
    if (!@copy($out, $to)) {
        @unlink($out);
        return $report + ['error' => 'не удалось сохранить результат'];
    }
    @unlink($out);
    return $report;
}
