<?php
require 'config.php';

$procedureId = isset($_GET['procedure_id']) ? (int)$_GET['procedure_id'] : 0;
if ($procedureId <= 0) { 
    http_response_code(400); 
    exit('Нужно передать ?procedure_id='); 
}

// Загружаем процедуру
$st = $pdo->prepare("SELECT id, title, start_date FROM evaluation_procedures WHERE id=?");
$st->execute([$procedureId]);
$procedure = $st->fetch();
if (!$procedure) exit('Процедура не найдена');

$year = $procedure['start_date'] 
    ? (new DateTime($procedure['start_date']))->format('Y') 
    : date('Y');

// Загружаем все ответы по процедуре
$sql = "
SELECT 
    et.id AS target_id,
    ut.fio AS target_fio,
    ue.fio AS evaluator_fio,
    ep.role,
    q.text AS question_text,
    a.score
FROM answers a
JOIN evaluation_participants ep ON ep.id = a.participant_id
JOIN evaluation_targets et ON et.id = ep.target_id
JOIN users ut ON ut.id = et.user_id         -- оцениваемый
JOIN users ue ON ue.id = ep.evaluator_id    -- оценщик
JOIN questions q ON q.id = a.question_id
WHERE et.procedure_id = ?
ORDER BY ut.fio, ue.fio, q.id
";
$st = $pdo->prepare($sql);
$st->execute([$procedureId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Готовим Excel (на самом деле HTML-таблица с расширением .xls)
$fname = 'procedure_answers_'.$procedureId.'.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$fname.'"');
echo "\xEF\xBB\xBF"; // BOM для UTF-8
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Ответы по процедуре <?= htmlspecialchars($procedure['title']) ?></title>
<style>
  td, th { border:1px solid #000; padding:4px; }
  table { border-collapse: collapse; }
</style>
</head>
<body>
<h3>Ответы по процедуре: <?= htmlspecialchars($procedure['title']) ?> (<?= $year ?> г.)</h3>
<table>
  <tr>
    <th>№</th>
    <th>Оцениваемый</th>
    <th>Оценщик</th>
    <th>Роль</th>
    <th>Вопрос</th>
    <th>Балл</th>
  </tr>
<?php 
$i=1;
foreach ($rows as $r): ?>
  <tr>
    <td><?= $i++ ?></td>
    <td><?= htmlspecialchars($r['target_fio']) ?></td>
    <td><?= htmlspecialchars($r['evaluator_fio']) ?></td>
    <td><?= htmlspecialchars($r['role']) ?></td>
    <td><?= htmlspecialchars($r['question_text']) ?></td>
    <td><?= $r['score'] !== null ? (float)$r['score'] : '-' ?></td>
  </tr>
<?php endforeach; ?>
</table>
</body>
</html>