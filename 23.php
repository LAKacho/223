<?php
// export_competency_breakdown_xls.php
// Детализация по компетенциям: M / C / S и взвешенный итог.
// Политика округления "как в Excel по умолчанию":
// - в вычислениях полная точность (double)
// - при выводе усечение (truncate) до ?dec= знаков (по умолчанию 2)

require 'config.php';

$procedureId = isset($_GET['procedure_id']) ? (int)$_GET['procedure_id'] : 0;
if ($procedureId <= 0) { http_response_code(400); exit('Нужно передать ?procedure_id='); }

// Сколько знаков показывать
$decimals = isset($_GET['dec']) ? max(0, (int)$_GET['dec']) : 2;

// --- Помощники округления/форматирования ---
// Усечение до N знаков (как визуальный формат в Excel без ROUND)
function trunc_dec($x, int $n): float {
    $p = pow(10, $n);
    // учитываем отрицательные значения корректно
    return ($x >= 0) ? floor($x * $p) / $p : ceil($x * $p) / $p;
}
function fmt_dec($x, int $n): string {
    // показываем именно усечённое значение
    $v = trunc_dec((float)$x, $n);
    return number_format($v, $n, '.', '');
}

// --- Загружаем процедуру ---
$st = $pdo->prepare("SELECT id, title, start_date FROM evaluation_procedures WHERE id=?");
$st->execute([$procedureId]);
$procedure = $st->fetch();
if (!$procedure) exit('Процедура не найдена');
$year = $procedure['start_date'] ? (new DateTime($procedure['start_date']))->format('Y') : date('Y');

// --- Компетенции, привязанные к процедуре ---
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

// --- Участники процедуры (оцениваемые) ---
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

// --- Нормализация строк для fallback по категориям ---
$normalize = function(?string $s): string {
    if ($s === null) return '';
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/^[0-9.\-\s]+/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', trim($s));
    return $s;
};

// --- Карта "компетенция -> вопросы" ---
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

// --- Fallback: если к компетенции не привязали вопросы, используем категории из questions ---
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
            $alias = $syn[$combNorm];
            $qids = $groupCat[$alias] ?? null;
        }

        if (!$qids) {
            $bestKey = null; $bestScore = -1;
            foreach ($groupCat as $k => $ids) {
                $score = -1;
                if (mb_strpos($k, $combNorm) !== false || mb_strpos($combNorm, $k) !== false) {
                    $score = max(mb_strlen($k,'UTF-8'), mb_strlen($combNorm,'UTF-8'));
                }
                if ($score > $bestScore) { $bestScore = $score; $bestKey = $k; }
            }
            if ($bestKey !== null && $bestScore >= 0) $qids = $groupCat[$bestKey];
        }

        $map[$cid] = $qids ?: [];
    }
}

// --- Правила ролей и итог по компетенции ---
// Роли, которые учитываем
$roles      = ['manager','colleague','subordinate'];
$roleLabels = ['manager'=>'Руководитель','colleague'=>'Коллеги','subordinate'=>'Подчинённые'];

// Итог по компетенции с динамическими долями ролей:
// присутствуют 3 роли → по 1/3; 2 роли → 50/50; 1 роль → 100%
function avgByPresentRoles(array $avgByRole): ?float {
    $vals = [];
    foreach (['manager','colleague','subordinate'] as $r) {
        if ($avgByRole[$r] !== null) $vals[] = (float)$avgByRole[$r];
    }
    $n = count($vals);
    if ($n === 0) return null;
    return array_sum($vals) / $n;
}

// --- Расчёт M/C/S и итогов по компетенциям ---
$results = []; // $results[fio][combination_id] = ['M'=>..., 'C'=>..., 'S'=>..., 'W'=>...]

foreach ($targets as $t) {
    $fio = $t['fio']; $tid = (int)$t['target_id'];
    $results[$fio] = [];

    foreach ($combIds as $cid) {
        $qids = $map[$cid] ?? [];
        if (!$qids) { $results[$fio][$cid] = ['M'=>null,'C'=>null,'S'=>null,'W'=>null]; continue; }

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

        $weighted = avgByPresentRoles($avgByRole);

        $results[$fio][$cid] = [
            'M' => $avgByRole['manager'],
            'C' => $avgByRole['colleague'],
            'S' => $avgByRole['subordinate'],
            'W' => $weighted,
        ];
    }
}

// --- Генерация Excel (HTML) ---
$fmtMask = ($decimals > 0) ? "0." . str_repeat('0', $decimals) : "0";
$css = "
.num{mso-number-format:'{$fmtMask}';text-align:center}
.txt{text-align:center}
th{font-weight:bold;text-align:center}
";

$fname = 'competency_breakdown_'.$procedureId.'.xls';
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
    <th colspan="<?= 1 + count($competencies)*4 ?>">Детализация оценки по компетенциям (M/C/S + итог)</th>
  </tr>
  <tr>
    <th colspan="<?= 1 + count($competencies)*4 ?>"><?= htmlspecialchars($procedure['title']) ?> — <?= $year ?> г.</th>
  </tr>

  <tr>
    <th rowspan="2">Сотрудник</th>
    <?php foreach ($competencies as $c): ?>
      <th colspan="4"><?= htmlspecialchars($c['name']) ?></th>
    <?php endforeach; ?>
  </tr>
  <tr>
    <?php foreach ($competencies as $c): ?>
      <th>M</th><th>C</th><th>S</th><th>Итог</th>
    <?php endforeach; ?>
  </tr>

  <?php foreach ($results as $fio => $byCid): ?>
    <tr>
      <td class="txt"><?= htmlspecialchars($fio) ?></td>
      <?php foreach ($competencies as $c):
            $cid = (int)$c['id'];
            $row = $byCid[$cid] ?? ['M'=>null,'C'=>null,'S'=>null,'W'=>null];
            $M = $row['M']; $C = $row['C']; $S = $row['S']; $W = $row['W']; ?>
        <?= ($M === null) ? '<td class="txt">-</td>' : '<td class="num">'.fmt_dec($M, $decimals).'</td>' ?>
        <?= ($C === null) ? '<td class="txt">-</td>' : '<td class="num">'.fmt_dec($C, $decimals).'</td>' ?>
        <?= ($S === null) ? '<td class="txt">-</td>' : '<td class="num">'.fmt_dec($S, $decimals).'</td>' ?>
        <?= ($W === null) ? '<td class="txt">-</td>' : '<td class="num">'.fmt_dec($W, $decimals).'</td>' ?>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
</table>

<p style="font-size:12px;color:#555;margin-top:10px;">
  Примечание: усреднение по ролям — равными долями только по реально присутствующим ролям (3→1/3; 2→1/2; 1→1).
  Числа в таблице усечены до <?= (int)$decimals ?> знаков (визуальная политика Excel без ROUND).
</p>

</body>
</html>