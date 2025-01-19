<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$userId = $_SESSION['user_id'];
$testId = $_GET['test_id'] ?? null;

if (!$testId) {
    header('Location: dashboard.php');
    exit;
}

// Проверка доступа к тесту
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

// Проверка, удалось ли декодировать JSON файл
if (!$testData) {
    die("Не удалось загрузить данные теста. Проверьте правильность файла JSON.");
}

$timeLimit = $testData['time_limit'];

// Инициализация времени начала теста в сессии
if (!isset($_SESSION['start_time']) || $_SESSION['test_id'] != $testId) {
    $_SESSION['start_time'] = time();
    $_SESSION['test_id'] = $testId;

    // Проверка на наличие массива вопросов и извлечение нужного количества вопросов
    if (!isset($testData['questions']) || !is_array($testData['questions'])) {
        die("Ошибка в структуре теста: вопросы не найдены.");
    }
    
    // Проверяем и добавляем уникальный `id` для каждого вопроса
    foreach ($testData['questions'] as $index => &$question) {
        if (!isset($question['id'])) {
            $question['id'] = $index + 1; // Присваиваем порядковый номер как `id`
        }
    }
    unset($question); // Завершаем ссылку на последний элемент

    $questions = $testData['questions'];
    shuffle($questions);
    $_SESSION['questions'] = array_slice($questions, 0, $testData['display_questions']);
    $_SESSION['current_question'] = 0;
    $_SESSION['answers'] = [];
}


// Рассчет времени
$elapsedTime = time() - $_SESSION['start_time'];
$remainingTime = $timeLimit - $elapsedTime;

if ($remainingTime <= 0) {
    header('Location: results.php');
    exit;
}
// Функция для сохранения ответа пользователя в базе данных
function saveUserAnswer($userId, $testId, $questionId, $answer, $isCorrect) {
    global $pdo;

    $stmt = $pdo->prepare("INSERT INTO user_answers (user_id, test_id, question_id, answer, is_correct, answer_time) 
                           VALUES (:user_id, :test_id, :question_id, :answer, :is_correct, NOW())");
    $stmt->execute([
        'user_id' => $userId,
        'test_id' => $testId,
        'question_id' => $questionId,
        'answer' => json_encode($answer),  // Сохраняем ответ как JSON
        'is_correct' => $isCorrect
    ]);
}

// Сохранение ответов
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $currentQuestionIndex = $_SESSION['current_question'];
    $question = $_SESSION['questions'][$currentQuestionIndex] ?? null;

    if ($question) {
        $userAnswer = $_POST['answer'] ?? [];
        $correctAnswer = $question['correct'] ?? [];

        $isCorrect = ($question['type'] === 'multiple')
            ? empty(array_diff($userAnswer, $correctAnswer)) && empty(array_diff($correctAnswer, $userAnswer))
            : in_array($userAnswer[0], $correctAnswer);

        $_SESSION['answers'][$currentQuestionIndex] = [
            'answer' => $userAnswer,
            'correct' => $isCorrect
        ];
		

        saveUserAnswer($userId, $testId, $question['id'], $userAnswer, $isCorrect);

        $_SESSION['current_question']++;
        if ($_SESSION['current_question'] >= count($_SESSION['questions'])) {
            header('Location: results.php');
            exit;
        } else {
            header("Location: test_player.php?test_id=" . urlencode($testId));
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
    <style>
        /* Основной контейнер с рамкой */
        .test-container {
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            border: 2px solid #4CAF50;
            border-radius: 8px;
            background-color: #f9f9f9;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        /* Заголовок теста */
        .test-container h2 {
            color: #333;
            font-size: 1.8em;
            text-align: center;
            margin-bottom: 10px;
        }

        /* Таймер */
        #timer {
            font-size: 1.2em;
            font-weight: bold;
            color: #e53935;
            text-align: center;
            margin: 10px 0 20px;
        }

        /* Вопрос */
        .question-text {
            font-size: 1.1em;
            margin-bottom: 15px;
            color: #333;
        }

        /* Опции ответов с обозначениями */
        .test-container label {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin: 8px 0;
            cursor: pointer;
            transition: background-color 0.3s ease, border-color 0.3s ease;
			
        }

        /* Кружок для одного варианта */
        .radio-icon {
		min-width: 16px;
		min-height: 16px;
		border-radius: 50%;
		border: 2px solid #4CAF50;
		margin-right: 12px;
		background-color: white;
		display: inline-block;
		flex-shrink: 0; /* Зафиксированный размер */
		}

        /* Квадрат для нескольких вариантов */
        .checkbox-icon {
		min-width: 16px;
		min-height: 16px;
		border-radius: 3px;
		border: 2px solid #4CAF50;
		margin-right: 12px;
		background-color: white;
		display: inline-block;
		flex-shrink: 0; /* Зафиксированный размер */
		}
		
		/* Опции ответов с обозначениями */
		.test-container label {
		display: flex;
		align-items: flex-start; /* Выравнивание по верхнему краю */
		padding: 8px 12px;
		background-color: #fff;
		border: 1px solid #ddd;
		border-radius: 5px;
		margin: 8px 0;
		cursor: pointer;
		transition: background-color 0.3s ease, border-color 0.3s ease;
		word-wrap: break-word; /* Перенос длинного текста */
		word-break: break-word;
		}

        /* Стили для выбранного ответа */
        .test-container input[type="radio"]:checked + label .radio-icon,
        .test-container input[type="checkbox"]:checked + label .checkbox-icon {
            background-color: #4CAF50;
        }

        /* Hover эффект на опциях */
        .test-container label:hover {
            background-color: #f1f1f1;
        }

        /* Скрываем инпуты */
        .test-container input[type="radio"],
        .test-container input[type="checkbox"] {
            display: none;
        }

        /* Кнопка отправки ответа */
        .test-container button {
            display: block;
            width: 100%;
            padding: 12px;
            font-size: 1.1em;
            background-color: #4CAF50;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        /* Кнопка неактивна */
        .test-container button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }

        /* Hover эффект для кнопки */
        .test-container button:hover:enabled {
            background-color: #45a049;
        }
    </style>
    <script>
        let timeLeft = <?= $remainingTime ?>;

        function formatTime(seconds) {
            const minutes = Math.floor(seconds / 60);
            const remainingSeconds = seconds % 60;
            return `${minutes}:${remainingSeconds < 10 ? '0' : ''}${remainingSeconds}`;
        }

        // Функция для активации кнопки, если выбран ответ
        function checkAnswerSelection() {
            const answers = document.querySelectorAll('input[name="answer[]"]');
            const submitButton = document.getElementById('submit-button');
            let isAnyAnswerSelected = false;

            answers.forEach(answer => {
                if (answer.checked) {
                    isAnyAnswerSelected = true;
                }
            });

            submitButton.disabled = !isAnyAnswerSelected;
        }

        document.addEventListener("DOMContentLoaded", function () {
            document.getElementById("timer").innerText = "Оставшееся время: " + formatTime(timeLeft);

            // Запускаем таймер
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

            // Ставим обработчик для активации кнопки при выборе ответа
            document.querySelectorAll('input[name="answer[]"]').forEach(answer => {
                answer.addEventListener('change', checkAnswerSelection);
            });

            // Проверка при загрузке, на случай возврата к вопросу
            checkAnswerSelection();
        });
    </script>
</head>
<body>
    <div class="test-container">
        <h2>Тест: <?= htmlspecialchars($testId) ?></h2>
        <p id="timer"></p>

        <?php
        $currentQuestionIndex = $_SESSION['current_question'] ?? 0;
        $question = $_SESSION['questions'][$currentQuestionIndex] ?? null;

        if ($question): ?>
            <form action="test_player.php?test_id=<?= urlencode($testId) ?>" method="post">
                <p class="question-text"><strong>Вопрос <?= $currentQuestionIndex + 1 ?>:</strong> <?= htmlspecialchars($question['question']) ?></p>
                
                <?php foreach ($question['options'] as $index => $option) : ?>
                    <input type="<?= $question['type'] === 'multiple' ? 'checkbox' : 'radio' ?>" 
                           id="option<?= $index ?>" 
                           name="answer[]" 
                           value="<?= $index ?>" 
                           style="display:none;">
                    <label for="option<?= $index ?>">
                        <?php if ($question['type'] === 'multiple'): ?>
                            <span class="checkbox-icon"></span>
                        <?php else: ?>
                            <span class="radio-icon"></span>
                        <?php endif; ?>
                        <?= htmlspecialchars($option) ?>
                    </label>
                <?php endforeach; ?>

                <!-- Кнопка отправки с начальным состоянием disabled -->
                <button type="submit" id="submit-button" disabled>Ответить</button>
            </form>
        <?php else: ?>
            <p>Вопрос не найден. Пожалуйста, перезагрузите страницу или начните тест заново.</p>
        <?php endif; ?>
    </div>
</body>
</html>
