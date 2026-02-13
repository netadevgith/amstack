# Полный путь отправки кампании — ESP Pipeline

## Обзор архитектуры

```
┌─────────────┐     ┌──────────┐     ┌───────────┐     ┌─────────┐
│  Laravel UI │────▶│  Redis   │────▶│ Gosender  │────▶│ Postfix │
│  (PHP 7.2)  │     │ (Queue)  │     │  (Go CLI) │     │ (SMTP)  │
└─────────────┘     └──────────┘     └───────────┘     └─────────┘
       │                 │                 │                 │
       ▼                 ▼                 ▼                 ▼
  Laravel Log      Redis Keys       gosender.log       mail.log
```

---

## ПУТЬ 1: Запуск новой кампании (Campaign Start)

### Шаг 1 — UI: Нажатие "Send"
- **URL:** `POST /campaigns/{uid}/start`
- **Контроллер:** `CampaignController::start()`
- **Лог:** `docker exec esp_app tail -f /home/app/public_html/storage/logs/laravel.log`

### Шаг 2 — PHP: Campaign::start()
- Загружает подписчиков из БД
- Создаёт tracking_logs записи
- Записывает в Redis:
  - `{uid}` — JSON с данными кампании + status="sending"
  - `campaign_{uid}_subscribers` — SET с подписчиками
  - `{uid}_total` — общее количество
  - `{uid}_sent` = 0
  - `{uid}_servers` — JSON массив серверов
- **Лог:** `docker exec esp_app tail -f /home/app/public_html/storage/logs/laravel.log`
- **Проверка:** `docker exec esp_app redis-cli -h 172.28.0.3 GET {uid} | python3 -m json.tool`

### Шаг 3 — PHP: exec() gosender
- Формирует команду и запускает gosender через exec():
```bash
$HOME/gosender --send --campuid {uid} \
  --smtphost {host} --smtpport {port} \
  --smtpspeed {speed} --username {user} --password {pass} \
  --app {deployment} [--ssl] [--nodone]
```
- Для каждого sending server — отдельный процесс gosender
- **Проверка:** `docker exec esp_app ps aux | grep gosender`

### Шаг 4 — Go: Gosender работает
- Читает подписчиков из Redis: `SPOP campaign_{uid}_subscribers`
- Для каждого подписчика:
  - Подставляет переменные ({EMAIL}, {NAME} и т.д.)
  - Подключается к SMTP серверу
  - Отправляет письмо
  - Инкрементирует `{uid}_counter`
- **Лог:** `docker exec esp_app tail -f /home/app/gosender.log`
- **Проверка:** `docker exec esp_app redis-cli -h 172.28.0.3 GET {uid}_counter`

### Шаг 5 — Postfix принимает и доставляет
- Gosender → localhost:2525 → Postfix → MX получателя
- **Лог:** `docker exec esp_app tail -f /var/log/mail.log`

### Шаг 6 — Callback: статистика
- Gosender вызывает API: `POST /api/campaign_counter`
  - type=sent → `{uid}_sent++`
  - type=bounced → `{uid}_bounced++`
  - type=deferred → `{uid}_deferred++`
- **Проверка:**
```bash
docker exec esp_app redis-cli -h 172.28.0.3 MGET \
  {uid}_sent {uid}_bounced {uid}_deferred {uid}_counter {uid}_total
```

### Шаг 7 — Postfix Log Parser (опционально, для production)
- `tools/parse_postfix` (Perl) — парсит /var/log/mail.log через SSH
- Определяет hard bounces по regex из `nustatymai.hardbounces`
- Blacklist → subscriber status='unconfirmed'
- **Лог:** Laravel log (MailLog::info внутри парсера)

---

## ПУТЬ 2: Restart Background Sending

### Шаг 1 — UI: Кнопка "Restart background sending"
- **URL:** `GET /campaigns/restartbackground?uids={uid}`
- **Контроллер:** `CampaignController::RestartBackground()`
- Создаёт `RestartProcessesJob` → dispatch в Redis queue
- **Лог:** `docker exec esp_app tail -f /home/app/public_html/storage/logs/laravel.log`
- **Проверка очереди:** `docker exec esp_app redis-cli -h 172.28.0.3 LLEN queues:queue`

### Шаг 2 — Queue Worker подбирает Job
- Supervisor запускает worker: `queue:work --queue=high,default`
- Worker забирает Job из `queues:queue` (default) или `queues:high`
- `RestartProcessesJob::handle()` → `Campaign::RestartBackroundProcesses()`
- **Лог:** `docker exec esp_app tail -f /home/app/public_html/storage/logs/queue-high.log`

### Шаг 3 — RestartBackroundProcesses()
1. Убивает все gosender для этой кампании: `kill $(ps | grep {uid})`
2. Читает Redis: `GET {uid}` → проверяет `status == "sending"`
   - **Если ключа нет или status != "sending" → СТОП, gosender не запускается**
3. Читает sending servers из БД (WHERE status='active' AND id > 1)
4. Сохраняет серверы: `SET {uid}_servers [json]`
5. Для каждого сервера — `exec("gosender --send ...")`
- **Проверка Redis:** `docker exec esp_app redis-cli -h 172.28.0.3 EXISTS {uid}`
- **Проверка серверов:** `docker exec esp_app redis-cli -h 172.28.0.3 GET {uid}_servers`

### Шаг 4-7 — Те же что в ПУТЬ 1 (шаги 4-7)

---

## Все Redis ключи кампании

| Ключ | Тип | Описание |
|------|-----|----------|
| `{uid}` | string(JSON) | Данные кампании + status |
| `{uid}_total` | string(int) | Всего подписчиков |
| `{uid}_sent` | string(int) | Успешно отправлено |
| `{uid}_bounced` | string(int) | Hard bounces |
| `{uid}_deferred` | string(int) | Временные отказы |
| `{uid}_deferred_sent` | string(int) | Повторно отправленные из deferred |
| `{uid}_counter` | string(int) | Общий счётчик обработки |
| `{uid}_servers` | string(JSON) | Массив серверов |
| `{uid}_sent_data` | hash | email → данные доставки |
| `{uid}_undelivered_data` | hash | email → тип ошибки |
| `{uid}_undelivered_val` | list | Список недоставленных |
| `{uid}_deferred_setting` | string(JSON) | Настройки deferred |
| `campaign_{uid}_subscribers` | set | Очередь подписчиков |
| `campaign_{uid}_deferreds` | set | Отложенные для повторной отправки |
| `campaign_{uid}_static` | hash | Данные подписчиков (для resend) |

---

## Все лог-файлы

| Сервис | Файл в контейнере | Команда |
|--------|-------------------|---------|
| Laravel (PHP) | `/home/app/public_html/storage/logs/laravel.log` | `docker exec esp_app tail -f /home/app/public_html/storage/logs/laravel.log` |
| Queue Worker | `/home/app/public_html/storage/logs/queue-high.log` | `docker exec esp_app tail -f /home/app/public_html/storage/logs/queue-high.log` |
| Gosender | `/home/app/gosender.log` | `docker exec esp_app tail -f /home/app/gosender.log` |
| Taskrunner | `/home/app/taskrunner/taskrunner.log` | `docker exec esp_app tail -f /home/app/taskrunner/taskrunner.log` |
| Storage | `/opt/storage/storage.log` | `docker exec esp_app tail -f /opt/storage/storage.log` |
| Postfix | `/var/log/mail.log` | `docker exec esp_app tail -f /var/log/mail.log` |
| Nginx | `/var/log/nginx/error.log` | `docker exec esp_app tail -f /var/log/nginx/error.log` |
| PHP-FPM | `/var/log/php-fpm-error.log` | `docker exec esp_app tail -f /var/log/php-fpm-error.log` |

---

## Диагностика: проверить всё одной командой

```bash
docker exec esp_app bash -c "
echo '=== SERVICES ==='
ps aux | grep -E 'postfix|taskrunner|storage|gosender|queue:work|nginx|php-fpm' | grep -v grep | awk '{print \$11, \$12, \$13}'
echo ''
echo '=== PORTS ==='
ss -tlnp | grep -E ':25 |:2525|:80 |:8082|:8083|:6379'
echo ''
echo '=== REDIS CAMPAIGN {uid} ==='
redis-cli -h 172.28.0.3 EXISTS {uid}
redis-cli -h 172.28.0.3 MGET {uid}_sent {uid}_bounced {uid}_deferred {uid}_total
echo ''
echo '=== QUEUE ==='
redis-cli -h 172.28.0.3 LLEN queues:queue
redis-cli -h 172.28.0.3 LLEN queues:high
"
```

---

## Исправленные баги

| Баг | Причина | Исправление |
|-----|---------|-------------|
| Queue worker не подбирает jobs | Worker слушал только `--queue=high`, jobs шли в `default` | `supervisord.conf`: `--queue=high,default` |
| 1000+ zombie queue:work процессов | Крон каждую минуту запускал queue:work (он демон, не cron task) | Убрали queue:work и schedule:run из cron |
| `Doctrine\Inflector not found` | OOM от zombie workers ломал autoloader | Крон исправлен + `composer dump-autoload` в init.sh |
| `mkdir() Permission denied` | Папка `public/source/` не существовала | `mkdir -p` + `chown www-data` в init.sh |
| Redis campaign key пропадает | Данные не персистятся при перезапуске контейнера | Кампанию нужно запускать через UI (start), не restart |
