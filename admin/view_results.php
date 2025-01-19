<?php
session_start();
require '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: ../index.php');
    exit;
}

// Получение всех попыток тестов из `test_results` или `user_attempts`
$stmt = $pdo->prepare("SELECT ua.user_id, ua.test_id, ua.score, ua.attempt_date, 
                              tr.start_time, tr.end_time, tr.total_time, tr.correct_answers, tr.total_questions, tr.passed
                       FROM user_attempts ua
                       LEFT JOIN test_results tr ON ua.user_id = tr.user_id AND ua.test_id = tr.test_id
                       ORDER BY ua.attempt_date DESC");
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

function saveUserAnswer($username, $test_id, $question_id, $answer) {
    global $pdo;
    
    // Сохранение ответа пользователя
    $stmt = $pdo->prepare("INSERT INTO user_answers (username, test_id, question_id, answer, answer_time) 
                           VALUES (:username, :test_id, :question_id, :answer, NOW())");
    $stmt->execute([
        'username' => $username,
        'test_id' => $test_id,
        'question_id' => $question_id,
        'answer' => $answer
    ]);
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Результаты тестов</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="results-container">
        <h2>Результаты тестов</h2>

        <?php if (count($results) > 0): ?>
            <table>
                <tr>
                    <th>ID пользователя</th>
                    <th>ID теста</th>
                    <th>Дата попытки</th>
                    <th>Оценка</th>
                    <th>Время начала</th>
                    <th>Время окончания</th>
                    <th>Общее время</th>
                    <th>Правильные ответы</th>
                    <th>Всего вопросов</th>
                    <th>Статус</th>
                </tr>
                <?php foreach ($results as $result): ?>
                    <tr>
                        <td><?= htmlspecialchars($result['user_id']) ?></td>
                        <td><?= htmlspecialchars($result['test_id']) ?></td>
                        <td><?= htmlspecialchars($result['attempt_date']) ?></td>
                        <td><?= htmlspecialchars($result['score']) ?>%</td>
                        <td><?= htmlspecialchars($result['start_time']) ?></td>
                        <td><?= htmlspecialchars($result['end_time']) ?></td>
                        <td><?= htmlspecialchars(gmdate("H:i:s", $result['total_time'])) ?></td>
                        <td><?= htmlspecialchars($result['correct_answers']) ?></td>
                        <td><?= htmlspecialchars($result['total_questions']) ?></td>
                        <td><?= $result['passed'] ? 'Пройдено' : 'Не пройдено' ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>Результаты тестов отсутствуют.</p>
        <?php endif; ?>
        
        <a href="../admin/admin.php">Назад к панели администратора</a>
    </div>
</body>
</html>
