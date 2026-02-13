# ESP Mailing Platform - Документация

## Обзор

ESP (Email Service Provider) Mailing Platform — комплексная платформа массовой email-рассылки. Проект состоит из **5 основных компонентов** в папке `extracted/`, каждый отвечает за свою часть процесса:

```
extracted/
├── acelle_esp/          # Laravel Web UI + конфиг gosender
├── gosender-src/        # Go: движок отправки писем
├── taskrunner/          # Go: оркестратор очередей и трекинга
├── storage/             # Go: API хранения данных и блэклистов
└── parkagency_crm/      # Redmine CRM (отдельная система)
```

---

## Как всё работает вместе

```
  Пользователь (браузер)
        │
        ▼
┌─────────────────────────────────────────────────────┐
│  1. ACELLE_ESP (Laravel Web UI)                     │
│     - Создание кампании, загрузка контактов          │
│     - Нажатие "Отправить" → задание в Redis          │
└──────────────────────┬──────────────────────────────┘
                       │ Redis pub/sub (канал "taskrunner")
                       ▼
┌─────────────────────────────────────────────────────┐
│  2. TASKRUNNER (Go оркестратор)                     │
│     - Получает задание из Redis                      │
│     - Загружает кампанию и список подписчиков из БД  │
│     - Запускает GOSENDER с параметрами кампании      │
│     - Принимает трекинг (открытия/клики) на :8555    │
│     - Отправляет трекинг данные в Storage API        │
└────────┬────────────────────────┬───────────────────┘
         │                        │
         ▼                        ▼
┌──────────────────┐   ┌─────────────────────────────┐
│ 3. GOSENDER      │   │ 4. STORAGE (Go API)         │
│    (Go CLI)      │   │    - Хранит email-адреса     │
│                  │   │    - Блэклисты доменов/MX    │
│ - SMTP отправка  │   │    - Статистика открытий     │
│ - Amazon SES     │   │    - Статистика кликов       │
│ - SendGrid API   │   │    - GeoIP геолокация        │
│ - Трекинг ссылок │   │    - REST API на :8082       │
└──────────────────┘   └─────────────────────────────┘
```

**5. PARKAGENCY_CRM** — отдельная система Redmine для управления проектами Park Agency, не связана с ESP-платформой.

---

## 1. ACELLE_ESP (`extracted/acelle_esp/`)

### Что это
Главный веб-интерфейс платформы на **Laravel 5.5** (форк Acelle Mail). Через него пользователь управляет всем: кампании, контакты, шаблоны, серверы отправки, статистика.

### Структура

```
acelle_esp/
├── public_html/              # Laravel приложение
│   ├── app/                  # Модели, Контроллеры, Jobs, Libraries
│   │   ├── Model/            # 94 модели (Campaign, Subscriber, SendingServer...)
│   │   └── Http/Controllers/ # Контроллеры (Auth, Campaign, List...)
│   ├── config/               # 16 конфигов Laravel
│   ├── database/migrations/  # Миграции БД
│   ├── resources/views/      # Blade-шаблоны
│   ├── vendor/               # Composer пакеты
│   ├── storage/logs/         # Логи Laravel
│   ├── tools/                # Go утилиты
│   │   ├── populate_storage_src/main.go  # Заполнение Storage из БД
│   │   ├── redis_cleanup_src/main.go     # Очистка Redis
│   │   └── go.mod
│   └── .env                  # Переменные окружения
├── go.mod                    # Go модуль "gosender" (зависимости gosender)
├── go.sum                    # Контрольные суммы Go зависимостей
├── gosender.json             # Конфигурация gosender
├── Makefile                  # Сборка gosender через packr2
├── mailsend/                 # C-утилита отправки (legacy)
└── campaign_logs/            # Логи кампаний
```

### Что делает
- Веб-панель управления кампаниями и подписчиками
- Конструктор email-шаблонов
- Настройка серверов отправки (SMTP, SES, SendGrid, Mailgun, SparkPost)
- Управление списками контактов и сегментацией
- Статистика: доставки, открытия, клики, отписки, жалобы
- Интеграция с Cloudflare DNS
- Платёжные системы: PayPal, Braintree, Stripe

### Подключения
- **MySQL** (`mailsendas_testdev`) — основная БД всех данных
- **Redis** — сессии, кеш, очереди Laravel, pub/sub для taskrunner
- **Storage API** — запросы к API хранилища для проверки блэклистов

### Ключевые файлы конфигурации
- `.env` — все переменные (БД, Redis, APP_URL, Storage URL)
- `gosender.json` — настройки для Go-отправщика:
  ```json
  {
    "MySQLHost": "172.28.0.2",
    "MySQLDb": "mailsendas_testdev",
    "StorageUrl": "http://172.28.0.10:8082/",
    "MinSpeed": 8000,
    "TrackingDomain": "...",
    "RedisHost": "localhost:6379"
  }
  ```

### Tools (Go утилиты)
В `public_html/tools/` лежат два Go-скрипта для обслуживания:
- **populate_storage** — перенос email-адресов из MySQL в Storage API
- **redis_cleanup** — очистка устаревших ключей Redis

Сборка: `cd tools && go build -o populate_storage ./populate_storage_src/`

---

## 2. GOSENDER-SRC (`extracted/gosender-src/`)

### Что это
**Движок отправки email** — Go CLI-утилита, которую taskrunner вызывает для каждой кампании. Не демон, а инструмент командной строки.

### Структура

```
gosender-src/
├── gosender.go          # Основной код (87KB, ~2650 строк)
├── check_license.go     # Проверка лицензии (отключена)
├── deferred.go          # Отложенная отправка
├── main-packr.go        # Packr2: встроенные ресурсы (сертификаты)
├── packrd/              # Автогенерированные packr2 файлы
│   └── packed-packr.go
├── examples/            # Примеры конфигураций
└── TODO                 # Заметки разработчика
```

**Примечание**: go.mod для gosender лежит в `acelle_esp/go.mod` (модуль `gosender`), потому что gosender-src — это подпакет модуля gosender.

### Что делает
1. Получает UID кампании через флаг `-campuid`
2. Загружает данные кампании из Redis (тело письма, подписчики, настройки)
3. Для каждого подписчика:
   - Подставляет переменные (`{EMAIL}`, `{NAME}`, `{date}` и т.д.)
   - Генерирует рандомные вставки (`{rndnum[1,100]}`, `{rndstr[10,20]}`, `{HTML_RANDOM_P[5]}`)
   - Заменяет ссылки на трекинговые (3 метода трекинга)
   - Добавляет пиксель отслеживания открытий
   - Отправляет через выбранный провайдер
4. По завершении обновляет статус кампании в Redis

### Провайдеры отправки
| Тип | Флаг `-type` | Описание |
|-----|-------------|----------|
| SMTP | `smtp` (по умолчанию) | Прямая отправка через SMTP-сервер |
| Amazon SES | `amazon-api` | Через AWS SDK (нужны `-accesskey`, `-secretkey`, `-region`) |
| SendGrid | `sendgrid-api` | Через SendGrid API |

### Флаги запуска
```bash
./gosender \
  -send \                    # Начать отправку
  -campuid "abc123" \        # UID кампании (обязательно)
  -type smtp \               # Тип: smtp / amazon-api / sendgrid-api
  -smtphost mail.server.com \# SMTP хост
  -smtpport 2525 \           # SMTP порт
  -smtpspeed 1000000 \       # Задержка между письмами (микросекунды)
  -username user \           # SMTP логин
  -password pass \           # SMTP пароль
  -ssl \                     # Использовать SSL
  -app devtest \             # Имя деплоймента
  -nodone                    # Не менять статус на "done"
```

### Подключения
- **Redis** (localhost:6379) — данные кампании, подписчики, настройки трекинга
- **MySQL** — информация о кампании (через gosender.json)
- **SMTP / SES / SendGrid** — непосредственная отправка

### Фичи
- **Трекинг ссылок** — 3 метода замены URL на трекинговые
- **Маскировка ссылок** — алгоритм замены символов: `{"0":"p","1":"s","2":"m"...}`
- **Рандомизация** — вставка случайного текста, чисел, HTML-тегов для уникализации
- **Отложенная отправка (deferred)** — перенос недоставленных в Redis-очередь
- **Кастомные заголовки** — загрузка из Redis `{campuid}_headers`
- **Babbler** — генератор случайного текста для обфускации контента

---

## 3. TASKRUNNER (`extracted/taskrunner/`)

### Что это
**Центральный оркестратор** — Go-демон, который управляет всем процессом рассылки: очереди, трекинг, вармап, координация сервисов.

### Структура

```
taskrunner/
├── main.go              # Ядро: очереди, Redis подписка, обработка (46KB)
├── tracking.go          # HTTP-сервер трекинга на :8555 (22KB)
├── storage.go           # Интеграция со Storage API (5KB)
├── warmup.go            # Вармап IP-адресов (12KB)
├── redis-cleanup.go     # Очистка Redis (12KB)
├── smtp.go              # SMTP утилиты
├── RedisMsgProtocol.go  # Протокол Redis сообщений
├── backend_tracking.go  # Бэкенд трекинга
├── utils.go             # Утилиты
├── check_license.go     # Лицензия (отключена через ldflags)
├── main-packr.go        # Packr2
├── packrd/              # Встроенные ресурсы
├── settings.json        # Настройки
├── go.mod               # Go модуль "taskrunner"
├── Makefile             # Сборка
└── cron_check           # Cron-скрипт проверки работы
```

### Что делает

#### Основной цикл (main.go)
```
1. Загрузка settings.json и Laravel .env
2. Подключение к Redis
3. Создание приоритетной очереди (heap)
4. Запуск горутин:
   - QueueHandler() — обработка очереди каждые 10 сек
   - TrackingServer() — HTTP на :8555
5. Подписка на Redis канал "taskrunner"
6. Обработка входящих сообщений:
   - Приоритет 1 → выполнить немедленно
   - Приоритет 2-3 → добавить в очередь
```

#### Типы сообщений
| Тип | Код | Что делает |
|-----|-----|-----------|
| LIST_UPDATE | 1 | Обновление кеша списка контактов |
| CAMPAIGN_UPDATE | 2 | Обновление кеша кампании, запуск gosender |
| REDIS_CLEANER | 500 | Очистка устаревших данных Redis |
| WARMUP_ADD | 501 | Добавление IP в вармап |
| SMTP_SEND | 502 | Прямая отправка через SMTP |

#### Приоритетная очередь
- **Приоритет 1** — мгновенное выполнение (минуя очередь)
- **Приоритет 2** — выполняется всегда
- **Приоритет 3** — пропускается при высокой нагрузке (load average > LoadLimitVal)

#### Трекинг-сервер (:8555)
- **Пиксель открытий** — при загрузке изображения из письма
- **Клик-трекинг** — при переходе по ссылке из письма
- **GeoIP** — определение страны/города по IP (MaxMind GeoLite2)
- **User-Agent** — парсинг браузера/ОС
- **Форма жалоб** — `/report` для abuse-репортов

### Подключения
- **Redis** (localhost:6379 через socat) — очереди, кампании, трекинг
- **MySQL** (через Laravel .env) — данные кампаний и подписчиков
- **Storage API** — отправка данных трекинга (storage.go)
- **Gosender** — запуск как дочерний процесс

### Настройки (settings.json)
```json
{
  "LoadLimitVal": 20,
  "LoadLimitEnabled": true,
  "Logging": true,
  "LaravelConfig": "../public_html/.env",
  "AutoFreeQueue": true
}
```

### Режимы запуска
```bash
./taskrunner                    # Основной режим (демон)
./taskrunner -testserv          # Только трекинг HTTP-сервер
./taskrunner -cleaner           # Очистка Redis и выход
./taskrunner -port 8555         # Задать порт трекинга
```

---

## 4. STORAGE (`extracted/storage/`)

### Что это
**REST API сервис** на Go для хранения email-данных, управления блэклистами и трекингом. Работает на высокопроизводительном fasthttp.

### Структура

```
storage/
├── src/
│   ├── main.go             # Точка входа, инициализация БД, regex
│   ├── api.go              # REST API эндпоинты (32KB)
│   ├── links.go            # Генерация/получение трекинг-ссылок (19KB)
│   ├── queue.go            # Redis-очередь обработки (9KB)
│   ├── sqltruct.go         # SQL-структуры и запросы (10KB)
│   ├── structs.go          # Структуры данных (8KB)
│   ├── helperfunctions.go  # Утилиты
│   └── settings.go         # Загрузка настроек
├── storage.json            # Конфигурация
├── GeoLite2-City.mmdb      # MaxMind GeoIP база (63MB)
├── go.mod                  # Go модуль "storage"
├── Makefile                # Сборка
└── VERSION                 # Версия (0.1)
```

### Что делает
- Хранит email-адреса с метаданными (домен, MX, IP, UserAgent, геолокация)
- Управляет блэклистами (email, домены, MX-серверы, имена)
- Принимает данные трекинга (открытия, клики, доставки) от taskrunner
- Предоставляет REST API для всех операций
- Кеширует данные в Redis для быстрого доступа

### API эндпоинты

Базовый URL: `http://localhost:8082/api/v1/`
Заголовок: `Authorization: 1122`

#### Email-данные
| Метод | Эндпоинт | Описание |
|-------|----------|----------|
| GET | `/mails/get/<email>` | Получить данные по email |
| POST | `/mails/getmulti` | Пакетный запрос email-данных |

#### Трекинг
| Метод | Эндпоинт | Описание |
|-------|----------|----------|
| POST | `/openers/submit` | Записать открытие письма |
| POST | `/clickers/submit` | Записать клик по ссылке |
| POST | `/deliveries/submit` | Записать факт доставки |

#### Блэклисты
| Метод | Эндпоинт | Описание |
|-------|----------|----------|
| POST | `/blacklists/set/<type>` | Добавить в блэклист (domain/email/mx/name) |
| GET | `/blacklists/get/<email>` | Проверить email в блэклисте |
| GET | `/blacklists/check/<email>` | Детальная проверка |
| GET | `/blacklists/mxcheck/<dns>` | Проверить MX-сервер |
| POST | `/blacklists/populate` | Массовое заполнение блэклиста |
| POST | `/blacklists/multiclean` | Массовая проверка списка |

#### Ссылки
| Метод | Эндпоинт | Описание |
|-------|----------|----------|
| POST | `/links/postimg` | Трекинг-пиксель |
| POST | `/links/postcamp` | Трекинг кампании |
| POST | `/links/postlink` | Трекинг произвольной ссылки |
| GET | `/links/get/<hash>` | Получить ссылку по хешу |

#### Статистика
| Метод | Эндпоинт | Описание |
|-------|----------|----------|
| GET | `/statprovider/<val>` | Статистика по провайдеру |
| GET | `/overall/stats` | Общая статистика |
| GET | `/ping/<val>` | Health check |

### Подключения
- **MySQL** (`storage` БД на 172.28.0.4) — основное хранилище
- **Redis** (localhost:6379) — кеш блэклистов, трекинга
- **GeoIP** (GeoLite2-City.mmdb) — определение геолокации

### Порты
- **8082** — основной API (0.0.0.0, доступен снаружи через 8091)
- **8083** — обработка ссылок (127.0.0.1, только внутри контейнера)

### Настройки (storage.json)
```json
{
  "Bind": "0.0.0.0",
  "Port": "8082",
  "LinksBind": "127.0.0.1",
  "LinksPort": "8083",
  "ApiAuthorization": "1122",
  "SqlHost": "172.28.0.2",
  "SqlDba": "storage",
  "SqlUser": "stor_user",
  "SqlPass": "stor_password_local",
  "SqlStorageTable": "emails_large",
  "QueueOversizeLimit": 3000,
  "BlQueueWatcherInterval": 30
}
```

---

## 5. PARKAGENCY_CRM (`extracted/parkagency_crm/`)

### Что это
**Redmine** — система управления проектами для Park Agency. **Полностью отдельная** от ESP-платформы, не взаимодействует с другими сервисами.

### Структура

```
parkagency_crm/
├── redmine.yml              # Docker Compose для Redmine
├── parkagency_crm.sql       # Дамп БД (5.5MB)
├── crm.parkagency.net.conf  # Nginx конфиг
└── redmine/                 # Конфигурация и файлы Redmine
    ├── configuration.yml
    └── files/               # Загруженные файлы
```

### Параметры
- **URL**: `crm.parkagency.net` / `crm.parkagency.org`
- **Контейнер**: 172.18.0.21:3000 (другая Docker-сеть!)
- **БД**: `parkagency_crm` (MySQL at 172.18.0.2)
- **Логин**: redmine / p1JvDbCmUFBAnCHoWihA

---

## Полный поток рассылки

```
1. ПОЛЬЗОВАТЕЛЬ создаёт кампанию в Laravel UI
   └→ Выбирает список подписчиков, шаблон, сервер отправки

2. ПОЛЬЗОВАТЕЛЬ нажимает "Отправить"
   └→ Laravel сохраняет данные кампании в MySQL
   └→ Laravel записывает подписчиков в Redis
   └→ Laravel публикует сообщение в Redis канал "taskrunner"
      (тип: CAMPAIGN_UPDATE, приоритет: 2, val: campaign_uid)

3. TASKRUNNER получает сообщение из Redis
   └→ Добавляет в приоритетную очередь
   └→ QueueHandler() извлекает задание
   └→ Загружает данные кампании из MySQL
   └→ Запускает: ./gosender -send -campuid <uid> -type smtp ...

4. GOSENDER обрабатывает кампанию
   └→ Читает подписчиков из Redis (батчами)
   └→ Для каждого подписчика:
      ├→ Подставляет переменные в шаблон
      ├→ Заменяет ссылки на трекинговые
      ├→ Добавляет пиксель отслеживания
      └→ Отправляет через SMTP/SES/SendGrid
   └→ Помечает кампанию как "done" в Redis

5. ПОЛУЧАТЕЛЬ открывает письмо
   └→ Браузер загружает пиксель с сервера трекинга
   └→ TASKRUNNER (:8555) получает запрос
      ├→ Записывает открытие в Redis
      ├→ Определяет GeoIP
      └→ Отправляет данные в STORAGE API (/openers/submit)

6. ПОЛУЧАТЕЛЬ кликает ссылку
   └→ Браузер идёт на трекинг-URL
   └→ TASKRUNNER (:8555) получает запрос
      ├→ Записывает клик в Redis
      ├→ Определяет GeoIP, User-Agent
      ├→ Отправляет данные в STORAGE API (/clickers/submit)
      └→ Редиректит на оригинальный URL

7. STORAGE сохраняет всё в MySQL
   └→ Таблицы: openers, clickers, deliveries, emails_large
   └→ Кеширует в Redis для быстрого доступа

8. ПОЛЬЗОВАТЕЛЬ видит статистику в Laravel UI
   └→ Laravel запрашивает данные из MySQL + Storage API
```

---

## Docker-архитектура

### Сеть (172.28.0.0/16)

```
┌─────────────────────────────────────────────────────────────┐
│                    Docker Network                            │
│                                                              │
│  ┌────────────┐  ┌────────────┐  ┌────────────────────────┐ │
│  │  MariaDB   │  │   Redis    │  │    App Container       │ │
│  │ 172.28.0.2 │  │ 172.28.0.3 │  │    172.28.0.10         │ │
│  │            │  │            │  │                        │ │
│  │ БД:        │  │ Каналы:    │  │  Nginx         :80    │ │
│  │ mailsendas │  │ taskrunner │  │  PHP-FPM              │ │
│  │ _testdev   │  │            │  │  Supervisor           │ │
│  │            │  │ Данные:    │  │  Socat    :6379→Redis │ │
│  └────────────┘  │ кампании   │  │  Taskrunner    :8555  │ │
│                  │ блэклисты  │  │  Storage       :8082  │ │
│  ┌────────────┐  │ сессии     │  │  Gosender (CLI)       │ │
│  │ StorageDB  │  │ кеш        │  │  Queue Workers        │ │
│  │ 172.28.0.4 │  └────────────┘  └────────────────────────┘ │
│  │            │                                              │
│  │ БД:        │                                              │
│  │ storage    │                                              │
│  └────────────┘                                              │
└─────────────────────────────────────────────────────────────┘
```

### Порты (хост → контейнер)

| Хост   | Контейнер | Сервис          |
|--------|-----------|-----------------|
| 8090   | 80        | Laravel Web UI  |
| 8091   | 8082      | Storage API     |
| 8092   | 8083      | Storage Links   |
| 3307   | 3306      | MariaDB         |
| 6380   | 6379      | Redis           |

---

## Запуск

```bash
# Полная сборка и запуск
docker compose up -d --build

# Только запуск (без пересборки)
docker compose up -d

# Логи в реальном времени
docker compose logs -f app

# Остановка
docker compose down
```

### Что делает init.sh при старте

1. Ждёт готовности MariaDB и Redis
2. Импортирует дамп БД (`mailsendas_testdev.sql.gz`) если пусто
3. Настраивает `.env` с Docker IP-адресами
4. Устанавливает права на `storage/` и `bootstrap/cache/`
5. Восстанавливает vendor-пакеты (composer install с обходом удалённого lsxiao)
6. Генерирует APP_KEY (только если не задан) и кеширует конфиг
7. Настраивает `gosender.json` и `storage.json`
8. Собирает Go-бинарники: **taskrunner**, **gosender**, **storage**
9. Запускает socat Redis-прокси (localhost:6379 → 172.28.0.3:6379)
10. Запускает **storage** и **taskrunner** как фоновые процессы
11. Стартует Supervisor (nginx + php-fpm + queue workers)

---

## Учётные данные

### Web UI
- **URL**: http://localhost:8090/login
- **Email**: `info@parkagency.org`
- **Password**: `password`

### MariaDB (основная)
- **Хост**: `localhost:3307` (с хоста) / `172.28.0.2:3306` (из контейнера)
- **БД**: `mailsendas_testdev`
- **User**: `app` / **Password**: `app_password_local`

### MariaDB (storage)
- **Хост**: `172.28.0.4:3306`
- **БД**: `storage`
- **User**: `stor_user` / **Password**: `stor_password_local`

### Redis
- **Хост**: `localhost:6380` (с хоста) / `172.28.0.3:6379` (из контейнера)
- **Пароль**: нет

### Storage API
- **URL**: http://localhost:8091
- **Заголовок**: `Authorization: 1122`

---

## Go модули

| Модуль | Путь | Сборка | Результат |
|--------|------|--------|-----------|
| `gosender` | `acelle_esp/go.mod` | `go build -o gosender ./gosender-src/` | `/home/app/gosender` |
| `taskrunner` | `taskrunner/go.mod` | `go build -ldflags "-X main.LICENSING=no" -o taskrunner` | `/home/app/taskrunner/taskrunner` |
| `storage` | `storage/go.mod` | `make build` (→ `go build ./src/`) | `/opt/storage/storage` |
| `tools` | `acelle_esp/public_html/tools/go.mod` | `go build ./populate_storage_src/` | Утилиты |

### Packr2 (встроенные ресурсы)
Taskrunner и Gosender используют `packr/v2` для встраивания файлов (TLS-сертификаты, шаблоны) в бинарник:
- `taskrunner/packrd/packed-packr.go` + `main-packr.go`
- `gosender-src/packrd/packed-packr.go` + `main-packr.go`

---

## Ключевые технические решения

### Socat Redis-прокси
Taskrunner использует **захардкоженный** адрес Redis `localhost:6379`. В Docker Redis на другом IP (172.28.0.3), поэтому в контейнере запускается socat-прокси:
```bash
socat TCP-LISTEN:6379,fork,reuseaddr TCP:172.28.0.3:6379 &
```

### Лицензирование
Gosender и Taskrunner содержат проверку лицензии через удалённый сервер. Для локальной среды отключена через ldflags:
```bash
go build -ldflags "-X main.LICENSING=no" ...
```

### Composer и удалённый пакет
Пакет `lsxiao/user-agent-for-laravel5` был удалён с GitHub. При `composer install`:
1. Удаляется из `composer.json` и `composer.lock`
2. Устанавливаются остальные пакеты
3. Восстанавливаются оригинальные файлы
4. Запускается `dump-autoload`

### PHP 7.2 vs platform_check
Некоторые пакеты требуют PHP 7.3+, но платформа работает на 7.2. Файл `platform_check.php` перезаписывается пустым при старте.

---

## Полезные команды

```bash
# === Логи ===
docker exec esp_app tail -f /home/app/public_html/storage/logs/laravel.log
docker exec esp_app tail -f /home/app/taskrunner/taskrunner.log
docker compose logs -f app

# === Статус процессов ===
docker exec esp_app ps aux | grep -E 'taskrunner|storage|gosender|php-fpm|nginx|socat'

# === Подключение к БД ===
docker exec -it esp_mariadb mysql -u app -papp_password_local mailsendas_testdev
docker exec -it esp_storage_db mysql -u stor_user -pstor_password_local storage

# === Redis ===
docker exec -it esp_redis redis-cli
docker exec -it esp_redis redis-cli KEYS "*"

# === Пересборка Go ===
docker exec esp_app bash -c "cd /home/app/taskrunner && go build -ldflags '-w -s -X main.LICENSING=no' -o taskrunner"
docker exec esp_app bash -c "cd /opt/storage && make build"
docker exec esp_app bash -c "cd /home/app && go build -ldflags '-w -s -X main.LICENSING=no' -o gosender ./gosender-src/"

# === Laravel ===
docker exec esp_app bash -c "cd /home/app/public_html && php artisan config:cache"
docker exec esp_app bash -c "cd /home/app/public_html && php artisan cache:clear"
docker exec esp_app bash -c "cd /home/app/public_html && php artisan queue:work --queue=high"

# === Storage API тест ===
curl -H "Authorization: 1122" http://localhost:8091/api/v1/ping/test
curl -H "Authorization: 1122" http://localhost:8091/api/v1/overall/stats

# === Полный перезапуск ===
docker compose down && docker compose up -d --build
```

---

## Исправления для Docker-среды

### Go
1. Созданы `go.mod` для taskrunner, storage, tools
2. Исправлено имя модуля gosender (`github.com/my/repo` → `gosender`)
3. Добавлены недостающие зависимости gosender (aws-sdk, go-redis, mysql, sendgrid, viper, gomail, xurls)
4. Исправлены пути packr2 (`home/devtest/...` → модульные)
5. Storage Makefile: `$(GOFILES)` → `./src/`
6. Tools: разделены `populate_storage.go` и `redis-cleanup.go` в подпапки (конфликт package main)
7. Исправлен формат `%s` → `%d` в populate_storage

### PHP/Laravel
8. Отключена `platform_check.php` (PHP 7.2 vs 7.3)
9. Восстановлен vendor через composer install с обходом lsxiao
10. Убран дублирующийся trait `ThrottlesLogins` в LoginController
11. Исправлен порядок `key:generate` / `config:cache` (фикс CSRF 419 ошибки)

### Инфраструктура
12. Socat Redis-прокси для taskrunner
13. Отключена лицензия через ldflags
14. Исправлен port mapping (storage: 8082, не 8081)
15. Добавлена сборка gosender в init.sh
