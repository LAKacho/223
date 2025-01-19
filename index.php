<?php
session_start();
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Проверка пользователя в базе данных
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Прямое сравнение пароля без хэширования
    if ($user && $password === $user['password']) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        
        // Перенаправление в админ-панель, если роль — admin
        if ($user['role'] === 'admin') {
            header('Location: admin/admin.php');
        } else {
            header('Location: dashboard.php'); // Перенаправление на тест для обычных пользователей
        }
        exit;
    } else {
        $error = "Неверный логин или пароль!";
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Онлайн Тестирование - Вход</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="login-container">
        <h2>Вход в систему</h2>
        <?php if (isset($error)) { echo "<p style='color: red;'>$error</p>"; } ?>
        <form action="index.php" method="post">
            <label for="username">Логин:</label>
            <input type="text" name="username" required>
            
            <label for="password">Пароль:</label>
            <input type="password" name="password" required>

            <button type="submit">Войти</button>
        </form>
    </div>
</body>
</html>
