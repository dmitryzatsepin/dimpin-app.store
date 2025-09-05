<?php
// /importToSpa/actions/import_helpers/3_execute_import.php (ИСПРАВЛЕННАЯ ВЕРСИЯ)
// Stage 3: Build and execute the final batch of add/update commands.

// These variables are expected to be available from the parent script (import_data.php):
// $action_current_domain, $action_current_access_token, $entityTypeId,
// $prepared_items, $existing_items_map, &$results

error_log("IMPORT_HELPER_3: Executing final import/update stage...");

$final_batch_commands = [];
$original_indices_map = [];

// --- Build Final Batch Commands (Add or Update) ---
foreach ($prepared_items as $index => $fields_for_api) {
    $current_title = $fields_for_api['TITLE'];
    $existing_item_status = $existing_items_map[$current_title] ?? null;

    if ($existing_item_status === 'multiple') {
        $results['failedCount']++;
        $results['errors'][] = [
            'itemIndexOriginal' => $index,
            'errorDetails' => "Skipped: Multiple items with title '{$current_title}' already exist. Cannot determine which one to update.",
            'type' => 'duplicate_error'
        ];
        continue;
    }

    if (isset($existing_item_status['id'])) {
        $item_id_to_update = $existing_item_status['id'];
        unset($fields_for_api['TITLE']);
        if (empty($fields_for_api)) {
            continue;
        }
        $command_key = 'update_item_' . $index;
        $final_batch_commands[$command_key] = 'crm.item.update?' . http_build_query([
            'entityTypeId' => $entityTypeId,
            'id' => $item_id_to_update,
            'fields' => $fields_for_api
        ]);
        $original_indices_map[$command_key] = $index;
    } else {
        $command_key = 'add_item_' . $index;
        $final_batch_commands[$command_key] = 'crm.item.add?' . http_build_query([
            'entityTypeId' => $entityTypeId,
            'fields' => $fields_for_api
        ]);
        $original_indices_map[$command_key] = $index;
    }
}

// --- Execute Final Batch Request ---
if (empty($final_batch_commands)) {
    error_log("IMPORT_HELPER_3_INFO: No items left to add or update after duplicate check.");
    return;
}

$chunked_batch_commands = array_chunk($final_batch_commands, 50, true);
foreach ($chunked_batch_commands as $chunk_index => $current_batch_chunk) {
    if (empty($current_batch_chunk))
        continue;

    error_log("IMPORT_HELPER_3_DEBUG: Sending final batch chunk #" . ($chunk_index + 1) . " with " . count($current_batch_chunk) . " commands.");
    $batch_response = callBitrixAPI($action_current_domain, $action_current_access_token, 'batch', ['halt' => 0, 'cmd' => $current_batch_chunk]);

    if (isset($batch_response['result']['result'])) {
        $cmd_results = $batch_response['result']['result'] ?? [];
        $cmd_errors = $batch_response['result']['result_error'] ?? [];

        foreach ($cmd_results as $cmd_key => $s_data) {
            $orig_idx = $original_indices_map[$cmd_key] ?? ('Unknown_Res_in_Chunk_' . $chunk_index);

            if (strpos($cmd_key, 'update_item_') === 0) {
                // Successful crm.item.update returns an object like: {"item": {"id": 123, ...}}
                if (isset($s_data['item']['id'])) {
                    $results['updatedCount']++;
                } else {
                    $results['failedCount']++;
                    $failure_details = 'Update command returned an unexpected response format.';
                    if (is_array($s_data)) {
                        $failure_details = json_encode($s_data);
                    } elseif (!empty($s_data)) {
                        $failure_details = strval($s_data);
                    }
                    $results['errors'][] = ['itemIndexOriginal' => $orig_idx, 'errorDetails' => $failure_details, 'itemData' => $s_data];
                }
            } elseif (strpos($cmd_key, 'add_item_') === 0) {
                // Successful crm.item.add also returns {"item": {"id": 123, ...}}
                if (isset($s_data['item']['id'])) {
                    $results['importedCount']++;
                } else {
                    $results['failedCount']++;
                    $results['errors'][] = ['itemIndexOriginal' => $orig_idx, 'errorDetails' => 'Item add command was processed but no ID was returned.', 'itemData' => $s_data];
                }
            }
        }

        foreach ($cmd_errors as $cmd_key => $e_data) {
            $orig_idx = $original_indices_map[$cmd_key] ?? ('Unknown_Err_in_Chunk_' . $chunk_index);
            $results['failedCount']++;
            $e_desc = 'Unknown API error for item.';
            if (is_array($e_data) && isset($e_data['error_description']))
                $e_desc = $e_data['error_description'];
            elseif (is_string($e_data))
                $e_desc = $e_data;
            else
                $e_desc = json_encode($e_data);
            $results['errors'][] = ['itemIndexOriginal' => $orig_idx, 'errorDetails' => $e_desc, 'itemData' => $e_data];
            error_log("IMPORT_HELPER_3_ITEM_ERROR: Item #{$orig_idx} failed in batch. Error: {$e_desc}");
        }
    } else {
        $error_desc = $batch_response['error_description'] ?? 'Global batch execution failed.';
        error_log("IMPORT_HELPER_3_CRITICAL_ERROR: The entire final batch request failed. Error: {$error_desc}");
        foreach (array_keys($current_batch_chunk) as $cmd_key) {
            $orig_idx = $original_indices_map[$cmd_key] ?? ('Unknown_Fail_in_Chunk_' . $chunk_index);
            $results['failedCount']++;
            $results['errors'][] = ['itemIndexOriginal' => $orig_idx, 'errorDetails' => $error_desc, 'itemData' => $batch_response];
        }
    }
}

error_log("IMPORT_HELPER_3: Import/update execution finished.");
?>