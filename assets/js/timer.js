<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Таймер</title>
</head>
<body>
    <!-- Таймер с временем в секундах, установленным через data-time -->
    <div id="timer" data-time="300"></div>

    <script>
        // timer.js

        // Начальное время в секундах, например 300 секунд = 5 минут
        let timeLeft = parseInt(document.getElementById('timer').getAttribute('data-time'), 10);
        const timerDisplay = document.getElementById('timer');

        // Функция для форматирования времени в MM:SS
        function formatTime(seconds) {
            const minutes = Math.floor(seconds / 60);
            const remainingSeconds = seconds % 60;
            return `${minutes}:${remainingSeconds < 10 ? '0' : ''}${remainingSeconds}`;
        }

        // Отображение начального значения таймера
        timerDisplay.textContent = `Оставшееся время: ${formatTime(timeLeft)}`;

        // Обратный отсчёт
        const countdown = setInterval(() => {
            if (timeLeft <= 0) {
                clearInterval(countdown);
                timerDisplay.textContent = "Время истекло!";
                timerDisplay.style.color = "#e63946";  // Меняем цвет текста на красный
                alert("Время теста истекло!");
            } else {
                timeLeft--;
                timerDisplay.textContent = `Оставшееся время: ${formatTime(timeLeft)}`;

                // Мигание последних 10 секунд
                if (timeLeft <= 10) {
                    timerDisplay.style.color = timeLeft % 2 === 0 ? "#e63946" : "#333";
                }
            }
        }, 1000);
    </script>
</body>
</html>
