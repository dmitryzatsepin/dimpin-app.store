<?php
// index.php - УНИВЕРСАЛЬНЫЙ ОБРАБОТЧИК (для официального SDK 1.5)

// Заголовки для работы во фрейме Битрикс24
header('X-Frame-Options: ALLOWALL');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../vendor/autoload.php';

use Bitrix24\SDK\Core\ApiClient;
use Bitrix24\SDK\Core\ApiLevelErrorHandler;
use Bitrix24\SDK\Core\Core;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Credentials;
use Bitrix24\SDK\Core\Credentials\Scope;
use Bitrix24\SDK\Infrastructure\HttpClient\RequestId\DefaultRequestIdGenerator;
use Bitrix24\SDK\Services\Placement\Service\Placement;
use Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\HttpClient;

// Отладочная информация
error_log('Index script called with params: ' . print_r($_REQUEST, true));

// Определяем тип запроса
$isInstallation = !isset($_REQUEST['PLACEMENT']) || 
                  (isset($_REQUEST['event']) && $_REQUEST['event'] === 'ONAPPINSTALL');
$isPlacementCall = isset($_REQUEST['PLACEMENT']) && $_REQUEST['PLACEMENT'] === 'CRM_DEAL_DETAIL_ACTIVITY';

error_log('Is installation: ' . ($isInstallation ? 'true' : 'false'));
error_log('Is placement call: ' . ($isPlacementCall ? 'true' : 'false'));

// Если это установка - перенаправляем на install.php
if ($isInstallation) {
    error_log('Redirecting to install.php');
    include __DIR__ . '/install.php';
    exit;
}

// Если это не placement call - показываем информацию
if (!$isPlacementCall) {
    echo '<!DOCTYPE html><html><head><title>LED Calculator - Info</title><style>body{font-family:Arial,sans-serif;margin:40px;background:#f5f5f5;} .container{background:white;padding:30px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1);} .info{background:#e3f2fd;padding:15px;border-radius:5px;margin:10px 0;} .error{background:#ffebee;padding:15px;border-radius:5px;margin:10px 0;} .success{background:#e8f5e8;padding:15px;border-radius:5px;margin:10px 0;}</style></head><body><div class="container">';
    echo '<h1>LED Calculator Widget</h1>';
    echo '<div class="info">';
    echo '<h3>Информация о запросе:</h3>';
    echo '<p><strong>PLACEMENT:</strong> ' . ($_REQUEST['PLACEMENT'] ?? 'не установлен') . '</p>';
    echo '<p><strong>DOMAIN:</strong> ' . ($_REQUEST['DOMAIN'] ?? 'не установлен') . '</p>';
    echo '<p><strong>member_id:</strong> ' . ($_REQUEST['member_id'] ?? 'не установлен') . '</p>';
    echo '<p><strong>AUTH_ID:</strong> ' . (isset($_REQUEST['AUTH_ID']) ? 'установлен' : 'не установлен') . '</p>';
    echo '</div>';
    
    if (isset($_REQUEST['PLACEMENT']) && $_REQUEST['PLACEMENT'] === 'DEFAULT') {
        echo '<div class="error">';
        echo '<h3>Проблема:</h3>';
        echo '<p>Приложение запущено с PLACEMENT=DEFAULT, что означает, что кнопка не привязана к карточке сделки.</p>';
        echo '<p>Возможные причины:</p>';
        echo '<ul>';
        echo '<li>Приложение не установлено корректно</li>';
        echo '<li>Недостаточно прав доступа</li>';
        echo '<li>Placement не создан в правильном месте</li>';
        echo '</ul>';
        echo '</div>';
    }
    
    echo '<div class="success">';
    echo '<h3>Рекомендации:</h3>';
    echo '<p>1. Проверьте, что приложение установлено с правами: crm, user, placement</p>';
    echo '<p>2. Убедитесь, что placement создан для CRM_DEAL_DETAIL_ACTIVITY</p>';
    echo '<p>3. Проверьте логи установки</p>';
    echo '</div>';
    
    echo '<p><a href="test-placement.php">Тестировать placement</a></p>';
    echo '</div></body></html>';
    exit;
}

// --- СОЗДАНИЕ CREDENTIALS ---
$authToken = new AuthToken($_REQUEST['AUTH_ID'], $_REQUEST['REFRESH_ID'] ?? null, time() + 3600);
$appProfile = new ApplicationProfile($_REQUEST['member_id'], 'dummy_secret', new Scope(['crm', 'user', 'placement']));
$credentials = Credentials::createFromOAuth($authToken, $appProfile, $_REQUEST['DOMAIN']);

// Инициализация "старым" способом, совместимым с 1.5.0
$apiClient = new ApiClient($credentials, HttpClient::create(), new DefaultRequestIdGenerator(), new ApiLevelErrorHandler(new Logger('dummy')), new Logger('dummy'));
$core = new Core($apiClient, new ApiLevelErrorHandler(new Logger('dummy')), new EventDispatcher(), new Logger('dummy'));

// --- ЗАПУСК КАЛЬКУЛЯТОРА ---
$placementService = new Placement($core, new Logger('dummy'));
$placementOptions = $placementService->getPlacementOptions();
if (empty($placementOptions['ID'])) { 
    throw new \Exception('Deal ID not found'); 
}
$currentUser = $core->getUserService()->current();
$queryParams = http_build_query([
    'dealId' => $placementOptions['ID'], 
    'userId' => $currentUser->getId(), 
    'domain' => $credentials->getDomainUrl(), 
    'authId' => $credentials->getAuthToken()->accessToken, 
    'memberId' => $credentials->getApplicationProfile()->getClientId()
]);
echo '<!DOCTYPE html><html><head><title>LED калькулятор</title><script src="//api.bitrix24.com/api/v1/"></script><style>html,body{margin:0;padding:0;height:100%;}iframe{border:0;width:100%;height:100%;}</style></head><body><iframe src="https://dimpin-app.store/apps/led-calculator/?' . htmlspecialchars($queryParams, ENT_QUOTES, 'UTF-8') . '"></iframe></body></html>';