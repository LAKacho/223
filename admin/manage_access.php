<?php
session_start();
require '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: ../index.php');
    exit;
}

// Функция для сохранения ответа пользователя
function saveUserAnswer($userId, $testId, $questionId, $answer) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO user_answers (user_id, test_id, question_id, answer, answer_time) 
                           VALUES (:user_id, :test_id, :question_id, :answer, NOW())");
    $stmt->execute([
        'user_id' => $userId,
        'test_id' => $testId,
        'question_id' => $questionId,
        'answer' => $answer
    ]);
}

// Получение списка пользователей
$userQuery = $pdo->query("SELECT id, username FROM users");
$users = $userQuery->fetchAll(PDO::FETCH_ASSOC);

// Получение списка JSON файлов с тестами
$testDir = __DIR__ . '/../data/tests/';
$testFiles = array_filter(scandir($testDir), fn($file) => pathinfo($file, PATHINFO_EXTENSION) === 'json');

// Обновление доступа к тестам
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_access'])) {
    $userId = $_POST['user_id'];
    $selectedTests = $_POST['test_access'] ?? [];

    // Удаляем предыдущий доступ к тестам для данного пользователя
    $pdo->prepare("DELETE FROM user_test_access WHERE user_id = :user_id")->execute(['user_id' => $userId]);

    // Устанавливаем новый доступ к тестам
    $stmt = $pdo->prepare("INSERT INTO user_test_access (user_id, test_id, access_level) VALUES (:user_id, :test_id, 1)");
    foreach ($selectedTests as $testFile) {
        $testId = pathinfo($testFile, PATHINFO_FILENAME);
        $stmt->execute(['user_id' => $userId, 'test_id' => $testId]);
    }
    echo "<p class='success-message'>Доступ обновлен успешно.</p>";
}

// Импорт доступа из CSV
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['import_tests'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $filePath = $_FILES['csv_file']['tmp_name'];
        assignTestsFromCsv($filePath);
    } else {
        echo "<p class='error-message'>Ошибка загрузки файла.</p>";
    }
}

function assignTestsFromCsv($filePath) {
    global $pdo;
    if (($handle = fopen($filePath, "r")) !== FALSE) {
        fgetcsv($handle, 1000, ";"); // Пропуск заголовка
        while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
            $username = trim($data[0]);
            $test_id = trim($data[1]);

            // Проверка и создание пользователя
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username");
            $stmt->execute(['username' => $username]);
            $user_id = $stmt->fetchColumn();

            if (!$user_id) {
                $stmt = $pdo->prepare("INSERT INTO users (username) VALUES (:username)");
                $stmt->execute(['username' => $username]);
                $user_id = $pdo->lastInsertId();
            }

            // Назначение теста, если ещё не назначен
            $stmt = $pdo->prepare("INSERT IGNORE INTO user_test_access (user_id, test_id, access_level) VALUES (:user_id, :test_id, 1)");
            $stmt->execute(['user_id' => $user_id, 'test_id' => $test_id]);
        }
        fclose($handle);
        echo "<p class='success-message'>Назначение тестов завершено!</p>";
    } else {
        echo "<p class='error-message'>Ошибка открытия файла.</p>";
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление доступом к тестам</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            background-color: #f0f2f5;
            color: #333;
            margin: 0;
        }
        .container {
            max-width: 700px;
            margin: 20px auto;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background-color: #fff;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .header h2 {
            margin: 0;
            color: #333;
        }
        .btn-logout {
            padding: 10px 20px;
            font-size: 0.9em;
            color: white;
            background-color: #e53935;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-logout:hover {
            background-color: #d32f2f;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            background-color: #4CAF50;
            color: white;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.3s;
        }
        .btn:hover {
            background-color: #45a049;
        }
        label {
            display: block;
            margin-top: 10px;
            font-weight: bold;
            color: #555;
        }
        select, input[type="file"] {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
            margin-bottom: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .checkbox-container {
            margin-top: 10px;
        }
        .success-message {
            color: #4CAF50;
            font-weight: bold;
        }
        .error-message {
            color: #e53935;
            font-weight: bold;
        }
    </style>
    <script>
        function loadTests(userId) {
            fetch(`get_user_tests.php?user_id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    const checkboxes = document.querySelectorAll("input[name='test_access[]']");
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = data.includes(checkbox.value);
                    });
                });
        }
    </script>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Назначение доступа к тестам</h2>
            <a href="../admin/admin.php" class="btn-logout">Выйти</a>
        </div>

        <form method="post">
            <label>Выберите пользователя:</label>
            <select name="user_id" required onchange="loadTests(this.value)">
                <option value="">-- Выберите пользователя --</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                <?php endforeach; ?>
            </select>

            <label>Выберите доступные тесты:</label>
            <div class="checkbox-container">
                <?php foreach ($testFiles as $testFile): ?>
                    <?php $testId = pathinfo($testFile, PATHINFO_FILENAME); ?>
                    <div>
                        <input type="checkbox" name="test_access[]" value="<?= htmlspecialchars($testFile) ?>">
                        <?= htmlspecialchars($testId) ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" name="update_access" class="btn">Обновить доступ</button>
        </form>

        <h3>Импорт доступа пользователей из CSV (формат: username;test_id)</h3>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="csv_file" accept=".csv" required>
            <button type="submit" name="import_tests" class="btn">Импортировать</button>
        </form>
    </div>
</body>
</html>
