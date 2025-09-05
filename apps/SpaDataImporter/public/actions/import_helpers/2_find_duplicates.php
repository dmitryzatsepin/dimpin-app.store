<?php
// /importToSpa/actions/import_helpers/2_find_duplicates.php
// Stage 2: Find duplicates by TITLE for all prepared items.

// These variables are expected to be available from the parent script (import_data.php):
// $action_current_domain, $action_current_access_token, $entityTypeId,
// $prepared_items, &$existing_items_map

error_log("IMPORT_HELPER_2: Finding duplicates for " . count($prepared_items) . " items...");

$search_batch_commands = [];
$titles_to_search_map = []; // Use a map to link TITLE back to original prepared_items keys

foreach ($prepared_items as $index => $fields_for_api) {
    // We must have a TITLE at this stage, as ensured by the previous helper
    $current_title = $fields_for_api['TITLE'] ?? null;

    if (empty($current_title)) {
        continue; // Should not happen if 1_prepare_data.php worked correctly
    }

    // Add search command to batch only if this title hasn't been added yet
    // This avoids searching for the same title multiple times if it's duplicated in the source file
    if (!isset($titles_to_search_map[$current_title])) {
        // We use a clean key for the batch command array
        $command_key = 'search_title_' . count($search_batch_commands);

        $search_batch_commands[$command_key] = 'crm.item.list?' . http_build_query([
            'entityTypeId' => $entityTypeId,
            'filter' => ['=title' => $current_title],
            'select' => ['id'] // We only need the ID to check for existence
        ]);

        // Map the title to the command key so we can find it in the response
        $titles_to_search_map[$current_title] = $command_key;
    }
}

// Execute the batch search request only if there are titles to search for
if (!empty($search_batch_commands)) {
    $search_response = callBitrixAPI($action_current_domain, $action_current_access_token, 'batch', ['halt' => 0, 'cmd' => $search_batch_commands]);

    if (isset($search_response['result']['result'])) {
        $search_results_by_cmd = $search_response['result']['result'] ?? [];
        $search_errors_by_cmd = $search_response['result']['result_error'] ?? [];

        // Now, populate the $existing_items_map based on the search results
        foreach ($titles_to_search_map as $title => $command_key) {
            if (isset($search_results_by_cmd[$command_key])) {
                // Command executed successfully
                $search_result_items = $search_results_by_cmd[$command_key]['items'] ?? [];

                if (count($search_result_items) === 1) {
                    // Found exactly one item, store its ID
                    $existing_items_map[$title] = ['id' => $search_result_items[0]['id']];
                } elseif (count($search_result_items) > 1) {
                    // Found multiple items with the same title, mark as ambiguous
                    $existing_items_map[$title] = 'multiple';
                } else {
                    // Found zero items
                    $existing_items_map[$title] = null;
                }
            } elseif (isset($search_errors_by_cmd[$command_key])) {
                // An error occurred for this specific search command
                $error_data = $search_errors_by_cmd[$command_key];
                $error_desc = $error_data['error_description'] ?? 'Unknown search error';
                error_log("IMPORT_HELPER_2_ERROR: Search for title '{$title}' failed. Error: {$error_desc}");
                // Mark this title as not found to be safe, so we will attempt to create it.
                // Or you could mark it as an error to skip it entirely. Let's mark as not found.
                $existing_items_map[$title] = null;
            }
        }
    } else {
        // The entire batch request failed
        $error_desc = $search_response['error_description'] ?? 'Batch search request failed.';
        error_log("IMPORT_HELPER_2_CRITICAL_ERROR: The entire batch search request failed. Error: {$error_desc}");
        // In this case, we can't determine duplicates, so we might have to stop
        // or assume nothing exists, which would lead to creating all items.
        // For safety, let's stop the process if the search fails globally.
        $results['success'] = false;
        $results['message'] = 'Could not check for duplicates due to a server error.';
        $results['details'] = $search_response;
        return;
    }
}

error_log("IMPORT_HELPER_2: Duplicate check finished. Found " . count(array_filter($existing_items_map)) . " existing titles.");

?>