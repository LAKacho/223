<?php

require 'config.php';

$procedureId = isset($_GET['procedure_id']) ? (int)$_GET['procedure_id'] : 0;
if ($procedureId <= 0) { http_response_code(400); exit('Нужно передать ?procedure_id='); }

$st = $pdo->prepare("SELECT id, title, start_date FROM evaluation_procedures WHERE id=?");
$st->execute([$procedureId]);
$procedure = $st->fetch();
if (!$procedure) exit('Процедура не найдена');
$year = $procedure['start_date'] ? (new DateTime($procedure['start_date']))->format('Y') : date('Y');

$st = $pdo->prepare(
  "SELECT c.id, c.name
   FROM procedure_combinations pc
   JOIN combinations c ON c.id = pc.combination_id
   WHERE pc.procedure_id = ?
   ORDER BY c.name"
);
$st->execute([$procedureId]);
$competencies = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$competencies) exit('К процедуре не привязаны компетенции.');

$combIds   = array_map(function($r){ return (int)$r['id']; }, $competencies);
$combNames = [];
foreach ($competencies as $c) $combNames[(int)$c['id']] = $c['name'] ?? '';

$st = $pdo->prepare(
  "SELECT et.id AS target_id, u.fio
   FROM evaluation_targets et
   JOIN users u ON u.id = et.user_id
   WHERE et.procedure_id = ?
   ORDER BY u.fio"
);
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
$needFallback = array_values(array_filter($combIds, function($cid) use ($hasLink){ return !$hasLink[$cid]; }));
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
            $alias = $syn[$combNorm];
            $qids = $groupCat[$alias] ?? null;
        }

        if (!$qids) {
            $bestKey = null; $bestScore = -1;
            foreach ($groupCat as $k => $ids) {
                $score = -1;
                if (mb_strpos($k, $combNorm) !== false || mb_strpos($combNorm, $k) !== false) {
                    $score = max(mb_strlen($k, 'UTF-8'), mb_strlen($combNorm, 'UTF-8'));
                }
                if ($score > $bestScore) { $bestScore = $score; $bestKey = $k; }
            }
            if ($bestKey !== null && $bestScore >= 0) $qids = $groupCat[$bestKey];
        }

        $map[$cid] = $qids ?: [];
    }
}

// ---- Роли: базовые веса при наличии всех трёх ----
$roleList   = ['manager','colleague','subordinate'];
$roleWeight = ['manager'=>0.334, 'colleague'=>0.333, 'subordinate'=>0.333];

// Функция: динамическое взвешивание в зависимости от присутствующих ролей
function weightedByPresentRoles(array $avgByRole, array $baseWeights): ?float {
    $present = [];
    foreach (['manager','colleague','subordinate'] as $r) {
        if ($avgByRole[$r] !== null) $present[] = $r;
    }
    $n = count($present);
    if ($n === 0) return null;

    if ($n === 3) {
        // все три роли: используем базовые веса 33.4/33.3/33.3
        $sum = 0.0;
        foreach ($present as $r) $sum += $baseWeights[$r] * (float)$avgByRole[$r];
        return $sum; // базовые веса уже суммируются в 1.0
    }

    // 2 роли -> 50/50, 1 роль -> 100%
    $w = 1.0 / $n;
    $sum = 0.0;
    foreach ($present as $r) $sum += $w * (float)$avgByRole[$r];
    return $sum;
}

// ---- Расчёт матрицы средних по компетенциям ----
$matrix = [];
foreach ($targets as $t) {
    $fio = $t['fio']; $tid = (int)$t['target_id'];
    $matrix[$fio] = [];

    foreach ($combIds as $cid) {
        $qids = $map[$cid] ?? [];
        if (!$qids) { $matrix[$fio][$cid] = null; continue; }

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

        // динамическое взвешивание по присутствующим ролям
        $matrix[$fio][$cid] = weightedByPresentRoles($avgByRole, $roleWeight);
    }
}

// ---- Веса компетенций: 6×8.33% + 2×25% ----
$mgrUnit  = 0.5 / 6.0;   // 8.333…%
$profEach = 0.25;        // 25%

$mgrSet  = array_map($normalize, [
  'принятие решений',
  'ответственность за результат', // допускаем оба варианта
  'ответственность',
  'планирование и контроль',
  'влияние и убеждение',
  'стремление к развитию',
  'клиентоориентированность',
]);
$prof1Set = array_map($normalize, [
  'профессиональные знания замещаемой должности',
  'профессиональные знания ключевой должности',
]);
$prof2Set = array_map($normalize, [
  'профессиональные знания должностей, смежных к текущей',
]);

$combWeight = [];
foreach ($combIds as $cid) {
    $n = $normalize($combNames[$cid]);
    if     (in_array($n, $mgrSet,  true)) $combWeight[$cid] = $mgrUnit;
    elseif (in_array($n, $prof1Set, true)) $combWeight[$cid] = $profEach;
    elseif (in_array($n, $prof2Set, true)) $combWeight[$cid] = $profEach;
    else                                   $combWeight[$cid] = null; // если найдётся «лишняя» компетенция — не учитываем в «Итого»
}

// ---- Итог по сотруднику (взвешивание по компетенциям) ----
$totals = [];
foreach ($matrix as $fio => $valsByCid) {
    $sumW = 0; $sumV = 0;
    foreach ($valsByCid as $cid => $v) {
        $w = $combWeight[$cid] ?? null;
        if ($w !== null && $v !== null) { $sumW += $w; $sumV += $w * $v; }
    }
    $totals[$fio] = ($sumW > 0) ? ($sumV / $sumW) : null;
}

// ---- Классы A–D ----
$grade = function($score) {
    if ($score === null) return '';
    if ($score >= 2.6) return 'A';
    if ($score >= 2.0) return 'B';
    if ($score >= 1.0) return 'C';
    return 'D';
};

// ---- Excel (HTML) ----
$css = ".num{mso-number-format:'0.00'; text-align:center}
.txt{text-align:center}
.grade{text-align:center; mso-number-format:'\\@'}";

$fname = 'procedure_report_360_6x2_'.$procedureId.'.xls';
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

<table border="0" cellpadding="0" cellspacing="0">
  <tr>
    <td colspan="<?= 3 + count($competencies) ?>" style="font-weight:bold;">
      Оценка компетенций резервистов методом «360 градусов» (6×8,33% + 2×25%) с динамическими весами ролей
    </td>
  </tr>
  <tr><td colspan="<?= 3 + count($competencies) ?>" style="font-weight:bold;"><?= $year ?> г.</td></tr>
  <tr><td colspan="<?= 3 + count($competencies) ?>">&nbsp;</td></tr>

  <tr class="rowHead">
    <td style="font-weight:bold;text-align:center;">ФИО</td>
    <?php foreach ($competencies as $c): ?>
      <td style="font-weight:bold;text-align:center;"><?= htmlspecialchars($c['name']) ?></td>
    <?php endforeach; ?>
    <td style="font-weight:bold;text-align:center;">Итого (взвеш.)</td>
    <td style="font-weight:bold;text-align:center;">Класс (A–D)</td>
  </tr>
  <tr class="rowNum">
    <td class="txt">№</td>
    <?php $i=1; foreach ($competencies as $_): ?><td class="txt"><?= $i++ ?></td><?php endforeach; ?>
    <td class="txt"><?= $i ?></td>
    <td class="txt"><?= $i+1 ?></td>
  </tr>

  <?php $r=1; foreach ($matrix as $fio => $vals): ?>
    <tr>
      <td><?= $r++.'. '.htmlspecialchars($fio) ?></td>
      <?php foreach ($competencies as $c):
            $cid = (int)$c['id']; $v = $vals[$cid]; ?>
        <?= ($v === null)
              ? '<td class="txt">-</td>'
              : '<td class="num">'.number_format((float)$v, 2, '.', '').'</td>' ?>
      <?php endforeach; ?>
      <?php $tv = $totals[$fio]; $gl = $grade($tv); ?>
      <?= ($tv === null) ? '<td class="txt">-</td>' : '<td class="num">'.number_format((float)$tv, 2, '.', '').'</td>' ?>
      <td class="grade"><?= $gl ?: '-' ?></td>
    </tr>
  <?php endforeach; ?>

  <tr><td colspan="<?= 3 + count($competencies) ?>">&nbsp;</td></tr>
  <tr>
    <td colspan="<?= 3 + count($competencies) ?>">
      Роли: при наличии всех трёх — 33,4% / 33,3% / 33,3%; при отсутствии части ролей — оставшиеся делят вес поровну (50/50 или 100%).<br>
      Компетенции: 6 управленческих по 8,33% (в сумме 50%) и 2 профессиональные по 25% (в сумме 50%).
    </td>
  </tr>
</table>

</body>
</html>