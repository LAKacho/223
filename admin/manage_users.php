<?php
session_start();
require '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: ../index.php');
    exit;
}

$error = '';
$success = '';

// Обработка удаления пользователя
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_user'])) {
    $userId = $_POST['user_id'];
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = :user_id");
    if ($stmt->execute(['user_id' => $userId])) {
        $success = "Пользователь успешно удален!";
    } else {
        $error = "Ошибка при удалении пользователя.";
    }
}

// Добавление пользователя
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user'])) {
    $username = $_POST['login'];
    $password = $_POST['password'];
    $role = $_POST['role'];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    $exists = $stmt->fetchColumn();

    if ($exists) {
        $error = "Пользователь с таким логином уже существует!";
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (:username, :password, :role)");
        $stmt->execute(['username' => $username, 'password' => $password, 'role' => $role]);
        $success = "Пользователь успешно добавлен!";
    }
}

// Импорт пользователей из CSV
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['import_excel'])) {
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] == 0) {
        $filePath = $_FILES['excel_file']['tmp_name'];
        if (($handle = fopen($filePath, 'r')) !== FALSE) {
            fgetcsv($handle, 1000, ";"); // Пропускаем первую строку, если это заголовок
            while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                $username = trim($data[0]);
                $password = trim($data[1]);
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
                $stmt->execute(['username' => $username]);
                $exists = $stmt->fetchColumn();
                if (!$exists) {
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (:username, :password, 'user')");
                    $stmt->execute(['username' => $username, 'password' => $password]);
                }
            }
            fclose($handle);
            $success = "Пользователи успешно импортированы!";
        } else {
            $error = "Ошибка при открытии файла.";
        }
    } else {
        $error = "Ошибка загрузки файла.";
    }
}

// Параметры поиска, сортировки и пагинации
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'id';
$order = $_GET['order'] ?? 'asc';
$validSortColumns = ['id', 'username', 'role'];
if (!in_array($sort, $validSortColumns)) {
    $sort = 'id';
}
$order = $order === 'desc' ? 'desc' : 'asc';

$usersPerPage = 50;
$page = max((int)($_GET['page'] ?? 1), 1);
$offset = ($page - 1) * $usersPerPage;

// Получение пользователей с учётом поиска, сортировки и пагинации
$stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE username LIKE :search ORDER BY $sort $order LIMIT :limit OFFSET :offset");
$stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
$stmt->bindValue(':limit', $usersPerPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Подсчёт общего количества пользователей для пагинации
$totalUsersStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username LIKE :search");
$totalUsersStmt->execute([':search' => '%' . $search . '%']);
$totalUsers = $totalUsersStmt->fetchColumn();
$totalPages = (int)ceil($totalUsers / $usersPerPage);

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление пользователями</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .manage-users-container {
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background-color: #f4f4f9;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        h2 {
            text-align: center;
            margin-bottom: 20px;
            font-size: 1.5em;
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 8px;
            font-size: 0.9em;
            text-align: center;
            border: 1px solid #ddd;
        }
        th {
            background-color: #4CAF50;
            color: white;
        }
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }
        .pagination a {
            margin: 0 5px;
            padding: 5px 10px;
            text-decoration: none;
            color: #4CAF50;
            border: 1px solid #4CAF50;
            border-radius: 4px;
            font-size: 0.9em;
        }
        .pagination a.active, .pagination a:hover {
            background-color: #4CAF50;
            color: white;
        }
        .search-sort-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .search-sort-container form input[type="text"] {
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9em;
        }
        .search-sort-container form button {
            padding: 5px 10px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9em;
        }
        .search-sort-container form button:hover {
            background-color: #45a049;
        }
        .sort-links {
            font-size: 0.9em;
        }
        .sort-links a {
            color: #4CAF50;
            font-weight: bold;
            text-decoration: none;
            padding: 5px;
            margin: 0 3px;
        }
        .sort-links a:hover {
            text-decoration: underline;
        }
        .small-button {
            width: 28px;
            height: 28px;
            font-size: 0.9em;
            line-height: 28px;
            color: white;
            border: none;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .small-button.add {
            background-color: #4CAF50;
        }
        .small-button.add:hover {
            background-color: #45a049;
        }
        .small-button.import {
            background-color: #2196F3;
        }
        .small-button.import:hover {
            background-color: #1e88e5;
        }
        .small-button.delete {
            background-color: #f44336;
        }
        .small-button.delete:hover {
            background-color: #e53935;
        }
        .message {
            text-align: center;
            font-weight: bold;
        }
        .message.success {
            color: green;
        }
        .message.error {
            color: red;
        }
    </style>
</head>
<body>
    <div class="manage-users-container">
        <h2>Управление пользователями</h2>

        <!-- Сообщения об успехе или ошибке -->
        <?php if ($error): ?>
            <p class="message error"><?= $error ?></p>
        <?php elseif ($success): ?>
            <p class="message success"><?= $success ?></p>
        <?php endif; ?>

        <div class="search-sort-container">
            <!-- Форма поиска -->
            <form method="get">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Поиск по логину">
                <button type="submit">Поиск</button>
            </form>

            <!-- Сортировка -->
            <div class="sort-links">
                <a href="?search=<?= urlencode($search) ?>&sort=id&order=<?= $order === 'asc' ? 'desc' : 'asc' ?>">ID</a> |
                <a href="?search=<?= urlencode($search) ?>&sort=username&order=<?= $order === 'asc' ? 'desc' : 'asc' ?>">Логин</a> |
                <a href="?search=<?= urlencode($search) ?>&sort=role&order=<?= $order === 'asc' ? 'desc' : 'asc' ?>">Роль</a>
            </div>
        </div>

        <!-- Форма добавления нового пользователя -->
        <form method="post" action="manage_users.php">
            <h3>Добавить нового пользователя</h3>
            <label>Логин:</label>
            <input type="text" name="login" required>
            <label>Пароль:</label>
            <input type="password" name="password" required>
            <label>Роль:</label>
            <select name="role">
                <option value="user">Пользователь</option>
                <option value="admin">Администратор</option>
            </select>
            <button type="submit" name="add_user" class="small-button add">+</button>
        </form>

        <!-- Форма загрузки Excel для импорта пользователей -->
        <form method="post" enctype="multipart/form-data">
            <h3>Импорт пользователей из Excel (CSV)</h3>
            <input type="file" name="excel_file" accept=".csv" required>
            <button type="submit" name="import_excel" class="small-button import">↑</button>
        </form>

        <!-- Таблица пользователей -->
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Логин</th>
                    <th>Роль</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($users)): ?>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= $user['id'] ?></td>
                            <td><?= htmlspecialchars($user['username']) ?></td>
                            <td><?= $user['role'] ?></td>
                            <td>
                                <!-- Кнопка для удаления пользователя -->
                                <form action="manage_users.php" method="post" onsubmit="return confirm('Вы уверены, что хотите удалить этого пользователя?');">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" name="delete_user" class="small-button delete">×</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4">Пользователи не найдены.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Пагинация -->
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?search=<?= urlencode($search) ?>&sort=<?= $sort ?>&order=<?= $order ?>&page=<?= $page - 1 ?>">&laquo; Предыдущая</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?search=<?= urlencode($search) ?>&sort=<?= $sort ?>&order=<?= $order ?>&page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="?search=<?= urlencode($search) ?>&sort=<?= $sort ?>&order=<?= $order ?>&page=<?= $page + 1 ?>">Следующая &raquo;</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
