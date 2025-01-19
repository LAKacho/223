<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Получаем ID пользователя и теста
$userId = $_SESSION['user_id'];
$testId = $_GET['test_id'] ?? null;

// Проверка, выбран ли тест
if (!$testId) {
    echo "<h2>Выберите доступный тест:</h2>";
    $stmt = $pdo->prepare("SELECT test_id FROM user_test_access WHERE user_id = :user_id AND access_level = 1 AND completed = FALSE");
    $stmt->execute(['user_id' => $userId]);
    $userTestAccess = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($userTestAccess as $allowedTestId) {
        echo "<a href='test.php?test_id=" . urlencode($allowedTestId) . "'>" . htmlspecialchars($allowedTestId) . "</a><br>";
    }
    exit;
}

// Проверка доступа и статуса теста
$stmt = $pdo->prepare("SELECT access_level, completed FROM user_test_access WHERE user_id = :user_id AND test_id = :test_id");
$stmt->execute(['user_id' => $userId, 'test_id' => $testId]);
$access = $stmt->fetch();

if (!$access || $access['access_level'] != 1 || $access['completed']) {
    die("У вас нет доступа к этому тесту или тест уже завершен.");
}

// Директория с тестами
$testDir = __DIR__ . '/data/tests/';
$testFilePath = $testDir . $testId . '.json';

if (!file_exists($testFilePath)) {
    die("Тест не найден.");
}

// Загрузка теста из JSON файла
$testData = json_decode(file_get_contents($testFilePath), true);
$timeLimit = $testData['time_limit']; // Время в секундах

// Инициализация времени начала теста в сессии
if (!isset($_SESSION['start_time'])) {
    $_SESSION['start_time'] = time();
}

// Вычисляем оставшееся время
$elapsedTime = time() - $_SESSION['start_time'];
$remainingTime = $timeLimit - $elapsedTime;

// Если время истекло, перенаправляем на страницу результатов
if ($remainingTime <= 0) {
    header('Location: results.php');
    exit;
}

// Инициализация теста
if (!isset($_SESSION['questions']) || $_SESSION['test_id'] != $testId) {
    $questions = $testData['questions'];
    shuffle($questions);
    $_SESSION['questions'] = array_slice($questions, 0, $testData['display_questions']);
    $_SESSION['current_question'] = 0;
    $_SESSION['answers'] = [];
    $_SESSION['test_id'] = $testId;
}

// Функция для сохранения ответа пользователя
function saveUserAnswer($userId, $testId, $questionId, $answer, $isCorrect) {
    global $pdo;

    $stmt = $pdo->prepare("INSERT INTO user_answers (user_id, test_id, question_id, answer, is_correct, answer_time) 
                           VALUES (:user_id, :test_id, :question_id, :answer, :is_correct, NOW())");
    $stmt->execute([
        'user_id' => $userId,
        'test_id' => $testId,
        'question_id' => $questionId,
        'answer' => json_encode($answer),
        'is_correct' => $isCorrect
    ]);
}

// Обработка отправки ответа
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $currentQuestionIndex = $_SESSION['current_question'];
    $question = $_SESSION['questions'][$currentQuestionIndex] ?? null;

    if ($question) {
        $userAnswer = $_POST['answer'] ?? [];
        $correctAnswer = $question['correct'] ?? [];

        $isCorrect = ($question['type'] === 'multiple')
            ? empty(array_diff($userAnswer, $correctAnswer)) && empty(array_diff($correctAnswer, $userAnswer))
            : in_array($userAnswer[0], $correctAnswer);

        // Сохраняем ответ в сессии и базе данных
        $_SESSION['answers'][$currentQuestionIndex] = [
            'answer' => $userAnswer,
            'correct' => $isCorrect
        ];

        saveUserAnswer($userId, $testId, $question['id'], $userAnswer, $isCorrect);

        // Переход к следующему вопросу или завершение теста
        $_SESSION['current_question']++;
        if ($_SESSION['current_question'] >= count($_SESSION['questions'])) {
            header('Location: results.php');
            exit;
        } else {
            header("Location: test.php?test_id=" . urlencode($testId));
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Тестирование: <?= htmlspecialchars($testId) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script>
        let timeLeft = <?= $remainingTime ?>;

        // Функция для форматирования времени в MM:SS
        function formatTime(seconds) {
            const minutes = Math.floor(seconds / 60);
            const remainingSeconds = seconds % 60;
            return `${minutes}:${remainingSeconds < 10 ? '0' : ''}${remainingSeconds}`;
        }

        // Установка начального значения таймера в формате MM:SS
        document.addEventListener("DOMContentLoaded", function () {
            document.getElementById("timer").innerText = "Оставшееся время: " + formatTime(timeLeft);

            // Запуск обратного отсчета
            const countdown = setInterval(function() {
                if (timeLeft <= 0) {
                    clearInterval(countdown);
                    alert("Время теста истекло!");
                    window.location.href = "results.php";
                } else {
                    timeLeft--;
                    document.getElementById("timer").innerText = "Оставшееся время: " + formatTime(timeLeft);
                }
            }, 1000);
        });
    </script>
</head>
<body>
    <div class="test-container">
        <h2><?= htmlspecialchars($testId) ?></h2>
        <p id="timer">Оставшееся время: <?= floor($remainingTime / 60) ?>:<?= str_pad($remainingTime % 60, 2, '0', STR_PAD_LEFT) ?></p>

        <?php
        $currentQuestionIndex = $_SESSION['current_question'] ?? 0;
        $question = $_SESSION['questions'][$currentQuestionIndex] ?? null;

        if ($question): ?>
            <form action="test.php?test_id=<?= urlencode($testId) ?>" method="post">
                <p><strong>Вопрос <?= $currentQuestionIndex + 1 ?>:</strong> <?= htmlspecialchars($question['question']) ?></p>
                
                <?php foreach ($question['options'] as $index => $option) : ?>
                    <label>
                        <input type="<?= $question['type'] === 'multiple' ? 'checkbox' : 'radio' ?>" 
                               name="answer[]" 
                               value="<?= $index ?>">
                        <?= htmlspecialchars($option) ?>
                    </label><br>
                <?php endforeach; ?>

                <button type="submit">Ответить</button>
            </form>
        <?php else: ?>
            <p>Вопрос не найден. Пожалуйста, перезагрузите страницу или начните тест заново.</p>
        <?php endif; ?>
    </div>
</body>
</html>
