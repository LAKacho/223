<?php
// export_group_answers_csv.php
// CSV-выгрузка всех ответов по процедуре: на кого, кто, роль, вопрос, балл.

require 'config.php';

// --- Вход
$procedureId = isset($_GET['procedure_id']) ? (int)$_GET['procedure_id'] : 0;
if ($procedureId <= 0) {
    http_response_code(400);
    exit('Нужно передать ?procedure_id=');
}

// Разделитель для CSV (в русской локали Excel чаще ожидает ;)
$sep = isset($_GET['sep']) ? $_GET['sep'] : ';';

// Включать ли самооценку (self)? по умолчанию да (1). Передайте include_self=0 чтобы исключить.
$includeSelf = isset($_GET['include_self']) ? (int)$_GET['include_self'] : 1;

// --- Процедура
$st = $pdo->prepare("SELECT id, title FROM evaluation_procedures WHERE id = ?");
$st->execute([$procedureId]);
$procedure = $st->fetch();
if (!$procedure) {
    exit('Процедура не найдена');
}

// --- Роль -> русская подпись
$roleLabel = array(
    'manager'      => 'Руководитель',
    'colleague'    => 'Коллега',
    'subordinate'  => 'Подчинённый',
    'self'         => 'Самооценка',
);

// --- SQL всех ответов по процедуре
// a.score может быть -1 (нет наблюдений) — в CSV отдадим пусто.
$sql = "
    SELECT
        ut.fio  AS target_fio,      -- кого оценивают
        ue.fio  AS evaluator_fio,   -- кто оценивает
        ep.role AS role_code,       -- роль
        q.text  AS question_text,   -- текст вопроса
        a.score AS score,           -- балл
        a.created_at AS answered_at -- если есть поле времени
    FROM answers a
    JOIN evaluation_participants ep ON ep.id = a.participant_id
    JOIN evaluation_targets et       ON et.id = ep.target_id
    JOIN users ut                    ON ut.id = et.user_id
    JOIN users ue                    ON ue.id = ep.evaluator_id
    JOIN questions q                 ON q.id = a.question_id
    WHERE et.procedure_id = ?
";
$params = array($procedureId);

if (!$includeSelf) {
    $sql .= " AND ep.role <> 'self' ";
}

$sql .= " ORDER BY ut.fio, ep.role, ue.fio, q.id";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

// --- Заголовки для отдачи файла
$fname = 'group_answers_'.$procedureId.'.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$fname.'"');

// Вставляем BOM, чтобы Excel корректно открыл кириллицу
echo "\xEF\xBB\xBF";

// Пишем CSV построчно (через fputcsv в поток вывода)
$fp = fopen('php://output', 'w');

// Настроим fputcsv под выбранный разделитель
if (!function_exists('fputcsv_custom')) {
    function fputcsv_custom($fp, $fields, $sep = ';', $enclosure = '"', $escape = '\\') {
        // Универсальный fputcsv (под старые PHP, если нужно задать кастомный разделитель)
        $line = '';
        $first = true;
        foreach ($fields as $field) {
            if (!$first) {
                $line .= $sep;
            }
            $first = false;

            // Приведём к строке
            if (is_null($field)) {
                $field = '';
            } elseif (is_bool($field)) {
                $field = $field ? '1' : '0';
            } elseif (!is_string($field)) {
                $field = (string)$field;
            }

            // Экранирование
            $needQuotes = (strpos($field, $sep) !== false) ||
                          (strpos($field, "\n") !== false) ||
                          (strpos($field, "\r") !== false) ||
                          (strpos($field, $enclosure) !== false);

            $field = str_replace($enclosure, $enclosure.$enclosure, $field);
            if ($needQuotes) {
                $field = $enclosure.$field.$enclosure;
            }
            $line .= $field;
        }
        $line .= "\r\n";
        fwrite($fp, $line);
    }
}

// Шапка CSV
fputcsv_custom($fp, array(
    'Процедура',
    'Кого оценивали (ФИО)',
    'Кто оценивал (ФИО)',
    'Роль',
    'Вопрос',
    'Балл',
    'Когда ответили'
), $sep);

// Данные
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $role  = isset($roleLabel[$row['role_code']]) ? $roleLabel[$row['role_code']] : $row['role_code'];

    // Преобразуем балл: -1 -> пусто (н/д)
    $score = $row['score'];
    if ($score === null) {
        $scoreOut = '';
    } else {
        $score = (float)$score;
        $scoreOut = ($score < 0) ? '' : (string)$score;
    }

    $when = isset($row['answered_at']) ? $row['answered_at'] : '';

    fputcsv_custom($fp, array(
        $procedure['title'],
        $row['target_fio'],
        $row['evaluator_fio'],
        $role,
        $row['question_text'],
        $scoreOut,
        $when
    ), $sep);
}

fclose($fp);
exit;