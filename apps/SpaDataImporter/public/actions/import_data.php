<?php
// /importToSpa/actions/import_data.php

if (!defined('DATA_DIR')) {
    $configPath = __DIR__ . '/../../config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
    } else {
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => 'config_error', 'message' => 'Application configuration not found.']));
    }
}
if (!function_exists('callBitrixAPI')) {
    $apiHelperPath = __DIR__ . '/../includes/api_helper.php';
    if (file_exists($apiHelperPath)) {
        require_once $apiHelperPath;
    } else {
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => 'config_error', 'message' => 'API helper function not found.']));
    }
}

// --- 1. Get and Validate Payload ---
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

// --- 2. Authenticate and Prepare Context ---
$action_member_id = htmlspecialchars($member_id_from_payload);
$action_current_domain = htmlspecialchars($domain_from_payload);
$safe_action_member_id_part = preg_replace('/[^a-zA-Z0-9_.-]/', '', $action_member_id);
$safe_action_domain_part = preg_replace('#^https?://#', '', $action_current_domain);
$safe_action_domain_part = str_replace(['.', '-'], '_', $safe_action_domain_part);
$safe_action_domain_part = preg_replace('/[^a-zA-Z0-9_]/', '', $safe_action_domain_part);
$action_token_file_path = DATA_DIR . 'token_' . $safe_action_domain_part . '_' . $safe_action_member_id_part . '.json';

if (!file_exists($action_token_file_path)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => "Token file missing. Ensure app is installed."]));
}
$saved_data_for_import = json_decode(file_get_contents($action_token_file_path), true);
if (!$saved_data_for_import || empty($saved_data_for_import['access_token'])) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Invalid token data. Reinstall app.']));
}

// (Здесь должна быть логика обновления токена, как в вашем последнем рабочем файле)
// Для краткости дирижера, пока предполагаем, что токен валиден или будет обновлен внутри хелперов, если понадобится.
// Пока просто берем токен.
$action_current_access_token = $saved_data_for_import['access_token'];

// --- 3. Execute Import Stages ---

// Initialize shared variables for the import process
$results = ['success' => true, 'message' => '', 'importedCount' => 0, 'updatedCount' => 0, 'failedCount' => 0, 'errors' => []];
$prepared_items = []; // Will be populated by 1_prepare_data.php

// Stage 1: Prepare and transform data from React
require_once __DIR__ . '/import_helpers/1_prepare_data.php';

// Stage 2: Find duplicates based on prepared data
// This stage will populate $existing_items_map
$existing_items_map = []; // Will be populated by 2_find_duplicates.php
if (!empty($prepared_items)) {
    require_once __DIR__ . '/import_helpers/2_find_duplicates.php';
}

// Stage 3: Execute Add/Update operations and finalize results
// This stage will use $prepared_items and $existing_items_map to build and run the final batch
$final_batch_commands = []; // Will be populated by 3_execute_import.php
if (!empty($prepared_items)) {
    require_once __DIR__ . '/import_helpers/3_execute_import.php';
}

// Final response to the client
if ($results['failedCount'] > 0 && ($results['importedCount'] + $results['updatedCount']) === 0 && count($items_to_import_from_react) > 0) {
    $results['success'] = false;
}

$message_parts = [];
if ($results['importedCount'] > 0) {
    $message_parts[] = "Imported: {$results['importedCount']}";
}
if ($results['updatedCount'] > 0) {
    $message_parts[] = "Updated: {$results['updatedCount']}";
}
if ($results['failedCount'] > 0) {
    $message_parts[] = "Failed: {$results['failedCount']}";
}

if (empty($message_parts)) {
    $results['message'] = $results['message'] ?: "No items were processed.";
} else {
    $results['message'] = "Processing complete. " . implode('. ', $message_parts) . ".";
}

error_log("ACTION_IMPORT_DATA_FINAL: " . json_encode($results));
echo json_encode($results);
exit;

?>