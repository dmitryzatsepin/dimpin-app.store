<?php
// /importFromSpa/test_permissions.php

// Включаем отображение всех ошибок прямо на странице
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Устанавливаем заголовок для корректного отображения русского языка
header('Content-Type: text/html; charset=utf-8');

echo "<h1>Тест прав доступа и логирования</h1>";

// Определяем путь к папке data. __DIR__ - это текущая папка, где лежит этот скрипт.
$data_dir = __DIR__ . '/data/';
echo "<p><strong>Целевая папка (DATA_DIR):</strong> <code>" . htmlspecialchars($data_dir) . "</code></p>";

// --- Проверка №1: Существует ли папка data/ ---
echo "<h2>1. Проверка существования папки data/</h2>";
if (is_dir($data_dir)) {
    echo "<p style='color:green;'><b>Статус:</b> Папка существует.</p>";
} else {
    echo "<p style='color:orange;'><b>Статус:</b> Папка НЕ существует. Пытаюсь создать...</p>";
    // Пытаемся создать папку рекурсивно с правами 0755
    if (@mkdir($data_dir, 0755, true)) {
        echo "<p style='color:green;'><b>Результат:</b> Папка успешно создана!</p>";
    } else {
        echo "<p style='color:red;'><b>Результат:</b> НЕ удалось создать папку! Это основная проблема. Проверьте права на родительскую директорию (<code>" . htmlspecialchars(__DIR__) . "</code>).</p>";
    }
}

echo "<hr>";

// --- Проверка №2: Права на запись в папку data/ ---
echo "<h2>2. Проверка возможности записи в папку data/</h2>";
if (is_writable($data_dir)) {
    echo "<p style='color:green;'><b>Статус:</b> Папка доступна для записи (is_writable = true).</p>";
} else {
    echo "<p style='color:red;'><b>Статус:</b> Папка НЕ доступна для записи (is_writable = false). Это проблема с правами доступа (permissions). Установите права 755 или 775 на папку 'data'.</p>";
}

echo "<hr>";

// --- Проверка №3: Создание и запись в тестовый файл ---
echo "<h2>3. Попытка создать и записать тестовый файл в data/</h2>";
$test_file_path = $data_dir . 'test_write.txt';
$test_content = "Тест записи прошел успешно в " . date('Y-m-d H:i:s');

// file_put_contents вернет количество записанных байт или false в случае ошибки
$bytes_written = @file_put_contents($test_file_path, $test_content);

if ($bytes_written !== false) {
    echo "<p style='color:green;'><b>Статус:</b> Успешно записано " . $bytes_written . " байт в файл <code>" . htmlspecialchars($test_file_path) . "</code>.</p>";
    echo "<p>Проверьте, появился ли этот файл в папке 'data' на вашем сервере.</p>";
} else {
    echo "<p style='color:red;'><b>Статус:</b> НЕ удалось записать в файл! (file_put_contents вернул false). Это подтверждает проблему с правами на запись.</p>";
}

echo "<hr>";

// --- Проверка №4: Работа функции error_log() ---
echo "<h2>4. Проверка работы системного error_log()</h2>";
$log_message = "DEBUG_TEST_PERMISSIONS: Это тестовое сообщение из test_permissions.php от " . date('Y-m-d H:i:s');
if (error_log($log_message)) {
    echo "<p style='color:green;'><b>Статус:</b> Команда error_log() выполнена успешно (вернула true).</p>";
    echo "<p>Теперь вам нужно найти, куда именно PHP сохранил этот лог. Возможные места:</p>";
    echo "<ul>";
    echo "<li>Файл `error_log` в той же папке, где лежит этот скрипт (<code>" . htmlspecialchars(__DIR__) . "</code>)</li>";
    echo "<li>Файл `error_log` или `php_error_log` в корневой папке вашего сайта (`public_html` или `www`)</li>";
    echo "<li>Специальная папка `logs/` в корне вашего хостинг-аккаунта.</li>";
    echo "<li>Место, указанное в настройках PHP вашего хостинга (директива `error_log` в `php.ini`).</li>";
    echo "</ul>";
    echo "<p>Если вы нашли этот файл и в нем есть сообщение '{$log_message}', значит системное логирование работает.</p>";
} else {
    echo "<p style='color:red;'><b>Статус:</b> Команда error_log() вернула false. Это означает, что PHP не смог записать системный лог. Проблема в глобальных настройках PHP на сервере.</p>";
}

?>