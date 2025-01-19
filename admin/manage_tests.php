<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: ../index.php');
    exit;
}

// Папка для хранения JSON файлов с тестами
$testDirectory = '../data/tests/';
$tests = array_diff(scandir($testDirectory), ['..', '.']);

// Подключение к базе данных
require '../db.php';

// Получение количества попыток для каждого теста
$testAttempts = [];
foreach ($tests as $testFile) {
    // Декодируем имя файла для отображения
    $testId = urldecode(pathinfo($testFile, PATHINFO_FILENAME)); // Для поддержки кириллицы

    // Преобразование кодировки в запросе для совместимости
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_attempts WHERE CONVERT(test_id USING utf8mb4) = ?");
    $stmt->execute([$testId]);
    $testAttempts[$testFile] = $stmt->fetchColumn();
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление тестами</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .manage-tests-container {
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background-color: #f4f4f9;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .back-button {
            margin-bottom: 20px;
            padding: 8px 16px;
            background-color: #2196F3;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9em;
            transition: background-color 0.3s;
        }
        .back-button:hover {
            background-color: #1976d2;
        }
        h2 {
            text-align: center;
            margin-bottom: 20px;
            font-size: 1.5em;
            color: #333;
        }
        h3 {
            margin-top: 20px;
            font-size: 1.2em;
            color: #333;
        }
        ul {
            list-style: none;
            padding: 0;
        }
        ul li {
            padding: 10px;
            background-color: #fff;
            margin-bottom: 10px;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        ul li a {
            text-decoration: none;
            color: #4CAF50;
            font-weight: bold;
        }
        ul li a:hover {
            text-decoration: underline;
        }
        form {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 20px;
        }
        label {
            font-weight: bold;
        }
        input[type="text"], input[type="number"] {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        button {
            width: fit-content;
            padding: 10px 20px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.3s;
        }
        button:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>
    <div class="manage-tests-container">
        <h2>Управление тестами</h2>
        
        <!-- Список доступных тестов с количеством попыток -->
        <h3>Существующие тесты</h3>
        <ul>
            <?php foreach ($tests as $testFile): ?>
                <li>
                    <a href="edit_test.php?file=<?= urlencode($testFile) ?>">
                        <?= htmlspecialchars($testFile, ENT_QUOTES, 'UTF-8') ?> (Попыток: <?= $testAttempts[$testFile] ?? 0 ?>)
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <!-- Форма для создания нового теста -->
        <h3>Создать новый тест</h3>
        <form action="create_test.php" method="post">
            <label>Название теста:</label>
            <input type="text" name="test_title" required>
            <label>Максимальное время (секунды):</label>
            <input type="number" name="time_limit" required>
            <label>Количество вопросов для отображения:</label>
            <input type="number" name="display_questions" required>
            <label>Проходной балл (%):</label>
            <input type="number" name="passing_score" required>
            <button type="submit">Создать тест</button>
        </form>
        <a href="admin.php" class="back-button">Назад</a>
    </div>
</body>
</html>
