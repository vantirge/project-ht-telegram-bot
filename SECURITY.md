# Security Documentation

## Обзор системы безопасности

Данный проект реализует комплексную систему защиты от различных типов атак:

### 1. Защита от SQL-инъекций

**Проблема**: Использование `whereRaw` в запросах может быть уязвимо к SQL-инъекциям.

**Решение**:
- Заменены все `whereRaw` на параметризованные запросы
- Добавлена валидация входных данных
- Использование Eloquent ORM для безопасных запросов

**Пример исправления**:
```php
// Было (уязвимо):
$user = User::whereRaw('LOWER(TRIM(name)) = LOWER(?)', [trim($login)])->first();

// Стало (безопасно):
$user = User::where('name', 'like', $login)->first();
```

### 2. Защита от XSS-атак

**Реализовано**:
- HTML-кодирование всех пользовательских данных
- Фильтрация опасных HTML-тегов
- Удаление JavaScript-кода

**Пример**:
```php
$input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
```

### 3. Защита от брутфорс-атак

**Функции**:
- Ограничение попыток авторизации (5 попыток)
- Ограничение попыток ввода кода (3 попытки)
- Временная блокировка после превышения лимита
- Логирование всех неудачных попыток

### 4. Rate Limiting

**Настройки**:
- 60 запросов в минуту на IP-адрес
- Автоматическая блокировка при превышении
- Логирование подозрительной активности

### 5. Валидация входных данных

**Типы валидации**:
- `login`: только буквы, цифры, точки, дефисы, подчеркивания
- `code`: только цифры, максимум 6 символов
- `text`: HTML-кодирование, максимум 1000 символов

### 6. Валидация Telegram-запросов

**Проверки**:
- User-Agent должен содержать "telegram"
- Структура запроса должна соответствовать Telegram API
- Валидация обязательных полей

## Конфигурация

### Переменные окружения

Добавьте в `.env` файл:

```env
# Security Settings
SECURITY_RATE_LIMITING=true
SECURITY_MAX_ATTEMPTS=60
SECURITY_DECAY_MINUTES=1

SECURITY_BRUTE_FORCE_PROTECTION=true
SECURITY_MAX_AUTH_ATTEMPTS=5
SECURITY_MAX_CODE_ATTEMPTS=3
SECURITY_LOCKOUT_MINUTES=5

SECURITY_INPUT_VALIDATION=true
SECURITY_MAX_LOGIN_LENGTH=50
SECURITY_MAX_TEXT_LENGTH=1000
SECURITY_MAX_CODE_LENGTH=6

SECURITY_SQL_INJECTION_PROTECTION=true
SECURITY_XSS_PROTECTION=true

SECURITY_LOGGING=true
SECURITY_LOG_SUSPICIOUS=true
SECURITY_LOG_FAILED_ATTEMPTS=true

SECURITY_TELEGRAM_VALIDATE_UA=true
SECURITY_TELEGRAM_VALIDATE_STRUCTURE=true
```

## Мониторинг безопасности

### Команды для мониторинга

```bash
# Просмотр отчета по безопасности
php artisan security:monitor

# Очистка старых логов безопасности
php artisan security:monitor --clean
```

### Логирование

Все события безопасности логируются в:
- `storage/logs/laravel.log` - основные логи
- `storage/logs/telegram_config.log` - логи Telegram

### Типы событий безопасности

1. **Suspicious input detected** - обнаружен подозрительный ввод
2. **Failed login attempt** - неудачная попытка входа
3. **Failed code attempt** - неудачная попытка ввода кода
4. **Rate limit exceeded** - превышен лимит запросов
5. **Brute force attempt detected** - обнаружена брутфорс-атака

## Рекомендации по безопасности

### 1. Регулярный мониторинг

```bash
# Добавьте в crontab для ежедневного мониторинга
0 9 * * * cd /path/to/project && php artisan security:monitor
```

### 2. Настройка алертов

Настройте уведомления при обнаружении подозрительной активности:

```php
// В SecurityService::logSecurityEvent()
if ($event === 'Brute force attempt detected') {
    // Отправить уведомление администратору
    Mail::to('admin@company.com')->send(new SecurityAlert($data));
}
```

### 3. Обновление паттернов

Регулярно обновляйте паттерны блокировки в `config/security.php`:

```php
'blocked_patterns' => [
    // Добавляйте новые паттерны атак
    '/new_attack_pattern/i',
],
```

### 4. Резервное копирование

Регулярно создавайте резервные копии логов безопасности:

```bash
# Создание резервной копии логов
tar -czf security_logs_$(date +%Y%m%d).tar.gz storage/logs/
```

## Тестирование безопасности

### Проверка защиты от SQL-инъекций

```bash
# Попробуйте ввести в поле логина:
admin' OR '1'='1
admin'; DROP TABLE users; --
```

### Проверка защиты от XSS

```bash
# Попробуйте ввести в любое текстовое поле:
<script>alert('XSS')</script>
<img src="x" onerror="alert('XSS')">
```

### Проверка rate limiting

```bash
# Отправьте много запросов подряд
for i in {1..100}; do curl -X POST http://your-domain/api/telegram/webhook; done
```

## Обновления безопасности

### Версия 1.0
- Базовая защита от SQL-инъекций
- Защита от XSS-атак
- Rate limiting
- Брутфорс-защита
- Валидация Telegram-запросов

### Планы на будущее
- Добавление CAPTCHA для подозрительной активности
- Интеграция с внешними сервисами безопасности
- Расширенная аналитика угроз
- Автоматическая блокировка IP-адресов 