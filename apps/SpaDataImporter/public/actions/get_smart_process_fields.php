<?php
// /importFromSpa/actions/get_smart_process_fields.php

// Переменные $current_domain, $current_access_token должны быть установлены
// в вызывающем файле (index.php) до подключения этого скрипта.
// Также здесь предполагается, что функция callBitrixAPI уже доступна.

if (!isset($current_domain, $current_access_token) || empty($current_domain) || empty($current_access_token)) {
    error_log("ACTION_GET_FIELDS_ERROR: Auth data (domain or token) not available.");
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'internal_auth_error', 'message' => 'Authentication data is not available for API call.']));
}

if (!function_exists('callBitrixAPI')) {
    error_log("ACTION_GET_FIELDS_ERROR: callBitrixAPI function is not available.");
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'internal_config_error', 'message' => 'API helper function is not available.']));
}

if (!isset($_GET['entityTypeId'])) {
    error_log("ACTION_GET_FIELDS_ERROR: entityTypeId parameter missing.");
    http_response_code(400); // Bad Request
    die(json_encode(['success' => false, 'error' => 'missing_param_entityTypeId', 'message' => 'entityTypeId is required.']));
}

// Отладочное логирование исходного и санитайзенного entityTypeId
$raw_entityTypeId = $_GET['entityTypeId'];
error_log("ACTION_GET_FIELDS_DEBUG: Raw entityTypeId from GET: " . $raw_entityTypeId);

$entityTypeId_param = filter_var($raw_entityTypeId, FILTER_SANITIZE_NUMBER_INT);
error_log("ACTION_GET_FIELDS_DEBUG: Sanitized entityTypeId: " . var_export($entityTypeId_param, true));

// Усиленная проверка entityTypeId_param
if (empty($entityTypeId_param) || !is_numeric($entityTypeId_param) || (int) $entityTypeId_param <= 0) {
    error_log("ACTION_GET_FIELDS_ERROR: entityTypeId_param is invalid after sanitization: " . var_export($entityTypeId_param, true) . ". Original: " . $raw_entityTypeId);
    http_response_code(400); // Bad Request
    die(json_encode(['success' => false, 'error' => 'invalid_param_entityTypeId_strict', 'message' => 'entityTypeId is invalid or non-positive. Original value: ' . htmlspecialchars($raw_entityTypeId)]));
}

error_log("ACTION_GET_FIELDS_DEBUG: Calling crm.item.fields for entityTypeId {$entityTypeId_param}. Domain: {$current_domain}, Token (first 10): " . substr($current_access_token, 0, 10));

$api_response = callBitrixAPI($current_domain, $current_access_token, 'crm.item.fields', ['entityTypeId' => $entityTypeId_param]);

if (isset($api_response['result']['fields'])) {
    $list = [];
    foreach ($api_response['result']['fields'] as $key => $data) {
        if (empty($data['title'])) { // Пропускаем поля без заголовка, они обычно служебные и не нужны для маппинга
            continue;
        }
        $list[] = [
            'id' => $key, // Системный код поля (UF_CRM_XXXX, TITLE, ASSIGNED_BY_ID)
            'title' => $data['title'],
            'type' => $data['type'] ?? 'string', // Тип по умолчанию, если не указан
            'isMultiple' => $data['isMultiple'] ?? false,
            'isRequired' => $data['isRequired'] ?? false,
            'isReadOnly' => $data['isReadOnly'] ?? false,
            'items' => $data['items'] ?? null, // Для списочных полей
        ];
    }
    error_log("ACTION_GET_FIELDS_DEBUG: Found " . count($list) . " fields for entityTypeId " . $entityTypeId_param);
    echo json_encode(['success' => true, 'data' => $list]);
} elseif (isset($api_response['error'])) {
    error_log("ACTION_GET_FIELDS_ERROR: API error for entityTypeId {$entityTypeId_param}. Desc: " . ($api_response['error_description'] ?? 'N/A') . ". Details: " . json_encode($api_response));
    http_response_code(isset($api_response['http_code']) && $api_response['http_code'] >= 400 ? $api_response['http_code'] : 500);
    die(json_encode(['success' => false, 'error' => 'api_error_fields', 'message' => $api_response['error_description'] ?? 'Failed to fetch smart process fields.', 'details' => $api_response]));
} else {
    error_log("ACTION_GET_FIELDS_ERROR: Unknown API response for entityTypeId {$entityTypeId_param}. Details: " . json_encode($api_response));
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'unknown_response_fields', 'message' => 'Unexpected API response format for smart process fields.', 'details' => $api_response]));
}
exit; // Важно завершить выполнение скрипта здесь
?>