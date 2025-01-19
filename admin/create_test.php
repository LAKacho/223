<?php
session_start();
require '../lib/PhpSpreadsheet/autoload.php'; // Локальное подключение PHPSpreadsheet

use PhpOffice\PhpSpreadsheet\IOFactory;

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = mb_convert_encoding($_POST['test_title'], 'UTF-8');
    $timeLimit = (int)$_POST['time_limit'];
    $displayQuestions = (int)$_POST['display_questions'];
    $passingScore = (int)$_POST['passing_score'];

    // Создаем структуру нового JSON теста
    $testData = [
        'title' => $title,
        'time_limit' => $timeLimit,
        'display_questions' => $displayQuestions,
        'passing_score' => $passingScore,
        'questions' => []
    ];

    // Используем urlencode для создания корректного имени файла
    $filename = '../data/tests/' . urlencode($title) . '.json';

    // Сохраняем новый JSON тест
    if (!file_put_contents($filename, json_encode($testData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        die("Ошибка при сохранении файла теста.");
    }

    // Проверка загрузки файла Excel
    if (isset($_FILES['file']) && $_FILES['file']['error'] == UPLOAD_ERR_OK) {
        $file = $_FILES['file']['tmp_name'];

        try {
            // Чтение Excel файла
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            // Начальный ID для вопросов
            $questionId = 1;

            foreach ($rows as $index => $row) {
                if ($index === 0) continue; // Пропустить заголовок (если есть)

                $questionText = mb_convert_encoding($row[0], 'UTF-8');
                $correctAnswer = mb_convert_encoding($row[1], 'UTF-8');
                $incorrectAnswers = array_map(fn($answer) => mb_convert_encoding($answer, 'UTF-8'), array_filter(array_slice($row, 2)));

                // Форматируем данные вопроса с уникальным ID
                $questionData = [
                    'id' => $questionId++,  // Создаем уникальный ID
                    'question' => $questionText,
                    'correct' => [$correctAnswer],
                    'options' => array_merge([$correctAnswer], $incorrectAnswers),
                    'type' => count($incorrectAnswers) > 1 ? 'multiple' : 'single'
                ];

                // Добавляем вопрос в структуру теста
                $testData['questions'][] = $questionData;
            }

            // Сохраняем обновленные данные обратно в JSON файл
            if (!file_put_contents($filename, json_encode($testData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                die("Ошибка при обновлении файла теста.");
            }
            
            echo "Тест и вопросы успешно созданы!";
        } catch (Exception $e) {
            die("Ошибка при чтении файла Excel: " . $e->getMessage());
        }
    }

    header('Location: manage_tests.php');
    exit;
}
?>
