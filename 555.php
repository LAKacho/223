<?php
/* mps_results_exact.php — «Результаты» точь-в-точь как в образце.
   Особенности:
   • Группировка: Подразделение → Терминал (B,C,D первыми) → Смена (1..4).
   • В каждой строке смены — распределение по вариантам ответа, сумма = 100% (нормализация ПО СМЕНЕ).
   • Шапка блока: 1-я строка — заголовок, 2-я строка — перечисление вариантов В ОДНОЙ СКОБЕ (как в листе).
   • В браузере показываются проценты (82%), в Excel — доли (0.8241) со стилем процентов (0%).
*/

/* ===== MySQL ===== */
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

/* ===== Параметры ===== */
$period   = isset($_GET['period']) ? trim($_GET['period']) : null;   // пример: МПС-04.09.2025–22.09.2025
$download = isset($_GET['download']) && $_GET['download']=='1';
$dec_html = 0;       // для показа процентов в браузере
$dec_xls  = 4;       // для долей в Excel

/* ===== Порядок терминалов/смен ===== */
$TERMINAL_ORDER = ['B'=>1,'C'=>2,'D'=>3];     // B,C,D первыми
$SHIFTS_FORCED  = ['1','2','3','4'];         // всегда 1..4

/* ===== Канонические варианты + ТЕКСТЫ ИЗ АНКЕТЫ (под лист «Результаты») ===== */
/* Отношение к работе (подписи как в анкете) */
$CATS['work_attitude'] = [
    'Работа нравится, работаю с желанием и высокой готовностью',
    'Работаю добросовестно, но без особого желания',
    'Выполняю только рабочий минимум',
    'К работе отношусь безразлично',
    'Работа не нравится, работаю по необходимости. Низкая готовность',
];
$TITLE['work_attitude'] = 'Готовность к выполнению рабочих задач (отношение к работе)';

/* Перспективы работы */
$CATS['prospects'] = [
    'Не хочу менять место работы',
    'Перевестись в другое подразделение (укажите какое)',
    'Уволиться со работы',
];
$TITLE['prospects'] = 'Какие перспективы своей работы Вы видите в настоящее время?';

/* Морально-психологическое состояние */
$CATS['moral_state'] = [
    'На высоком уровне',
    'На среднем уровне',
    'На низком уровне',
];
$TITLE['moral_state'] = 'Как Вы оцениваете: Своё морально-психологическое состояние';

/* Сплочённость */
$CATS['cohesion'] = [
    'На высоком уровне',
    'На среднем уровне',
    'На низком уровне',
];
$TITLE['cohesion'] = 'Как Вы оцениваете: Психологическую сплочённость работников Вашего подразделения';

/* Удовлетворенность — 7 подпунктов */
$CATS['satisfaction'] = ['Удовлетворен','Не удовлетворен'];
$SAT_QUEST = [
    'Своими взаимоотношениями с коллегами',
    'Своими взаимоотношениями с начальником смены',
    'Своими взаимоотношениями с начальником подразделения',
    'Содержанием своей профессиональной деятельности (выполняемыми обязанностями)',
    'Организацией трудовой деятельности непосредственным начальником',
    'Распределением нагрузки на каждого работника',
    'Влиянием непосредственного руководителя на формирование благоприятного социально-психологического климата и позитивных традиций в коллективе',
];

/* Факторы влияния — 5 подпунктов */
$CATS['factor'] = ['Оказывает довольно сильное влияние','Оказывает некоторое влияние','Я этого не испытываю'];
$FACTOR_QUEST = [
    'Высокая психологическая напряженность в коллективе',
    'Профессиональная некомпетентность начальника смены',
    'Профессиональная некомпетентность начальника подразделения',
    'Нарушение коллегами трудовой и исполнительской дисциплины',
    'Распространение сплетен и слухов в коллективе, приводящих к возникновению конфликтных ситуаций',
];

/* Ущемления/нарушения — 7 подпунктов */
$CATS['violation'] = ['Никогда','Иногда','Часто'];
$VIOL_QUEST = [
    'Грубость, оскорбления со стороны начальника смены',
    'Грубость, оскорбления со стороны начальника подразделения',
    'Грубость, оскорбления со стороны коллег',
    'Необоснованные наказания',
    'Формальность рабочих отношений, равнодушие к Вам и Вашим проблемам со стороны начальника смены',
    'Формальность рабочих отношений, равнодушие к Вам и Вашим проблемам со стороны начальника подразделения',
    'Несправедливость к Вам при распределении нагрузки',
];

/* Итоговые суждения: коллектив — A/B/C (в базе хранится полный текст) */
$TITLE['collective_statement'] = 'Какое из высказываний в большей степени относится к Вашему коллективу?';

/* Изменение деятельности подразделения */
$CATS['department_change'] = ['Улучшилась','Не изменилась','Ухудшилась'];
$TITLE['department_change'] = 'Как изменилась деятельность подразделения за последний год?';

/* ===== Нормализация значений из БД к канону анкеты ===== */
function normalize_value(string $v, array $cats): string {
    $x = trim($v);
    $lo = mb_strtolower($x);

    // точные совпадения текста анкеты
    foreach ($cats as $c) if (mb_strtolower($c) === $lo) return $c;

    // маппинги из «коротких» значений базы в формулировки анкеты
    // уровни
    if (in_array('На высоком уровне',$cats,true) && (str_contains($lo,'высок') || $lo==='высокий')) return 'На высоком уровне';
    if (in_array('На среднем уровне',$cats,true) && (str_contains($lo,'сред')  || $lo==='средний')) return 'На среднем уровне';
    if (in_array('На низком уровне',$cats,true)  && (str_contains($lo,'низк')  || $lo==='низкий')) return 'На низком уровне';

    // удовлетворенность
    if (in_array('Удовлетворен',$cats,true) && str_contains($lo,'удовлетвор')) {
        return str_contains($lo,'не ')?'Не удовлетворен':'Удовлетворен';
    }

    // факторы
    if (in_array('Оказывает довольно сильное влияние',$cats,true) && (str_contains($lo,'сильн') || str_contains($lo,'довольно'))) return 'Оказывает довольно сильное влияние';
    if (in_array('Оказывает некоторое влияние',$cats,true) && str_contains($lo,'некотор')) return 'Оказывает некоторое влияние';
    if (in_array('Я этого не испытываю',$cats,true) && (str_contains($lo,'не испыты') || str_contains($lo,'не оказыва'))) return 'Я этого не испытываю';

    // нарушения
    foreach (['никогда'=>'Никогда','иногда'=>'Иногда','часто'=>'Часто'] as $k=>$v2)
        if (in_array($v2,$cats,true) && str_contains($lo,$k)) return $v2;

    // отношение к работе — укороченные формулировки
    if (in_array('К работе отношусь безразлично',$cats,true) && str_contains($lo,'безразлич')) return 'К работе отношусь безразлично';
    if (in_array('Выполняю только рабочий минимум',$cats,true) && str_contains($lo,'минимум')) return 'Выполняю только рабочий минимум';
    if (in_array('Работаю добросовестно, но без особого желания',$cats,true) && str_contains($lo,'добросовест')) return 'Работаю добросовестно, но без особого желания';
    if (in_array('Работа не нравится, работаю по необходимости. Низкая готовность',$cats,true) && str_contains($lo,'не нрав')) return 'Работа не нравится, работаю по необходимости. Низкая готовность';
    if (in_array('Работа нравится, работаю с желанием и высокой готовностью',$cats,true) && (str_contains($lo,'желан')||str_contains($lo,'готовност'))) return 'Работа нравится, работаю с желанием и высокой готовностью';

    // перспективы
    if (in_array('Перевестись в другое подразделение (укажите какое)',$cats,true) && str_contains($lo,'перевест')) return 'Перевестись в другое подразделение (укажите какое)';
    if (in_array('Не хочу менять место работы',$cats,true) && str_contains($lo,'не хочу')) return 'Не хочу менять место работы';
    if (in_array('Уволиться со работы',$cats,true) && str_contains($lo,'увол')) return 'Уволиться со работы';

    // изменение деятельности
    foreach (['улучш'=>'Улучшилась','ухудш'=>'Ухудшилась','не измен'=>'Не изменилась'] as $k=>$v2)
        if (in_array($v2,$cats??[],true) && str_contains($lo,$k)) return $v2;

    return $x; // оставим как есть (появится отдельной колонкой)
}

/* ===== Загрузка сгруппированных счетчиков ===== */
function loadGrouped(PDO $pdo, string $field, ?string $period): array {
    if (!preg_match('/^[a-zA-Z0-9_]+$/',$field)) throw new RuntimeException('Illegal field name');
    $sql="SELECT department, terminal, shift, {$field} AS val, COUNT(*) cnt
          FROM mps_responses ".($period?"WHERE period_name=:p ":"")."
          GROUP BY department, terminal, shift, val
          ORDER BY department, terminal, CAST(shift AS UNSIGNED)";
    $st=$pdo->prepare($sql);
    if($period) $st->bindValue(':p',$period);
    $st->execute();

    $data=[];
    while($r=$st->fetch()){
        $dep=trim($r['department']); $term=trim($r['terminal']); $sh=trim($r['shift']);
        $val=(string)$r['val']; $cnt=(int)$r['cnt'];
        $data[$dep][$term][$sh]['counts'][$val]=($data[$dep][$term][$sh]['counts'][$val]??0)+$cnt;
        $data[$dep][$term][$sh]['total']=($data[$dep][$term][$sh]['total']??0)+$cnt;
    }
    return $data;
}

/* ===== Доли по смене (сумма = 1.0000) ===== */
function shares_by_shift(array $counts, array $cats, int $dec_xls): array {
    $total = array_sum($counts);
    $res   = array_fill_keys($cats, 0.0);
    if ($total <= 0) return $res;

    $sum=0.0; $maxKey=$cats[0];
    foreach ($cats as $c) {
        $v = (float)($counts[$c] ?? 0);
        if ($v > ($counts[$maxKey] ?? 0)) $maxKey = $c;
        $res[$c] = round($v/$total, $dec_xls);
        $sum += $res[$c];
    }
    // коррекция до 1.0
    $diff = round(1.0 - $sum, $dec_xls);
    if (abs($diff) > 0 && isset($res[$maxKey])) $res[$maxKey] = round($res[$maxKey]+$diff, $dec_xls);
    return $res;
}

/* ===== Сортировка терминалов (B,C,D → далее по алфавиту) ===== */
function sortTerminals(array &$terms, array $order): void {
    uksort($terms, function($a,$b)use($order){
        $oa=$order[$a]??999; $ob=$order[$b]??999;
        return $oa===$ob ? strcmp($a,$b) : ($oa<=>$ob);
    });
}

/* ===== Рендер одного блока (точно как в листе: шапка + строка с перечнем вариантов + данные по сменам) ===== */
function render_block(PDO $pdo, string $field, string $title, array $cats_display, ?string $period, array $termOrder, array $shiftsForce, int $dec_html, int $dec_xls, bool $download, ?string $questionTail=null) {
    $raw = loadGrouped($pdo, $field, $period);

    // Нормализация значений к текстам анкеты
    $norm=[];
    foreach ($raw as $dep=>$terms){
        foreach ($terms as $term=>$shifts){
            foreach ($shifts as $sh=>$info){
                $new = array_fill_keys($cats_display, 0);
                foreach (($info['counts']??[]) as $k=>$v){
                    $nk = normalize_value((string)$k, $cats_display);
                    if (!isset($new[$nk])) $new[$nk]=0;
                    $new[$nk] += (int)$v;
                }
                $norm[$dep][$term][$sh] = ['counts'=>$new,'total'=>($info['total']??0)];
            }
        }
    }
    $raw = $norm;

    // Глобальные суммы для «Итог»
    $global = [];
    foreach ($shiftsForce as $s) $global[$s] = array_fill_keys($cats_display, 0);

    echo '<table class="blk">';
    // 1) Заголовок блока
    echo '<tr>';
    echo '<th class="left" style="width:200px">Подразделение</th>';
    echo '<th colspan="'.count($cats_display).'" class="left">'.htmlspecialchars($title).($questionTail? ' — '.htmlspecialchars($questionTail):'').'</th>';
    echo '</tr>';

    // 2) Строка с перечнем вариантов (одна ячейка на все варианты — как в листе)
    echo '<tr><td></td><td colspan="'.count($cats_display).'" class="left">'.htmlspecialchars(implode(' / ', $cats_display)).'</td></tr>';

    if (empty($raw)) { echo '<tr><td class="left" colspan="'.(1+count($cats_display)).'">Нет данных.</td></tr></table>'; return; }

    foreach ($raw as $dep=>$terms){
        echo '<tr><td class="group-head left" colspan="'.(1+count($cats_display)).'">'.htmlspecialchars($dep).'</td></tr>';
        sortTerminals($terms,$termOrder);

        foreach ($terms as $term=>$shifts){
            echo '<tr><td class="sub-head left">Терминал '.htmlspecialchars($term).'</td><td class="sub-head left" colspan="'.count($cats_display).'"></td></tr>';

            $order = $shiftsForce;
            $extra = array_diff(array_keys($shifts), $order);
            $order = array_merge($order, $extra);

            foreach ($order as $sh){
                $info   = $shifts[$sh] ?? ['counts'=>array_fill_keys($cats_display,0),'total'=>0];
                $counts = $info['counts'] ?? [];
                foreach ($cats_display as $c) if(!isset($counts[$c])) $counts[$c]=0;

                // Копим для «Итог»
                foreach ($cats_display as $c) $global[$sh][$c] += (int)$counts[$c];

                // Доли по смене (100% на смене)
                $shares = shares_by_shift($counts, $cats_display, $dec_xls);

                echo '<tr>';
                echo '<td class="left">'.htmlspecialchars($sh).' смена</td>';
                foreach ($cats_display as $c){
                    if ($download) {
                        $val = number_format((float)$shares[$c], $dec_xls, '.', '');
                        echo '<td style="mso-number-format:0%">'.$val.'</td>';
                    } else {
                        $pct = round(((float)$shares[$c])*100, $dec_html);
                        echo '<td>'.$pct.'%</td>';
                    }
                }
                echo '</tr>';
            }
        }
    }

    // Итог по всем подразделениям (сумма по смене → доли)
    echo '<tr><td class="group-head left">Итог</td><td class="group-head left" colspan="'.count($cats_display).'"></td></tr>';
    foreach ($shiftsForce as $sh){
        $shares = shares_by_shift($global[$sh], $cats_display, $dec_xls);
        echo '<tr><td class="left">'.htmlspecialchars($sh).' смена</td>';
        foreach ($cats_display as $c){
            if ($download) {
                $val = number_format((float)$shares[$c], $dec_xls, '.', '');
                echo '<td style="mso-number-format:0%">'.$val.'</td>';
            } else {
                $pct = round(((float)$shares[$c])*100, $dec_html);
                echo '<td>'.$pct.'%</td>';
            }
        }
        echo '</tr>';
    }

    echo '</table>';
}

/* ===== Excel/HTML обёртка ===== */
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
/* ПОРЯДОК БЛОКОВ — как в анкете/листе «Результаты»: */

/* 1) Отношение к работе */
render_block($pdo, 'work_attitude', $TITLE['work_attitude'], $CATS['work_attitude'], $period, $TERMINAL_ORDER, $SHIFTS_FORCED, $dec_html, $dec_xls, $download);

/* 2) Перспективы работы */
render_block($pdo, 'prospects', $TITLE['prospects'], $CATS['prospects'], $period, $TERMINAL_ORDER, $SHIFTS_FORCED, $dec_html, $dec_xls, $download);

/* 3) Морально-психологическое состояние */
render_block($pdo, 'moral_state', $TITLE['moral_state'], $CATS['moral_state'], $period, $TERMINAL_ORDER, $SHIFTS_FORCED, $dec_html, $dec_xls, $download);

/* 4) Сплочённость */
render_block($pdo, 'cohesion', $TITLE['cohesion'], $CATS['cohesion'], $period, $TERMINAL_ORDER, $SHIFTS_FORCED, $dec_html, $dec_xls, $download);

/* 5) Удовлетворенность — 7 подпунктов satisfaction_0..6 */
for($i=0;$i<7;$i++){
    $q = $SAT_QUEST[$i] ?? ('пункт '.($i+1));
    render_block($pdo, 'satisfaction_'.$i, 'Удовлетворенность — '.$q, $CATS['satisfaction'], $period, $TERMINAL_ORDER, $SHIFTS_FORCED, $dec_html, $dec_xls, $download);
}

/* 6) Факторы влияния — 5 подпунктов factor_0..4 */
for($i=0;$i<5;$i++){
    $q = $FACTOR_QUEST[$i] ?? ('пункт '.($i+1));
    render_block($pdo, 'factor_'.$i, 'Оцените степень влияния — '.$q, $CATS['factor'], $period, $TERMINAL_ORDER, $SHIFTS_FORCED, $dec_html, $dec_xls, $download);
}

/* 7) Ущемления/нарушения — 7 подпунктов violation_0..6 */
for($i=0;$i<7;$i++){
    $q = $VIOL_QUEST[$i] ?? ('пункт '.($i+1));
    render_block($pdo, 'violation_'.$i, 'Приходилось ли сталкиваться — '.$q, $CATS['violation'], $period, $TERMINAL_ORDER, $SHIFTS_FORCED, $dec_html, $dec_xls, $download);
}

/* 8) Итоговые суждения — коллектив (A/B/C) — колонок как таковых нет (свободный текст), поэтому здесь считаем распределение по 3 типовым формулировкам анкеты */
$COLLECTIVE_CATS = [
    'Коллектив сплоченный. Преобладает доброжелательность в отношениях, поддержка, взаимное уважение. Справедливое отношение ко всем членам коллектива. Климат в целом «благоприятный»',
    'Отношения носят формально-деловой характер, возможны противоречия между членами коллектива и безразличие к проблемам друг друга. Не исключаются конфликтные ситуации, которые чаще разрешаются конструктивными методами. Климат в целом «средне благоприятный»',
    'Коллектив скорее разобщен, между работниками часто возникают неприязненные отношения. Критические замечания работников в адрес друг друга носят характер явных или скрытых личных выпадов. Климат в целом «неблагоприятный»',
];
render_block($pdo, 'collective_statement', $TITLE['collective_statement'], $COLLECTIVE_CATS, $period, $TERMINAL_ORDER, $SHIFTS_FORCED, $dec_html, $dec_xls, $download);

/* 9) Изменение деятельности подразделения */
render_block($pdo, 'department_change', $TITLE['department_change'], $CATS['department_change'], $period, $TERMINAL_ORDER, $SHIFTS_FORCED, $dec_html, $dec_xls, $download);
?>

</body>
</html>