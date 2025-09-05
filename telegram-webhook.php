<?php
/**
 * Telegram Webhook для обработки команд мониторинга сервера
 * Автор: AI Assistant
 * Дата: 2025-08-23
 */

// Логирование
$log_file = '/var/log/telegram-webhook.log';

function log_message($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
}

// Получаем данные от Telegram
$input = file_get_contents('php://input');
$data = json_decode($input, true);

log_message("Received webhook: " . $input);

// Проверяем, что это сообщение
if (!isset($data['message']['text'])) {
    http_response_code(200);
    exit;
}

$message_text = $data['message']['text'];
$chat_id = $data['message']['chat']['id'];

// Проверяем, что сообщение от авторизованного пользователя
$authorized_chat_id = '395792611';
if ($chat_id != $authorized_chat_id) {
    log_message("Unauthorized access attempt from chat_id: $chat_id");
    http_response_code(200);
    exit;
}

// Обрабатываем команды
switch ($message_text) {
    case '/start':
        $response = "🤖 <b>Бот мониторинга сервера</b>

Доступные команды:
• /status - текущий статус сервера
• /logs - последние ошибки
• /resources - использование ресурсов
• /restart_service - перезапуск сервисов
• /daily_report - получить ежедневный отчет
• /clear_logs - очистить логи сервера

Сервер: " . gethostname();
        break;
        
    case '/status':
        $uptime = shell_exec('uptime -p');
        $load = shell_exec('uptime | awk -F\'load average:\' \'{print $2}\'');
        $memory = shell_exec('free -h | grep Mem | awk \'{print $3"/"$2}\'');
        $disk = shell_exec('df -h / | tail -1 | awk \'{print $5}\'');
        
        $response = "📊 <b>Статус сервера</b>

🖥️ <b>Сервер:</b> " . gethostname() . "
⏰ <b>Аптайм:</b> " . trim($uptime) . "
📈 <b>Нагрузка:</b> " . trim($load) . "
💾 <b>Память:</b> " . trim($memory) . "
💿 <b>Диск:</b> " . trim($disk);
        break;
        
    case '/logs':
        $recent_errors = shell_exec('tail -10 /var/log/angie/error.log 2>/dev/null | head -5');
        if (!empty(trim($recent_errors))) {
            $response = "📝 <b>Последние ошибки:</b>\n\n" . $recent_errors;
        } else {
            $response = "✅ <b>Ошибок не найдено</b>";
        }
        break;
        
    case '/resources':
        $cpu = shell_exec('top -bn1 | grep "Cpu(s)" | awk \'{print $2}\' | cut -d\'%\' -f1');
        $memory = shell_exec('free | grep Mem | awk \'{printf("%.1f", $3/$2 * 100.0)}\'');
        $disk = shell_exec('df -h / | tail -1 | awk \'{print $5}\'');
        
        $response = "💻 <b>Использование ресурсов:</b>

🖥️ <b>CPU:</b> " . trim($cpu) . "%
💾 <b>Память:</b> " . trim($memory) . "%
💿 <b>Диск:</b> " . trim($disk);
        break;
        
    case '/restart_service':
        $response = "🔄 <b>Перезапуск сервисов</b>

Для перезапуска конкретного сервиса используйте:
• /restart_angie
• /restart_php";
        break;
        
    case '/restart_angie':
        $result = shell_exec('sudo systemctl restart angie 2>&1');
        if (strpos($result, 'error') === false) {
            $response = "✅ <b>Angie перезапущен успешно</b>";
        } else {
            $response = "❌ <b>Ошибка при перезапуске Angie:</b>\n$result";
        }
        break;
        
    case '/restart_php':
        $result = shell_exec('sudo systemctl restart php8.3-fpm 2>&1');
        if (strpos($result, 'error') === false) {
            $response = "✅ <b>PHP-FPM перезапущен успешно</b>";
        } else {
            $response = "❌ <b>Ошибка при перезапуске PHP-FPM:</b>\n$result";
        }
        break;
        
    case '/daily_report':
        // Генерируем отчет прямо здесь
        $current_date = date('Y-m-d');
        
        // Собираем статистику ошибок
        $errors_404 = shell_exec('grep "' . $current_date . '" /var/log/server-monitor-stats.txt 2>/dev/null | awk -F"=" \'{sum+=$2} END {print sum+0}\'') ?: 0;
        $errors_500 = shell_exec('grep "' . $current_date . '" /var/log/server-monitor-stats.txt 2>/dev/null | awk -F"=" \'{sum+=$3} END {print sum+0}\'') ?: 0;
        
        // Получаем информацию о системе
        $uptime = trim(shell_exec('uptime -p 2>/dev/null') ?: 'unknown');
        $load = trim(shell_exec('uptime | awk -F\'load average:\' \'{print $2}\' 2>/dev/null') ?: 'unknown');
        $memory = trim(shell_exec('free -h | grep Mem | awk \'{print $3"/"$2}\' 2>/dev/null') ?: 'unknown');
        $disk = trim(shell_exec('df -h / | tail -1 | awk \'{print $5}\' 2>/dev/null') ?: 'unknown');
        
        $response = "📊 <b>Ежедневный отчет сервера</b>

📅 <b>Дата:</b> $current_date
🖥️ <b>Сервер:</b> " . gethostname() . "

📈 <b>Статистика ошибок:</b>
• 404 ошибки: $errors_404
• 500 ошибки: $errors_500

💻 <b>Состояние системы:</b>
• Аптайм: $uptime
• Нагрузка: $load
• Память: $memory
• Диск: $disk

🔧 <b>Команды:</b>
• /status - текущий статус
• /logs - последние ошибки
• /resources - использование ресурсов";
        break;
        
    case '/clear_logs':
        // Очищаем логи
        $cleared = [];
        
        // Очищаем access логи Angie
        $result = shell_exec('sudo truncate -s 0 /var/log/angie/access.log 2>&1');
        if (empty($result)) {
            $cleared[] = "✅ Access логи Angie";
        } else {
            $cleared[] = "❌ Access логи Angie: $result";
        }
        
        // Очищаем error логи Angie  
        $result = shell_exec('sudo truncate -s 0 /var/log/angie/error.log 2>&1');
        if (empty($result)) {
            $cleared[] = "✅ Error логи Angie";
        } else {
            $cleared[] = "❌ Error логи Angie: $result";
        }
        
        // Очищаем логи мониторинга
        $result = shell_exec('sudo truncate -s 0 /var/log/server-monitor.log 2>&1');
        if (empty($result)) {
            $cleared[] = "✅ Логи мониторинга";
        } else {
            $cleared[] = "❌ Логи мониторинга: $result";
        }
        
        // Очищаем статистику
        $result = shell_exec('sudo truncate -s 0 /var/log/server-monitor-stats.txt 2>&1');
        if (empty($result)) {
            $cleared[] = "✅ Статистика мониторинга";
        } else {
            $cleared[] = "❌ Статистика мониторинга: $result";
        }
        
        // Очищаем системные логи (только последние 1000 строк оставляем)
        shell_exec('sudo journalctl --vacuum-time=1d 2>/dev/null');
        $cleared[] = "✅ Системные логи (старше 1 дня)";
        
        $response = "🧹 <b>Очистка логов завершена</b>

" . implode("\n", $cleared) . "

📊 <b>Освобождено места:</b>
" . trim(shell_exec('df -h /var/log | tail -1 | awk \'{print "Диск /var/log: " $5 " занято"}\''));
        break;
        
    default:
        $response = "❓ <b>Неизвестная команда</b>

Используйте /start для просмотра доступных команд.";
        break;
}

// Отправляем ответ в Telegram
$bot_token = '7953598163:AAFdNW7xA75BNySuPK44a6NrvZ_JX_GqtNQ';
$url = "https://api.telegram.org/bot$bot_token/sendMessage";

$post_data = [
    'chat_id' => $chat_id,
    'text' => $response,
    'parse_mode' => 'HTML'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$result = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

log_message("Response sent. HTTP code: $http_code, Result: $result");

// Отвечаем Telegram, что обработали webhook
http_response_code(200);
echo 'OK';
?>
