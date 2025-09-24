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
$baseWeights = ['manager'=>0.334,'colleague'=>0.333,'subordinate'=>0.333];

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
  return $out;
}

$results = []; // [fio]['byComb'][cid] => ['m_itog'=>..., 'm_perq'=>[qid=>...], 'k_itog'=>..., 'p_itog'=>..., 's_itog'=>...]
foreach ($targets as $t) {
  $tid = (int)$t['target_id'];
  $fio = $t['fio'];
  $results[$fio] = ['byComb'=>[]];

  foreach ($combs as $c) {
    $cid = (int)$c['id'];
    $qs = $combQuestions[$cid] ?? [];
    $qids = array_map(fn($r)=>(int)$r['id'],$qs);

    $mgrPerQ = avgByQuery($pdo, $tid, $qids, ['manager']);
    $m_perq = [];
    foreach ($qids as $qid) { $m_perq[$qid] = $mgrPerQ[$qid]['manager'] ?? null; }

    $byRole = avgByQuery($pdo, $tid, $qids, ['manager','colleague','subordinate','self']);
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

    $results[$fio]['byComb'][$cid] = [
      'm_itog'=>$m_itog,
      'm_perq'=>$m_perq,
      'k_itog'=>$k_itog,
      'p_itog'=>$p_itog,
      's_itog'=>$s_itog
    ];
  }
}

$fmt = ($decimals > 0) ? "0." . str_repeat('0',$decimals) : "0";
$css = ".n{mso-number-format:'$fmt';text-align:center}.t{text-align:center}th{font-weight:bold;text-align:center}td{vertical-align:middle}.b{background:#e9f1f7}";

$fname = 'report_questions_by_role_blocks_'.$procedureId.'.xls';
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
        $colspan += 1 + count($qs) + 3; // Р. Итог + R1..Rn + К Итог + П Итог + С Итог
      }
    ?>
    <th colspan="<?= $colspan ?>"><?= htmlspecialchars($procedure['title']) ?> — <?= $year ?> г.</th>
  </tr>
  <tr>
    <th class="b">Оцениваемый</th>
    <?php foreach ($combs as $c): $cid=(int)$c['id']; $qs=$combQuestions[$cid]??[]; ?>
      <th class="b" colspan="<?= 1 + count($qs) + 3 ?>"><?= htmlspecialchars($c['name']) ?></th>
    <?php endforeach; ?>
  </tr>
  <tr>
    <th class="t">ФИО</th>
    <?php foreach ($combs as $c): $cid=(int)$c['id']; $qs=$combQuestions[$cid]??[]; $i=1; ?>
      <th>Р. Итог</th>
      <?php foreach ($qs as $_): ?><th>R<?= $i++ ?></th><?php endforeach; ?>
      <th>К. Итог</th>
      <th>П. Итог</th>
      <th>С. Итог</th>
    <?php endforeach; ?>
  </tr>

  <?php foreach ($results as $fio => $by): ?>
    <tr>
      <td class="t"><?= htmlspecialchars($fio) ?></td>
      <?php foreach ($combs as $c):
        $cid=(int)$c['id']; $qs=$combQuestions[$cid]??[];
        $v = $by['byComb'][$cid] ?? null;
        $m_itog = $v['m_itog'] ?? null;
        $k_itog = $v['k_itog'] ?? null;
        $p_itog = $v['p_itog'] ?? null;
        $s_itog = $v['s_itog'] ?? null;

        echo ($m_itog===null)?'<td class="t">-</td>':'<td class="n">'.number_format($m_itog,$decimals,'.','').'</td>';
        foreach ($qs as $q) {
          $qid=(int)$q['id']; $mv = $v['m_perq'][$qid] ?? null;
          echo ($mv===null)?'<td class="t">-</td>':'<td class="n">'.number_format($mv,$decimals,'.','').'</td>';
        }
        echo ($k_itog===null)?'<td class="t">-</td>':'<td class="n">'.number_format($k_itog,$decimals,'.','').'</td>';
        echo ($p_itog===null)?'<td class="t">-</td>':'<td class="n">'.number_format($p_itog,$decimals,'.','').'</td>';
        echo ($s_itog===null)?'<td class="t">-</td>':'<td class="n">'.number_format($s_itog,$decimals,'.','').'</td>';
      endforeach; ?>
    </tr>
  <?php endforeach; ?>
</table>
</body>
</html>