# Bunch flowers — Avito/Telegram Bot (PHP)
https://bunchflowers.ru/avito/


Этот репозиторий — серверная часть “бота-ассистента” для Bunch flowers:
- принимает входящие сообщения из Avito (через webhook/интеграцию),
- отвечает на базовые вопросы по базе знаний + уточняет детали заказа,
- при необходимости уведомляет команду в Telegram,
- хранит историю диалогов (MySQL опционально),
- даёт веб-панель управления + логи,
- умеет поднять Telegram webhook для команд/служебных действий.

> Важно: ChatGPT не “встраивается в Avito”. Работает схема **Avito → ваш webhook → LLM (OpenAI/DeepSeek) → ответ в Avito**.


---

## 1) Что внутри

### Основные возможности
- **/avito/webhook.php** — входящий webhook для Avito (или вашего интегратора/CRM), генерирует ответ через выбранный LLM (OpenAI/DeepSeek).
- **Telegram уведомления** (Bot API): отправка лидов/событий в TG.
- **MySQL (опционально)**: хранение диалогов, сообщений, лидов.
- **/avito/telegram.php** — управление Telegram webhook, ручные отправки и логи.
- **/avito/avito.php** — статусы Avito, ручные отправки, диалоги (если MySQL включен).
- **/avito/openai.php** — ручной чат с OpenAI и логи.
- **/avito/deepseek.php** — ручной чат с DeepSeek и логи.
- **/avito/tg_webhook.php** — приёмник Telegram webhook (для команд, тестов, служебной отправки в Avito через официальный Avito API).
- **/avito/admin.php** — базовые настройки (ключи/секреты) + включение MySQL.

### Структура папки `/avito/`

/avito/
.htaccess
admin.php
telegram.php
avito.php
avito_oauth_callback.php
openai.php
deepseek.php
webhook.php
tg_webhook.php

config.php
db.php
kb_client.php
migrate.sql

/_private/ (создаётся автоматически, лучше создать руками)
config.json (создастся после сохранения в админке)
panel_settings.json (создастся после сохранения в telegram.php или avito.php)
/logs/
app.log
in.log
out.log
openai.log
deepseek.log
db.log
tg.log
tg_webhook.log
/sessions/ (fallback, если MySQL выключен)


---

## 2) Архитектура

### Входящие сообщения Avito
1) Avito/интегратор отправляет событие на:
   - `https://ВАШ_ДОМЕН/avito/webhook.php`
2) Сервер:
   - пишет логи,
   - сохраняет историю в MySQL (если включено) или в файл,
   - зовёт выбранный LLM (OpenAI/DeepSeek),
   - возвращает JSON с `reply_text`.

> Важно: точный формат событий Avito зависит от того, как вы подключаете Avito Messenger API или какой “прокси” используете. `webhook.php` старается быть терпимым к структуре payload.

### Уведомления в Telegram
- `webhook.php` может слать уведомление в Telegram (например, когда видит “передать менеджеру”).
- Telegram webhook **не обязателен**, если вам нужны только уведомления.
- Telegram webhook нужен, если вы хотите управлять через Telegram командами (пример: “/avito chat_id текст”).

---

## 3) Бизнес-ограничения для текстов бота (обязательно)

Файл `kb_client.php` содержит “инструкции” и базу фактов, которые бот может говорить клиентам.

**Нельзя** в клиентских ответах раскрывать:
- конкретную страну происхождения базовой красной розы,
- конкретный сорт базовой красной розы.

**Можно** описывать базовую розу так:
- “импортная красная роза премиум-качества”,
- “это не российская и не китайская роза — качество и стойкость выше”,
- “бутон плотный, аккуратный, чуть меньше по размеру, чем у эквадорской розы”.

Цены, заложенные в тексты (для базовой розы):
- **89 ₽/шт** онлайн
- спеццена на остатки: **75 ₽/шт** (ниже — только как осознанный эксперимент)

---

## 4) Требования

- PHP 8.0+ (желательно 8.1/8.2)
- Расширение **cURL** или включённый `allow_url_fopen` (для OpenAI/DeepSeek и Telegram)
- MySQL 5.7+/8.0 (опционально)
- Права на запись в `/avito/_private/`

---

## 5) Установка

1) Загрузите все файлы в папку сайта:
   - `https://ВАШ_ДОМЕН/avito/`

2) Создайте директории (или дайте скриптам создать автоматически):

/avito/_private
/avito/_private/logs
/avito/_private/sessions


3) Проверьте, что `.htaccess` работает и закрывает доступ к `_private/`.

4) Откройте:
- `https://ВАШ_ДОМЕН/avito/admin.php`
- установите пароль админки.

---

## 6) Настройка через `admin.php`

В `admin.php` задаются ключевые параметры:

### Безопасность вебхука
- `webhook_secret` — если задан, `webhook.php` будет требовать заголовок `X-Webhook-Secret: <secret>`
- `allow_ips` — если заполнено, принимает запросы только от этих IP

### LLM (OpenAI/DeepSeek)
- `llm_provider` — провайдер ответов (`openai` или `deepseek`)
- `openai_api_key` — ключ из OpenAI API (Developer Platform)
- `openai_model` — модель (например `gpt-4.1-mini`)
- `openai_max_output_tokens` — ограничение длины ответа
- `deepseek_api_key` — ключ из DeepSeek API
- `deepseek_model` — модель (например `deepseek-chat`)
- `deepseek_max_output_tokens` — ограничение длины ответа

### Telegram
- `tg_bot_token` — токен Telegram-бота
- `tg_chat_id` — куда слать уведомления (личка/группа/канал)
- `tg_notify_mode` — когда уведомлять:
  - `handoff` / `always` / `never`

### MySQL (опционально)
- `mysql_enabled` — включить хранилище
- host/port/db/user/pass/prefix

---

## 7) MySQL: создание таблиц

Откройте `migrate.sql` и выполните в вашей базе данных.

Таблицы:
- `avito_conversations`
- `avito_messages`
- `avito_leads`

Если используете префикс (`mysql_prefix`), он будет добавлен к именам таблиц.

---

## 8) Webhook Avito: формат входящих и исходящих

`webhook.php` пытается найти текст в нескольких местах, например:
- `payload.message.text`
- `payload.message_text`
- `payload.text`

И chat_id в местах:
- `payload.chat_id`
- `payload.conversation_id`
- `payload.message.chat_id`

### Пример теста (curl)
```bash
curl -X POST https://ВАШ_ДОМЕН/avito/webhook.php \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: YOUR_SECRET" \
  -d '{"chat_id":"test123","message":{"text":"Сколько стоит 25 роз?"}}'


9) Панели управления

Откройте:

https://ВАШ_ДОМЕН/avito/telegram.php
https://ВАШ_ДОМЕН/avito/avito.php
https://ВАШ_ДОМЕН/avito/openai.php
https://ВАШ_ДОМЕН/avito/deepseek.php

Telegram:

- установка/разрыв webhook
- ручные отправки сообщений
- логи сообщений боту

Avito:

- статус входящих, webhook URL + секрет
- ручная отправка через официальный Avito API (messenger)
- диалоги (если MySQL включен)

OAuth (Avito Messenger):

1) В админке заполните `avito_client_id` и `avito_client_secret`.
2) В настройках приложения Avito укажите Redirect URL: `https://ВАШ_ДОМЕН/avito/avito_oauth_callback.php`.
3) Нажмите ссылку “Авторизоваться в Avito (OAuth)” в админке — токены сохранятся автоматически.

OpenAI:

- ручной чат и просмотр логов

DeepSeek:

- ручной чат и просмотр логов

Webhook URL по умолчанию:

https://ВАШ_ДОМЕН/avito/tg_webhook.php

Секрет:

хранится в /avito/_private/panel_settings.json как tg_secret_token

Telegram будет присылать его в заголовке:

X-Telegram-Bot-Api-Secret-Token

10) Telegram webhook (tg_webhook.php)

Файл логирует входящие апдейты в:

/avito/_private/logs/tg_webhook.log

Поддерживаемые команды:

/ping

/help

/avito <chat_id> <текст> — отправка сообщения “в Avito” через официальный Avito API (нужны avito_user_id и avito_access_token)

11) Логи

Все логи лежат в:

/avito/_private/logs/

Ключевые:

in.log — входящие Avito

out.log — ответы Avito

openai.log — ошибки/ответы OpenAI
deepseek.log — ошибки/ответы DeepSeek

tg.log — уведомления в TG

tg_webhook.log — входящие Telegram webhook

12) Безопасность (рекомендуется)

Не храните ключи в публичных файлах/репозитории.

Закройте _private/ через .htaccess.

Используйте webhook_secret для Avito-интеграции.

Для Telegram webhook используйте tg_secret_token.

Ограничьте доступ к admin.php по IP (если возможно).

Доступ к страницам telegram.php/avito.php/openai.php идёт через сессию админки (логин через admin.php).

13) Roadmap (автоматизация OAuth и расширение Avito API)

- Автоматизировать получение/обновление access token через OAuth client_credentials
- Добавить кеширование токена и автоматическую ротацию

Подтвердить и внедрить проверку подписи входящих webhook (если Avito её использует)

(Опционально) управление webhook Avito из панели (если Avito даёт методы установки/удаления)
