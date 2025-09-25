<?php
/* mps_export.php — Свод «Результаты» по блокам, проценты по Подразделение→Терминал→Смена */

/* ====== НАСТРОЙКИ БД ====== */
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
    die('DB connection failed: ' . htmlspecialchars($e->getMessage()));
}

/* ====== ПАРАМЕТРЫ ====== */
$period   = isset($_GET['period']) ? trim($_GET['period']) : null;
$dec      = isset($_GET['dec']) ? max(0, (int)$_GET['dec']) : 0;
$download = isset($_GET['download']) && $_GET['download'] == '1';

/* ====== СЛОВАРИ/КАНОНЫ ====== */
$TERMINAL_ORDER = ['B'=>1,'C'=>2,'D'=>3]; // B,C,D первыми

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
    'satisfaction'=> ['удовлетворен','не удовлетворен'],
    'factor'      => ['сильное','некоторое','не оказывает'], // заголовок блока ниже подменим текстом
    'violation'   => ['никогда','иногда','часто'],
];

/* подписи подпунктов (как в вашем файле на фото/листе) */
$FACTOR_TITLES = [
    'Высокая психологическая напряженность в коллективе',
    'Профессиональная некомпетентность начальника смены',
    'Профессиональная некомпетентность начальника подразделения',
    'Нарушение коллегами трудовой и исполнительской дисциплины',
    'Распространение сплетен и слухов в коллективе, приводящих к возникновению конфликтных ситуаций',
];
$SAT_TITLES = null;     // при желании подставьте массив из 7 названий вопросов удовлетворенности
$VIOL_TITLES = null;    // при желании подставьте массив из 7 названий пунктов нарушений

/* ====== УТИЛИТЫ ====== */
function normalizeToCanon(string $val, array $canon): string {
    $v = mb_strtolower(trim($val));
    foreach ($canon as $c) if (mb_strtolower($c) === $v) return $c;

    // эвристики
    if (in_array('удовлетворен',$canon,true) && str_contains($v,'удовлетвор')) {
        return str_contains($v,'не ') ? 'не удовлетворен' : 'удовлетворен';
    }
    if (in_array('никогда',$canon,true) && str_contains($v,'никог')) return 'никогда';
    if (in_array('иногда',$canon,true) && str_contains($v,'иногда')) return 'иногда';
    if (in_array('часто',$canon,true) && str_contains($v,'част')) return 'часто';
    if (in_array('низкий',$canon,true) && str_contains($v,'низк')) return 'низкий';
    if (in_array('средний',$canon,true) && str_contains($v,'сред')) return 'средний';
    if (in_array('высокий',$canon,true) && str_contains($v,'высок')) return 'высокий';

    // work_attitude
    if (in_array('Работа нравится, работаю с желанием и высокой готовностью',$canon,true) && (str_contains($v,'желан')||str_contains($v,'готовност'))) return 'Работа нравится, работаю с желанием и высокой готовностью';
    if (in_array('Работаю добросовестно, но без особого желания',$canon,true) && str_contains($v,'добросовест')) return 'Работаю добросовестно, но без особого желания';
    if (in_array('Выполняю только рабочий минимум',$canon,true) && str_contains($v,'минимум')) return 'Выполняю только рабочий минимум';
    if (in_array('К работе отношусь безразлично',$canon,true) && str_contains($v,'безразлич')) return 'К работе отношусь безразлично';
    if (in_array('Работа не нравится, работаю по необходимости, низкая готовность',$canon,true) && (str_contains($v,'не нрав')||str_contains($v,'необход'))) return 'Работа не нравится, работаю по необходимости, низкая готовность';

    // факторы (варианты влияния)
    if (in_array('сильное',$canon,true) && (str_contains($v,'сильн')||str_contains($v,'довольно силь'))) return 'сильное';
    if (in_array('некоторое',$canon,true) && str_contains($v,'некотор')) return 'некоторое';
    if (in_array('не оказывает',$canon,true) && str_contains($v,'не оказыва')) return 'не оказывает';

    return trim($val); // оставить как есть (будет отдельной колонкой)
}
function percentify(array $counts, int $dec): array {
    $total = array_sum($counts);
    if ($total <= 0) return array_map(fn()=>0,$counts);
    $pcts = []; $sum=0;
    foreach ($counts as $k=>$v) { $p=round($v*100/$total,$dec); $pcts[$k]=$p; $sum+=$p; }
    if ($dec===0) { $diff=100-(int)$sum; if ($diff!=0){ arsort($counts); $top=array_key_first($counts); $pcts[$top]+=$diff; } }
    return $pcts;
}
function sortTerminals(array &$terms, array $order): void {
    uksort($terms,function($a,$b)use($order){$oa=$order[$a]??999;$ob=$order[$b]??999;return $oa===$ob?strcmp($a,$b):($oa<=>$ob);});
}

/* ====== ЧТЕНИЕ ГРУППИРОВАННЫХ ДАННЫХ ПО ПОЛЮ ====== */
function loadGrouped(PDO $pdo, string $field, ?string $period): array {
    if (!preg_match('/^[a-zA-Z0-9_]+$/',$field)) throw new RuntimeException('Illegal field name');
    $sql="SELECT department, terminal, shift, {$field} AS val, COUNT(*) cnt
          FROM mps_responses ".($period?"WHERE period_name=:p ":"").
         "GROUP BY department, terminal, shift, val
          ORDER BY department, terminal, CAST(shift AS UNSIGNED)";
    $st=$pdo->prepare($sql);
    if($period)$st->bindValue(':p',$period);
    $st->execute();

    $data=[];
    while($r=$st->fetch()){
        $dep=trim($r['department']); $term=trim($r['terminal']); $sh=trim($r['shift']);
        $val=trim((string)$r['val']);  $cnt=(int)$r['cnt'];
        $data[$dep][$term][$sh]['counts'][$val]=($data[$dep][$term][$sh]['counts'][$val]??0)+$cnt;
        $data[$dep][$term][$sh]['total']=($data[$dep][$term][$sh]['total']??0)+$cnt;
    }
    return $data;
}

/* ====== ПЕЧАТЬ ОДНОЙ ТАБЛИЦЫ БЛОКА ====== */
function renderSingleTable(PDO $pdo, string $title, string $field, ?array $canon, ?string $period, int $dec, array $termOrder, bool $forceShifts1to4=true): void {
    $raw = loadGrouped($pdo, $field, $period);

    // собрать список всех категорий, если канон не задан
    $cats = $canon ?? [];
    if (!$canon) {
        $seen = [];
        foreach ($raw as $dep=>$terms) foreach ($terms as $term=>$shifts) foreach ($shifts as $sh=>$info) {
            foreach ($info['counts'] as $val=>$_) $seen[$val]=true;
        }
        $cats = array_values(array_keys($seen));
    } else {
        // привести ключи к канону
        $normed=[];
        foreach ($raw as $dep=>$terms){
            foreach ($terms as $term=>$shifts){
                foreach ($shifts as $sh=>$info){
                    $new=array_fill_keys($canon,0);
                    foreach ($info['counts'] as $k=>$v){
                        $nk=normalizeToCanon($k,$canon);
                        if(!isset($new[$nk]))$new[$nk]=0;
                        $new[$nk]+=$v;
                    }
                    $normed[$dep][$term][$sh]=['counts'=>$new,'total'=>$info['total']];
                }
            }
        }
        if ($normed) $raw=$normed;
        $cats=$canon;
    }

    echo '<table class="blk"><tr><th colspan="'.(2+count($cats)).'">'.htmlspecialchars($title).'</th></tr>';
    echo '<tr><th class="nowrap">Подразделение</th><th class="nowrap">Терминал / Смена</th>';
    foreach ($cats as $c) echo '<th class="left">'.htmlspecialchars($c).'</th>';
    echo '</tr>';

    if (empty($raw)) { echo '<tr><td class="left" colspan="'.(2+count($cats)).'">Нет данных.</td></tr></table>'; return; }

    foreach ($raw as $dep=>$terms){
        echo '<tr><td class="group-head left" colspan="'.(2+count($cats)).'">'.htmlspecialchars($dep).'</td></tr>';
        sortTerminals($terms,$termOrder);

        foreach ($terms as $term=>$shifts){
            echo '<tr><td class="sub-head left">Терминал '.htmlspecialchars($term).'</td><td class="sub-head left" colspan="'.(1+count($cats)).'"></td></tr>';

            // гарантируем строки смен 1..4
            $order = ['1','2','3','4'];
            if(!$forceShifts1to4){
                $order = array_merge($order, array_diff(array_keys($shifts), $order));
            } else {
                // добавим фактические «лишние» смены (если вдруг есть) после 1–4
                $extra = array_diff(array_keys($shifts), $order);
                $order = array_merge($order, $extra);
            }

            foreach ($order as $sh){
                $info = $shifts[$sh] ?? ['counts'=>[], 'total'=>0];
                $counts = $info['counts'];
                foreach ($cats as $c) if(!isset($counts[$c])) $counts[$c]=0;
                $pcts = percentify($counts,$dec);
                $n = (int)($info['total']??0);

                echo '<tr><td class="left">&nbsp;</td><td class="left">'.htmlspecialchars($sh).' смена'.($n? " (n=$n)":'').'</td>';
                foreach ($cats as $c){
                    $text = number_format((float)$pcts[$c], $dec, ',', ' ').'%';
                    echo '<td>'.$text.'</td>';
                }
                echo '</tr>';
            }
        }
    }
    echo '</table>';
}

/* ====== ПЕЧАТЬ НАБОРА ТАБЛИЦ ПО ПРЕФИКСАМ ====== */
function renderMultiTables(PDO $pdo, string $titlePattern, string $prefix, int $count, array $canon, ?string $period, int $dec, array $termOrder, ?array $names=null): void {
    for($i=0;$i<$count;$i++){
        $title = str_replace('{i}', (string)($i+1), $titlePattern);
        if($names && isset($names[$i])) $title .= ' — '.$names[$i];
        renderSingleTable($pdo, $title, $prefix.$i, $canon, $period, $dec, $termOrder);
    }
}

/* ====== ВЫХОД: EXCEL ИЛИ HTML ====== */
if ($download) {
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"mps_results_".date('Y-m-d_H-i-s').".xls\"");
    echo "\xEF\xBB\xBF";
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>МПС — Результаты (по блокам)</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,"Noto Sans","Liberation Sans",sans-serif;font-size:14px}
h2{margin:10px 0 6px}
p.meta{color:#555;margin:2px 0 12px}
table.blk{width:100%;border-collapse:collapse;margin:16px 0 28px}
table.blk th,table.blk td{border:1px solid #9db2bd;padding:6px 8px;text-align:center}
table.blk th{background:#d8e6ee}
.group-head{background:#cfe6ff;font-weight:700;text-align:left}
.sub-head{background:#eaf3ff;font-weight:700;text-align:left}
.left{text-align:left}.nowrap{white-space:nowrap}
</style>
</head>
<body>
<h2>МПС — свод «Результаты»</h2>
<p class="meta">
<?php if($period): ?>Период: <b><?=htmlspecialchars($period)?></b> · <?php endif; ?>
Знаков после запятой: <b><?=$dec?></b>
<?php if(!$download): ?> · <a href="?download=1<?= $period ? '&period='.urlencode($period):'' ?>&dec=<?=$dec?>">Скачать Excel</a><?php endif; ?>
</p>

<?php
/* 1) Отношение к работе */
renderSingleTable($pdo,'Готовность к выполнению рабочих задач (отношение к работе)','work_attitude',$CANON['work_attitude'],$period,$dec,$TERMINAL_ORDER);

/* 2) Морально-психологическое состояние */
renderSingleTable($pdo,'Морально-психологическое состояние','moral_state',$CANON['moral_state'],$period,$dec,$TERMINAL_ORDER);

/* 3) Сплоченность */
renderSingleTable($pdo,'Сплоченность','cohesion',$CANON['cohesion'],$period,$dec,$TERMINAL_ORDER);

/* 4) Удовлетворенность — 7 подпунктов */
renderMultiTables($pdo,'Удовлетворенность — пункт {i}','satisfaction_',7,$CANON['satisfaction'],$period,$dec,$TERMINAL_ORDER,$SAT_TITLES);

/* 5) Факторы — 5 подпунктов (с названиями как в образце) */
renderMultiTables($pdo,'Факторы — пункт {i}','factor_',5,$CANON['factor'],$period,$dec,$TERMINAL_ORDER,$FACTOR_TITLES);

/* 6) Нарушения — 7 подпунктов */
renderMultiTables($pdo,'Нарушения — пункт {i}','violation_',7,$CANON['violation'],$period,$dec,$TERMINAL_ORDER,$VIOL_TITLES);
?>

</body></html>