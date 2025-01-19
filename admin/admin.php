<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

// Логика для выхода из аккаунта
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: ../index.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* Основные стили для контейнера админ-панели */
        .admin-container {
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background-color: #f4f4f9;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        .admin-container h2 {
            margin-bottom: 20px;
            font-size: 1.5em;
            color: #333;
        }

        /* Стили навигации */
        nav ul {
            list-style-type: none;
            padding: 0;
        }
        nav ul li {
            margin: 10px 0;
        }
        nav ul li a {
            display: flex;
            align-items: center;
            justify-content: start;
            padding: 8px;
            text-decoration: none;
            color: #333;
            font-weight: bold;
            border-radius: 8px;
            transition: background-color 0.3s ease;
        }
        nav ul li a:hover {
            background-color: #e0e0e0;
        }

        /* Стили для иконки */
        .nav-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background-color: #4CAF50;
            color: white;
            border-radius: 50%;
            font-size: 1.2em;
            margin-right: 10px;
            transition: background-color 0.3s ease;
        }
        nav ul li a:hover .nav-icon {
            background-color: #45a049;
        }

        /* Текст рядом с иконками */
        .nav-text {
            font-size: 1em;
            color: #333;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <h2>Добро пожаловать в Админ-панель</h2>
        <nav>
            <ul>
                <li>
                    <a href="manage_users.php">
                        <div class="nav-icon">👥</div>
                        <span class="nav-text">Управление пользователями</span>
                    </a>
                </li>
                <li>
                    <a href="manage_tests.php">
                        <div class="nav-icon">📑</div>
                        <span class="nav-text">Управление тестами</span>
                    </a>
                </li>
                <li>
                    <a href="manage_access.php">
                        <div class="nav-icon">🔑</div>
                        <span class="nav-text">Управление доступом к тестам</span>
                    </a>
                </li>
                <li>
                    <a href="view_results.php">
                        <div class="nav-icon">📊</div>
                        <span class="nav-text">Просмотр результатов</span>
                    </a>
                </li>
                <li>
                    <a href="?logout=true">
                        <div class="nav-icon">🚪</div>
                        <span class="nav-text">Выйти</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</body>
</html>
