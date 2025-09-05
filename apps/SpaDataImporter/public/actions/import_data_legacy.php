<?php
// /importToSpa/actions/import_data.php (Исправленная версия)
error_log("DEBUG_IMPORT_DATA: import_data.php script was called at " . date('Y-m-d H:i:s'));
if (!defined('DATA_DIR')) {
    $configPath = __DIR__ . '/../../config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
    } else {
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => 'config_error', 'message' => 'Application configuration (DATA_DIR) not found.']));
    }
}
if (!function_exists('callBitrixAPI')) {
    $apiHelperPath = __DIR__ . '/../includes/api_helper.php';
    if (file_exists($apiHelperPath)) {
        require_once $apiHelperPath;
    } else {
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => 'config_error', 'message' => 'API helper function (callBitrixAPI) not found.']));
    }
}

$json_input_data = file_get_contents('php://input');
$payload = json_decode($json_input_data, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Invalid JSON payload received. Error: ' . json_last_error_msg()]));
}

$member_id_from_payload = $payload['member_id'] ?? null;
$domain_from_payload = $payload['DOMAIN'] ?? ($payload['domain'] ?? null);
$entityTypeId = isset($payload['entityTypeId']) ? filter_var($payload['entityTypeId'], FILTER_SANITIZE_NUMBER_INT) : null;
$items_to_import_from_react = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];

if (empty($member_id_from_payload) || empty($domain_from_payload) || empty($entityTypeId)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Required parameters (member_id, DOMAIN, entityTypeId) missing in payload.']));
}
if (empty($items_to_import_from_react)) {
    http_response_code(200);
    die(json_encode(['success' => true, 'message' => 'No items received for import.', 'importedCount' => 0, 'failedCount' => 0, 'errors' => []]));
}

$action_member_id = htmlspecialchars($member_id_from_payload);
$action_current_domain = htmlspecialchars($domain_from_payload);

$safe_action_member_id_part = preg_replace('/[^a-zA-Z0-9_.-]/', '', $action_member_id);
$safe_action_domain_part = preg_replace('#^https?://#', '', $action_current_domain);
$safe_action_domain_part = str_replace(['.', '-'], '_', $safe_action_domain_part);
$safe_action_domain_part = preg_replace('/[^a-zA-Z0-9_]/', '', $safe_action_domain_part);
$action_token_file_path = null;
if (!empty($safe_action_member_id_part) && !empty($safe_action_domain_part)) {
    $action_token_file_path = DATA_DIR . 'token_' . $safe_action_domain_part . '_' . $safe_action_member_id_part . '.json';
} else {
    error_log("ACTION_IMPORT_DATA_ERROR: Could not form a safe filename for token from member_id ('{$action_member_id}') or domain ('{$action_current_domain}').");
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'internal_config_error', 'message' => 'Cannot determine token file name.']));
}

error_log("ACTION_IMPORT_DATA_DEBUG: Starting import. Will use token file: " . $action_token_file_path);

if (!file_exists($action_token_file_path)) {
    error_log("ACTION_IMPORT_DATA_ERROR: Token file missing. Path: {$action_token_file_path}");
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => "Token file missing. Ensure app is installed."]));
}
$saved_data_for_import = json_decode(file_get_contents($action_token_file_path), true);
if (!$saved_data_for_import || empty($saved_data_for_import['access_token'])) {
    error_log("ACTION_IMPORT_DATA_ERROR: Invalid token data. File: {$action_token_file_path}");
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Invalid token data. Reinstall app.']));
}

// Check and refresh token if necessary for the import action
$action_current_access_token = $saved_data_for_import['access_token']; // Default to existing token

if (time() >= $saved_data_for_import['expires_at'] - 60) { // Refresh if token is expired or will expire in 60 seconds
    error_log("ACTION_IMPORT_DATA_TOKEN: Token for import (member_id: {$action_member_id}) expired or nearing expiration. Attempting refresh.");

    // Ensure B24_CLIENT_ID and B24_CLIENT_SECRET are available from config.php
    // These constants should be loaded if config.php was included at the top.
    // If not, it's a critical config error.
    if (!defined('B24_CLIENT_ID') || !defined('B24_CLIENT_SECRET')) {
        error_log("ACTION_IMPORT_DATA_TOKEN_ERROR: B24_CLIENT_ID or B24_CLIENT_SECRET constants not defined. Cannot refresh token.");
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => 'config_error', 'message' => 'Application client credentials not configured for token refresh.']));
    }

    $refresh_url = 'https://' . $saved_data_for_import['domain'] . '/oauth/token/';
    $refresh_query_params = http_build_query([
        'grant_type' => 'refresh_token',
        'client_id' => B24_CLIENT_ID, // Use constant from config.php
        'client_secret' => B24_CLIENT_SECRET, // Use constant from config.php
        'refresh_token' => $saved_data_for_import['refresh_token']
    ]);

    $ch_refresh_import = curl_init();
    curl_setopt_array($ch_refresh_import, [
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_URL => $refresh_url . '?' . $refresh_query_params,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response_refresh_body_import = curl_exec($ch_refresh_import);
    $error_refresh_import = curl_error($ch_refresh_import);
    $http_code_refresh_import = curl_getinfo($ch_refresh_import, CURLINFO_HTTP_CODE);
    curl_close($ch_refresh_import);

    if ($error_refresh_import || $http_code_refresh_import != 200) {
        $error_details_import = $error_refresh_import ?: "HTTP {$http_code_refresh_import} - " . substr($response_refresh_body_import, 0, 500);
        error_log("ACTION_IMPORT_DATA_TOKEN_ERROR: Token refresh failed for import (member_id: {$action_member_id}): {$error_details_import}");
        // Critical error: cannot proceed with import if token refresh fails and token was expired.
        http_response_code(401); // Unauthorized or token expired
        die(json_encode(['success' => false, 'error' => 'token_refresh_failed_for_import', 'message' => 'Failed to refresh access token for import operation. Please try again.', 'details' => $error_details_import]));
    } else {
        $new_token_data_import = json_decode($response_refresh_body_import, true);
        if (isset($new_token_data_import['access_token'])) {
            $saved_data_for_import['access_token'] = $new_token_data_import['access_token'];
            $saved_data_for_import['refresh_token'] = $new_token_data_import['refresh_token'];
            $saved_data_for_import['expires_at'] = time() + (isset($new_token_data_import['expires_in']) ? intval($new_token_data_import['expires_in']) : 3600);
            if (isset($new_token_data_import['user_id'])) { // Bitrix24 might return user_id on refresh
                $saved_data_for_import['user_id'] = $new_token_data_import['user_id'];
            }

            // Save the updated token data back to the file
            if (file_put_contents($action_token_file_path, json_encode($saved_data_for_import, JSON_PRETTY_PRINT))) {
                $action_current_access_token = $saved_data_for_import['access_token'];
                // Update $current_b24_user_id_for_import if it changed
                $current_b24_user_id_for_import = $saved_data_for_import['user_id'] ?? $current_b24_user_id_for_import;
                error_log("ACTION_IMPORT_DATA_TOKEN: Token refreshed and saved successfully for import (member_id: {$action_member_id}).");
            } else {
                error_log("ACTION_IMPORT_DATA_TOKEN_ERROR: Failed to save refreshed token to file: {$action_token_file_path}");
                // Minor issue, proceed with refreshed token in memory, but log persistence error.
                $action_current_access_token = $saved_data_for_import['access_token'];
            }
        } else {
            error_log("ACTION_IMPORT_DATA_TOKEN_ERROR: Invalid response on token refresh for import (member_id: {$action_member_id}): " . substr($response_refresh_body_import, 0, 500));
            http_response_code(500); // Server error, as refresh response was malformed
            die(json_encode(['success' => false, 'error' => 'invalid_refresh_response_for_import', 'message' => 'Received an invalid response when trying to refresh access token.', 'details' => substr($response_refresh_body_import, 0, 200)]));
        }
    }
} else {
    // Token is still valid, use existing one
    $action_current_access_token = $saved_data_for_import['access_token'];
    error_log("ACTION_IMPORT_DATA_TOKEN: Existing token is still valid for import (member_id: {$action_member_id}).");
}

// Ensure $action_current_access_token is set, otherwise it's a critical failure
if (empty($action_current_access_token)) {
    error_log("ACTION_IMPORT_DATA_CRITICAL_ERROR: Access token is empty after processing for member_id: {$action_member_id}. This should not happen.");
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'internal_token_error', 'message' => 'Access token could not be established for import.']));
}


$process_fields_meta_response = callBitrixAPI($action_current_domain, $action_current_access_token, 'crm.item.fields', ['entityTypeId' => $entityTypeId]);
$process_fields_meta = [];
if (isset($process_fields_meta_response['result']['fields'])) {
    $process_fields_meta = $process_fields_meta_response['result']['fields'];
    error_log("ACTION_IMPORT_DATA_DEBUG: Fetched " . count($process_fields_meta) . " field metadata for entityTypeId {$entityTypeId}.");
} else {
    error_log("ACTION_IMPORT_DATA_ERROR: Failed to get field metadata for entityTypeId {$entityTypeId}. API Resp: " . print_r($process_fields_meta_response, true));
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'Failed to retrieve field metadata for import.', 'details' => $process_fields_meta_response]));
}
$current_b24_user_id_for_import = $saved_data_for_import['user_id'] ?? null;

// --- Часть 3: Подготовка данных для batch-импорта с преобразованиями ---
$results = ['success' => true, 'message' => '', 'importedCount' => 0, 'failedCount' => 0, 'errors' => []];
$all_batch_commands_as_strings = [];
$userFieldTypes = ['employee', 'user'];
$original_indices_map = [];

foreach ($items_to_import_from_react as $index => $item_fields_from_react) {
    $command_key = 'add_item_' . $index;
    $fields_for_api = [];
    $currentItemHasError = false;
    foreach ($item_fields_from_react as $field_code => $file_value) {
        $original_file_value = $file_value;
        if ($file_value === null || (is_string($file_value) && trim($file_value) === '')) {
            // Пропускаем пустые значения, если не требуется передавать их для очистки полей
            continue;
        }

        $field_meta = $process_fields_meta[$field_code] ?? null;

        // Если поле не TITLE и нет метаданных, пропускаем его, т.к. не знаем как обрабатывать
        if (!$field_meta && strtoupper($field_code) !== 'TITLE') {
            error_log("ACTION_IMPORT_DATA_WARNING: Field '{$field_code}' from file not found in smart process metadata for entityTypeId {$entityTypeId}. Skipping.");
            continue;
        }

        $field_type = $field_meta['type'] ?? 'string'; // Тип по умолчанию, если метаданные неполные
        $is_multiple = $field_meta['isMultiple'] ?? false;
        $current_value_to_process = $original_file_value;

        // --- Блок преобразований ---
        if ($is_multiple && is_array($current_value_to_process)) {
            $transformed_multiple_values = [];
            foreach ($current_value_to_process as $single_val) {
                if ($single_val === null || (is_string($single_val) && trim($single_val) === ''))
                    continue;
                $processed_val = trim(strval($single_val)); // Начальное значение для обработки

                if ($field_type === 'enumeration' && isset($field_meta['items']) && is_array($field_meta['items'])) {
                    $found_id_multi = null;
                    foreach ($field_meta['items'] as $item) {
                        if (isset($item['ID'], $item['VALUE']) && strcasecmp(trim($item['VALUE']), $processed_val) === 0) {
                            $found_id_multi = $item['ID'];
                            break;
                        }
                    }
                    if ($found_id_multi !== null)
                        $processed_val = $found_id_multi;
                    else {
                        error_log("ACTION_IMPORT_DATA_TRANSFORM_WARN: Enum value '{$processed_val}' for multi-field '{$field_code}' not found. Skipping this value.");
                        continue;
                    } // Пропускаем ненайденное значение
                } elseif ($field_type === 'boolean') {
                    $tmp_bool_multi = strtolower($processed_val);
                    if (in_array($tmp_bool_multi, ['yes', 'y', 'true', '1', 'да', 'д']))
                        $processed_val = 'Y';
                    elseif (in_array($tmp_bool_multi, ['no', 'n', 'false', '0', 'нет', 'н']))
                        $processed_val = 'N';
                    else {
                        error_log("ACTION_IMPORT_DATA_TRANSFORM_WARN: Boolean value '{$processed_val}' for multi-field '{$field_code}' not recognized. Skipping this value.");
                        continue;
                    }
                }
                // TODO: Добавить другие преобразования для элементов множественных полей, если необходимо (например, даты)
                $transformed_multiple_values[] = $processed_val;
            }
            if (!empty($transformed_multiple_values))
                $fields_for_api[$field_code] = $transformed_multiple_values;
            continue; // К следующему полю из файла
        }

        // Одиночные значения
        $current_value_to_process_str = trim(strval($current_value_to_process));

        if ($field_type === 'enumeration' && isset($field_meta['items']) && is_array($field_meta['items'])) {
            $found_id_single = null;
            foreach ($field_meta['items'] as $item) {
                if (isset($item['ID'], $item['VALUE']) && strcasecmp(trim($item['VALUE']), $current_value_to_process_str) === 0) {
                    $found_id_single = $item['ID'];
                    break;
                }
            }
            if ($found_id_single !== null)
                $fields_for_api[$field_code] = $found_id_single;
            else {
                error_log("ACTION_IMPORT_DATA_TRANSFORM_WARN: Enum value '{$current_value_to_process_str}' for field '{$field_code}' not found. Skipping field.");
            }
            continue;
        }
        if ($field_type === 'boolean') {
            $tmp_bool_single = strtolower($current_value_to_process_str);
            if (in_array($tmp_bool_single, ['yes', 'y', 'true', '1', 'да', 'д']))
                $fields_for_api[$field_code] = 'Y';
            elseif (in_array($tmp_bool_single, ['no', 'n', 'false', '0', 'нет', 'н']))
                $fields_for_api[$field_code] = 'N';
            else {
                error_log("ACTION_IMPORT_DATA_TRANSFORM_WARN: Boolean value '{$current_value_to_process_str}' for field '{$field_code}' not recognized. Skipping field.");
            }
            continue;
        }
        if (in_array($field_type, ['date', 'datetime'])) {
            $possible_date_formats = ['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'm/d/Y H:i:s', 'm/d/Y H:i', 'm/d/Y'];
            $api_date_value = null;
            foreach ($possible_date_formats as $format) {
                $date_obj = DateTime::createFromFormat($format, $current_value_to_process_str);
                if ($date_obj && $date_obj->format($format) === $current_value_to_process_str) {
                    $api_date_value = $date_obj->format($field_type === 'datetime' ? 'Y-m-d\TH:i:sP' : 'Y-m-d');
                    break;
                }
            }
            if ($api_date_value !== null)
                $fields_for_api[$field_code] = $api_date_value;
            else {
                error_log("ACTION_IMPORT_DATA_TRANSFORM_WARN: Date value '{$current_value_to_process_str}' for field '{$field_code}' not recognized. Skipping field.");
            }
            continue;
        }
        // Universal User/Employee field processing
        if (in_array($field_type, $userFieldTypes)) {
            $user_identifier_from_file = $current_value_to_process_str; // Already trimmed string
            $found_user_id = null;
            $user_search_error_detail = '';

            if (is_numeric($user_identifier_from_file)) {
                $user_id_candidate = (int) $user_identifier_from_file;
                // Optional: Check if user exists by ID
                $user_get_response = callBitrixAPI($action_current_domain, $action_current_access_token, 'user.get', ['ID' => $user_id_candidate]);
                if (isset($user_get_response['result'][0]['ID'])) { // user.get returns an array
                    $found_user_id = $user_get_response['result'][0]['ID'];
                } else {
                    $user_search_error_detail = "User with ID '{$user_id_candidate}' not found.";
                }
            } elseif (filter_var($user_identifier_from_file, FILTER_VALIDATE_EMAIL)) {
                $user_get_response = callBitrixAPI($action_current_domain, $action_current_access_token, 'user.get', ['FILTER' => ['EMAIL' => $user_identifier_from_file, 'ACTIVE' => 'Y']]);
                if (isset($user_get_response['result']) && count($user_get_response['result']) === 1) {
                    $found_user_id = $user_get_response['result'][0]['ID'];
                } elseif (isset($user_get_response['result']) && count($user_get_response['result']) > 1) {
                    $user_search_error_detail = "Multiple active users found for email '{$user_identifier_from_file}'.";
                } else {
                    $user_search_error_detail = "No active user found for email '{$user_identifier_from_file}'.";
                }
            } else {
                // Try as Name LastName or general FIND
                $name_parts = array_map('trim', explode(' ', $user_identifier_from_file, 2));
                $search_params_user = ['FILTER' => ['ACTIVE' => 'Y']];
                $name_to_search = $name_parts[0] ?? '';
                $last_name_to_search = $name_parts[1] ?? '';

                if (!empty($name_to_search) && !empty($last_name_to_search)) {
                    $search_params_user['FILTER']['NAME'] = $name_to_search;
                    $search_params_user['FILTER']['LAST_NAME'] = $last_name_to_search;
                } elseif (!empty($name_to_search)) {
                    $search_params_user['FILTER']['FIND'] = $name_to_search; // Use FIND for single word (name, login, part of email etc.)
                } else {
                    $user_search_error_detail = "User identifier string is empty for search.";
                }

                // Proceed with search only if there's something to search for
                if (
                    empty($user_search_error_detail) &&
                    (isset($search_params_user['FILTER']['FIND']) || (isset($search_params_user['FILTER']['NAME']) && isset($search_params_user['FILTER']['LAST_NAME'])))
                ) {
                    $user_search_response = callBitrixAPI($action_current_domain, $action_current_access_token, 'user.search', $search_params_user);
                    if (isset($user_search_response['result']) && is_array($user_search_response['result']) && count($user_search_response['result']) === 1) {
                        $found_user_id = $user_search_response['result'][0]['ID'];
                    } elseif (isset($user_search_response['result']) && count($user_search_response['result']) > 1) {
                        $user_search_error_detail = "Multiple active users found for '{$user_identifier_from_file}'. Consider using User ID or Email.";
                    } else {
                        // If $user_search_error_detail is already set (e.g. "identifier string is empty"), don't overwrite it
                        $user_search_error_detail = $user_search_error_detail ?: "No active user found for '{$user_identifier_from_file}'.";
                    }
                }
            }

            if ($found_user_id !== null) {
                $fields_for_api[$field_code] = $found_user_id;
            } else {
                if (strtoupper($field_code) === 'ASSIGNED_BY_ID' && $current_b24_user_id_for_import !== null) {
                    $fields_for_api[$field_code] = $current_b24_user_id_for_import;
                    $warning_message_assigned_by = "User '{$user_identifier_from_file}' for field '{$field_meta['title']}' not uniquely found ({$user_search_error_detail}). Used current importer (ID: {$current_b24_user_id_for_import}).";
                    error_log("ACTION_IMPORT_DATA_ITEM_WARN: Item #{$index}, Field '{$field_code} ({$field_meta['title']})': User '{$user_identifier_from_file}' not uniquely found ({$user_search_error_detail}). Used current importer (ID: {$current_b24_user_id_for_import}).");
                    $results['errors'][] = [
                        'itemIndexOriginal' => $index,
                        'errorDetails' => $warning_message_assigned_by,
                        'itemData' => ['field_code' => $field_code, 'original_value' => $user_identifier_from_file],
                        'type' => 'field_fallback_used' // Specific type for this case
                    ];
                } else { // For all other user/employee fields, this is an error
                    $skip_message_user = "User '{$user_identifier_from_file}' for field '{$field_meta['title']}' could not be uniquely identified ({$user_search_error_detail}). This field will be skipped for item #{$index}.";
                    error_log("ACTION_IMPORT_DATA_ITEM_FIELD_SKIPPED (Field: {$field_code}): {$skip_message_user}");
                    $results['errors'][] = [
                        'itemIndexOriginal' => $index,
                        'errorDetails' => $skip_message_user,
                        'itemData' => ['field_code' => $field_code, 'original_value' => $user_identifier_from_file],
                        'type' => 'field_skip_warning'
                    ];
                }
            }
            continue; // Processed user field, move to the next field from the file
        }
        // End of Universal User/Employee field processing
        // Если никакие преобразования не применялись, передаем значение как есть
        if (!array_key_exists($field_code, $fields_for_api)) {
            $fields_for_api[$field_code] = $original_file_value; // Строки, числа, деньги (если формат подходит)
        }
    } // Конец цикла по полям внутри одной строки файла ($item_fields_from_react)
    if ($currentItemHasError) {
        $results['failedCount']++; // Increment failed counter
        error_log("ACTION_IMPORT_DATA_ITEM_SKIPPED: Item #{$index} skipped due to errors during field processing.");
        continue; // Skip this item entirely, move to the next row from file
    }
    // Гарантируем наличие TITLE
    if (!isset($fields_for_api['TITLE']) || empty(trim(strval($fields_for_api['TITLE'])))) {
        $potential_title = null;
        foreach ($fields_for_api as $f_key => $f_val) {
            if (in_array(strtoupper($f_key), ['ID', 'ASSIGNED_BY_ID', 'OBSERVER_IDS', 'CREATED_BY', 'UPDATED_BY', 'MOVED_BY']))
                continue;
            if (!empty($f_val) && (is_string($f_val) || is_numeric($f_val))) {
                $potential_title = strval($f_val);
                break;
            }
        }
        if (!empty($potential_title)) {
            $fields_for_api['TITLE'] = $potential_title;
        } else {
            $results['failedCount']++;
            $results['errors'][] = ['itemIndexOriginal' => $index, 'errorDetails' => 'TITLE field is missing or could not be determined for item after transformations.', 'itemData' => $item_fields_from_react];
            error_log("ACTION_IMPORT_DATA_ITEM_ERROR: Item #{$index} - TITLE missing. Original data: " . json_encode($item_fields_from_react));
            continue; // Пропускаем этот элемент
        }
    }
    if (empty($fields_for_api)) { // Если после всех преобразований и фильтраций не осталось полей
        error_log("ACTION_IMPORT_DATA_ITEM_WARN: Item #{$index} resulted in empty fields after processing. Original data: " . json_encode($item_fields_from_react));
        // Решите, считать ли это ошибкой. Пока пропускаем.
        continue;
    }

    $command_params_string = http_build_query(['entityTypeId' => $entityTypeId, 'fields' => $fields_for_api]);
    $all_batch_commands_as_strings[$command_key] = 'crm.item.add?' . $command_params_string;
    $original_indices_map[$command_key] = $index;
} // Конец основного цикла по строкам из файла ($items_to_import_from_react)


// --- Часть 4: Отправка batch-запросов и обработка результатов ---
if (empty($all_batch_commands_as_strings)) {
    $results['message'] = 'No items to import after data preparation stage.';
    if (isset($items_to_import_from_react) && $results['failedCount'] == count($items_to_import_from_react) && $results['failedCount'] > 0) {
        $results['success'] = false; // Все элементы привели к ошибке на этапе подготовки
        $results['message'] = 'All items resulted in errors during data preparation.';
    } elseif (count($items_to_import_from_react) === 0 && $results['failedCount'] === 0) {
        $results['message'] = 'No valid items found in the file to import.';
    }
    error_log("ACTION_IMPORT_DATA_INFO: No batch commands to send. Message: " . $results['message']);
    echo json_encode($results);
    exit;
}

error_log("ACTION_IMPORT_DATA_DEBUG: Preparing to send " . count($all_batch_commands_as_strings) . " batch commands in chunks.");
// Для отладки команд: file_put_contents(DATA_DIR . 'import_batch_commands_log.txt', date('[Y-m-d H:i:s] ') . print_r($all_batch_commands_as_strings, true) . PHP_EOL, FILE_APPEND | LOCK_EX);

$chunked_batch_commands = array_chunk($all_batch_commands_as_strings, 50, true);
foreach ($chunked_batch_commands as $chunk_index => $current_batch_chunk_strings) {
    if (empty($current_batch_chunk_strings))
        continue;

    error_log("ACTION_IMPORT_DATA_DEBUG: Sending batch chunk #" . ($chunk_index + 1) . " with " . count($current_batch_chunk_strings) . " commands.");
    $batch_response = callBitrixAPI($action_current_domain, $action_current_access_token, 'batch', ['halt' => 0, 'cmd' => $current_batch_chunk_strings]);

    if (isset($batch_response['error']) && !isset($batch_response['result']) && strtolower($batch_response['error']) !== 'expired_token') { // Исключаем ошибку токена, т.к. ее должен обрабатывать auth_handler
        $results['success'] = false; // Глобальная ошибка batch-запроса
        $error_detail_msg = "Batch API call failed for chunk #" . ($chunk_index + 1) . ": " . ($batch_response['error_description'] ?? $batch_response['error']);
        error_log("ACTION_IMPORT_DATA_BATCH_ERROR: " . $error_detail_msg . ". Details: " . json_encode($batch_response));
        foreach (array_keys($current_batch_chunk_strings) as $cmd_key) {
            $orig_idx = $original_indices_map[$cmd_key] ?? ('Unknown_in_Chunk_' . $chunk_index);
            $results['failedCount']++;
            $results['errors'][] = ['itemIndexOriginal' => $orig_idx, 'errorDetails' => $error_detail_msg, 'itemData' => $items_to_import_from_react[$orig_idx] ?? []];
        }
    } elseif (isset($batch_response['result'])) {
        $cmd_results = $batch_response['result']['result'] ?? [];
        $cmd_errors = $batch_response['result']['result_error'] ?? [];

        foreach ($cmd_results as $cmd_key => $s_data) {
            $orig_idx = $original_indices_map[$cmd_key] ?? ('Unknown_Res_in_Chunk_' . $chunk_index);
            if (isset($s_data['item']['id'])) {
                $results['importedCount']++;
            } else {
                $results['failedCount']++;
                $item_error_detail = 'Item processed by batch, but no ID returned. Response: ' . json_encode($s_data);
                $results['errors'][] = ['itemIndexOriginal' => $orig_idx, 'errorDetails' => $item_error_detail, 'itemData' => $items_to_import_from_react[$orig_idx] ?? null];
                error_log("ACTION_IMPORT_DATA_ITEM_WARN: Item #{$orig_idx} - {$item_error_detail}");
            }
        }
        foreach ($cmd_errors as $cmd_key => $e_data) {
            $orig_idx = $original_indices_map[$cmd_key] ?? ('Unknown_Err_in_Chunk_' . $chunk_index);
            $results['failedCount']++;
            $e_desc = 'Unknown item error in batch.';
            if (is_array($e_data) && isset($e_data['error_description']))
                $e_desc = $e_data['error_description'];
            elseif (is_string($e_data))
                $e_desc = $e_data;
            else
                $e_desc = json_encode($e_data);
            $results['errors'][] = ['itemIndexOriginal' => $orig_idx, 'errorDetails' => $e_desc, 'itemData' => $items_to_import_from_react[$orig_idx] ?? null];
            error_log("ACTION_IMPORT_DATA_ITEM_ERROR: Item #{$orig_idx} - Batch error: {$e_desc}");
        }
    } else {
        $results['success'] = false;
        $unknown_error_msg = 'Unknown batch response format in chunk ' . ($chunk_index + 1) . '.';
        $results['message'] .= ($results['message'] ? ' ' : '') . $unknown_error_msg;
        error_log("ACTION_IMPORT_DATA_BATCH_ERROR: " . $unknown_error_msg . ". Details: " . json_encode($batch_response));
        foreach (array_keys($current_batch_chunk_strings) as $cmd_key) {
            $orig_idx = $original_indices_map[$cmd_key] ?? ('Unknown_Fail_in_Chunk_' . $chunk_index);
            $results['failedCount']++;
            $results['errors'][] = ['itemIndexOriginal' => $orig_idx, 'errorDetails' => "Unknown batch API response for item: " . json_encode($batch_response), 'itemData' => $items_to_import_from_react[$orig_idx] ?? []];
        }
    }
} // конец цикла по чанкам

// Финальное формирование сообщения и статуса успеха
if ($results['failedCount'] > 0 && $results['importedCount'] === 0 && count($items_to_import_from_react) > 0) {
    $results['success'] = false;
} elseif ($results['failedCount'] > 0 && $results['importedCount'] > 0) {
    $results['success'] = true; // Частичный успех
    $results['message'] = "Import partially completed. Imported: {$results['importedCount']}. Failed: {$results['failedCount']}.";
} elseif ($results['importedCount'] > 0) {
    $results['message'] = "Import successful. Imported: {$results['importedCount']} items.";
} elseif ($results['failedCount'] > 0) {
    $results['success'] = false;
    $results['message'] = "Import failed. All {$results['failedCount']} processed items had errors.";
} else { // Ничего не импортировано, и ошибок не было (или все отфильтровано до batch)
    $results['message'] = $results['message'] ?: "No items were imported (e.g., empty file, no mappable data, or all items filtered prior to sending).";
}

error_log("ACTION_IMPORT_DATA_INFO: Import finished. Success: " . ($results['success'] ? 'Yes' : 'No') . ". Message: " . $results['message'] . ". Imported: " . $results['importedCount'] . ". Failed: " . $results['failedCount']);
echo json_encode($results);
exit;
?>