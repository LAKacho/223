<?php
// export_answers_detailed_xls.php
// Детальный отчёт: Кого оценивали, Кто оценивал (ФИО), Роль,
// Вопрос (текст), Категория (компетенция из questions.category), Балл.
// Включая self. Без внешних библиотек — HTML-таблица как XLS.

require 'config.php';

$procedureId = isset($_GET['procedure_id']) ? (int)$_GET['procedure_id'] : 0;
if ($procedureId <= 0) { http_response_code(400); exit('Нужно передать ?procedure_id='); }

$decimals = isset($_GET['dec']) ? max(0, (int)$_GET['dec']) : 2;

// усечение для отображения (как формат ячейки в Excel, а не математическое округление)
function trunc_dec($x, int $n): float {
    $p = pow(10, $n);
    return ($x >= 0) ? floor($x * $p) / $p : ceil($x * $p) / $p;
}
function fmt_dec($x, int $n): string {
    $v = trunc_dec((float)$x, $n);
    return number_format($v, $n, '.', '');
}

// Заголовок и год
$st = $pdo->prepare("SELECT id, title, start_date FROM evaluation_procedures WHERE id=?");
$st->execute([$procedureId]);
$procedure = $st->fetch() ?: exit('Процедура не найдена');
$year = $procedure['start_date'] ? (new DateTime($procedure['start_date']))->format('Y') : date('Y');

// Основной запрос
// Берём только ответы тех участников (ep), чьи target_id входят в evaluation_targets данной процедуры.
$sql = "
SELECT
  tu.fio                 AS target_fio,
  eu.fio                 AS evaluator_fio,
  ep.role                AS role,
  q.id                   AS question_id,
  q.text                 AS question_text,
  q.category             AS category,
  a.score                AS score
FROM answers a
JOIN evaluation_participants ep ON ep.id = a.participant_id
JOIN evaluation_targets et      ON et.id = ep.target_id
JOIN users eu                   ON eu.id = ep.user_id        -- кто оценивает
JOIN users tu                   ON tu.id = et.user_id        -- кого оценивают
JOIN questions q                ON q.id = a.question_id
WHERE et.procedure_id = ?
ORDER BY tu.fio, 
         FIELD(ep.role, 'manager','colleague','subordinate','self'),
         eu.fio,
         q.id
";
$st = $pdo->prepare($sql);
$st->execute([$procedureId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Преобразуем в вид «группы по target», как просили раньше
$byTarget = [];
foreach ($rows as $r) {
    $byTarget[$r['target_fio']][] = $r;
}

// CSS-формат под Excel (табличка)
$fmtMask = ($decimals > 0) ? "0." . str_repeat('0', $decimals) : "0";
$css = "
.num{mso-number-format:'{$fmtMask}';text-align:center}
.txt{text-align:left}
th{font-weight:bold;text-align:center}
.target{background:#f2f6ff;font-weight:bold}
.role{background:#fff7e6}
.sep{height:6px}
";

$fname = 'answers_detailed_'.$procedureId.'.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$fname.'"');
echo \"\\xEF\\xBB\\xBF\";
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
    <th colspan="6">
      Детальный отчёт по ответам (включая самооценку) — <?= htmlspecialchars($procedure['title']) ?>, <?= $year ?> г.
    </th>
  </tr>
  <tr>
    <th>Кого оценивали (ФИО)</th>
    <th>Кто оценивал (ФИО)</th>
    <th>Роль</th>
    <th>Категория (компетенция)</th>
    <th>Вопрос</th>
    <th>Балл</th>
  </tr>

<?php if (!$rows): ?>
  <tr><td colspan="6" class="txt">Ответов не найдено.</td></tr>
<?php else: ?>
  <?php foreach ($byTarget as $targetFio => $items): ?>
    <!-- строка-шапка для оцениваемого -->
    <tr class="target"><td class="txt" colspan="6"><?= htmlspecialchars($targetFio) ?></td></tr>

    <?php foreach ($items as $r):
          $role = $r['role'];
          // человекопонятные ярлыки ролей
          if     ($role==='manager')      $roleTitle='Руководитель';
          elseif ($role==='colleague')    $roleTitle='Коллега';
          elseif ($role==='subordinate')  $roleTitle='Подчинённый';
          elseif ($role==='self')         $roleTitle='Самооценка';
          else                            $roleTitle=$role;

          $score = $r['score'];
          // -1 / отрицательное = «недостаточно данных», покажем тире
          $cell = ($score === null || $score < 0) ? '<td class="num">-</td>'
                                                 : '<td class="num">'.fmt_dec($score,$decimals).'</td>';
    ?>
      <tr>
        <td class="txt"><?= htmlspecialchars($targetFio) ?></td>
        <td class="txt"><?= htmlspecialchars($r['evaluator_fio']) ?></td>
        <td class="txt"><?= htmlspecialchars($roleTitle) ?></td>
        <td class="txt"><?= htmlspecialchars((string)$r['category']) ?></td>
        <td class="txt"><?= htmlspecialchars((string)$r['question_text']) ?></td>
        <?= $cell ?>
      </tr>
    <?php endforeach; ?>

    <!-- пустая разделительная строка между сотрудниками -->
    <tr class="sep"><td colspan="6">&nbsp;</td></tr>
  <?php endforeach; ?>
<?php endif; ?>
</table>

<p style="font-size:12px;color:#555;margin-top:10px;">
  Балл «-» означает отсутствие наблюдения/ответа (score &lt; 0). Отображение чисел — с усечением до <?= (int)$decimals ?> знаков.
</p>

</body>
</html>