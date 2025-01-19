<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$userId = $_SESSION['user_id'];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Личный кабинет</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Общий контейнер */
        .dashboard-container {
            display: flex;
            max-width: 1200px;
            margin: 50px auto;
            padding: 20px;
        }

        /* Меню навигации */
        .sidebar {
            width: 250px;
            background-color: #f5f5f5;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            margin-right: 20px;
        }

        .sidebar h3 {
            font-size: 1.2em;
            color: #333;
            margin-bottom: 20px;
        }

        .sidebar a {
            display: block;
            color: #4CAF50;
            padding: 10px;
            border-radius: 5px;
            text-decoration: none;
            transition: background-color 0.3s;
        }

        .sidebar a:hover {
            background-color: #e0f7fa;
        }

        /* Основная панель контента */
        .main-content {
            flex-grow: 1;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .main-content h2 {
            font-size: 1.8em;
            color: #333;
            margin-bottom: 20px;
        }

        /* Список тестов */
        .test-list a {
            display: block;
            padding: 10px;
            margin: 10px 0;
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-decoration: none;
            color: #333;
            transition: background-color 0.3s ease;
        }

        .test-list a:hover {
            background-color: #e0f7fa;
            border-color: #4CAF50;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Боковое меню навигации -->
        <div class="sidebar">
            <h3>Меню</h3>
            <a href="dashboard.php">Мои тесты</a>
            <a href="profile.php">Профиль</a>
            <a href="settings.php">Настройки</a>
            <a href="help.php">Помощь</a>
            <a href="logout.php">Выход</a>
        </div>

        <!-- Основная панель контента -->
        <div class="main-content">
            <h2>Мои тесты</h2>
            <div class="test-list">
                <?php
                // Получаем доступные тесты для пользователя
                require 'db.php';
                $stmt = $pdo->prepare("SELECT test_id FROM user_test_access WHERE user_id = :user_id AND access_level = 1 AND completed = FALSE");
                $stmt->execute(['user_id' => $userId]);
                $userTestAccess = $stmt->fetchAll(PDO::FETCH_COLUMN);

                if (empty($userTestAccess)) {
                    echo "<p>Нет доступных тестов.</p>";
                } else {
                    foreach ($userTestAccess as $allowedTestId) {
                        echo "<a href='test_player.php?test_id=" . urlencode($allowedTestId) . "'>" . htmlspecialchars($allowedTestId) . "</a>";
                    }
                }
                ?>
            </div>
        </div>
    </div>
</body>
</html>