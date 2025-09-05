<?php
// /importToSpa/actions/import_helpers/1_prepare_data.php
// Stage 1: Prepare and transform data received from React.

// These variables are expected to be available from the parent script (import_data.php):
// $action_current_domain, $action_current_access_token, $entityTypeId,
// $items_to_import_from_react, &$results, &$prepared_items, $saved_data_for_import

error_log("IMPORT_HELPER_1: Preparing data...");

// --- Get Field Metadata ---
$process_fields_meta_response = callBitrixAPI($action_current_domain, $action_current_access_token, 'crm.item.fields', ['entityTypeId' => $entityTypeId]);
$process_fields_meta = [];
if (isset($process_fields_meta_response['result']['fields'])) {
    $process_fields_meta = $process_fields_meta_response['result']['fields'];
} else {
    $results['success'] = false;
    $results['message'] = 'Failed to retrieve field metadata for import.';
    $results['details'] = $process_fields_meta_response;
    error_log("IMPORT_HELPER_1_CRITICAL_ERROR: Failed to get field metadata for entityTypeId {$entityTypeId}. API Resp: " . print_r($process_fields_meta_response, true));
    return; // Stop execution of this include, the parent script will handle the rest.
}

$current_b24_user_id_for_import = $saved_data_for_import['user_id'] ?? null;
$userFieldTypes = ['employee', 'user']; // Customize this array if your user field types are different

// --- Iterate and Transform Each Row ---
foreach ($items_to_import_from_react as $index => $item_fields_from_react) {
    $fields_for_api = [];
    $currentItemHasError = false; // This flag is for critical errors that should stop the entire row from being processed.

    foreach ($item_fields_from_react as $field_code => $file_value) {
        if ($currentItemHasError)
            break;

        $original_file_value = $file_value;
        if ($file_value === null || (is_string($file_value) && trim($file_value) === '')) {
            continue;
        }

        $field_meta = $process_fields_meta[$field_code] ?? null;
        if (!$field_meta && strtoupper($field_code) !== 'TITLE') {
            continue;
        }

        $field_type = $field_meta['type'] ?? 'string';
        $is_multiple = $field_meta['isMultiple'] ?? false;
        $current_value_to_process = $original_file_value;

        // --- Transformations Block ---
        if ($is_multiple && is_array($current_value_to_process)) {
            $transformed_multiple_values = [];
            foreach ($current_value_to_process as $single_val) {
                if ($single_val === null || (is_string($single_val) && trim($single_val) === ''))
                    continue;
                $processed_val = trim(strval($single_val));
                // Apply transformations to $processed_val here. If a value is invalid, it's just skipped from the array.
                if ($field_type === 'enumeration' && isset($field_meta['items'])) {
                    $found_id = null;
                    foreach ($field_meta['items'] as $item) {
                        if (isset($item['ID'], $item['VALUE']) && strcasecmp(trim($item['VALUE']), $processed_val) === 0) {
                            $found_id = $item['ID'];
                            break;
                        }
                    }
                    if ($found_id !== null) {
                        $processed_val = $found_id;
                    } else {
                        continue;
                    }
                }
                $transformed_multiple_values[] = $processed_val;
            }
            if (!empty($transformed_multiple_values))
                $fields_for_api[$field_code] = $transformed_multiple_values;
            continue;
        }

        $current_value_to_process_str = trim(strval($current_value_to_process));

        if (in_array($field_type, $userFieldTypes)) {
            $user_identifier_from_file = $current_value_to_process_str;
            $found_user_id = null;
            $user_search_error_detail = '';
            if (is_numeric($user_identifier_from_file)) {
                $user_get_response = callBitrixAPI($action_current_domain, $action_current_access_token, 'user.get', ['ID' => (int) $user_identifier_from_file]);
                if (isset($user_get_response['result'][0]['ID'])) {
                    $found_user_id = $user_get_response['result'][0]['ID'];
                } else {
                    $user_search_error_detail = "User with ID '{$user_identifier_from_file}' not found.";
                }
            } elseif (filter_var($user_identifier_from_file, FILTER_VALIDATE_EMAIL)) {
                $user_get_response = callBitrixAPI($action_current_domain, $action_current_access_token, 'user.get', ['FILTER' => ['EMAIL' => $user_identifier_from_file, 'ACTIVE' => 'Y']]);
                if (isset($user_get_response['result']) && count($user_get_response['result']) === 1) {
                    $found_user_id = $user_get_response['result'][0]['ID'];
                } else {
                    $user_search_error_detail = "No unique active user found for email '{$user_identifier_from_file}'.";
                }
            } else {
                $name_parts = array_map('trim', explode(' ', $user_identifier_from_file, 2));
                $search_params_user = ['FILTER' => ['ACTIVE' => 'Y']];
                $name_to_search = $name_parts[0] ?? '';
                $last_name_to_search = $name_parts[1] ?? '';
                if (!empty($name_to_search) && !empty($last_name_to_search)) {
                    $search_params_user['FILTER']['NAME'] = $name_to_search;
                    $search_params_user['FILTER']['LAST_NAME'] = $last_name_to_search;
                } elseif (!empty($name_to_search)) {
                    $search_params_user['FILTER']['FIND'] = $name_to_search;
                } else {
                    $user_search_error_detail = "User identifier string is empty.";
                }
                if (empty($user_search_error_detail)) {
                    $user_search_response = callBitrixAPI($action_current_domain, $action_current_access_token, 'user.search', $search_params_user);
                    if (isset($user_search_response['result']) && is_array($user_search_response['result']) && count($user_search_response['result']) === 1) {
                        $found_user_id = $user_search_response['result'][0]['ID'];
                    } else {
                        $user_search_error_detail = "No unique active user found for '{$user_identifier_from_file}'.";
                    }
                }
            }
            if ($found_user_id !== null) {
                $fields_for_api[$field_code] = $found_user_id;
            } else {
                if (strtoupper($field_code) === 'ASSIGNED_BY_ID' && $current_b24_user_id_for_import !== null) {
                    $fields_for_api[$field_code] = $current_b24_user_id_for_import;
                    $results['errors'][] = ['itemIndexOriginal' => $index, 'errorDetails' => "Warning for '{$field_meta['title']}': User '{$user_identifier_from_file}' not found, used current importer. {$user_search_error_detail}", 'type' => 'field_fallback_used'];
                } else {
                    $results['errors'][] = ['itemIndexOriginal' => $index, 'errorDetails' => "Field '{$field_meta['title']}': User '{$user_identifier_from_file}' not found and was skipped. {$user_search_error_detail}", 'type' => 'field_skip_warning'];
                }
            }
            continue;
        }

        if ($field_type === 'enumeration' && isset($field_meta['items']) && is_array($field_meta['items'])) {
            $found_id_single = null;
            foreach ($field_meta['items'] as $item) {
                if (isset($item['ID'], $item['VALUE']) && strcasecmp(trim($item['VALUE']), $current_value_to_process_str) === 0) {
                    $found_id_single = $item['ID'];
                    break;
                }
            }
            if ($found_id_single !== null) {
                $fields_for_api[$field_code] = $found_id_single;
            } else {
                $results['errors'][] = ['itemIndexOriginal' => $index, 'errorDetails' => "Field '{$field_meta['title']}': Value '{$current_value_to_process_str}' not found in list and was skipped.", 'type' => 'field_skip_warning'];
            }
            continue;
        }

        if ($field_type === 'boolean') {
            $tmp_bool_single = strtolower($current_value_to_process_str);
            if (in_array($tmp_bool_single, ['yes', 'y', 'true', '1', 'да', 'д'])) {
                $fields_for_api[$field_code] = 'Y';
            } elseif (in_array($tmp_bool_single, ['no', 'n', 'false', '0', 'нет', 'н'])) {
                $fields_for_api[$field_code] = 'N';
            } else {
                $results['errors'][] = ['itemIndexOriginal' => $index, 'errorDetails' => "Field '{$field_meta['title']}': Value '{$current_value_to_process_str}' not recognized as boolean and was skipped.", 'type' => 'field_skip_warning'];
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
            if ($api_date_value !== null) {
                $fields_for_api[$field_code] = $api_date_value;
            } else {
                $results['errors'][] = ['itemIndexOriginal' => $index, 'errorDetails' => "Field '{$field_meta['title']}': Date value '{$current_value_to_process_str}' not recognized and was skipped.", 'type' => 'field_skip_warning'];
            }
            continue;
        }

        if (!array_key_exists($field_code, $fields_for_api)) {
            $fields_for_api[$field_code] = $original_file_value;
        }
    } // End foreach field in row

    if ($currentItemHasError) {
        $results['failedCount']++;
        continue;
    }

    if (!isset($fields_for_api['TITLE']) || empty(trim(strval($fields_for_api['TITLE'])))) {
        $potential_title = null;
        foreach ($fields_for_api as $f_key => $f_val) {
            if (in_array(strtoupper($f_key), ['ID', 'ASSIGNED_BY_ID', 'OBSERVERS']))
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
            $results['errors'][] = ['itemIndexOriginal' => $index, 'errorDetails' => 'TITLE is missing and could not be determined. This row was skipped.'];
            continue;
        }
    }

    $prepared_items[$index] = $fields_for_api;

} // End foreach row from React

error_log("IMPORT_HELPER_1: Data preparation finished. Prepared " . count($prepared_items) . " items. Found " . $results['failedCount'] . " rows with critical errors during preparation.");

?>