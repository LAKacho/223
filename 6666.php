<?php
require 'config.php';

$procedureId = isset($_GET['procedure_id']) ? (int)$_GET['procedure_id'] : 0;
if ($procedureId <= 0) { http_response_code(400); exit('Нужно передать ?procedure_id='); }
$decimals = isset($_GET['dec']) ? max(0,(int)$_GET['dec']) : 2;

$st = $pdo->prepare("SELECT id,title,start_date FROM evaluation_procedures WHERE id=?");
$st->execute([$procedureId]);
$procedure = $st->fetch() ?: exit('Процедура не найдена');
$year = $procedure['start_date'] ? (new DateTime($procedure['start_date']))->format('Y') : date('Y');

$st = $pdo->prepare("
  SELECT et.id AS target_id, u.fio
  FROM evaluation_targets et
  JOIN users u ON u.id = et.user_id
  WHERE et.procedure_id = ?
  ORDER BY u.fio
");
$st->execute([$procedureId]);
$targets = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$targets) exit('В процедуре нет участников.');

$st = $pdo->prepare("
  SELECT c.id, c.name
  FROM procedure_combinations pc
  JOIN combinations c ON c.id = pc.combination_id
  WHERE pc.procedure_id = ?
  ORDER BY c.name
");
$st->execute([$procedureId]);
$combs = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$combs) exit('К процедуре не привязаны компетенции.');

$combQuestions = [];  // [cid] => [['id'=>qid,'text'=>...], ...]
$allQids = [];
if ($combs) {
  $in = implode(',', array_fill(0, count($combs), '?'));
  $combIds = array_map(fn($r)=>(int)$r['id'], $combs);

  // связь из question_combination
  $q = $pdo->prepare("
    SELECT qc.combination_id, qc.question_id, q.text
    FROM question_combination qc
    JOIN questions q ON q.id = qc.question_id
    WHERE qc.combination_id IN ($in)
    ORDER BY qc.combination_id, qc.question_id
  ");
  $q->execute($combIds);
  foreach ($q as $row) {
    $cid = (int)$row['combination_id'];
    $qid = (int)$row['question_id'];
    $combQuestions[$cid][] = ['id'=>$qid,'text'=>$row['text']];
    $allQids[$qid] = true;
  }
}
if (!$combQuestions) exit('У компетенций нет вопросов.');

$roles = ['manager','colleague','subordinate','self'];
$roleLabelFull = ['manager'=>'Руководитель','colleague'=>'Коллеги','subordinate'=>'Подчинённые','self'=>'Сам'];
$baseW = ['manager'=>0.334,'colleague'=>0.333,'subordinate'=>0.333]; // self не учитывается

// Подготовим кеш по каждому оцениваемому: средние руководителя по ВОПРОСАМ + средние ролей по КОМПЕТЕНЦИЯМ
function avgByQuery(PDO $pdo, int $targetId, array $qids, array $rolesFilter): array {
  if (!$qids) return [];
  $ph = implode(',', array_fill(0, count($qids), '?'));
  $inRoles = "'".implode("','",$rolesFilter)."'";

  $sql = "
    SELECT a.question_id, ep.role, AVG(CASE WHEN a.score>=0 THEN a.score END) AS avg_score
    FROM answers a
    JOIN evaluation_participants ep ON ep.id=a.participant_id
    WHERE ep.target_id = ?
      AND ep.role IN ($inRoles)
      AND a.question_id IN ($ph)
    GROUP BY a.question_id, ep.role
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute(array_merge([$targetId], $qids));
  $out = [];
  foreach ($stmt as $r) {
    $qid = (int)$r['question_id'];
    $role = $r['role'];
    $out[$qid][$role] = ($r['avg_score'] !== null) ? (float)$r['avg_score'] : null;
  }
  return $out; // [qid][role] => avg
}

// Общий взвешенный итог по всем компетенциям
function weightedTotal(array $perCombWeighted, array $combWeights): ?float {
  $sumW=0.0; $sumV=0.0;
  foreach ($perCombWeighted as $cid=>$val) {
    $w = $combWeights[$cid] ?? null;
    if ($w!==null && $val!==null) { $sumW += $w; $sumV += $w*$val; }
  }
  return ($sumW>0) ? $sumV/$sumW : null;
}

// Веса компетенций (пример: 4×12.5% и 2×25%; подстройте если у вас другая схема)
$normalize = fn(string $s)=>mb_strtolower(preg_replace('/\s+/u',' ',trim($s)),'UTF-8');
$mgrNames  = array_map($normalize, [
  'Построение систем управления','Формирование команды','Принятие решений','Ответственность',
  'Ответственность за результат','Планирование и контроль','Влияние и убеждение','Стремление к развитию','Клиентоориентированность'
]);
$prof1Names = array_map($normalize, ['Профессиональные знания замещаемой должности','Профессиональные знания ключевой должности']);
$prof2Names = array_map($normalize, ['Профессиональные знания должностей, смежных к текущей']);

$combWeights = [];
$mgrUnit  = 0.5/4.0;  // если у вас 4 управленческих; для 6×8.33% поменяйте на 0.5/6.0
$profEach = 0.25;
foreach ($combs as $c) {
  $cid = (int)$c['id'];
  $name = $normalize($c['name']);
  if (in_array($name,$mgrNames,true))      $combWeights[$cid] = $mgrUnit;
  elseif (in_array($name,$prof1Names,true))$combWeights[$cid] = $profEach;
  elseif (in_array($name,$prof2Names,true))$combWeights[$cid] = $profEach;
  else                                     $combWeights[$cid] = $mgrUnit; // по умолчанию
}

// Подготовим структуру вывода
$data = []; // [fio] => ['total'=>..., 'byComb'=> [cid=>['m_itog'=>..., 'm_perq'=>[qid=>...], 'c_itog'=>..., 'p_itog'=>..., 's_itog'=>..., 'w_itog'=>...]]]
foreach ($targets as $t) {
  $tid = (int)$t['target_id'];
  $fio = $t['fio'];

  $data[$fio] = ['total'=>null,'byComb'=>[]];

  $perCombWeighted = []; // для общего итога

  foreach ($combs as $c) {
    $cid = (int)$c['id'];
    $qArr = $combQuestions[$cid] ?? [];
    $qids = array_map(fn($r)=>(int)$r['id'],$qArr);

    // Руководитель по каждому вопросу
    $mgrPerQ = avgByQuery($pdo, $tid, $qids, ['manager']); // [qid]['manager'] => val
    $m_perq = [];
    foreach ($qids as $qid) {
      $m_perq[$qid] = isset($mgrPerQ[$qid]['manager']) ? (float)$mgrPerQ[$qid]['manager'] : null;
    }
    // Итоги по ролям (среднее по вопросам компетенции)
    $byRole = avgByQuery($pdo, $tid, $qids, ['manager','colleague','subordinate','self']); // [qid][role]=>avg
    $acc = ['manager'=>[],'colleague'=>[],'subordinate'=>[],'self'=>[]];
    foreach ($qids as $qid) {
      foreach (['manager','colleague','subordinate','self'] as $rl) {
        if (isset($byRole[$qid][$rl]) && $byRole[$qid][$rl] !== null) $acc[$rl][] = (float)$byRole[$qid][$rl];
      }
    }
    $m_itog = $acc['manager']     ? array_sum($acc['manager'])/count($acc['manager'])         : null;
    $k_itog = $acc['colleague']   ? array_sum($acc['colleague'])/count($acc['colleague'])     : null;
    $p_itog = $acc['subordinate'] ? array_sum($acc['subordinate'])/count($acc['subordinate']) : null;
    $s_itog = $acc['self']        ? array_sum($acc['self'])/count($acc['self'])               : null;

    // Взвешенный итог по компетенции (роль self исключается, веса нормализуются по имеющимся)
    $present = []; $sumW = 0.0; $sumV = 0.0;
    if ($m_itog !== null) $present['manager'] = $m_itog;
    if ($k_itog !== null) $present['colleague'] = $k_itog;
    if ($p_itog !== null) $present['subordinate'] = $p_itog;
    if ($present) {
      $raw = 0.0; foreach ($present as $rl=>$v) $raw += $baseW[$rl];
      foreach ($present as $rl=>$v) { $w = $baseW[$rl]/$raw; $sumV += $w*$v; $sumW += $w; }
    }
    $w_itog = ($sumW>0)?$sumV:null;

    $data[$fio]['byComb'][$cid] = [
      'm_itog'=>$m_itog, 'm_perq'=>$m_perq,
      'k_itog'=>$k_itog, 'p_itog'=>$p_itog, 's_itog'=>$s_itog,
      'w_itog'=>$w_itog
    ];
    $perCombWeighted[$cid] = $w_itog;
  }
  $data[$fio]['total'] = weightedTotal($perCombWeighted, $combWeights);
}

// Стили и шапка
$fmt = ($decimals>0) ? "0.".str_repeat('0',$decimals) : "0";
$css = "
.n{mso-number-format:'$fmt';text-align:center}
.t{text-align:center}
th{font-weight:bold;text-align:center;vertical-align:middle}
td{vertical-align:middle}
.block{background:#e9f1f7}
";

$fname = 'report_mas3_'.$procedureId.'.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$fname.'"');
echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($procedure['title']) ?></title>
<style><?= $css ?></style>
</head>
<body>
<table border="1" cellspacing="0" cellpadding="4">
  <tr>
    <?php
      $colspan = 2; // ФИО + Итоговый балл
      foreach ($combs as $c) {
        $cid = (int)$c['id'];
        $qs  = $combQuestions[$cid] ?? [];
        // Р. Итог + (кол-во вопросов) + К. Итог + П. Итог + С. Итог
        $colspan += 1 + count($qs) + 3;
      }
    ?>
    <th colspan="<?= $colspan ?>"><?= htmlspecialchars($procedure['title']) ?> — <?= $year ?> г.</th>
  </tr>

  <tr>
    <th class="block">ФИО</th>
    <th class="block">Итоговый балл</th>
    <?php foreach ($combs as $c):
      $cid=(int)$c['id']; $qs=$combQuestions[$cid]??[]; ?>
      <th class="block" colspan="<?= 1 + count($qs) + 3 ?>"><?= htmlspecialchars($c['name']) ?></th>
    <?php endforeach; ?>
  </tr>

  <tr>
    <th class="t">№</th>
    <th class="t">—</th>
    <?php foreach ($combs as $c):
      $cid=(int)$c['id']; $qs=$combQuestions[$cid]??[]; $i=1; ?>
      <th>Р. Итог</th>
      <?php foreach ($qs as $_): ?>
        <th>R<?= $i++ ?></th>
      <?php endforeach; ?>
      <th>К. Итог</th>
      <th>П. Итог</th>
      <th>С. Итог</th>
    <?php endforeach; ?>
  </tr>

  <?php $rownum=1; foreach ($data as $fio=>$by): ?>
    <tr>
      <td class="t"><?= $rownum++ ?></td>
      <td class="n"><?= $by['total']!==null ? number_format($by['total'],$decimals,'.','') : '-' ?></td>
      <?php foreach ($combs as $c):
        $cid=(int)$c['id']; $qs=$combQuestions[$cid]??[];
        $v = $by['byComb'][$cid] ?? null;
        $m_itog = $v['m_itog'] ?? null;
        $k_itog = $v['k_itog'] ?? null;
        $p_itog = $v['p_itog'] ?? null;
        $s_itog = $v['s_itog'] ?? null;
        echo ($m_itog===null) ? '<td class="t">-</td>' : '<td class="n">'.number_format($m_itog,$decimals,'.','').'</td>';
        foreach ($qs as $q) {
          $qid=(int)$q['id']; $mv=$v['m_perq'][$qid] ?? null;
          echo ($mv===null) ? '<td class="t">-</td>' : '<td class="n">'.number_format($mv,$decimals,'.','').'</td>';
        }
        echo ($k_itog===null) ? '<td class="t">-</td>' : '<td class="n">'.number_format($k_itog,$decimals,'.','').'</td>';
        echo ($p_itog===null) ? '<td class="t">-</td>' : '<td class="n">'.number_format($p_itog,$decimals,'.','').'</td>';
        echo ($s_itog===null) ? '<td class="t">-</td>' : '<td class="n">'.number_format($s_itog,$decimals,'.','').'</td>';
      endforeach; ?>
    </tr>
    <tr>
      <td class="t" colspan="2"><?= htmlspecialchars($fio) ?></td>
      <?php foreach ($combs as $c):
        $cid=(int)$c['id']; $qs=$combQuestions[$cid]??[]; ?>
        <td class="t" colspan="<?= 1 + count($qs) + 3 ?>">&nbsp;</td>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
</table>
</body>
</html>