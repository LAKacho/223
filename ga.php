<?php
// export_group_report_xls.php
// Групповой отчёт (Сотрудник × Роль × Компетенции) → Excel-совместимый .xls (HTML)
// Считает напрямую из answers, НИЧЕГО не пишет в БД.

// ---------- базовые настройки вывода чисел ----------
ini_set('serialize_precision', -1);
ini_set('precision', 15);

// helper: печать числа в Excel без лишних нулей/точки, без округления
function xls_raw_number($v): string {
    if ($v === null) return '-';
    return rtrim(rtrim(sprintf('%.15F', (float)$v), '0'), '.');
}

require 'config.php';

$procedureId = isset($_GET['procedure_id']) ? (int)$_GET['procedure_id'] : 0;
if ($procedureId <= 0) { http_response_code(400); exit('Нужно передать ?procedure_id='); }

/* ───────── Процедура ───────── */
$st = $pdo->prepare("SELECT id, title, start_date FROM evaluation_procedures WHERE id=?");
$st->execute([$procedureId]);
$procedure = $st->fetch() ?: exit('Процедура не найдена');
$year = $procedure['start_date'] ? (new DateTime($procedure['start_date']))->format('Y') : date('Y');

/* ─────── Компетенции в процедуре ─────── */
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

/* ───────── Оцениваемые (targets) ───────── */
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

/* ───── Нормализация строк для сопоставления ───── */
$normalize = function(?string $s): string {
    if ($s === null) return '';
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/^[0-9.\-\s]+/u', '', $s);   // снять префиксы вида "09.2 "
    $s = preg_replace('/\s+/u', ' ', trim($s));
    return $s;
};

/* ───── Карта "компетенция → вопросы" (map) ───── */
$map = []; $hasLink = [];
foreach ($combIds as $cid) { $map[$cid] = []; $hasLink[$cid] = false; }

// 1) Явные связи
if ($combIds) {
    $in = implode(',', array_fill(0, count($combIds), '?'));
    $q  = $pdo->prepare("SELECT combination_id, question_id FROM combination_questions WHERE combination_id IN ($in)");
    $q->execute($combIds);
    foreach ($q as $row) {
        $map[(int)$row['combination_id']][] = (int)$row['question_id'];
        $hasLink[(int)$row['combination_id']] = true;
    }
}

// 2) Fallback: совпадение category ↔ name + синонимы + частичное совпадение
$needFallback = array_values(array_filter($combIds, fn($cid)=>!$hasLink[$cid]));
if ($needFallback) {
    $allQ = $pdo->query("SELECT id, category FROM questions")->fetchAll(PDO::FETCH_ASSOC);

    // нормализованная категория -> список вопрос-id
    $groupCat = [];
    foreach ($allQ as $row) {
        $k = $normalize($row['category']);
        $groupCat[$k][] = (int)$row['id'];
    }

    // словарь синонимов: комп → категория вопросов (оба нормализованные)
    $synRaw = [
        'ответственность' => 'ответственность за результат',
        'профессиональные знания замещаемой должности' => 'профессиональные знания ключевой должности',
        'профессиональные знания должностей, смежных к текущей' => 'профессиональные знания должностей, смежных к текущей должности',
    ];
    $syn = [];
    foreach ($synRaw as $k=>$v) { $syn[$normalize($k)] = $normalize($v); }

    foreach ($needFallback as $cid) {
        $combNorm = $normalize($combNames[$cid]);

        // 1) точное совпадение
        $qids = $groupCat[$combNorm] ?? null;

        // 2) по словарю синонимов
        if (!$qids && isset($syn[$combNorm])) {
            $alias = $syn[$combNorm];
            $qids = $groupCat[$alias] ?? null;
        }

        // 3) частичное совпадение (contains) — берём ближайшее
        if (!$qids) {
            $bestKey = null; $bestScore = -1;
            foreach ($groupCat as $k => $ids) {
                $score = -1;
                if (str_contains($k, $combNorm) || str_contains($combNorm, $k)) {
                    $score = max(strlen($k), strlen($combNorm));
                }
                if ($score > $bestScore) { $bestScore = $score; $bestKey = $k; }
            }
            if ($bestKey !== null && $bestScore >= 0) $qids = $groupCat[$bestKey];
        }

        $map[$cid] = $qids ?: [];
    }
}

/* ───── Роли и ярлыки ───── */
$roles = ['self','manager','colleague','subordinate'];
$roleLabel = [
    'self'        => 'Сам',
    'manager'     => 'Руководитель',
    'colleague'   => 'Коллега',
    'subordinate' => 'Подчинённый',
];

/* ───── Расчёт средних по роли для каждой компетенции ───── */
$matrix = []; // [fio][role][comb_id] = float|null

foreach ($targets as $t) {
    $fio = $t['fio']; $tid = (int)$t['target_id'];
    $matrix[$fio] = [];

    foreach ($roles as $role) {
        $matrix[$fio][$role] = [];
        foreach ($combIds as $cid) {
            $qids = $map[$cid] ?? [];
            if (!$qids) { $matrix[$fio][$role][$cid] = null; continue; }

            $ph  = implode(',', array_fill(0, count($qids), '?'));
            $sql = "
              SELECT AVG(CASE WHEN a.score >= 0 THEN a.score END) AS avg_score
              FROM answers a
              JOIN evaluation_participants ep ON ep.id = a.participant_id
              WHERE ep.target_id = ?
                AND ep.role = ?
                AND a.question_id IN ($ph)
            ";
            $stmt = $pdo->prepare($sql);
            $params = array_merge([$tid, $role], $qids);
            $stmt->execute($params);
            $avg = $stmt->fetchColumn();
            $matrix[$fio][$role][$cid] = ($avg !== null) ? (float)$avg : null;
        }
    }
}

/* ───── CSS и отдача как .xls ───── */
$css = '';
if (is_file(__DIR__.'/34.html')) {
    $tpl = file_get_contents(__DIR__.'/34.html');
    if (preg_match('~<style[^>]*>(.*?)</style>~is', $tpl, $m)) $css = $m[1];
}
// до 15 знаков после запятой, Excel покажет столько, сколько есть
$css .= ".num{mso-number-format:'0.###############';text-align:center}
.txt{text-align:center}.small{font-size:11px}";

$fname = 'group_report_roles_'.$procedureId.'.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$fname.'"');
// UTF-8 BOM для корректной кириллицы в Excel
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
    <td colspan="<?= 2 + count($competencies) ?>" style="font-weight:bold;">
      Групповой сравнительный отчёт (по процедуре)
    </td>
  </tr>
  <tr><td colspan="<?= 2 + count($competencies) ?>" style="font-weight:bold;"><?= $year ?> г.</td></tr>
  <tr><td colspan="<?= 2 + count($competencies) ?>">&nbsp;</td></tr>

  <tr class="rowHead">
    <td style="font-weight:bold;text-align:center;">Сотрудник</td>
    <td style="font-weight:bold;text-align:center;">Роль</td>
    <?php foreach ($competencies as $c): ?>
      <td style="font-weight:bold;text-align:center;"><?= htmlspecialchars($c['name']) ?></td>
    <?php endforeach; ?>
  </tr>

  <?php foreach ($targets as $t): ?>
    <?php $fio = $t['fio']; $first = true; ?>
    <?php foreach ($roles as $role): ?>
      <tr>
        <td class="txt"><?= $first ? htmlspecialchars($fio) : '' ?></td>
        <td class="txt"><?= $roleLabel[$role] ?></td>
        <?php foreach ($competencies as $c):
              $cid = (int)$c['id'];
              $v = $matrix[$fio][$role][$cid] ?? null; ?>
          <?= ($v === null)
                ? '<td class="txt">-</td>'
                : '<td class="num">'.xls_raw_number($v).'</td>' ?>
        <?php endforeach; ?>
      </tr>
      <?php $first = false; ?>
    <?php endforeach; ?>
  <?php endforeach; ?>
</table>

</body>
</html>