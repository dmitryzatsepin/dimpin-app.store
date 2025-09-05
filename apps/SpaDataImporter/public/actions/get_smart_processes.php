<?php
// /importFromSpa/actions/get_smart_processes.php

// Переменные $current_domain, $current_access_token должны быть установлены
// в вызывающем файле (index.php) до подключения этого скрипта.
// Также здесь предполагается, что функция callBitrixAPI уже доступна.

if (!isset($current_domain, $current_access_token) || empty($current_domain) || empty($current_access_token)) {
    error_log("ACTION_GET_SP_ERROR: Auth data (domain or token) not available.");
    http_response_code(500); // Internal Server Error - проблема конфигурации
    die(json_encode(['success' => false, 'error' => 'internal_auth_error', 'message' => 'Authentication data is not available for API call.']));
}

if (!function_exists('callBitrixAPI')) {
    error_log("ACTION_GET_SP_ERROR: callBitrixAPI function is not available.");
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'internal_config_error', 'message' => 'API helper function is not available.']));
}

error_log("ACTION_GET_SP_DEBUG: Calling crm.type.list. Domain: {$current_domain}, Token (first 10): " . substr($current_access_token, 0, 10));

$api_response = callBitrixAPI($current_domain, $current_access_token, 'crm.type.list');

if (isset($api_response['result']['types'])) {
    $all_types = $api_response['result']['types'];
    $list = [];
    foreach ($all_types as $type) {
        // Уточненная логика определения смарт-процесса
        $is_smart_process = false;
        if (isset($type['entityTypeId'])) {
            // Смарт-процессы обычно имеют entityTypeId >= 128 (в некоторых документациях >= 1000 для пользовательских)
            // и не являются стандартными сущностями
            $entityTypeIdNum = (int) filter_var($type['entityTypeId'], FILTER_SANITIZE_NUMBER_INT); // Извлекаем число из CRM_DYNAMIC_ITEM_XXX
            if ($entityTypeIdNum == 0 && isset($type['id'])) { // Если entityTypeId не числовой, используем id как числовой тип
                $entityTypeIdNum = (int) $type['id'];
            }

            if ($entityTypeIdNum >= 128) { // Минимальный порог для динамических типов
                $is_smart_process = true;
            }
        }
        // Дополнительные проверки, если есть флаги isAutomationEnabled или isClientEnabled
        if (!$is_smart_process && isset($type['isAutomationEnabled']) && $type['isAutomationEnabled'] === true) {
            $is_smart_process = true;
        }
        if (!$is_smart_process && isset($type['isClientEnabled']) && $type['isClientEnabled'] === true) {
            $is_smart_process = true;
        }


        $standard_crm_codes = ['LEAD', 'DEAL', 'CONTACT', 'COMPANY', 'INVOICE', 'QUOTE', 'SMART_INVOICE', 'ORDER'];
        if ($is_smart_process && isset($type['title'], $type['id']) && !in_array(strtoupper($type['code'] ?? ''), $standard_crm_codes)) {
            // `id` из crm.type.list это числовой идентификатор типа, который используется как entityTypeId
            // Убедимся, что entityTypeId существует и является числом
            if (isset($type['entityTypeId']) && is_numeric($type['entityTypeId'])) {
                $list[] = ['id' => (string) $type['entityTypeId'], 'title' => $type['title']]; // Используем entityTypeId сущности
            } else if (isset($type['id'])) { // Фолбэк, если entityTypeId отсутствует или не числовой (маловероятно для смарт-процессов)
                // Это может быть не смарт-процесс, или старая структура ответа. Логируем.
                error_log("ACTION_GET_SP_WARNING: entityTypeId for type '{$type['title']}' (id: {$type['id']}) is missing or not numeric. Full type data: " . json_encode($type));
                // Можно либо пропустить этот тип, либо использовать $type['id'] с осторожностью
                // $list[] = ['id' => (string)$type['id'], 'title' => $type['title']];
            }
        }
    }
    error_log("ACTION_GET_SP_DEBUG: Found " . count($list) . " smart processes.");
    echo json_encode(['success' => true, 'data' => $list]);
} elseif (isset($api_response['error'])) {
    error_log("ACTION_GET_SP_ERROR: API error. Desc: " . ($api_response['error_description'] ?? 'N/A') . ". Details: " . json_encode($api_response));
    http_response_code(isset($api_response['http_code']) && $api_response['http_code'] >= 400 ? $api_response['http_code'] : 500); // Используем HTTP код ошибки от API, если есть
    die(json_encode(['success' => false, 'error' => 'api_error_sp_list', 'message' => $api_response['error_description'] ?? 'Failed to fetch smart process list.', 'details' => $api_response]));
} else {
    error_log("ACTION_GET_SP_ERROR: Unknown API response. Details: " . json_encode($api_response));
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'unknown_response_sp_list', 'message' => 'Unexpected API response format for smart process list.', 'details' => $api_response]));
}
exit; // Важно завершить выполнение скрипта здесь
?>