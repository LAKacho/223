<?php
require 'config.php';

$procedureId = isset($_GET['procedure_id']) ? (int)$_GET['procedure_id'] : 0;
if ($procedureId <= 0) { http_response_code(400); exit('Нужно передать ?procedure_id='); }

$decimals = isset($_GET['dec']) ? max(0, (int)$_GET['dec']) : 2;

$st = $pdo->prepare("SELECT id, title, start_date FROM evaluation_procedures WHERE id=?");
$st->execute([$procedureId]);
$procedure = $st->fetch();
if (!$procedure) exit('Процедура не найдена');
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

  $q = $pdo->prepare("SELECT qc.combination_id, qc.question_id, q.text
                      FROM question_combination qc
                      JOIN questions q ON q.id = qc.question_id
                      WHERE qc.combination_id IN ($in)
                      ORDER BY qc.combination_id, qc.question_id");
  $q->execute($combIds);
  foreach ($q as $row) {
    $cid = (int)$row['combination_id'];
    $qid = (int)$row['question_id'];
    $combQuestions[$cid][] = ['id'=>$qid, 'text'=>$row['text']];
    $allQuestionIds[$qid] = $row['text'];
  }
}

if (!$combQuestions) exit('У привязанных компетенций нет вопросов.');

$roles = ['manager','colleague','subordinate','self'];
$roleLabel = ['manager'=>'Рук','colleague'=>'Колл','subordinate'=>'Подч','self'=>'Сам'];
$baseWeights = ['manager'=>0.334,'colleague'=>0.333,'subordinate'=>0.333]; // self не учитывается

$results = []; // [fio][question_id] => ['manager'=>avg,'colleague'=>avg,'subordinate'=>avg,'self'=>avg,'weighted'=>val]

foreach ($targets as $t) {
  $tid = (int)$t['target_id'];
  $fio = $t['fio'];
  $results[$fio] = [];

  $qids = array_keys($allQuestionIds);
  $ph = implode(',', array_fill(0, count($qids), '?'));

  $sql = "
    SELECT a.question_id,
           ep.role,
           AVG(CASE WHEN a.score >= 0 THEN a.score END) AS avg_score
    FROM answers a
    JOIN evaluation_participants ep ON ep.id = a.participant_id
    WHERE ep.target_id = ?
      AND ep.role IN ('manager','colleague','subordinate','self')
      AND a.question_id IN ($ph)
    GROUP BY a.question_id, ep.role
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute(array_merge([$tid], $qids));

  $perQ = []; // temp
  foreach ($stmt as $r) {
    $qid = (int)$r['question_id'];
    $role = $r['role'];
    $avg  = ($r['avg_score'] !== null) ? (float)$r['avg_score'] : null;
    if (!isset($perQ[$qid])) $perQ[$qid] = ['manager'=>null,'colleague'=>null,'subordinate'=>null,'self'=>null];
    $perQ[$qid][$role] = $avg;
  }

  foreach ($qids as $qid) {
    $byRole = $perQ[$qid] ?? ['manager'=>null,'colleague'=>null,'subordinate'=>null,'self'=>null];

    $sumW = 0.0; $sumV = 0.0;
    $present = [];
    foreach (['manager','colleague','subordinate'] as $rl) {
      if ($byRole[$rl] !== null) $present[$rl] = true;
    }

    if ($present) {
      $rawW = 0.0;
      foreach ($present as $rl => $_) $rawW += $baseWeights[$rl];
      foreach ($present as $rl => $_) {
        $w = $baseWeights[$rl] / $rawW;
        $sumW += $w;
        $sumV += $w * $byRole[$rl];
      }
    }
    $weighted = ($sumW > 0) ? $sumV : null;

    $results[$fio][$qid] = [
      'manager'     => $byRole['manager'],
      'colleague'   => $byRole['colleague'],
      'subordinate' => $byRole['subordinate'],
      'self'        => $byRole['self'],
      'weighted'    => $weighted,
    ];
  }
}

$fmt = ($decimals > 0) ? "0." . str_repeat('0',$decimals) : "0";
$css = ".n{mso-number-format:'$fmt';text-align:center}.t{text-align:center}th{font-weight:bold;text-align:center}td{vertical-align:middle}";

$fname = 'report_questions_by_role_'.$procedureId.'.xls';
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
        $colspan += count($qs) * 5; // M,C,S,Self,Итог
      }
    ?>
    <th colspan="<?= $colspan ?>"><?= htmlspecialchars($procedure['title']) ?> — <?= $year ?> г.</th>
  </tr>
  <tr>
    <th rowspan="2">Оцениваемый</th>
    <?php foreach ($combs as $c): $cid = (int)$c['id']; $qs = $combQuestions[$cid] ?? []; ?>
      <th colspan="<?= count($qs)*5 ?>"><?= htmlspecialchars($c['name']) ?></th>
    <?php endforeach; ?>
  </tr>
  <tr>
    <?php foreach ($combs as $c): $cid = (int)$c['id']; $qs = $combQuestions[$cid] ?? []; $i=1; ?>
      <?php foreach ($qs as $q): ?>
        <th>M<br><?= $i ?></th>
        <th>C<br><?= $i ?></th>
        <th>S<br><?= $i ?></th>
        <th>Self<br><?= $i ?></th>
        <th>Итог<br><?= $i++ ?></th>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </tr>

  <?php foreach ($results as $fio => $byQ): ?>
    <tr>
      <td class="t"><?= htmlspecialchars($fio) ?></td>
      <?php foreach ($combs as $c): $cid = (int)$c['id']; $qs = $combQuestions[$cid] ?? []; ?>
        <?php foreach ($qs as $q): $qid = (int)$q['id']; $r = $byQ[$qid] ?? null; ?>
          <?php
            $M = $r['manager']     ?? null;
            $C = $r['colleague']   ?? null;
            $S = $r['subordinate'] ?? null;
            $SELF = $r['self']     ?? null;
            $W = $r['weighted']    ?? null;
          ?>
          <?= ($M===null)?'<td class="t">-</td>':'<td class="n">'.number_format($M,$decimals,'.','').'</td>' ?>
          <?= ($C===null)?'<td class="t">-</td>':'<td class="n">'.number_format($C,$decimals,'.','').'</td>' ?>
          <?= ($S===null)?'<td class="t">-</td>':'<td class="n">'.number_format($S,$decimals,'.','').'</td>' ?>
          <?= ($SELF===null)?'<td class="t">-</td>':'<td class="n">'.number_format($SELF,$decimals,'.','').'</td>' ?>
          <?= ($W===null)?'<td class="t">-</td>':'<td class="n">'.number_format($W,$decimals,'.','').'</td>' ?>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
</table>
</body>
</html>