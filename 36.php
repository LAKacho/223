<?php
require 'config.php';

$procedureId = isset($_GET['procedure_id']) ? (int)$_GET['procedure_id'] : 0;
if ($procedureId <= 0) { http_response_code(400); exit('Нужно передать ?procedure_id='); }
$decimals = isset($_GET['dec']) ? max(0, (int)$_GET['dec']) : 2;

function trunc_dec($x, int $n): float {
    $p = pow(10, $n);
    return ($x >= 0) ? floor($x * $p) / $p : ceil($x * $p) / $p;
}
function fmt_dec($x, int $n): string {
    $v = trunc_dec((float)$x, $n);
    return number_format($v, $n, '.', '');
}

$st = $pdo->prepare("SELECT id, title, start_date FROM evaluation_procedures WHERE id=?");
$st->execute([$procedureId]);
$procedure = $st->fetch() ?: exit('Процедура не найдена');
$year = $procedure['start_date'] ? (new DateTime($procedure['start_date']))->format('Y') : date('Y');

$st = $pdo->prepare("
  SELECT c.id, c.name
  FROM procedure_combinations pc
  JOIN combinations c ON c.id = pc.combination_id
  WHERE pc.procedure_id = ?
  ORDER BY c.name
");
$st->execute([$procedureId]);
$competencies = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$competencies) exit('К процедуре не привязаны компетенции.');

$combIds   = array_map(fn($r)=>(int)$r['id'], $competencies);
$combNames = [];
foreach ($competencies as $c) $combNames[(int)$c['id']] = $c['name'] ?? '';

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

$normalize = function(?string $s): string {
    if ($s === null) return '';
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/^[0-9.\-\s]+/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', trim($s));
    return $s;
};

$map = []; $hasLink = [];
foreach ($combIds as $cid) { $map[$cid] = []; $hasLink[$cid] = false; }

if ($combIds) {
    $in = implode(',', array_fill(0, count($combIds), '?'));
    $q  = $pdo->prepare("SELECT combination_id, question_id FROM combination_questions WHERE combination_id IN ($in)");
    $q->execute($combIds);
    foreach ($q as $row) {
        $map[(int)$row['combination_id']][] = (int)$row['question_id'];
        $hasLink[(int)$row['combination_id']] = true;
    }
}

$needFallback = array_values(array_filter($combIds, fn($cid)=>!$hasLink[$cid]));
if ($needFallback) {
    $allQ = $pdo->query("SELECT id, category FROM questions")->fetchAll(PDO::FETCH_ASSOC);

    $groupCat = [];
    foreach ($allQ as $row) {
        $k = $normalize($row['category']);
        $groupCat[$k][] = (int)$row['id'];
    }

    $synRaw = [
        'ответственность' => 'ответственность за результат',
        'профессиональные знания замещаемой должности' => 'профессиональные знания ключевой должности',
        'профессиональные знания должностей, смежных к текущей' => 'профессиональные знания должностей, смежных к текущей должности',
    ];
    $syn = [];
    foreach ($synRaw as $k=>$v) { $syn[$normalize($k)] = $normalize($v); }

    foreach ($needFallback as $cid) {
        $combNorm = $normalize($combNames[$cid]);

        $qids = $groupCat[$combNorm] ?? null;
        if (!$qids && isset($syn[$combNorm])) {
            $qids = $groupCat[$syn[$combNorm]] ?? null;
        }
        if (!$qids) {
            $bestKey = null; $bestScore = -1;
            foreach ($groupCat as $k => $ids) {
                $score = -1;
                if (mb_strpos($k, $combNorm)!==false || mb_strpos($combNorm, $k)!==false) {
                    $score = max(mb_strlen($k,'UTF-8'), mb_strlen($combNorm,'UTF-8'));
                }
                if ($score > $bestScore) { $bestScore = $score; $bestKey = $k; }
            }
            if ($bestKey !== null && $bestScore >= 0) $qids = $groupCat[$bestKey];
        }

        $map[$cid] = $qids ?: [];
    }
}

$roles       = ['manager','colleague','subordinate'];
$roleLabels  = ['manager'=>'Руководитель', 'colleague'=>'Коллеги', 'subordinate'=>'Подчинённые'];

$rows = [];  

foreach ($targets as $t) {
    $fio = $t['fio']; $tid = (int)$t['target_id'];
    $rows[$fio] = [];

    foreach ($roles as $rl) {
        $rows[$fio][$rl] = ['total'=>null, 'by_cid'=>[]];
    }

    foreach ($combIds as $cid) {
        $qids = $map[$cid] ?? [];
        if (!$qids) {
            foreach ($roles as $rl) $rows[$fio][$rl]['by_cid'][$cid] = null;
            continue;
        }

        $ph = implode(',', array_fill(0, count($qids), '?'));
        $sql = "
          SELECT ep.role, AVG(CASE WHEN a.score >= 0 THEN a.score END) AS avg_score
          FROM answers a
          JOIN evaluation_participants ep ON ep.id = a.participant_id
          WHERE ep.target_id = ?
            AND ep.role IN ('manager','colleague','subordinate')
            AND a.question_id IN ($ph)
          GROUP BY ep.role
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$tid], $qids));

        $avgByRole = ['manager'=>null,'colleague'=>null,'subordinate'=>null];
        foreach ($stmt as $r) {
            if ($r['avg_score'] !== null) $avgByRole[$r['role']] = (float)$r['avg_score'];
        }

        foreach ($roles as $rl) {
            $rows[$fio][$rl]['by_cid'][$cid] = $avgByRole[$rl];
        }
    }

    foreach ($roles as $rl) {
        $vals = array_values(array_filter(
            $rows[$fio][$rl]['by_cid'],
            fn($v)=>$v !== null
        ));
        $rows[$fio][$rl]['total'] = count($vals) ? array_sum($vals)/count($vals) : null;
    }
}

$fmtMask = ($decimals > 0) ? "0." . str_repeat('0', $decimals) : "0";
$css = "
.num{mso-number-format:'{$fmtMask}';text-align:center}
.txt{text-align:center}
th{font-weight:bold;text-align:center}
.roleHead{background:#eaf2ff}
";

$fname = 'role_averages_'.$procedureId.'.xls';
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
    <th colspan="<?= 1 + count($roles)*(1+count($competencies)) ?>">
      Средний балл по ответам по каждой роли — <?= htmlspecialchars($procedure['title']) ?> (<?= $year ?> г.)
    </th>
  </tr>

  <tr>
    <th rowspan="2">Кого оценивали (ФИО)</th>
    <?php foreach ($roles as $rl): ?>
      <th class="roleHead" colspan="<?= 1 + count($competencies) ?>">
        <?= htmlspecialchars($roleLabels[$rl]) ?>
      </th>
    <?php endforeach; ?>
  </tr>
  <tr>
    <?php foreach ($roles as $rl): ?>
      <th>Итог</th>
      <?php foreach ($competencies as $c): ?>
        <th><?= htmlspecialchars($c['name']) ?></th>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </tr>

  <?php foreach ($rows as $fio => $byRole): ?>
    <tr>
      <td class="txt"><?= htmlspecialchars($fio) ?></td>

      <?php foreach ($roles as $rl): ?>
        <?php $tot = $byRole[$rl]['total']; ?>
        <?= ($tot === null) ? '<td class="txt">-</td>' : '<td class="num">'.fmt_dec($tot,$decimals).'</td>' ?>
        <?php foreach ($competencies as $c):
              $cid = (int)$c['id']; $v = $byRole[$rl]['by_cid'][$cid] ?? null; ?>
          <?= ($v === null) ? '<td class="txt">-</td>' : '<td class="num">'.fmt_dec($v,$decimals).'</td>' ?>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
</table>

<p style="font-size:12px;color:#555;margin-top:10px;">
 <?= (int)$decimals ?> 
</p>

</body>
</html>
