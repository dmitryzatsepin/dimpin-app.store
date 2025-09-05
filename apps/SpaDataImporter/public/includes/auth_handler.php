<?php
// /importFromSpa/includes/auth_handler.php (ИСПРАВЛЕННАЯ ВЕРСИЯ)

if (!defined('DATA_DIR') || !defined('B24_CLIENT_ID') || !defined('B24_CLIENT_SECRET')) {
    $configPath = __DIR__ . '/../config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
    } else {
        error_log("CRITICAL_AUTH_HANDLER: Required constants not defined and config.php not found.");
        if (isset($_GET['action']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            die(json_encode(['error' => 'config_error', 'message' => 'Application configuration is missing.']));
        }
        die("Critical Configuration Error: Application constants not defined. Please check installation.");
    }
}

global $current_access_token, $current_domain, $member_id, $token_file_path, $initial_app_data_for_react, $tokens_processed_successfully;

$is_b24_auth_post_request = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['AUTH_ID']) && isset($_POST['REFRESH_ID']);
$local_is_api_action_request = isset($_GET['action']);

error_log("AUTH_HANDLER_ENTRY: is_b24_auth_post_request=" . ($is_b24_auth_post_request ? 'true' : 'false') . ", local_is_api_action_request=" . ($local_is_api_action_request ? 'true' : 'false'));
error_log("AUTH_HANDLER_ENTRY: member_id='{$member_id}', current_domain='{$current_domain}', token_file_path=" . var_export($token_file_path, true));

// 1. Handle POST auth request from Bitrix24 (on install or regular app open)
if ($is_b24_auth_post_request && (!$local_is_api_action_request || ($local_is_api_action_request && $_GET['action'] !== 'import_data'))) {
    error_log("AUTH_HANDLER_BRANCH_1: Handling POST auth from Bitrix24.");

    if (empty($current_domain) || empty($member_id) || empty($token_file_path)) {
        $error_message = "Domain, member_id or token_file_path is missing or empty. Cannot process auth. Domain: '{$current_domain}', MemberID: '{$member_id}', TokenFilePath: " . var_export($token_file_path, true);
        error_log("AUTH_HANDLER_BRANCH_1_ERROR: " . $error_message);
        if ($local_is_api_action_request) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            die(json_encode(['error' => 'internal_config_error', 'message' => 'Cannot determine critical auth parameters.']));
        }
        http_response_code(500);
        echo "Internal Server Error: " . $error_message;
        exit;
    }

    // --- START OF CRITICAL FIX ---
    // First, try to read the existing token file to preserve the manually entered client_secret
    $existing_data = [];
    if (file_exists($token_file_path)) {
        $existing_json = @file_get_contents($token_file_path);
        if ($existing_json) {
            $existing_data = json_decode($existing_json, true) ?: [];
        }
    }

    // Get client_id from the B24 POST request if available. Fallback to existing data, then to config.
    $client_id_to_save = isset($_POST['APP_ID']) ? htmlspecialchars($_POST['APP_ID']) : ($existing_data['client_id'] ?? B24_CLIENT_ID);

    // Get client_secret from the EXISTING file. Fallback to config only if file didn't exist.
    // This is the key to preserving your manual change.
    $client_secret_to_save = $existing_data['client_secret'] ?? B24_CLIENT_SECRET;
    // --- END OF CRITICAL FIX ---

    $data_to_save = [
        'client_id' => $client_id_to_save,
        'client_secret' => $client_secret_to_save, // Now this will be the one from the file (if it exists)
        'domain' => $current_domain,
        'access_token' => htmlspecialchars($_POST['AUTH_ID']),
        'expires_at' => time() + (isset($_POST['AUTH_EXPIRES']) ? intval($_POST['AUTH_EXPIRES']) : 3600),
        'refresh_token' => htmlspecialchars($_POST['REFRESH_ID']),
        'member_id' => $member_id,
        'user_id' => isset($_POST['USER_ID']) ? htmlspecialchars($_POST['USER_ID']) : ($existing_data['user_id'] ?? null),
        'status' => isset($_POST['status']) ? htmlspecialchars($_POST['status']) : ($existing_data['status'] ?? null),
    ];

    if (file_put_contents($token_file_path, json_encode($data_to_save, JSON_PRETTY_PRINT))) {
        $current_access_token = $data_to_save['access_token'];
        $tokens_processed_successfully = true;
        $initial_app_data_for_react['domain'] = $current_domain;
        $initial_app_data_for_react['member_id'] = $member_id;
        error_log("AUTH_HANDLER_BRANCH_1_SUCCESS: Tokens saved/updated to '{$token_file_path}' for member_id {$member_id}.");
    } else {
        error_log("AUTH_HANDLER_BRANCH_1_ERROR: Failed to save tokens for member_id {$member_id} to '{$token_file_path}'. Check permissions.");
        if ($local_is_api_action_request) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            die(json_encode(['error' => 'auth_error', 'message' => 'Failed to save tokens on POST auth.']));
        }
        http_response_code(500);
        echo "Error: Failed to save tokens during POST auth.";
        exit;
    }
}
// 2. Regular app open or API request (tokens should already exist)
elseif (isset($token_file_path) && file_exists($token_file_path)) {
    error_log("AUTH_HANDLER_BRANCH_2: Token file '{$token_file_path}' exists. Attempting to load/refresh.");
    $saved_data = json_decode(file_get_contents($token_file_path), true);
    if (!$saved_data || empty($saved_data['access_token']) || empty($saved_data['domain']) || empty($saved_data['client_id']) || empty($saved_data['client_secret'])) { // Added check for client keys
        error_log("AUTH_HANDLER_ERROR: Failed to read or parse saved token file, or it is missing critical keys: {$token_file_path}");
        if ($local_is_api_action_request) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            die(json_encode(['error' => 'auth_error', 'message' => 'Token file is corrupted or missing critical keys. Please reinstall the application.']));
        }
        http_response_code(500);
        echo "Error: Could not read saved token data or it is corrupted. Please reinstall the application.";
        exit;
    }

    if (empty($current_domain)) {
        $current_domain = $saved_data['domain'];
    } elseif ($current_domain !== $saved_data['domain'] && (!$local_is_api_action_request || ($local_is_api_action_request && $_GET['action'] !== 'import_data'))) {
        error_log("AUTH_HANDLER_WARNING: Domain mismatch. Request: '{$current_domain}', Stored: '{$saved_data['domain']}'. Using stored for member_id '{$member_id}'.");
        $current_domain = $saved_data['domain'];
    }

    if (time() >= $saved_data['expires_at'] - 60) {
        error_log("AUTH_HANDLER_DEBUG: Token expired or nearing expiration for member_id {$member_id}. Attempting refresh.");
        $refresh_url = 'https://' . $saved_data['domain'] . '/oauth/token/';
        $refresh_query_params = http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $saved_data['client_id'],
            'client_secret' => $saved_data['client_secret'],
            'refresh_token' => $saved_data['refresh_token']
        ]);
        if (!function_exists('callBitrixAPI')) {
            $apiHelperPath = __DIR__ . '/api_helper.php';
            if (file_exists($apiHelperPath)) {
                require_once $apiHelperPath;
            } else { /* ... error handling ... */
            }
        }
        $ch_refresh = curl_init();
        curl_setopt_array($ch_refresh, [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $refresh_url . '?' . $refresh_query_params, CURLOPT_TIMEOUT => 30,]);
        $response_refresh_body = curl_exec($ch_refresh);
        $error_refresh = curl_error($ch_refresh);
        $http_code_refresh = curl_getinfo($ch_refresh, CURLINFO_HTTP_CODE);
        curl_close($ch_refresh);

        if ($error_refresh || $http_code_refresh != 200) {
            $error_details = $error_refresh ?: "HTTP {$http_code_refresh} - " . substr($response_refresh_body, 0, 500);
            error_log("AUTH_HANDLER_ERROR: Token refresh failed for member_id '{$member_id}': {$error_details}");
            $tokens_processed_successfully = false;
            if ($local_is_api_action_request) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(401);
                die(json_encode(['error' => 'token_refresh_failed', 'message' => 'Token refresh failed. Please check app configuration (client_secret) or reinstall.', 'details' => $error_details]));
            } else {
                http_response_code(401);
                echo "<h1>Authentication Error</h1><p>Could not refresh access token. Please check app configuration or reinstall.</p><p>Details: {$error_details}</p>";
                exit;
            }
        } else {
            $new_token_data = json_decode($response_refresh_body, true);
            if (isset($new_token_data['access_token'])) {
                $saved_data['access_token'] = $new_token_data['access_token'];
                $saved_data['refresh_token'] = $new_token_data['refresh_token'];
                $saved_data['expires_at'] = time() + (isset($new_token_data['expires_in']) ? intval($new_token_data['expires_in']) : 3600);
                if (isset($new_token_data['user_id'])) {
                    $saved_data['user_id'] = $new_token_data['user_id'];
                }
                if (file_put_contents($token_file_path, json_encode($saved_data, JSON_PRETTY_PRINT))) {
                    $current_access_token = $saved_data['access_token'];
                    $tokens_processed_successfully = true;
                    error_log("AUTH_HANDLER_DEBUG: Token refreshed and saved successfully for member_id {$member_id}.");
                } else {
                    $tokens_processed_successfully = false;
                    error_log("AUTH_HANDLER_ERROR: Failed to save refreshed token for member_id '{$member_id}'.");
                    if ($local_is_api_action_request) {
                        header('Content-Type: application/json; charset=utf-8');
                        http_response_code(500);
                        die(json_encode(['error' => 'auth_error', 'message' => 'Failed to save refreshed token.']));
                    }
                }
            } else {
                $tokens_processed_successfully = false;
                error_log("AUTH_HANDLER_ERROR: Invalid response on token refresh for member_id '{$member_id}': " . substr($response_refresh_body, 0, 500));
                if ($local_is_api_action_request) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(500);
                    die(json_encode(['error' => 'auth_error', 'message' => 'Invalid refresh response.', 'details' => substr($response_refresh_body, 0, 500)]));
                }
            }
        }
    } else {
        $current_access_token = $saved_data['access_token'];
        $tokens_processed_successfully = true;
        error_log("AUTH_HANDLER_DEBUG: Token loaded from file and is still valid for member_id {$member_id}.");
    }
    if ($tokens_processed_successfully) {
        $initial_app_data_for_react['domain'] = $current_domain;
        $initial_app_data_for_react['member_id'] = $member_id;
    }
}
// 3. Token file not found, and not a B24 POST request
elseif (!$is_b24_auth_post_request && (!$local_is_api_action_request || ($local_is_api_action_request && $_GET['action'] !== 'import_data'))) {
    error_log("AUTH_HANDLER_ERROR: Token file not found and not a POST auth. Path: " . ($token_file_path ?? 'NOT SET') . ". Action: " . ($_GET['action'] ?? 'NONE'));
    if ($local_is_api_action_request) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        die(json_encode(['error' => 'auth_error', 'message' => 'App not installed or token file missing.']));
    }
    http_response_code(403);
    echo "Error: Application not installed or token file is missing. Please reinstall.";
    exit;
} else {
    error_log("AUTH_HANDLER_UNEXPECTED_BRANCH: Reached an unhandled state. Action: " . ($_GET['action'] ?? 'NONE'));
    $tokens_processed_successfully = false;
}

error_log("AUTH_HANDLER_FINAL_STATE: tokens_processed_successfully=" . ($tokens_processed_successfully ? 'true' : 'false') .
    ", current_domain=" . var_export($current_domain, true) .
    ", current_access_token (isset)=" . (isset($current_access_token) ? 'Yes' : 'No') .
    ", member_id=" . var_export($member_id, true));

?>