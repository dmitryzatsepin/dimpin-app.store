<?php
// /importFromSpa/includes/api_helper.php

if (!defined('DATA_DIR')) {
    // Попытка определить DATA_DIR, если файл вызывается напрямую (не рекомендуется, но для безопасности)
    // Это предполагает, что config.php находится на один уровень выше и в той же директории, что и папка includes
    $configPath = __DIR__ . '/../config.php';
    if (file_exists($configPath)) {
        require_once $configPath; // Загрузит DATA_DIR и другие константы
    } else {
        // Если config.php не найден, устанавливаем DATA_DIR по умолчанию относительно этого файла
        // Это менее надежно, лучше всегда подключать config.php первым в index.php
        if (!defined('DATA_DIR_API_HELPER_FALLBACK')) { // Проверка, чтобы не переопределять, если уже задано
            define('DATA_DIR_API_HELPER_FALLBACK', __DIR__ . '/../data/');
        }
    }
}


function callBitrixAPI($domain, $access_token, $method, $params = [])
{
    // Определяем DATA_DIR для логирования, если он не был определен ранее (например, через config.php)
    // Это для случаев, когда функция может быть вызвана в контексте, где DATA_DIR из config.php не доступен глобально,
    // хотя лучшей практикой является передача DATA_DIR как параметра или использование глобальной константы, установленной ранее.
    $logDataDir = defined('DATA_DIR') ? DATA_DIR : (defined('DATA_DIR_API_HELPER_FALLBACK') ? DATA_DIR_API_HELPER_FALLBACK : __DIR__ . '/../data/');
    // Убедимся, что папка для логов существует, если используем DATA_DIR_API_HELPER_FALLBACK
    if (!is_dir($logDataDir) && (defined('DATA_DIR_API_HELPER_FALLBACK') && $logDataDir === DATA_DIR_API_HELPER_FALLBACK)) {
        @mkdir($logDataDir, 0755, true); // Попытка создать, если ее нет
    }


    $url = 'https://' . trim($domain) . '/rest/' . trim($method) . '.json';
    $url_params_auth_only = ['auth' => $access_token];
    $curl_options = [
        CURLOPT_SSL_VERIFYPEER => false, // На продакшене лучше true и настроить сертификаты
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120, // Увеличим таймаут для потенциально долгих запросов
        CURLOPT_CONNECTTIMEOUT => 30,
    ];
    $request_body_for_log = "";

    if (strtolower($method) === 'batch' && isset($params['cmd'])) {
        $curl_options[CURLOPT_URL] = $url . '?' . http_build_query($url_params_auth_only);
        $curl_options[CURLOPT_POST] = true;
        $post_fields_batch = [];
        if (isset($params['halt'])) {
            $post_fields_batch['halt'] = $params['halt'];
        }
        // 'cmd' должен быть массивом команд в формате "ключ_команды" => "метод?параметры"
        foreach ($params['cmd'] as $key => $command_string_with_params) {
            $post_fields_batch['cmd'][$key] = $command_string_with_params;
        }
        $curl_options[CURLOPT_POSTFIELDS] = http_build_query($post_fields_batch); // http_build_query корректно обработает вложенный массив cmd
        $request_body_for_log = urldecode(http_build_query($post_fields_batch)); // Для читаемого лога
    } else {
        $all_request_params = array_merge($url_params_auth_only, $params);
        $curl_options[CURLOPT_URL] = $url . '?' . http_build_query($all_request_params);
    }

    $curl = curl_init();
    curl_setopt_array($curl, $curl_options);
    $response_body = curl_exec($curl);
    $curl_error = curl_error($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    // Логирование сырого ответа для отладки
    if (is_writable($logDataDir)) { // Логируем только если есть куда писать
        $log_file_name = (strtolower($method) === 'batch') ? 'log_batch_api.txt' : 'log_generic_api.txt';
        $log_entry = "[" . date('Y-m-d H:i:s') . "] Method: {$method}, HTTP: {$http_code}, CurlErr: " . ($curl_error ?: 'OK') . PHP_EOL;
        $log_entry .= "URL: " . ($curl_options[CURLOPT_URL] ?? 'N/A') . PHP_EOL;
        if ($request_body_for_log) {
            $log_entry .= "POST Body: " . $request_body_for_log . PHP_EOL;
        }
        $log_entry .= "Response (first 2KB): " . substr($response_body, 0, 2048) . PHP_EOL . "---" . PHP_EOL;
        file_put_contents($logDataDir . $log_file_name, $log_entry, FILE_APPEND | LOCK_EX);
    }


    if ($curl_error) {
        if (is_writable($logDataDir)) {
            file_put_contents($logDataDir . 'log_curl_errors.txt', date('[Y-m-d H:i:s] ') . "cURL Error for method {$method}: " . $curl_error . PHP_EOL, FILE_APPEND);
        }
        return ['error' => 'curl_error', 'error_description' => $curl_error, 'method' => $method];
    }

    $decoded_response = json_decode($response_body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        if (is_writable($logDataDir)) {
            file_put_contents($logDataDir . 'log_json_decode_errors.txt', date('[Y-m-d H:i:s] ') . "JSON Decode Error for method {$method}. Response: " . substr($response_body, 0, 500) . PHP_EOL, FILE_APPEND);
        }
        return ['error' => 'json_decode_error', 'error_description' => 'Failed to decode JSON response from API.', 'http_code' => $http_code, 'response_snippet' => substr($response_body, 0, 200)];
    }

    // Стандартная ошибка Bitrix24 API возвращается с кодом 200, но содержит 'error' в теле
    if (isset($decoded_response['error']) && $http_code == 200 && !isset($decoded_response['result'])) {
        // Исключение для batch, где 'error' может быть на уровне отдельной команды, а не всего запроса
        if (strtolower($method) !== 'batch' || (strtolower($method) === 'batch' && !isset($decoded_response['result']['result_error']))) {
            if (is_writable($logDataDir)) {
                file_put_contents($logDataDir . 'log_b24_api_errors.txt', date('[Y-m-d H:i:s] ') . "Bitrix24 API Error for method {$method}. Desc: " . ($decoded_response['error_description'] ?? 'N/A') . ". Resp: " . substr($response_body, 0, 1000) . PHP_EOL, FILE_APPEND);
            }
            return $decoded_response; // Возвращаем как есть, чтобы React мог обработать
        }
    }
    // Ошибки HTTP, не являющиеся 200
    if ($http_code != 200) {
        if (is_writable($logDataDir)) {
            file_put_contents($logDataDir . 'log_http_errors.txt', date('[Y-m-d H:i:s] ') . "HTTP Error {$http_code} for method {$method}. Resp: " . substr($response_body, 0, 1000) . PHP_EOL, FILE_APPEND);
        }
        return ['error' => 'http_error', 'error_description' => 'API request failed with HTTP status ' . $http_code, 'http_code' => $http_code, 'response_snippet' => substr($response_body, 0, 200)];
    }

    return $decoded_response;
}
?>