<?php
// /importToSpa/config.php

// Включаем отображение всех ошибок для отладки (на продакшене лучше настроить логирование в файл)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Определение констант
define('DATA_DIR', __DIR__ . '/data/'); // Путь к папке для данных и логов

// --- Конфигурация приложения Bitrix24 ---
define('B24_CLIENT_ID', 'local.684939a2a1be77.80830412'); // Ваш Client ID
define('B24_CLIENT_SECRET', 'KWviBU7l7KN9CK0ZiX5mEHzioQ8AnLN8vZVwkdSdq0iiJBWWC2'); // Ваш Client Secret

// Проверка и создание папки data, если ее нет
if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0755, true)) {
        // Если это API запрос, отвечаем JSON-ошибкой
        if (isset($_GET['action']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            die(json_encode(['error' => 'setup_error', 'message' => 'Failed to create data directory. Check server permissions.']));
        }
        // Если это загрузка HTML, выводим ошибку
        error_log("CRITICAL: Failed to create data directory: " . DATA_DIR);
        die("Critical Error: Failed to create data directory. Please check server permissions for the script's parent directory.");
    }
}

// Проверка на возможность записи в папку data
if (!is_writable(DATA_DIR) && is_dir(DATA_DIR)) {
    if (isset($_GET['action']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        die(json_encode(['error' => 'setup_error', 'message' => 'Data directory is not writable. Check server permissions.']));
    }
    error_log("CRITICAL: Data directory not writable: " . DATA_DIR);
    die("Critical Error: Data directory (" . DATA_DIR . ") is not writable. Please check server permissions.");
}

// Установка корректной кодировки для вывода (если это не API запрос, который сам установит Content-Type)
if (!isset($_GET['action']) && !(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
    if (!headers_sent()) { // Проверяем, не были ли заголовки уже отправлены
        header('Content-Type: text/html; charset=utf-8');
    }
}
?>