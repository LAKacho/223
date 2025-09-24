<?php
require 'config.php';

$procedureId = isset($_GET['procedure_id']) ? (int)$_GET['procedure_id'] : 0;
if ($procedureId <= 0) { http_response_code(400); exit('Нужно передать ?procedure_id='); }
$decimals = isset($_GET['dec']) ? max(0, (int)$_GET['dec']) : 2;

$st = $pdo->prepare("SELECT id, title, start_date FROM evaluation_procedures WHERE id=?");
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

$combQuestions = [];
$allQuestionIds = [];
if ($combs) {
  $in = implode(',', array_fill(0, count($combs), '?'));
  $combIds = array_map(fn($r)=>(int)$r['id'], $combs);
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
    $allQuestionIds[$qid] = true;
  }
}
if (!$combQuestions) exit('У привязанных компетенций нет вопросов.');

$roles = ['manager','colleague','subordinate','self'];

function fetchPerQuestionByRole(PDO $pdo, int $targetId, array $qids): array {
  if (!$qids) return [];
  $ph = implode(',', array_fill(0, count($qids), '?'));
  $sql = "
    SELECT a.question_id, ep.role, AVG(CASE WHEN a.score>=0 THEN a.score END) AS avg_score
    FROM answers a
    JOIN evaluation_participants ep ON ep.id=a.participant_id
    WHERE ep.target_id = ?
      AND ep.role IN ('manager','colleague','subordinate','self')
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
  return $out;
}

$results = []; // [fio]['byComb'][cid] => ['R'=>['itog'=>..,'perq'=>[qid=>..]], 'K'=>..., 'P'=>..., 'S'=>...]
foreach ($targets as $t) {
  $tid = (int)$t['target_id'];
  $fio = $t['fio'];
  $results[$fio] = ['byComb'=>[]];

  foreach ($combs as $c) {
    $cid = (int)$c['id'];
    $qs = $combQuestions[$cid] ?? [];
    $qids = array_map(fn($r)=>(int)$r['id'],$qs);

    $perQ = fetchPerQuestionByRole($pdo, $tid, $qids);

    $packs = ['R'=>'manager','K'=>'colleague','P'=>'subordinate','S'=>'self'];
    $block = [];
    foreach ($packs as $label=>$rl) {
      $perq = [];
      $acc = [];
      foreach ($qids as $qid) {
        $v = $perQ[$qid][$rl] ?? null;
        $perq[$qid] = $v;
        if ($v !== null) $acc[] = $v;
      }
      $itog = $acc ? array_sum($acc)/count($acc) : null;
      $block[$label] = ['itog'=>$itog,'perq'=>$perq];
    }

    $results[$fio]['byComb'][$cid] = $block;
  }
}

$fmt = ($decimals > 0) ? "0." . str_repeat('0',$decimals) : "0";
$css = ".n{mso-number-format:'$fmt';text-align:center}.t{text-align:center}th{font-weight:bold;text-align:center}td{vertical-align:middle}.b{background:#e9f1f7}.g{background:#f7f7f7}";

$fname = 'report_questions_roles_full_'.$procedureId.'.xls';
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
<table border="1" cellspacing="0" cellpadding="3">
  <tr>
    <?php
      $colspan = 1;
      foreach ($combs as $c) {
        $cid = (int)$c['id'];
        $qs  = $combQuestions[$cid] ?? [];
        $n = count($qs);
        $colspan += 4 * (1 + $n); // Р блок + К блок + П блок + С блок
      }
    ?>
    <th colspan="<?= $colspan ?>"><?= htmlspecialchars($procedure['title']) ?> — <?= $year ?> г.</th>
  </tr>

  <tr>
    <th class="b">Оцениваемый</th>
    <?php foreach ($combs as $c): $cid=(int)$c['id']; $qs=$combQuestions[$cid]??[]; $n=count($qs); ?>
      <th class="b" colspan="<?= 4*(1+$n) ?>"><?= htmlspecialchars($c['name']) ?></th>
    <?php endforeach; ?>
  </tr>

  <tr>
    <th class="t">ФИО</th>
    <?php foreach ($combs as $c): $cid=(int)$c['id']; $qs=$combQuestions[$cid]??[]; $n=count($qs); ?>
      <th class="g" colspan="<?= 1+$n ?>">Р</th>
      <th class="g" colspan="<?= 1+$n ?>">К</th>
      <th class="g" colspan="<?= 1+$n ?>">П</th>
      <th class="g" colspan="<?= 1+$n ?>">С</th>
    <?php endforeach; ?>
  </tr>

  <tr>
    <th class="t"></th>
    <?php foreach ($combs as $c): $cid=(int)$c['id']; $qs=$combQuestions[$cid]??[]; $i=1; ?>
      <th>Р. Итог</th>
      <?php foreach ($qs as $_): ?><th>Р<?= $i++ ?></th><?php endforeach; ?>
      <?php $i=1; ?>
      <th>К. Итог</th>
      <?php foreach ($qs as $_): ?><th>К<?= $i++ ?></th><?php endforeach; ?>
      <?php $i=1; ?>
      <th>П. Итог</th>
      <?php foreach ($qs as $_): ?><th>П<?= $i++ ?></th><?php endforeach; ?>
      <?php $i=1; ?>
      <th>С. Итог</th>
      <?php foreach ($qs as $_): ?><th>С<?= $i++ ?></th><?php endforeach; ?>
    <?php endforeach; ?>
  </tr>

  <?php foreach ($results as $fio => $by): ?>
    <tr>
      <td class="t"><?= htmlspecialchars($fio) ?></td>
      <?php foreach ($combs as $c):
        $cid=(int)$c['id']; $qs=$combQuestions[$cid]??[]; $block=$by['byComb'][$cid]??null;

        $R=$block['R']??['itog'=>null,'perq'=>[]];
        echo ($R['itog']===null)?'<td class="t">-</td>':'<td class="n">'.number_format($R['itog'],$decimals,'.','').'</td>';
        foreach ($qs as $q){ $v=$R['perq'][(int)$q['id']]??null; echo ($v===null)?'<td class="t">-</td>':'<td class="n">'.number_format($v,$decimals,'.','').'</td>'; }

        $K=$block['K']??['itog'=>null,'perq'=>[]];
        echo ($K['itog']===null)?'<td class="t">-</td>':'<td class="n">'.number_format($K['itog'],$decimals,'.','').'</td>';
        foreach ($qs as $q){ $v=$K['perq'][(int)$q['id']]??null; echo ($v===null)?'<td class="t">-</td>':'<td class="n">'.number_format($v,$decimals,'.','').'</td>'; }

        $P=$block['P']??['itog'=>null,'perq'=>[]];
        echo ($P['itog']===null)?'<td class="t">-</td>':'<td class="n">'.number_format($P['itog'],$decimals,'.','').'</td>';
        foreach ($qs as $q){ $v=$P['perq'][(int)$q['id']]??null; echo ($v===null)?'<td class="t">-</td>':'<td class="n">'.number_format($v,$decimals,'.','').'</td>'; }

        $S=$block['S']??['itog'=>null,'perq'=>[]];
        echo ($S['itog']===null)?'<td class="t">-</td>':'<td class="n">'.number_format($S['itog'],$decimals,'.','').'</td>';
        foreach ($qs as $q){ $v=$S['perq'][(int)$q['id']]??null; echo ($v===null)?'<td class="t">-</td>':'<td class="n">'.number_format($v,$decimals,'.','').'</td>'; }
      endforeach; ?>
    </tr>
  <?php endforeach; ?>
</table>
</body>
</html>