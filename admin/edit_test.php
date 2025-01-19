<?php
session_start();
require '../lib/PhpSpreadsheet/autoload.php'; // Подключаем PHPSpreadsheet

use PhpOffice\PhpSpreadsheet\IOFactory;

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: ../index.php');
    exit;
}

$file = $_GET['file'] ?? '';
$testPath = "../data/tests/$file";

if (!file_exists($testPath)) {
    die("Файл теста не найден.");
}

// Загрузка данных теста из JSON
$testData = json_decode(file_get_contents($testPath), true);

// Определяем начальный ID для новых вопросов
$maxId = 0;
foreach ($testData['questions'] as $question) {
    if (isset($question['id']) && $question['id'] > $maxId) {
        $maxId = $question['id'];
    }
}
$newId = $maxId + 1; // Начальный ID для новых вопросов

// Обработка загрузки файла Excel и добавления вопросов
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file']) && $_FILES['file']['error'] == UPLOAD_ERR_OK) {
    $file = $_FILES['file']['tmp_name'];

    try {
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        foreach ($rows as $index => $row) {
            if ($index === 0) continue; // Пропустить заголовок

            $questionText = $row[0];
            $correctAnswer = $row[1];
            $incorrectAnswers = array_slice($row, 2);

            // Создаем структуру вопроса с уникальным ID
            $questionData = [
                'id' => $newId++, // Назначаем уникальный ID и увеличиваем его для следующего вопроса
                'question' => $questionText,
                'correct' => [$correctAnswer],
                'options' => array_merge([$correctAnswer], $incorrectAnswers),
                'type' => count($incorrectAnswers) > 1 ? 'multiple' : 'single'
            ];

            $testData['questions'][] = $questionData;
        }

        file_put_contents($testPath, json_encode($testData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "Вопросы успешно добавлены!";
    } catch (Exception $e) {
        die("Ошибка при обработке файла: " . $e->getMessage());
    }
}

// Обработка добавления и редактирования вопросов вручную
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_question') {
        $newQuestion = [
            'id' => $newId++,  // Присваиваем уникальный ID новому вопросу
            'question' => $_POST['question'],
            'type' => $_POST['type'],
            'options' => array_filter($_POST['options'], 'strlen'),
            'correct' => array_map('intval', $_POST['correct'] ?? [])
        ];
        $testData['questions'][] = $newQuestion;
    } elseif ($_POST['action'] === 'edit_question') {
        $questionIndex = (int)$_POST['question_index'];
        $testData['questions'][$questionIndex]['question'] = $_POST['question'];
        $testData['questions'][$questionIndex]['type'] = $_POST['type'];
        $testData['questions'][$questionIndex]['options'] = array_filter($_POST['options'], 'strlen');
        $testData['questions'][$questionIndex]['correct'] = array_map('intval', $_POST['correct'] ?? []);
    }

    file_put_contents($testPath, json_encode($testData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    header("Location: edit_test.php?file=" . urlencode($file));
    exit;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактирование теста</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* Контейнер для каждого вопроса */
        .edit-test-container {
            max-width: 800px;
            margin: auto;
        }
        /* Стили для текстового поля вопроса */
        .question-input {
            width: 100%;
            padding: 10px;
            font-size: 1.1em;
            margin-bottom: 8px;
            resize: vertical;
            min-height: 80px;
            max-height: 200px;
        }
        /* Контейнер для текста вопроса, обрезка текста */
        .question-text {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        /* Стили для вариантов ответа */
        .option-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
        }
        .option-wrapper input[type="text"] {
            flex: 1;
            padding: 8px;
            font-size: 1em;
            height: 40px;
        }
        .option-wrapper label {
            margin-right: auto;
        }
        /* Компактная кнопка удаления */
        .small-button {
            padding: 4px 8px;
            font-size: 0.9em;
            cursor: pointer;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 3px;
            transition: background-color 0.3s ease;
        }
        .small-button:hover {
            background-color: #45a049;
        }
        .small-button.delete {
            background-color: #f44336;
            width: 20px;
            height: 20px;
            padding: 0;
            font-size: 1em;
            line-height: 20px;
            text-align: center;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .small-button.delete:hover {
            background-color: #e53935;
        }
    </style>
    <script>
        function addOptionField(containerId) {
            const container = document.getElementById(containerId);
            const optionIndex = container.childElementCount;
            const optionWrapper = document.createElement('div');
            optionWrapper.classList.add('option-wrapper');

            optionWrapper.innerHTML = `
                <input type="text" name="options[]" placeholder="Вариант ${optionIndex + 1}" required>
                <label>
                    <input type="checkbox" name="correct[]" value="${optionIndex}"> Верный
                </label>
                <button type="button" class="small-button delete" onclick="removeOptionField(this)">×</button>
            `;
            container.appendChild(optionWrapper);
        }

        function removeOptionField(button) {
            const optionWrapper = button.parentNode;
            optionWrapper.parentNode.removeChild(optionWrapper);
        }
    </script>
</head>
<body>
    <div class="edit-test-container">
        <h2>Редактирование теста: <span class="question-text"><?= htmlspecialchars($testData['title']) ?></span></h2>

        <!-- Список существующих вопросов с возможностью редактирования -->
        <h3>Существующие вопросы</h3>
        <ul>
            <?php foreach ($testData['questions'] as $index => $question): ?>
                <li>
                    <form action="edit_test.php?file=<?= urlencode($file) ?>" method="post">
                        <input type="hidden" name="action" value="edit_question">
                        <input type="hidden" name="question_index" value="<?= $index ?>">
                        
                        <label>Текст вопроса:</label>
                        <textarea name="question" class="question-input" required><?= htmlspecialchars($question['question']) ?></textarea>
                        
                        <label>Тип вопроса:</label>
                        <select name="type">
                            <option value="single" <?= $question['type'] === 'single' ? 'selected' : '' ?>>Один верный ответ</option>
                            <option value="multiple" <?= $question['type'] === 'multiple' ? 'selected' : '' ?>>Несколько верных ответов</option>
                        </select>

                        <label>Варианты ответа:</label>
                        <div id="options-container-<?= $index ?>">
                            <?php foreach ($question['options'] as $optionIndex => $option): ?>
                                <div class="option-wrapper">
                                    <input type="text" name="options[]" value="<?= htmlspecialchars($option) ?>" required>
                                    <label>
                                        <input type="checkbox" name="correct[]" value="<?= $optionIndex ?>" <?= in_array($optionIndex, $question['correct']) ? 'checked' : '' ?>> Верный
                                    </label>
                                    <button type="button" class="small-button delete" onclick="removeOptionField(this)">×</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="small-button" onclick="addOptionField('options-container-<?= $index ?>')">Добавить ответ</button>

                        <button type="submit" class="small-button">Сохранить изменения</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>

        <!-- Форма для добавления нового вопроса -->
        <h3>Добавить новый вопрос</h3>
        <form action="edit_test.php?file=<?= urlencode($file) ?>" method="post">
            <input type="hidden" name="action" value="add_question">
            
            <label>Текст вопроса:</label>
            <textarea name="question" class="question-input" required></textarea>
            
            <label>Тип вопроса:</label>
            <select name="type">
                <option value="single">Один верный ответ</option>
                <option value="multiple">Несколько верных ответов</option>
            </select>

            <label>Варианты ответа:</label>
            <div id="new-options-container">
                <div class="option-wrapper">
                    <input type="text" name="options[]" placeholder="Вариант 1" required>
                    <label>
                        <input type="checkbox" name="correct[]" value="0"> Верный
                    </label>
                    <button type="button" class="small-button delete" onclick="removeOptionField(this)">×</button>
                </div>
                <div class="option-wrapper">
                    <input type="text" name="options[]" placeholder="Вариант 2" required>
                    <label>
                        <input type="checkbox" name="correct[]" value="1"> Верный
                    </label>
                    <button type="button" class="small-button delete" onclick="removeOptionField(this)">×</button>
                </div>
            </div>
            <button type="button" class="small-button" onclick="addOptionField('new-options-container')">Добавить ответ</button>

            <button type="submit" class="small-button">Добавить вопрос</button>
        </form>

        <h3>Импорт вопросов из Excel</h3>
        <form action="edit_test.php?file=<?= urlencode($file) ?>" method="post" enctype="multipart/form-data">
            <label>Загрузите файл с вопросами (Excel):</label>
            <input type="file" name="file" accept=".xlsx, .xls" required><br><br>
            <button type="submit" class="small-button">Импортировать вопросы</button>
        </form>

        <a href="manage_tests.php">Вернуться к управлению тестами</a>
    </div>
</body>
</html>
