
<?php
/* mps_export.php — выгрузка «по блокам» с процентами по Подразделение→Терминал→Смена */

///////////////////////
// НАСТРОЙКИ БАЗЫ ДАННЫХ
///////////////////////
$dbHost = 'localhost';
$dbName = 'YOUR_DB_NAME';
$dbUser = 'YOUR_DB_USER';
$dbPass = 'YOUR_DB_PASS';
$dbCharset = 'utf8mb4';

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}",
        $dbUser, $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    die('DB connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES));
}

///////////////////////
// ПАРАМЕТРЫ ЗАПРОСА
///////////////////////
$period   = isset($_GET['period']) ? trim($_GET['period']) : null;
$dec      = isset($_GET['dec']) ? max(0, (int)$_GET['dec']) : 0;
$download = isset($_GET['download']) && $_GET['download'] == '1';

///////////////////////
// СЛОВАРИ БЛОКОВ
///////////////////////

// Порядок терминалов B,C,D, затем остальные
$TERMINAL_ORDER = ['B'=>1,'C'=>2,'D'=>3];

$NORM = function($s){
    $s = trim((string)$s);
    return preg_replace('/\s+/u',' ',$s);
};

// Канонические списки для ключевых блоков (чтобы колонки всегда были в нужном порядке)
$CANON = [
    'work_attitude' => [
        'Работа нравится, работаю с желанием и высокой готовностью',
        'Работаю добросовестно, но без особого желания',
        'Выполняю только рабочий минимум',
        'К работе отношусь безразлично',
        'Работа не нравится, работаю по необходимости, низкая готовность',
    ],
    'moral_state' => ['низкий','средний','высокий'],
    'cohesion'    => ['низкий','средний','высокий'],
    // массивы подприсутствий
    'satisfaction' => ['удовлетворен','не удовлетворен'],
    'factor'       => ['не испытываю','некоторое','сильное'],
    'violation'    => ['никогда','иногда','часто'],
];

// Хелпер: эвристическое приведение к канону (на случай легких расхождений в строках)
function normalizeToCanon(string $val, array $canon): string {
    $v = mb_strtolower(trim($val));
    foreach ($canon as $c) if (mb_strtolower($c) === $v) return $c;

    // специальные эвристики для work_attitude
    if (in_array('Работа нравится, работаю с желанием и высокой готовностью',$canon,true) &&
        (str_contains($v,'желани') || str_contains($v,'готовност'))) {
        return 'Работа нравится, работаю с желанием и высокой готовностью';
    }
    if (in_array('Работаю добросовестно, но без особого желания',$canon,true) &&
        (str_contains($v,'добросовест'))) {
        return 'Работаю добросовестно, но без особого желания';
    }
    if (in_array('Выполняю только рабочий минимум',$canon,true) && str_contains($v,'минимум')) {
        return 'Выполняю только рабочий минимум';
    }
    if (in_array('К работе отношусь безразлично',$canon,true) && str_contains($v,'безразлич')) {
        return 'К работе отношусь безразлично';
    }
    if (in_array('Работа не нравится, работаю по необходимости, низкая готовность',$canon,true) &&
        (str_contains($v,'не нрав') || str_contains($v,'необходим'))) {
        return 'Работа не нравится, работаю по необходимости, низкая готовность';
    }

    // общие: низкий/средний/высокий
    foreach (['низкий','средний','высокий'] as $lvl) {
        if (in_array($lvl,$canon,true) && str_contains($v,$lvl)) return $lvl;
    }
    // общие: удовлетворен/не удовлетворен
    if (in_array('удовлетворен',$canon,true) && str_contains($v,'удовлетвор')) {
        return (str_contains($v,'не ')?'не ':'') . 'удовлетворен';
    }
    // общие: никогда/иногда/часто
    foreach (['никогда','иногда','часто'] as $f) {
        if (in_array($f,$canon,true) && str_contains($v,$f)) return $f;
    }
    // общие: не испытываю/некоторое/сильное
    if (in_array('не испытываю',$canon,true) && str_contains($v,'не испыты')) return 'не испытываю';
    if (in_array('некоторое',$canon,true) && str_contains($v,'некотор')) return 'некоторое';
    if (in_array('сильное',$canon,true) && str_contains($v,'сильн')) return 'сильное';

    // если не распознали — вернуть исходное, чтобы не потерять (потом появится отдельной колонкой)
    return trim($val);
}

// Сумма процентов = 100 (при dec=0 аккуратно подправляем крупнейшую категорию)
function percentify(array $counts, int $dec): array {
    $total = array_sum($counts);
    if ($total <= 0) return array_map(fn()=>0,$counts);

    $pcts = [];
    $sum  = 0.0;
    foreach ($counts as $k=>$v) {
        $p = $v * 100.0 / $total;
        $p = round($p, $dec);
        $pcts[$k] = $p;
        $sum += $p;
    }
    if ($dec === 0) {
        $diff = 100 - (int)$sum;
        if ($diff !== 0 && !empty($pcts)) {
            arsort($counts); // ключ крупнейшей категории
            $topKey = array_key_first($counts);
            $pcts[$topKey] += $diff;
        }
    }
    return $pcts;
}

// Считываем сгруппированные счета по одному полю
function loadGrouped(PDO $pdo, string $field, ?string $period): array {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
        throw new RuntimeException('Illegal field name');
    }
    $sql = "SELECT department, terminal, shift, {$field} AS val, COUNT(*) AS cnt
            FROM mps_responses " . ($period ? "WHERE period_name = :period " : "") . "
            GROUP BY department, terminal, shift, val
            ORDER BY department, terminal, CAST(shift AS UNSIGNED)";
    $st  = $pdo->prepare($sql);
    if ($period) $st->bindValue(':period', $period);
    $st->execute();

    $data = []; // dep→term→shift→val=count; also store totals
    while ($r = $st->fetch()) {
        $dep  = trim($r['department']);
        $term = trim($r['terminal']);
        $sh   = trim($r['shift']);
        $val  = trim((string)$r['val']);
        $cnt  = (int)$r['cnt'];

        if (!isset($data[$dep])) $data[$dep] = [];
        if (!isset($data[$dep][$term])) $data[$dep][$term] = [];
        if (!isset($data[$dep][$term][$sh])) $data[$dep][$term][$sh] = ['counts'=>[], 'total'=>0];
        if (!isset($data[$dep][$term][$sh]['counts'][$val])) $data[$dep][$term][$sh]['counts'][$val]=0;

        $data[$dep][$term][$sh]['counts'][$val] += $cnt;
        $data[$dep][$term][$sh]['total']        += $cnt;
    }
    return $data;
}

// Переупорядочиваем терминалы B,C,D первыми
function sortTerminals(array &$terms, array $order): void {
    uksort($terms, function($a,$b) use ($order){
        $oa = $order[$a] ?? 999;
        $ob = $order[$b] ?? 999;
        if ($oa === $ob) return strcmp($a,$b);
        return $oa <=> $ob;
    });
}

// Рендер одной таблицы блока (single field)
function renderSingleTable(PDO $pdo, string $title, string $field, ?array $canon, ?string $period, int $dec, array $termOrder): void {
    $raw = loadGrouped($pdo, $field, $period);

    // Список всех увиденных категорий (если канон не задан явно)
    $allCats = [];
    foreach ($raw as $dep=>$terms) foreach ($terms as $term=>$shifts) foreach ($shifts as $sh=>$info) {
        foreach ($info['counts'] as $val=>$n) $allCats[$val]=true;
    }
    $cats = $canon ? $canon : array_values(array_keys($allCats));
    // Приводим сырые ключи к канону (если он есть)
    if ($canon) {
        $normed = [];
        foreach ($raw as $dep=>$terms) {
            foreach ($terms as $term=>$shifts) {
                foreach ($shifts as $sh=>$info) {
                    $newCounts = array_fill_keys($canon, 0);
                    foreach ($info['counts'] as $k=>$v) {
                        $nk = normalizeToCanon($k, $canon);
                        if (!isset($newCounts[$nk])) $newCounts[$nk]=0;
                        $newCounts[$nk] += $v;
                    }
                    $normed[$dep][$term][$sh] = ['counts'=>$newCounts, 'total'=>$info['total']];
                }
            }
        }
        $raw = $normed;
        $cats = $canon;
    }

    echo '<table class="blk"><tr><th colspan="'.(2+count($cats)).'">'.htmlspecialchars($title).'</th></tr>';
    echo '<tr><th class="nowrap">Подразделение</th><th class="nowrap">Терминал / Смена</th>';
    foreach ($cats as $c) echo '<th class="left">'.htmlspecialchars($c).'</th>';
    echo '</tr>';

    if (empty($raw)) {
        echo '<tr><td class="left" colspan="'.(2+count($cats)).'">Нет данных по заданным условиям.</td></tr>';
        echo '</table>';
        return;
    }

    foreach ($raw as $dep=>$terms) {
        echo '<tr><td class="group-head left" colspan="'.(2+count($cats)).'">'.htmlspecialchars($dep).'</td></tr>';
        sortTerminals($terms, $termOrder);

        foreach ($terms as $term=>$shifts) {
            echo '<tr><td class="sub-head left">Терминал '.htmlspecialchars($term).'</td><td class="sub-head left" colspan="'.(1+count($cats)).'"></td></tr>';

            // порядок смен 1..4, затем прочее
            $known = ['1','2','3','4'];
            $order = array_merge($known, array_diff(array_keys($shifts), $known));
            foreach ($order as $sh) {
                if (!isset($shifts[$sh])) continue;
                $counts = $shifts[$sh]['counts'];
                // добьем отсутствующие категории нулями
                foreach ($cats as $c) if (!isset($counts[$c])) $counts[$c]=0;
                $pcts   = percentify($counts, $dec);
                $n      = (int)$shifts[$sh]['total'];
                echo '<tr><td class="left">&nbsp;</td><td class="left">'.htmlspecialchars($sh).' смена'.($n? " (n=$n)":'').'</td>';
                foreach ($cats as $c) {
                    $text = number_format((float)$pcts[$c], $dec, ',', ' ').'%';
                    echo '<td>'.$text.'</td>';
                }
                echo '</tr>';
            }
        }
    }
    echo '</table>';
}

// Рендер набора таблиц для массива колонок prefix_0..N-1
function renderMultiTables(PDO $pdo, string $titlePattern, string $prefix, int $count, array $canon, ?string $period, int $dec, array $termOrder, ?array $questionNames = null): void {
    for ($i=0; $i<$count; $i++) {
        $title = str_replace('{i}', (string)($i+1), $titlePattern);
        if ($questionNames && isset($questionNames[$i])) {
            $title .= ' — ' . $questionNames[$i];
        }
        renderSingleTable($pdo, $title, $prefix.$i, $canon, $period, $dec, $termOrder);
    }
}

///////////////////////
// ОТДАЧА EXCEL ИЛИ HTML
///////////////////////
if ($download) {
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"mps_export_".date('Y-m-d_H-i-s').".xls\"");
    echo "\xEF\xBB\xBF";
}
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>МПС — Выгрузка по блокам</title>
<style>
body { font-family: system-ui,-apple-system,Segoe UI,Roboto,Arial,"Noto Sans","Liberation Sans",sans-serif; font-size:14px; }
h2 { margin: 10px 0 4px; }
p.meta { color:#555; margin: 2px 0 12px; }
table.blk { width:100%; border-collapse:collapse; margin: 16px 0 28px; }
table.blk th, table.blk td { border:1px solid #9db2bd; padding:6px 8px; text-align:center; }
table.blk th { background:#d8e6ee; }
.group-head { background:#cfe6ff; font-weight:700; text-align:left; }
.sub-head  { background:#eaf3ff; font-weight:700; text-align:left; }
.left { text-align:left; }
.nowrap { white-space:nowrap; }
</style>
</head>
<body>

<h2>МПС — свод по блокам</h2>
<p class="meta">
<?php if ($period): ?>
Период: <b><?=htmlspecialchars($period)?></b> ·
<?php endif; ?>
Знаков после запятой: <b><?=$dec?></b>
<?php if (!$download): ?>
 · <a href="?download=1<?= $period ? '&period='.urlencode($period) : '' ?>&dec=<?=$dec?>">Скачать Excel</a>
<?php endif; ?>
</p>

<?php
// 1) Отношение к работе
renderSingleTable(
    $pdo,
    'Готовность к выполнению рабочих задач (отношение к работе)',
    'work_attitude',
    $CANON['work_attitude'],
    $period, $dec, $TERMINAL_ORDER
);

// 2) Моральное состояние
renderSingleTable(
    $pdo,
    'Моральное состояние',
    'moral_state',
    $CANON['moral_state'],
    $period, $dec, $TERMINAL_ORDER
);

// 3) Сплоченность
renderSingleTable(
    $pdo,
    'Сплоченность',
    'cohesion',
    $CANON['cohesion'],
    $period, $dec, $TERMINAL_ORDER
);

// 4) Удовлетворенность: satisfaction_0..6 (у вас 7 полей)
renderMultiTables(
    $pdo,
    'Удовлетворенность — вопрос {i}',
    'satisfaction_',
    7,
    $CANON['satisfaction'],
    $period, $dec, $TERMINAL_ORDER
    // , ['название п.1','название п.2', ...] // можно передать свои подписи вопросов
);

// 5) Факторы: factor_0..4
renderMultiTables(
    $pdo,
    'Факторы — пункт {i}',
    'factor_',
    5,
    $CANON['factor'],
    $period, $dec, $TERMINAL_ORDER
);

// 6) Нарушения: violation_0..6
renderMultiTables(
    $pdo,
    'Нарушения — пункт {i}',
    'violation_',
    7,
    $CANON['violation'],
    $period, $dec, $TERMINAL_ORDER
);

// 7) «Изменение отношения к подразделению» (категории возьмем динамически из БД)
renderSingleTable(
    $pdo,
    'Изменение отношения к подразделению',
    'department_change',
    null, // без канона — вытащит все уникальные значения как есть
    $period, $dec, $TERMINAL_ORDER
);
?>

</body>
</html>