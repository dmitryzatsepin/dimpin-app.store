<?php
// /importToSpa/index.php
error_log("DEBUG_INDEX: index.php script was called at " . date('Y-m-d H:i:s'));

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/api_helper.php';
require_once __DIR__ . '/includes/view_renderer.php';

$current_access_token = null;
$current_domain = null;
$member_id = null;
$token_file_path = null;
$initial_app_data_for_react = [];
$tokens_processed_successfully = false;

if (isset($_POST['member_id'])) {
    $member_id = htmlspecialchars(trim($_POST['member_id']));
} elseif (isset($_REQUEST['member_id'])) {
    $member_id = htmlspecialchars(trim($_REQUEST['member_id']));
}

if (isset($_POST['DOMAIN'])) {
    $current_domain = htmlspecialchars(trim($_POST['DOMAIN']));
} elseif (isset($_POST['domain'])) {
    $current_domain = htmlspecialchars(trim($_POST['domain']));
} elseif (isset($_REQUEST['DOMAIN'])) {
    $current_domain = htmlspecialchars(trim($_REQUEST['DOMAIN']));
} elseif (isset($_REQUEST['domain'])) {
    $current_domain = htmlspecialchars(trim($_REQUEST['domain']));
}

if ($member_id && $current_domain) {
    $safe_member_id_part = preg_replace('/[^a-zA-Z0-9_.-]/', '', $member_id);
    $safe_domain_part = preg_replace('#^https?://#', '', $current_domain);
    $safe_domain_part = str_replace(['.', '-'], '_', $safe_domain_part);
    $safe_domain_part = preg_replace('/[^a-zA-Z0-9_]/', '', $safe_domain_part);
    if (!empty($safe_member_id_part) && !empty($safe_domain_part)) {
        $token_file_path = DATA_DIR . 'token_' . $safe_domain_part . '_' . $safe_member_id_part . '.json';
        error_log("INDEX_DEBUG: Token file path set to: " . $token_file_path);
    } else {
        error_log("INDEX_ERROR: Could not form a safe filename part from member_id ('{$member_id}') or domain ('{$current_domain}').");
    }
} else {
    error_log("INDEX_WARN: member_id or current_domain is empty when trying to set token_file_path.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event']) && $_POST['event'] === 'ONAPPUNINSTALL') {
    if ($token_file_path && file_exists($token_file_path)) {
        @unlink($token_file_path);
        error_log("APP_UNINSTALL: Token file deleted for member_id {$member_id}. Path: {$token_file_path}");
        // Также удалить файлы маппингов, если они есть
        $mapping_files = glob(DATA_DIR . 'mapping_' . $safe_domain_part . '_' . $safe_member_id_part . '_*.json');
        if ($mapping_files) {
            foreach ($mapping_files as $mfile) {
                @unlink($mfile);
            }
        }
    }
    http_response_code(200);
    echo 'OK';
    exit;
}

require_once __DIR__ . '/includes/auth_handler.php';


$is_api_action_request = isset($_GET['action']);
if ($is_api_action_request) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $action = trim($_GET['action']);
    $action_file_path = __DIR__ . '/actions/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $action) . '.php';

    if ($action !== 'import_data') {
        if (!$tokens_processed_successfully || empty($current_access_token) || empty($current_domain)) {
            error_log("INDEX_API_ERROR: Auth data not ready for action '{$action}'.");
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'auth_failure', 'message' => 'Authentication failed or tokens are not ready.']);
            exit;
        }
    }
    if (file_exists($action_file_path)) {
        require $action_file_path;
    } else {
        error_log("INDEX_API_ERROR: Unknown action requested: '{$action}'.");
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'unknown_action', 'message' => 'API action not recognized.']);
    }
    exit;
} else {
    if (!$tokens_processed_successfully) {
        error_log("INDEX_HTML_ERROR: Tokens not processed successfully for HTML shell. MemberID: " . ($member_id ?? 'N/A'));
        echo "<h1>Application Error</h1><p>Could not initialize application. Please try reloading or reinstalling.</p>";
        if ($member_id && $token_file_path) {
            echo "<p>Debug Info: Token File Path '{$token_file_path}'.</p>";
        }
        exit;
    }
    render_app_shell($initial_app_data_for_react, $_SERVER['SCRIPT_NAME']);
}
?>