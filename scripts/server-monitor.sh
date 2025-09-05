#!/bin/bash

# Упрощенный скрипт мониторинга сервера с ежедневными отчетами
# Автор: AI Assistant
# Дата: 2025-08-23

# Конфигурация
TELEGRAM_BOT_TOKEN="7953598163:AAFdNW7xA75BNySuPK44a6NrvZ_JX_GqtNQ"
TELEGRAM_CHAT_ID="395792611"
LOG_FILE="/var/log/server-monitor.log"
STATS_FILE="/var/log/server-monitor-stats.txt"
LAST_REPORT_FILE="/var/log/server-monitor-last-report.txt"

# Список критических сервисов
CRITICAL_SERVICES=(
    "php8.3-fpm"
    "angie"
)

# Функция отправки уведомления в Telegram
send_telegram_notification() {
    local message="$1"
    local url="https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage"
    
    curl -s -X POST "$url" \
        -d "parse_mode=HTML" \
        -d "chat_id=${TELEGRAM_CHAT_ID}" \
        -d "text=${message}" > /dev/null 2>&1
    
    echo "$(date): Telegram notification sent: $message" >> "$LOG_FILE"
}

# Функция проверки критических ошибок (мгновенные уведомления)
check_critical_errors() {
    # Проверяем 500 ошибки
    local error_500_count=$(tail -50 /var/log/angie/error.log 2>/dev/null | grep -c "500\|502\|503\|504" 2>/dev/null || echo "0")
    
    if [ -n "$error_500_count" ] && [ "$error_500_count" -gt 0 ]; then
        local message="🚨 <b>Критические ошибки сервера!</b>

📊 <b>Детали:</b>
• Количество 500 ошибок: $error_500_count
• Время: $(date '+%Y-%m-%d %H:%M:%S')
• Сервер: $(hostname)

🔧 <b>Проверьте:</b>
• Логи: tail -f /var/log/angie/error.log
• Статус сервисов: systemctl status angie php8.3-fpm"
        
        send_telegram_notification "$message"
    fi
    
    # Проверяем падение критических сервисов
    for service in "${CRITICAL_SERVICES[@]}"; do
        if ! systemctl is-active --quiet "$service"; then
            local message="🚨 <b>Критический сервис упал!</b>

📊 <b>Детали:</b>
• Сервис: $service
• Время: $(date '+%Y-%m-%d %H:%M:%S')
• Сервер: $(hostname)

🔧 <b>Действия:</b>
• Перезапуск: systemctl restart $service
• Проверка: systemctl status $service"
            
            send_telegram_notification "$message"
            
            # Автоматический перезапуск
            systemctl restart "$service"
        fi
    done
}

# Функция сбора статистики ошибок (без уведомлений)
collect_error_stats() {
    local current_date=$(date +%Y-%m-%d)
    
    # Считаем 404 ошибки
    local error_404_count=$(tail -100 /var/log/angie/error.log 2>/dev/null | grep -c "404\|No such file or directory" 2>/dev/null || echo "0")
    
    # Считаем критические ошибки
    local critical_count=$(tail -100 /var/log/angie/error.log 2>/dev/null | grep -c "500\|502\|503\|504" 2>/dev/null || echo "0")
    
    # Записываем статистику
    echo "$current_date: 404=$error_404_count, 500=$critical_count" >> "$STATS_FILE"
    
    # Ограничиваем размер файла статистики
    tail -1000 "$STATS_FILE" > "${STATS_FILE}.tmp" && mv "${STATS_FILE}.tmp" "$STATS_FILE"
}

# Функция проверки ресурсов (только логирование)
check_resources() {
    # CPU
    local cpu_usage=$(top -bn1 | grep "Cpu(s)" | awk '{print $2}' | cut -d'%' -f1 2>/dev/null || echo "0")
    local cpu_usage_int=${cpu_usage%.*}
    
    if [ "$cpu_usage_int" -gt 90 ]; then
        echo "$(date): High CPU usage: ${cpu_usage}%" >> "$LOG_FILE"
    fi
    
    # Memory
    local mem_usage=$(free | grep Mem | awk '{printf("%.2f", $3/$2 * 100.0)}' 2>/dev/null || echo "0")
    local mem_usage_int=${mem_usage%.*}
    
    if [ "$mem_usage_int" -gt 90 ]; then
        echo "$(date): High memory usage: ${mem_usage}%" >> "$LOG_FILE"
    fi
    
    # Disk
    df -h | grep -E '^/dev/' | while read line; do
        local usage=$(echo "$line" | awk '{print $5}' | sed 's/%//' 2>/dev/null || echo "0")
        local mount_point=$(echo "$line" | awk '{print $6}')
        
        if [ "$usage" -gt 90 ]; then
            echo "$(date): Disk full: $mount_point ${usage}%" >> "$LOG_FILE"
        fi
    done
}

# Функция отправки ежедневного отчета
send_daily_report() {
    local current_date=$(date +%Y-%m-%d)
    local last_report_date=""
    
    # Проверяем, когда был отправлен последний отчет
    if [ -f "$LAST_REPORT_FILE" ]; then
        last_report_date=$(cat "$LAST_REPORT_FILE")
    fi
    
    # Отправляем отчет только раз в день
    if [ "$current_date" != "$last_report_date" ]; then
        # Подсчитываем статистику за день
        local errors_404=$(grep "$current_date" "$STATS_FILE" 2>/dev/null | awk -F'=' '{sum+=$2} END {print sum+0}')
        local errors_500=$(grep "$current_date" "$STATS_FILE" 2>/dev/null | awk -F'=' '{sum+=$3} END {print sum+0}')
        
        # Получаем информацию о системе
        local uptime=$(uptime -p 2>/dev/null || echo "unknown")
        local load=$(uptime | awk -F'load average:' '{print $2}' 2>/dev/null || echo "unknown")
        local memory=$(free -h | grep Mem | awk '{print $3"/"$2}' 2>/dev/null || echo "unknown")
        local disk=$(df -h / | tail -1 | awk '{print $5}' 2>/dev/null || echo "unknown")
        
        local message="📊 <b>Ежедневный отчет сервера</b>

📅 <b>Дата:</b> $current_date
🖥️ <b>Сервер:</b> $(hostname)

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
• /resources - использование ресурсов"
        
        send_telegram_notification "$message"
        
        # Отмечаем дату последнего отчета
        echo "$current_date" > "$LAST_REPORT_FILE"
    fi
}

# Основная функция мониторинга
main() {
    echo "$(date): Simple server monitoring started" >> "$LOG_FILE"
    
    while true; do
        # Проверяем критические ошибки (мгновенные уведомления)
        check_critical_errors
        
        # Собираем статистику ошибок
        collect_error_stats
        
        # Проверяем ресурсы
        check_resources
        
        # Отправляем ежедневный отчет в 9:00
        if [ "$(date +%H:%M)" = "09:00" ]; then
            send_daily_report
        fi
        
        # Проверяем каждые 60 секунд
        sleep 60
    done
}

# Запуск мониторинга
main
