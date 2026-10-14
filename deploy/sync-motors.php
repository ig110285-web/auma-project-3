<?php
define('BASE_DIR', '/var/www/auma');
require '/var/www/auma/app/config.php';
require '/var/www/auma/app/helpers.php';
require '/var/www/auma/app/db.php';
require '/var/www/auma/app/passport.php';

// 1. таблица справочника двигателей
db_exec('CREATE TABLE IF NOT EXISTS motors (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    moto_speed  VARCHAR(80) NOT NULL,
    tip         VARCHAR(48)  NULL,
    v_speed     VARCHAR(24)  NULL,
    max_nm      VARCHAR(24)  NULL,
    motor       VARCHAR(48)  NULL,
    nom_power   VARCHAR(24)  NULL,
    speed_rpm   VARCHAR(24)  NULL,
    nom_tok     VARCHAR(24)  NULL,
    max_tok     VARCHAR(24)  NULL,
    pysk_tok    VARCHAR(24)  NULL,
    cos         VARCHAR(24)  NULL,
    tok_off     VARCHAR(24)  NULL,
    kontaktor   VARCHAR(24)  NULL,
    tiristor    VARCHAR(24)  NULL,
    oper_mode   VARCHAR(48)  NULL,
    voltage     VARCHAR(24)  NULL,
    tok_holost  VARCHAR(32)  NULL,
    high_v_test VARCHAR(32)  NULL,
    UNIQUE KEY uk_moto_speed (moto_speed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

// 2. служебная таблица: что и когда перелито
db_exec('CREATE TABLE IF NOT EXISTS sync_meta (
    name     VARCHAR(64) PRIMARY KEY,
    checksum CHAR(32) NULL,
    row_count INT NULL,
    synced   DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

echo "таблицы созданы\n";

// 3. перелив справочника из xlsx
$file = passport_motor_file();
echo 'файл: ' . $file . ' (' . filesize($file) . " байт)\n";
$sum = md5_file($file);

$rows = xlsx_rows($file, 0);
$hdr = xlsx_header($rows);
$map = [];
foreach ($hdr as $c => $n) {
    $map[trim((string) $n)] = $c;
}
$pick = static function (array $row, array $map, array $names): string {
    foreach ($names as $n) {
        if (isset($map[$n])) {
            return trim((string) ($row[$map[$n]] ?? ''));
        }
    }
    return '';
};

$data = [];
foreach (array_slice($rows, 1, null, true) as $row) {
    $key = $pick($row, $map, ['moto.speed']);
    if ($key === '') {
        continue;
    }
    $data[$key] = [
        $key,
        $pick($row, $map, ['tip']),
        $pick($row, $map, ['v.speed']),
        $pick($row, $map, ['max.nm']),
        $pick($row, $map, ['motor']),
        $pick($row, $map, ['nom.power.kvt']),
        $pick($row, $map, ['speed.rpm']),
        $pick($row, $map, ['nom.tok.a']),
        $pick($row, $map, ['max.tok.a']),
        $pick($row, $map, ['pysk.tok.a']),
        $pick($row, $map, ['cos']),
        $pick($row, $map, ['tok.off.a']),
        $pick($row, $map, ['kontaktor']),
        $pick($row, $map, ['tiristor']),
        $pick($row, $map, ['operating.mode']),
        $pick($row, $map, ['voltage']),
        $pick($row, $map, ['tok.holost.hoda']),
        $pick($row, $map, ['high.v.test.kvt']),
    ];
}
echo 'строк к переливу: ' . count($data) . "\n";

if (count($data) < 100) {
    echo "строк подозрительно мало — перелив отменён, старая копия не тронута\n";
    exit(1);
}

$pdo = db();
$pdo->beginTransaction();
db_exec('DELETE FROM motors');
$sql = 'INSERT INTO motors (moto_speed, tip, v_speed, max_nm, motor, nom_power,
        speed_rpm, nom_tok, max_tok, pysk_tok, cos, tok_off, kontaktor, tiristor,
        oper_mode, voltage, tok_holost, high_v_test)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
$st = $pdo->prepare($sql);
foreach ($data as $r) {
    $st->execute($r);
}
$pdo->commit();

db_exec('INSERT INTO sync_meta (name, checksum, row_count, synced) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), row_count = VALUES(row_count),
         synced = VALUES(synced)', ['motors', $sum, count($data), now()]);

$n = db_one('SELECT COUNT(*) AS n FROM motors');
echo 'в таблице motors: ' . $n['n'] . " строк\n";
$probe = db_one('SELECT * FROM motors WHERE moto_speed = ?', ['VD00063-4-0,10-48']);
if ($probe) {
    echo "проверка VD00063-4-0,10-48: nom_power={$probe['nom_power']} nom_tok={$probe['nom_tok']} cos={$probe['cos']}\n";
}
