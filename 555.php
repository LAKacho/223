<?php
/* mps_results.php — «Результаты» как в образце. 100% — по СМЕНЕ, а не по вопросу. */

/* ==== MySQL ==== */
$dbHost = 'localhost';
$dbName = 'YOUR_DB_NAME';
$dbUser = 'YOUR_DB_USER';
$dbPass = 'YOUR_DB_PASS';
$dbCharset = 'utf8mb4';

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    exit('DB connection failed: '.htmlspecialchars($e->getMessage(), ENT_QUOTES));
}

/* ==== Параметры ==== */
$period   = isset($_GET['period']) ? trim($_GET['period']) : null;
$download = isset($_GET['download']) && $_GET['download']=='1';   // Excel (HTML-as-Excel)
$dec_html = 0;          // для показа в браузере (82%)
$dec_xls  = 4;          // для числа-доли в Excel (0.8241)

/* ==== Порядок и словари ==== */
$TERMINAL_ORDER = ['B'=>1,'C'=>2,'D'=>3]; // сначала B,C,D
$SHIFTS_FORCED  = ['1','2','3','4'];      // всегда показываем 1..4

$CANON = [
    'work_attitude' => [
        'Работа нравится, работаю с желанием и высокой готовностью',
        'Работаю добросовестно, но без особого желания',
        'Выполняю только рабочий минимум',
        'К работе отношусь безразлично',
        'Работа не нравится, работаю по необходимости, низкая готовность',
    ],
    'moral_state'   => ['низкий','средний','высокий'],
    'cohesion'      => ['низкий','средний','высокий'],
    'satisfaction'  => ['удовлетворен','не удовлетворен'],
    'factor'        => ['не испытываю','некоторое','сильное'],
    'violation'     => ['никогда','иногда','часто'],
];

/* подписи подпунктов (по образцу) */
$FACTOR_TITLES = [
    'Высокая психологическая напряженность в коллективе',
    'Профессиональная некомпетентность начальника смены',
    'Профессиональная некомпетентность начальника подразделения',
    'Нарушение коллегами трудовой и исполнительской дисциплины',
    'Распространение сплетен и слухов в коллективе, приводящих к возникновению конфликтных ситуаций',
];

/* ==== Утилиты ==== */
function normalizeToCanon(string $val, array $canon): string {
    $v = mb_strtolower(trim($val));
    foreach ($canon as $c) if (mb_strtolower($c) === $v) return $c;

    // мягкие эвристики
    if (in_array('удовлетворен',$canon,true) && str_contains($v,'удовлетвор')) {
        return str_contains($v,'не ')?'не удовлетворен':'удовлетворен';
    }
    foreach (['никогда','иногда','часто','низкий','средний','высокий','не испытываю','некоторое','сильное'] as $k) {
        if (in_array($k,$canon,true) && str_contains($v,$k)) return $k;
    }
    if (in_array('Выполняю только рабочий минимум',$canon,true) && str_contains($v,'минимум')) return 'Выполняю только рабочий минимум';
    if (in_array('К работе отношусь безразлично',$canon,true) && str_contains($v,'безразлич')) return 'К работе отношусь безразлично';
    if (in_array('Работаю добросовестно, но без особого желания',$canon,true) && str_contains($v,'добросовест')) return 'Работаю добросовестно, но без особого желания';
    if (in_array('Работа не нравится, работаю по необходимости, низкая готовность',$canon,true) && (str_contains($v,'не нрав')||str_contains($v,'необход'))) return 'Работа не нравится, работаю по необходимости, низкая готовность';
    if (in_array('Работа нравится, работаю с желанием и высокой готовностью',$canon,true) && (str_contains($v,'желан')||str_contains($v,'готовност'))) return 'Работа нравится, работаю с желанием и высокой готовностью';

    return trim($val);
}

function sortTerminals(array &$terms, array $order): void {
    uksort($terms, function($a,$b)use($order){
        $oa=$order[$a]??999; $ob=$order[$b]??999;
        return $oa===$ob ? strcmp($a,$b) : ($oa<=>$ob);
    });
}

/* Возвращает ДОЛИ (0..1), нормированные ПО СМЕНЕ, сумма ровно 1.0000 (100%) */
function shares_by_shift(array $counts, array $cats, int $dec_xls): array {
    $total = array_sum($counts);
    $res   = array_fill_keys($cats, 0.0);
    if ($total <= 0) return $res;

    // первичное округление
    $sum = 0.0;
    $maxKey = $cats[0];
    foreach ($cats as $c) {
        $v = isset($counts[$c]) ? (float)$counts[$c] : 0.0;
        if ($v > ($counts[$maxKey] ?? 0)) $maxKey = $c;
        $res[$c] = round($v/$total, $dec_xls);
        $sum += $res[$c];
    }
    // коррекция до 1.0
    $diff = round(1.0 - $sum, $dec_xls);
    if (abs($diff) > 0 && isset($res[$maxKey])) $res[$maxKey] = round($res[$maxKey] + $diff, $dec_xls);
    return $res;
}

function loadGrouped(PDO $pdo, string $field, ?string $period): array {
    if (!preg_match('/^[a-zA-Z0-9_]+$/',$field)) throw new RuntimeException('Illegal field name');
    $sql = "SELECT department, terminal, shift, {$field} AS val, COUNT(*) cnt
            FROM mps_responses ".($period?"WHERE period_name=:p ":"")."
            GROUP BY department, terminal, shift, val
            ORDER BY department, terminal, CAST(shift AS UNSIGNED)";
    $st = $pdo->prepare($sql);
    if ($period) $st->bindValue(':p',$period);
    $st->execute();

    $data=[];
    while($r=$st->fetch()){
        $dep=trim($r['department']); $term=trim($r['terminal']); $sh=trim($r['shift']);
        $val=trim((string)$r['val']);  $cnt=(int)$r['cnt'];
        if(!isset($data[$dep][$term][$sh]['counts'])) $data[$dep][$term][$sh]['counts']=[];
        $data[$dep][$term][$sh]['counts'][$val]=($data[$dep][$term][$sh]['counts'][$val]??0)+$cnt;
        $data[$dep][$term][$sh]['total']=($data[$dep][$term][$sh]['total']??0)+$cnt;
    }
    return $data;
}

/* Рендер одной таблицы блока (точная шапка как в образце: строка с названием блока + строка со списком вариантов) */
function render_block(PDO $pdo, string $block_title, string $field, array $canon, ?string $period, array $termOrder, array $shiftsForce, int $dec_html, int $dec_xls, bool $download) {
    $raw = loadGrouped($pdo, $field, $period);

    // нормализуем категории к канону
    $norm=[];
    foreach ($raw as $dep=>$terms){
        foreach ($terms as $term=>$shifts){
            foreach ($shifts as $sh=>$info){
                $new = array_fill_keys($canon, 0);
                foreach ($info['counts'] as $k=>$v){
                    $nk=normalizeToCanon($k,$canon);
                    if(!isset($new[$nk])) $new[$nk]=0;
                    $new[$nk]+=$v;
                }
                $norm[$dep][$term][$sh] = ['counts'=>$new,'total'=>$info['total']];
            }
        }
    }
    $raw = $norm;

    echo '<table class="blk">';
    // 1) строка с названием блока
    echo '<tr>';
    echo '<th class="left" style="width:180px">Подразделение</th>';
    echo '<th colspan="'.count($canon).'" class="left">'.htmlspecialchars($block_title).'</th>';
    echo '</tr>';

    // 2) строка со списком вариантов (в одной ячейке как в образце)
    echo '<tr>';
    echo '<td></td>';
    echo '<td colspan="'.count($canon).'" class="left">'.htmlspecialchars(implode(" / ", $canon)).'</td>';
    echo '</tr>';

    if (empty($raw)) {
        echo '<tr><td class="left" colspan="'.(1+count($canon)).'">Нет данных.</td></tr></table>';
        return;
    }

    foreach ($raw as $dep=>$terms){
        // заголовок подразделения
        echo '<tr><td class="group-head left" colspan="'.(1+count($canon)).'">'.htmlspecialchars($dep).'</td></tr>';

        sortTerminals($terms, $termOrder);
        foreach ($terms as $term=>$shifts){
            // подпояснение Терминала
            echo '<tr><td class="sub-head left">Терминал '.htmlspecialchars($term).'</td><td class="sub-head left" colspan="'.count($canon).'"></td></tr>';

            // 1..4 смена + возможные лишние
            $order = $shiftsForce;
            $extra = array_diff(array_keys($shifts), $order);
            $order = array_merge($order, $extra);

            foreach ($order as $sh){
                $info   = $shifts[$sh] ?? ['counts'=>array_fill_keys($canon,0),'total'=>0];
                $counts = $info['counts'] ?? [];
                foreach ($canon as $c) if(!isset($counts[$c])) $counts[$c]=0;

                // доли (0..1), нормированные ПО СМЕНЕ (100% на смене)
                $shares = shares_by_shift($counts, $canon, $dec_xls);

                echo '<tr>';
                echo '<td class="left">'.htmlspecialchars($sh).' смена</td>';

                foreach ($canon as $c){
                    if ($download) {
                        // Excel: отдаем долю, а Excel покажет % → mso-number-format:0%
                        $val = number_format((float)$shares[$c], $dec_xls, '.', '');
                        echo '<td style="mso-number-format:0%">'.$val.'</td>';
                    } else {
                        // Браузер: покажем «82%»
                        $pct = round(((float)$shares[$c])*100, $dec_html);
                        echo '<td>'.$pct.'%</td>';
                    }
                }
                echo '</tr>';
            }
        }
    }

    echo '</table>';
}

/* ====== Выход: Excel/HTML ====== */
if ($download) {
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"mps_results_".date('Y-m-d_H-i-s').".xls\"");
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>МПС — Результаты</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,"Noto Sans","Liberation Sans",sans-serif;font-size:14px}
table.blk{width:100%;border-collapse:collapse;margin:18px 0 28px}
table.blk th,table.blk td{border:1px solid #9db2bd;padding:6px 8px;text-align:center;vertical-align:middle}
table.blk th{background:#d8e6ee}
.group-head{background:#cfe6ff;font-weight:700}
.sub-head{background:#eaf3ff;font-weight:700}
.left{text-align:left}
</style>
</head>
<body>

<?php
// 1) Готовность к выполнению рабочих задач (отношение к работе)
render_block($pdo,
    'Готовность к выполнению рабочих задач (отношение к работе)',
    'work_attitude', $CANON['work_attitude'],
    $period, $TERMINAL_ORDER, $SHIFTS_FORCED, $dec_html, $dec_xls, $download);

// 2) Морально-психологическое состояние
render_block($pdo,
    'Морально-психологическое состояние',
    'moral_state', $CANON['moral_state'],
    $period, $TERMINAL_ORDER, $SHIFTS_FORCED, $dec_html, $dec_xls, $download);

// 3) Сплоченность
render_block($pdo,
    'Сплоченность',
    'cohesion', $CANON['cohesion'],
    $period, $TERMINAL_ORDER, $SHIFTS_FORCED, $dec_html, $dec_xls, $download);

// 4) Удовлетворенность — 7 подпунктов satisfaction_0..6
for($i=0;$i<7;$i++){
    render_block($pdo,
        'Удовлетворенность — пункт '.($i+1),
        'satisfaction_'.$i, $CANON['satisfaction'],
        $period, $TERMINAL_ORDER, $SHIFTS_FORCED, $dec_html, $dec_xls, $download);
}

// 5) Факторы — 5 подпунктов factor_0..4
for($i=0;$i<5;$i++){
    $title = 'Факторы — пункт '.($i+1);
    if (isset($FACTOR_TITLES[$i])) $title .= ' — '.$FACTOR_TITLES[$i];
    render_block($pdo,
        $title,
        'factor_'.$i, $CANON['factor'],
        $period, $TERMINAL_ORDER, $SHIFTS_FORCED, $dec_html, $dec_xls, $download);
}

// 6) Нарушения — 7 подпунктов violation_0..6
for($i=0;$i<7;$i++){
    render_block($pdo,
        'Нарушения — пункт '.($i+1),
        'violation_'.$i, $CANON['violation'],
        $period, $TERMINAL_ORDER, $SHIFTS_FORCED, $dec_html, $dec_xls, $download);
}
?>

</body>
</html>