<?php
// export_answers_detailed_xls.php
// Выгрузка всех ответов по процедуре в Excel-совместимый .xls (HTML), без библиотек.
// Колонки: Оцениваемый • Оценивающий • Роль • Вопрос (текст) • Компетенция • Балл • Комментарий.

require 'config.php';

$procedureId  = isset($_GET['procedure_id']) ? (int)$_GET['procedure_id'] : 0;
$includeSelf  = !empty($_GET['include_self']); // 1 — включать самооценку, по умолчанию выключено
if ($procedureId <= 0) { http_response_code(400); exit('Нужно передать ?procedure_id='); }

// --- Процедура ---
$st = $pdo->prepare("SELECT id, title, start_date FROM evaluation_procedures WHERE id=?");
$st->execute([$procedureId]);
$procedure = $st->fetch();
if (!$procedure) exit('Процедура не найдена');
$procTitle = $procedure['title'] ?? ('Процедура #'.$procedureId);
$year = $procedure['start_date'] ? (new DateTime($procedure['start_date']))->format('Y') : date('Y');

// --- Роли (русские ярлыки) ---
$roleLabel = [
    'self'        => 'Сам',
    'manager'     => 'Руководитель',
    'colleague'   => 'Коллега',
    'subordinate' => 'Подчинённый',
];

// --- Данные: только фактически заполненные ответы (JOIN с answers) ---
$sql = "
  SELECT 
      u_t.fio             AS target_fio,
      u_e.fio             AS evaluator_fio,
      ep.role             AS role,
      q.text              AS question_text,
      q.category          AS question_category,
      a.score             AS score,
      a.comment           AS comment
  FROM evaluation_participants ep
  JOIN evaluation_targets et    ON et.id = ep.target_id
  JOIN users u_t                ON u_t.id = et.user_id               -- оцениваемый
  JOIN users u_e                ON u_e.id = ep.evaluator_id          -- оценивающий
  JOIN answers a                ON a.participant_id = ep.id          -- только реальные ответы
  LEFT JOIN questions q         ON q.id = a.question_id
  WHERE et.procedure_id = ?
    ".($includeSelf ? "" : "AND ep.role <> 'self'")."
  ORDER BY 
    u_t.fio,
    FIELD(ep.role,'manager','colleague','subordinate','self'),
    u_e.fio,
    q.id
";
$st = $pdo->prepare($sql);
$st->execute([$procedureId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// --- Excel-совместимый вывод ---
$css = "
.num{mso-number-format:'0';text-align:center}
.txt{text-align:left}
.head{font-weight:bold;text-align:center}
.small{font-size:11px}
td,th{vertical-align:middle}
";

$fname = 'answers_detailed_'.$procedureId.'.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename=\"'.$fname.'\"');
// UTF-8 BOM для корректной кириллицы
echo \"\\xEF\\xBB\\xBF\";
?>
<!DOCTYPE html>
<html>
<head>
<meta charset=\"utf-8\">
<title><?= htmlspecialchars($procTitle) ?></title>
<style><?= $css ?></style>
</head>
<body>

<table border=\"0\" cellspacing=\"0\" cellpadding=\"3\">
  <tr>
    <td class=\"head\" colspan=\"7\">Ответы по процедуре: <?= htmlspecialchars($procTitle) ?></td>
  </tr>
  <tr>
    <td class=\"head\" colspan=\"7\"><?= (int)$year ?> г.</td>
  </tr>
  <tr><td colspan=\"7\">&nbsp;</td></tr>
</table>

<table border=\"1\" cellspacing=\"0\" cellpadding=\"3\">
  <tr>
    <th class=\"head\">Оцениваемый (ФИО)</th>
    <th class=\"head\">Оценивающий (ФИО)</th>
    <th class=\"head\">Роль</th>
    <th class=\"head\">Вопрос</th>
    <th class=\"head\">Компетенция</th>
    <th class=\"head\">Балл</th>
    <th class=\"head\">Комментарий</th>
  </tr>

  <?php if (!$rows): ?>
    <tr><td class=\"txt\" colspan=\"7\">Данных нет (возможно, ещё нет ответов или фильтр исключил все строки).</td></tr>
  <?php else: ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class=\"txt\"><?= htmlspecialchars($r['target_fio'] ?? '') ?></td>
        <td class=\"txt\"><?= htmlspecialchars($r['evaluator_fio'] ?? '') ?></td>
        <td class=\"txt\"><?= htmlspecialchars($roleLabel[$r['role']] ?? $r['role'] ?? '') ?></td>
        <td class=\"txt\"><?= htmlspecialchars($r['question_text'] ?? '') ?></td>
        <td class=\"txt\"><?= htmlspecialchars($r['question_category'] ?? '') ?></td>
        <td class=\"num\"><?= ($r['score'] !== null && $r['score'] !== '') ? (float)$r['score'] : '' ?></td>
        <td class=\"txt\"><?= htmlspecialchars($r['comment'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
  <?php endif; ?>
</table>

<table border=\"0\" cellspacing=\"0\" cellpadding=\"4\" class=\"small\">
  <tr><td>&nbsp;</td></tr>
  <tr>
    <td>
      Примечание: выгружаются только фактически заполненные ответы (JOIN с <code>answers</code>).
      <?php if (!$includeSelf): ?>Самооценка исключена (добавьте <code>&include_self=1</code>, чтобы включить).<?php endif; ?>
    </td>
  </tr>
</table>

</body>
</html>