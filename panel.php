<?php
// /avito/panel.php
declare(strict_types=1);

/**
 * Панель управления:
 * - Avito: статус входящих (по последнему событию), подсказки для установки webhook вручную
 * - Telegram: setWebhook / deleteWebhook / getWebhookInfo + авто URL для webhook
 * - Окно сообщений Avito (если MySQL включен) + лог Telegram webhook
 * - Ручная отправка: в Telegram и в Avito (через avito_send_url, если задан)
 *
 * Требует:
 *   /avito/config.php
 *   /avito/db.php   (если используете MySQL)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

session_start();
avito_bootstrap_dirs();

// Вход только после admin.php
if (empty($_SESSION['admin_ok'])) {
  header('Location: /avito/admin.php');
  exit;
}

const PANEL_SETTINGS_FILE = AVITO_PRIVATE_DIR . '/panel_settings.json';

function panel_default_settings(): array {
  return [
    // Общие
    'messages_limit' => 60,
    'log_tail_lines' => 200,

    // Avito: URL нашего входящего webhook (если пусто — подставим автоматически)
    'avito_webhook_receiver_url' => '',
    'avito_webhook_secret_header' => 'X-Webhook-Secret',
    'avito_webhook_secret_value' => '', // если пусто — берём из config.php webhook_secret

    // Avito: отправка сообщения (пока через внешний endpoint — можно позже заменить на Avito API)
    'avito_send_url' => '',
    'avito_send_auth_header' => '', // одной строкой, напр. "Authorization: Bearer ..."

    // Telegram: webhook URL (если пусто — подставим автоматически /avito/tg_webhook.php)
    'tg_webhook_url' => '',
    // Telegram: secret token для заголовка X-Telegram-Bot-Api-Secret-Token
    'tg_secret_token' => '',
    'tg_drop_pending_updates' => true,
    'tg_allowed_updates' => 'message', // строка через запятую, напр: "message,callback_query"

    // Кеш статуса (для отображения)
    'tg_last_checked_at' => null,
    'tg_last_error' => '',
    'tg_last_info_json' => null,
  ];
}

function panel_load_settings(): array {
  $base = panel_default_settings();
  if (is_file(PANEL_SETTINGS_FILE)) {
    $raw = @file_get_contents(PANEL_SETTINGS_FILE);
    $j = json_decode($raw ?: '[]', true);
    if (is_array($j)) {
      foreach ($base as $k => $v) {
        if (array_key_exists($k, $j)) $base[$k] = $j[$k];
      }
    }
  }
  return $base;
}

function panel_save_settings(array $settings): bool {
  $base = panel_default_settings();
  $clean = [];
  foreach ($base as $k => $_) {
    $clean[$k] = $settings[$k] ?? $base[$k];
  }
  $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) return false;
  return (bool)@file_put_contents(PANEL_SETTINGS_FILE, $json . PHP_EOL, LOCK_EX);
}

function current_base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'bunchflowers.ru';
  return $scheme . '://' . $host;
}

function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
  }
  return (string)$_SESSION['csrf_token'];
}

function csrf_check(): void {
  $t = (string)($_POST['csrf_token'] ?? '');
  if ($t === '' || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $t)) {
    http_response_code(403);
    echo 'CSRF token mismatch';
    exit;
  }
}

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mask_secret(string $s, int $showLast = 4): string {
  $s = trim($s);
  if ($s === '') return '';
  $len = mb_strlen($s);
  if ($len <= $showLast) return str_repeat('•', $len);
  return str_repeat('•', $len - $showLast) . mb_substr($s, -$showLast);
}

function http_request_json(string $method, string $url, array $payload = [], array $headers = [], int $timeout = 20): array {
  $ch = curl_init($url);
  $method = strtoupper($method);

  $hdrs = array_merge(['Content-Type: application/json'], $headers);

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_HTTPHEADER => $hdrs,
    CURLOPT_CUSTOMREQUEST => $method,
  ]);

  if ($method !== 'GET') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
  }

  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $out = [
    'ok' => ($err === '' && $status >= 200 && $status < 300),
    'status' => $status,
    'error' => $err,
    'raw' => $raw,
    'json' => null,
  ];

  if (is_string($raw) && $raw !== '') {
    $j = json_decode($raw, true);
    if (is_array($j)) $out['json'] = $j;
  }

  return $out;
}

function tail_lines(string $file, int $lines = 200): string {
  if (!is_file($file)) return '';
  $lines = max(10, min(5000, $lines));
  $fp = @fopen($file, 'rb');
  if (!$fp) return '';
  $buffer = '';
  $chunkSize = 4096;

  fseek($fp, 0, SEEK_END);
  $pos = ftell($fp);
  $lineCount = 0;

  while ($pos > 0 && $lineCount <= $lines) {
    $readSize = ($pos - $chunkSize) >= 0 ? $chunkSize : $pos;
    $pos -= $readSize;
    fseek($fp, $pos, SEEK_SET);
    $chunk = fread($fp, $readSize);
    if ($chunk === false) break;
    $buffer = $chunk . $buffer;
    $lineCount = substr_count($buffer, "\n");
  }

  fclose($fp);

  $parts = explode("\n", $buffer);
  $parts = array_slice($parts, -$lines);
  return implode("\n", $parts);
}

function extract_last_log_time(string $logPath): ?int {
  // Ищем последнюю строку вида: [2026-01-31T...]
  if (!is_file($logPath)) return null;
  $tail = tail_lines($logPath, 50);
  $lines = array_values(array_filter(array_map('trim', explode("\n", $tail))));
  for ($i = count($lines) - 1; $i >= 0; $i--) {
    if (preg_match('/^\[(.+?)\]/', $lines[$i], $m)) {
      $ts = strtotime($m[1]);
      if ($ts !== false) return (int)$ts;
    }
  }
  return null;
}

function tg_api_base(string $botToken): string {
  return 'https://api.telegram.org/bot' . $botToken;
}

function tg_get_webhook_info(string $botToken): array {
  $url = tg_api_base($botToken) . '/getWebhookInfo';
  return http_request_json('GET', $url, [], [], 12);
}

function tg_set_webhook(string $botToken, string $webhookUrl, string $secretToken, bool $dropPending, array $allowedUpdates): array {
  $url = tg_api_base($botToken) . '/setWebhook';
  $payload = [
    'url' => $webhookUrl,
    'drop_pending_updates' => $dropPending,
  ];
  if ($secretToken !== '') {
    $payload['secret_token'] = $secretToken;
  }
  if (!empty($allowedUpdates)) {
    $payload['allowed_updates'] = $allowedUpdates;
  }
  return http_request_json('POST', $url, $payload, [], 15);
}

function tg_delete_webhook(string $botToken, bool $dropPending): array {
  $url = tg_api_base($botToken) . '/deleteWebhook';
  $payload = [
    'drop_pending_updates' => $dropPending,
  ];
  return http_request_json('POST', $url, $payload, [], 15);
}

function tg_send_message(string $botToken, string $chatId, string $text): array {
  $url = tg_api_base($botToken) . '/sendMessage';
  $payload = [
    'chat_id' => $chatId,
    'text' => $text,
    'disable_web_page_preview' => true,
  ];
  return http_request_json('POST', $url, $payload, [], 12);
}

function parse_allowed_updates(string $s): array {
  $s = trim($s);
  if ($s === '') return [];
  $parts = preg_split('/[\s,]+/', $s) ?: [];
  $parts = array_values(array_filter(array_map('trim', $parts)));
  // Telegram принимает массив строк
  return $parts;
}

// -------------------- load config/settings --------------------

$cfg = avito_get_config();
$settings = panel_load_settings();

$baseUrl = current_base_url();

// Авто URL'ы
$autoAvitoWebhookUrl = $baseUrl . '/avito/webhook.php';
$autoTgWebhookUrl = $baseUrl . '/avito/tg_webhook.php';

// Подставляем URL’ы и секреты (логика использования)
$avitoWebhookReceiverUrl = trim((string)$settings['avito_webhook_receiver_url']);
if ($avitoWebhookReceiverUrl === '') $avitoWebhookReceiverUrl = $autoAvitoWebhookUrl;

$avitoSecretHeader = trim((string)$settings['avito_webhook_secret_header']);
if ($avitoSecretHeader === '') $avitoSecretHeader = 'X-Webhook-Secret';

$avitoSecretValue = trim((string)$settings['avito_webhook_secret_value']);
if ($avitoSecretValue === '') $avitoSecretValue = (string)($cfg['webhook_secret'] ?? '');

$tgWebhookUrl = trim((string)$settings['tg_webhook_url']);
if ($tgWebhookUrl === '') $tgWebhookUrl = $autoTgWebhookUrl;

// Telegram secret token: если пусто — возьмём из avito webhook_secret или сгенерим
$tgSecretToken = trim((string)$settings['tg_secret_token']);
if ($tgSecretToken === '') {
  $seed = trim((string)($cfg['webhook_secret'] ?? ''));
  $tgSecretToken = $seed !== '' ? $seed : bin2hex(random_bytes(16));
  $settings['tg_secret_token'] = $tgSecretToken;
  @panel_save_settings($settings);
}

$dropPending = !empty($settings['tg_drop_pending_updates']);
$allowedUpdatesStr = (string)($settings['tg_allowed_updates'] ?? 'message');
$allowedUpdates = parse_allowed_updates($allowedUpdatesStr);

// Statuses
$openaiOk = !empty($cfg['openai_api_key']);
$tgConfigured = !empty($cfg['tg_bot_token']); // для webhook нужен токен
$tgNotifyReady = !empty($cfg['tg_bot_token']) && !empty($cfg['tg_chat_id']);
$mysqlEnabled = !empty($cfg['mysql_enabled']);

// DB status
$pdo = null;
$dbOk = false;
try {
  $pdo = avito_db();
  $dbOk = ($pdo instanceof PDO);
} catch (Throwable $e) {
  $dbOk = false;
}

// Flash
$flash = '';
$flashType = 'ok';

// -------------------- actions --------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');

  // Save settings (panel)
  if ($action === 'save_panel_settings') {
    $new = $settings;

    $new['messages_limit'] = (int)($_POST['messages_limit'] ?? 60);
    $new['log_tail_lines'] = (int)($_POST['log_tail_lines'] ?? 200);

    $new['avito_webhook_receiver_url'] = trim((string)($_POST['avito_webhook_receiver_url'] ?? ''));
    $new['avito_webhook_secret_header'] = trim((string)($_POST['avito_webhook_secret_header'] ?? 'X-Webhook-Secret'));
    $new['avito_webhook_secret_value'] = trim((string)($_POST['avito_webhook_secret_value'] ?? ''));

    $new['avito_send_url'] = trim((string)($_POST['avito_send_url'] ?? ''));
    $new['avito_send_auth_header'] = trim((string)($_POST['avito_send_auth_header'] ?? ''));

    $new['tg_webhook_url'] = trim((string)($_POST['tg_webhook_url'] ?? ''));
    $new['tg_secret_token'] = trim((string)($_POST['tg_secret_token'] ?? ''));
    $new['tg_drop_pending_updates'] = !empty($_POST['tg_drop_pending_updates']);
    $new['tg_allowed_updates'] = trim((string)($_POST['tg_allowed_updates'] ?? 'message'));

    if (panel_save_settings($new)) {
      $settings = $new;
      $flash = 'Настройки панели сохранены ✅';
      $flashType = 'ok';
    } else {
      $flash = 'Не удалось сохранить /avito/_private/panel_settings.json (проверь права) ❌';
      $flashType = 'bad';
    }
  }

  // Telegram webhook: refresh status
  if ($action === 'tg_refresh_status') {
    if (empty($cfg['tg_bot_token'])) {
      $flash = 'В config не задан tg_bot_token — Telegram webhook не настроить ❌';
      $flashType = 'bad';
    } else {
      $res = tg_get_webhook_info((string)$cfg['tg_bot_token']);
      $settings['tg_last_checked_at'] = date('c');
      if ($res['ok'] && is_array($res['json'])) {
        $settings['tg_last_error'] = '';
        $settings['tg_last_info_json'] = $res['json'];
        panel_save_settings($settings);
        $flash = 'Статус Telegram webhook обновлён ✅';
        $flashType = 'ok';
      } else {
        $err = $res['error'] ?: ('HTTP ' . $res['status']);
        $settings['tg_last_error'] = $err;
        $settings['tg_last_info_json'] = $res['json'] ?? null;
        panel_save_settings($settings);
        $flash = 'Ошибка получения статуса Telegram webhook ❌: ' . $err;
        $flashType = 'bad';
      }
    }
  }

  // Telegram webhook: set
  if ($action === 'tg_set_webhook') {
    if (empty($cfg['tg_bot_token'])) {
      $flash = 'В config не задан tg_bot_token — Telegram webhook не настроить ❌';
      $flashType = 'bad';
    } else {
      $settings = panel_load_settings();
      $tgWebhookUrl2 = trim((string)$settings['tg_webhook_url']);
      if ($tgWebhookUrl2 === '') $tgWebhookUrl2 = $autoTgWebhookUrl;

      $tgSecret2 = trim((string)$settings['tg_secret_token']);
      if ($tgSecret2 === '') $tgSecret2 = $tgSecretToken;

      $dropPending2 = !empty($settings['tg_drop_pending_updates']);
      $allowed2 = parse_allowed_updates((string)($settings['tg_allowed_updates'] ?? 'message'));

      $res = tg_set_webhook((string)$cfg['tg_bot_token'], $tgWebhookUrl2, $tgSecret2, $dropPending2, $allowed2);

      $settings['tg_last_checked_at'] = date('c');
      if ($res['ok'] && is_array($res['json'])) {
        $settings['tg_last_error'] = '';
        // после установки сразу дернем getWebhookInfo
        $info = tg_get_webhook_info((string)$cfg['tg_bot_token']);
        $settings['tg_last_info_json'] = $info['json'] ?? $res['json'];
        panel_save_settings($settings);

        $flash = 'Telegram webhook установлен ✅';
        $flashType = 'ok';
      } else {
        $err = $res['error'] ?: ('HTTP ' . $res['status']);
        $settings['tg_last_error'] = $err;
        $settings['tg_last_info_json'] = $res['json'] ?? null;
        panel_save_settings($settings);

        $flash = 'Ошибка установки Telegram webhook ❌: ' . $err;
        $flashType = 'bad';
      }
    }
  }

  // Telegram webhook: delete
  if ($action === 'tg_delete_webhook') {
    if (empty($cfg['tg_bot_token'])) {
      $flash = 'В config не задан tg_bot_token — Telegram webhook не настроить ❌';
      $flashType = 'bad';
    } else {
      $settings = panel_load_settings();
      $dropPending2 = !empty($settings['tg_drop_pending_updates']);

      $res = tg_delete_webhook((string)$cfg['tg_bot_token'], $dropPending2);

      $settings['tg_last_checked_at'] = date('c');
      if ($res['ok'] && is_array($res['json'])) {
        $settings['tg_last_error'] = '';
        $info = tg_get_webhook_info((string)$cfg['tg_bot_token']);
        $settings['tg_last_info_json'] = $info['json'] ?? $res['json'];
        panel_save_settings($settings);

        $flash = 'Telegram webhook удалён ✅';
        $flashType = 'ok';
      } else {
        $err = $res['error'] ?: ('HTTP ' . $res['status']);
        $settings['tg_last_error'] = $err;
        $settings['tg_last_info_json'] = $res['json'] ?? null;
        panel_save_settings($settings);

        $flash = 'Ошибка удаления Telegram webhook ❌: ' . $err;
        $flashType = 'bad';
      }
    }
  }

  // Telegram: test notify to configured tg_chat_id
  if ($action === 'tg_test_notify') {
    if (empty($cfg['tg_bot_token']) || empty($cfg['tg_chat_id'])) {
      $flash = 'В config нужно задать tg_bot_token и tg_chat_id для теста ❌';
      $flashType = 'bad';
    } else {
      $msg = "✅ Тестовое уведомление.\nPanel: {$baseUrl}/avito/panel.php\nTime: " . date('c');
      $res = tg_send_message((string)$cfg['tg_bot_token'], (string)$cfg['tg_chat_id'], $msg);
      if ($res['ok']) {
        $flash = 'Тестовое сообщение отправлено в Telegram ✅';
        $flashType = 'ok';
      } else {
        $flash = 'Ошибка тестового сообщения в Telegram ❌: ' . ($res['error'] ?: ('HTTP ' . $res['status']));
        $flashType = 'bad';
      }
    }
  }

  // Manual send to Telegram (any chat)
  if ($action === 'send_tg_manual') {
    $to = trim((string)($_POST['tg_to'] ?? ''));
    $text = trim((string)($_POST['tg_text'] ?? ''));
    if (empty($cfg['tg_bot_token'])) {
      $flash = 'В config не задан tg_bot_token ❌';
      $flashType = 'bad';
    } elseif ($to === '') {
      $flash = 'Telegram chat_id пустой ❌';
      $flashType = 'bad';
    } elseif ($text === '') {
      $flash = 'Текст пустой ❌';
      $flashType = 'bad';
    } else {
      $res = tg_send_message((string)$cfg['tg_bot_token'], $to, $text);
      if ($res['ok']) {
        $flash = 'Отправлено в Telegram ✅';
        $flashType = 'ok';
      } else {
        $flash = 'Ошибка отправки в Telegram ❌: ' . ($res['error'] ?: ('HTTP ' . $res['status']));
        $flashType = 'bad';
      }
    }
  }

  // Manual send to Avito (via external send URL)
  if ($action === 'send_avito_manual') {
    $settings = panel_load_settings();
    $sendUrl = trim((string)$settings['avito_send_url']);
    $authHeader = trim((string)$settings['avito_send_auth_header']);
    $headers = [];
    if ($authHeader !== '') $headers[] = $authHeader;

    $chatId = trim((string)($_POST['avito_chat_id'] ?? ''));
    $text = trim((string)($_POST['avito_text'] ?? ''));

    if ($sendUrl === '') {
      $flash = 'Не задан avito_send_url в настройках панели ❌';
      $flashType = 'bad';
    } elseif ($chatId === '') {
      $flash = 'Avito chat_id пустой ❌';
      $flashType = 'bad';
    } elseif ($text === '') {
      $flash = 'Текст пустой ❌';
      $flashType = 'bad';
    } else {
      $payload = ['chat_id' => $chatId, 'text' => $text];
      $res = http_request_json('POST', $sendUrl, $payload, $headers, 20);
      if ($res['ok']) {
        $flash = 'Отправлено в Avito (через send URL) ✅';
        $flashType = 'ok';
      } else {
        $flash = 'Ошибка отправки в Avito ❌: ' . ($res['error'] ?: ('HTTP ' . $res['status']));
        $flashType = 'bad';
      }
    }
  }

  // Generate new TG secret token
  if ($action === 'tg_regen_secret') {
    $settings = panel_load_settings();
    $settings['tg_secret_token'] = bin2hex(random_bytes(16));
    if (panel_save_settings($settings)) {
      $flash = 'Секрет Telegram обновлён ✅ (не забудь снова нажать “Установить Telegram webhook”)';
      $flashType = 'ok';
    } else {
      $flash = 'Не удалось сохранить новый секрет ❌';
      $flashType = 'bad';
    }
  }
}

// reload settings after actions
$settings = panel_load_settings();

$messagesLimit = max(20, min(200, (int)($settings['messages_limit'] ?? 60)));
$logTailLines = max(50, min(2000, (int)($settings['log_tail_lines'] ?? 200)));

$avitoWebhookReceiverUrl = trim((string)$settings['avito_webhook_receiver_url']);
if ($avitoWebhookReceiverUrl === '') $avitoWebhookReceiverUrl = $autoAvitoWebhookUrl;
$avitoSecretHeader = trim((string)$settings['avito_webhook_secret_header']);
if ($avitoSecretHeader === '') $avitoSecretHeader = 'X-Webhook-Secret';
$avitoSecretValue = trim((string)$settings['avito_webhook_secret_value']);
if ($avitoSecretValue === '') $avitoSecretValue = (string)($cfg['webhook_secret'] ?? '');

$tgWebhookUrl = trim((string)$settings['tg_webhook_url']);
if ($tgWebhookUrl === '') $tgWebhookUrl = $autoTgWebhookUrl;
$tgSecretToken = trim((string)$settings['tg_secret_token']);
$dropPending = !empty($settings['tg_drop_pending_updates']);
$allowedUpdatesStr = (string)($settings['tg_allowed_updates'] ?? 'message');

$tgLastChecked = (string)($settings['tg_last_checked_at'] ?? '');
$tgLastError = (string)($settings['tg_last_error'] ?? '');
$tgInfo = $settings['tg_last_info_json'] ?? null;

// -------------------- Avito status (by last incoming) --------------------
$avitoLastInTs = null;

if ($dbOk && $pdo instanceof PDO) {
  try {
    $tMsg = avito_db_prefix() . 'avito_messages';
    $tConv = avito_db_prefix() . 'avito_conversations';
    $sql = "
      SELECT MAX(m.created_at) AS last_in
      FROM {$tMsg} m
      WHERE m.role = 'user'
    ";
    $st = $pdo->query($sql);
    $row = $st ? $st->fetch() : null;
    if (is_array($row) && !empty($row['last_in'])) {
      $ts = strtotime((string)$row['last_in']);
      if ($ts !== false) $avitoLastInTs = (int)$ts;
    }
  } catch (Throwable $e) {
    // fallback to logs
    $avitoLastInTs = extract_last_log_time(AVITO_LOG_DIR . '/in.log');
  }
} else {
  $avitoLastInTs = extract_last_log_time(AVITO_LOG_DIR . '/in.log');
}

$now = time();
$avitoActive = ($avitoLastInTs !== null && ($now - $avitoLastInTs) <= 60 * 30); // 30 минут
$avitoLastInHuman = $avitoLastInTs ? date('Y-m-d H:i:s', $avitoLastInTs) : 'нет данных';

// -------------------- Messages window (Avito DB) --------------------
$selectedChat = trim((string)($_GET['chat'] ?? ''));
$conversations = [];
$thread = [];

if ($dbOk && $pdo instanceof PDO) {
  try {
    $tConv = avito_db_prefix() . 'avito_conversations';
    $tMsg  = avito_db_prefix() . 'avito_messages';

    $sql = "
      SELECT
        c.chat_id,
        c.updated_at,
        (
          SELECT m1.text
          FROM {$tMsg} m1
          WHERE m1.conversation_id = c.id AND m1.role = 'user'
          ORDER BY m1.id DESC
          LIMIT 1
        ) AS last_user_text,
        (
          SELECT m3.created_at
          FROM {$tMsg} m3
          WHERE m3.conversation_id = c.id
          ORDER BY m3.id DESC
          LIMIT 1
        ) AS last_message_at
      FROM {$tConv} c
      ORDER BY c.updated_at DESC
      LIMIT :lim
    ";
    $st = $pdo->prepare($sql);
    $st->bindValue(':lim', $messagesLimit, PDO::PARAM_INT);
    $st->execute();
    $conversations = $st->fetchAll() ?: [];

    if ($selectedChat !== '') {
      $sql2 = "
        SELECT m.role, m.text, m.created_at
        FROM {$tMsg} m
        JOIN {$tConv} c ON c.id = m.conversation_id
        WHERE c.chat_id = :chat
        ORDER BY m.id DESC
        LIMIT 120
      ";
      $st2 = $pdo->prepare($sql2);
      $st2->execute(['chat' => $selectedChat]);
      $rows = $st2->fetchAll() ?: [];
      $thread = array_reverse($rows);
    }
  } catch (Throwable $e) {
    $dbOk = false;
  }
}

// Logs
$knownLogs = [
  'in.log' => AVITO_LOG_DIR . '/in.log',
  'out.log' => AVITO_LOG_DIR . '/out.log',
  'openai.log' => AVITO_LOG_DIR . '/openai.log',
  'db.log' => AVITO_LOG_DIR . '/db.log',
  'tg.log' => AVITO_LOG_DIR . '/tg.log',
  'tg_webhook.log' => AVITO_LOG_DIR . '/tg_webhook.log',
];

$selectedLog = (string)($_GET['log'] ?? 'in.log');
if (!isset($knownLogs[$selectedLog])) $selectedLog = 'in.log';
$logText = tail_lines($knownLogs[$selectedLog], $logTailLines);

// UI pills
function pill(string $text, string $type): string {
  $type = in_array($type, ['ok','bad','warn'], true) ? $type : 'warn';
  return '<span class="pill ' . $type . '">' . h($text) . '</span>';
}

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bot Panel</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;max-width:1180px;margin:24px auto;padding:0 12px;background:#fafafa;}
    a{color:#111}
    .top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
    .nav{display:flex;gap:10px;flex-wrap:wrap}
    .nav a{padding:8px 10px;border-radius:10px;background:#fff;border:1px solid #eee;text-decoration:none}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media (max-width: 980px){ .grid{grid-template-columns:1fr} }
    .card{border:1px solid #eee;border-radius:16px;padding:14px;background:#fff;margin:12px 0}
    .card h2{margin:0 0 8px 0;font-size:18px}
    .card h3{margin:0 0 8px 0;font-size:16px}
    .hint{color:#666;font-size:13px;line-height:1.45}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media (max-width: 720px){ .row{grid-template-columns:1fr} }
    .split{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media (max-width: 980px){ .split{grid-template-columns:1fr} }
    input,select,textarea{width:100%;padding:10px;margin:6px 0 12px;border:1px solid #ccc;border-radius:12px;background:#fff}
    textarea{min-height:90px}
    button{padding:10px 14px;border-radius:12px;border:0;background:#111;color:#fff;cursor:pointer}
    button.secondary{background:#555}
    button.danger{background:#b00020}
    code{background:#f2f2f2;padding:2px 6px;border-radius:10px}
    .flash{padding:10px 12px;border-radius:12px;margin:12px 0}
    .flash.ok{background:#e9f7ee;border:1px solid #b9e4c7;color:#0a7a2a}
    .flash.bad{background:#fdecee;border:1px solid #f3b5bd;color:#b00020}
    .pill{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;border:1px solid #eee;background:#fff}
    .pill.ok{border-color:#b9e4c7;background:#e9f7ee;color:#0a7a2a}
    .pill.bad{border-color:#f3b5bd;background:#fdecee;color:#b00020}
    .pill.warn{border-color:#ffe3a5;background:#fff6df;color:#8a5b00}
    table{width:100%;border-collapse:collapse}
    th,td{border-top:1px solid #eee;padding:10px;vertical-align:top;font-size:14px}
    th{background:#fafafa;text-align:left}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
    .msg{padding:10px;border-radius:14px;margin:8px 0;white-space:pre-wrap}
    .msg.user{background:#f5f5ff;border:1px solid #e5e5ff}
    .msg.assistant{background:#f7f7f7;border:1px solid #ededed}
    .msgmeta{font-size:12px;color:#666;margin-top:6px}
  </style>
</head>
<body>

<div class="top">
  <div>
    <h1 style="margin:0">Bot Panel</h1>
    <div class="hint">Avito + Telegram: вебхуки, статусы, диалоги, логи, ручные отправки.</div>
  </div>
  <div class="nav">
    <a href="#avito">Avito</a>
    <a href="#telegram">Telegram</a>
    <a href="#messages">Диалоги</a>
    <a href="#send">Отправка</a>
    <a href="#logs">Логи</a>
    <a href="/avito/admin.php">Админка</a>
  </div>
</div>

<?php if ($flash !== ''): ?>
  <div class="flash <?=h($flashType)?>"><?=h($flash)?></div>
<?php endif; ?>

<div class="grid">
  <div class="card">
    <h2>Статус системы</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
      <?= pill('OpenAI: ' . ($openaiOk ? 'ключ задан' : 'нет ключа'), $openaiOk ? 'ok' : 'bad') ?>
      <?= pill('Telegram: ' . ($tgNotifyReady ? 'уведомления готовы' : ($tgConfigured ? 'токен есть, chat_id нет' : 'не настроено')), $tgNotifyReady ? 'ok' : 'warn') ?>
      <?= pill('MySQL: ' . ($mysqlEnabled ? ($dbOk ? 'подключено' : 'ошибка') : 'выключено'), $mysqlEnabled ? ($dbOk ? 'ok' : 'bad') : 'warn') ?>
      <?= pill('Avito inbound: ' . ($avitoActive ? 'активно' : 'неактивно'), $avitoActive ? 'ok' : 'warn') ?>
    </div>
    <div class="hint" style="margin-top:10px">
      Последнее Avito входящее: <span class="mono"><?=h($avitoLastInHuman)?></span>
    </div>
  </div>

  <div class="card">
    <h2>Быстрые действия</h2>
    <div class="hint">Telegram webhook можно установить/удалить прямо отсюда. Avito webhook на старте — вручную (URL + secret).</div>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <button type="submit" name="action" value="tg_set_webhook">Установить Telegram webhook</button>
      <button type="submit" name="action" value="tg_delete_webhook" class="danger">Разорвать Telegram webhook</button>
      <button type="submit" name="action" value="tg_refresh_status" class="secondary">Обновить статус TG</button>
      <button type="submit" name="action" value="tg_test_notify" class="secondary">Тест в TG</button>
    </form>
  </div>
</div>

<div class="card" id="avito">
  <h2>Avito Webhook</h2>
  <div class="hint">
    Сейчас блок сделан в “manual mode”: данные для webhook уже готовы, а статус считается по факту входящих сообщений (последняя активность).
    Когда вы подключите официальный Avito Messenger API — добавим OAuth и “кнопки установки” через API (если Avito это поддержит).
  </div>

  <div class="row" style="margin-top:10px">
    <div>
      <label>Webhook URL (куда Avito будет слать события)</label>
      <input value="<?=h($avitoWebhookReceiverUrl)?>" readonly>
      <div class="hint">Это ваш входящий endpoint для Avito: <code class="mono">/avito/webhook.php</code></div>
    </div>
    <div>
      <label>Secret header + value</label>
      <input value="<?=h($avitoSecretHeader . ': ' . mask_secret($avitoSecretValue))?>" readonly>
      <div class="hint">Заголовок и секрет для защиты webhook (если используете).</div>
    </div>
  </div>

  <div style="margin-top:10px">
    <?= pill('Статус: ' . ($avitoActive ? 'активен' : 'не было событий недавно'), $avitoActive ? 'ok' : 'warn') ?>
    <span class="pill">Последнее входящее: <span class="mono"><?=h($avitoLastInHuman)?></span></span>
  </div>
</div>

<div class="card" id="telegram">
  <h2>Telegram Webhook</h2>
  <div class="hint">
    Telegram webhook нужен, если вы хотите принимать команды/сообщения из Telegram (например: отправка в Avito по chat_id).
    Если Telegram нужен только для уведомлений — webhook не обязателен.
  </div>

  <div class="row" style="margin-top:10px">
    <div>
      <label>Webhook URL</label>
      <input value="<?=h($tgWebhookUrl)?>" readonly>
      <div class="hint">
        По умолчанию это <code class="mono">/avito/tg_webhook.php</code>. Этот файл должен существовать (см. ниже в моём сообщении).
      </div>
    </div>
    <div>
      <label>Secret token (в заголовке Telegram)</label>
      <input value="<?=h(mask_secret($tgSecretToken))?>" readonly>
      <div class="hint">
        Telegram будет присылать заголовок <code class="mono">X-Telegram-Bot-Api-Secret-Token</code>. Мы его проверяем в <code class="mono">tg_webhook.php</code>.
      </div>
      <form method="post" style="margin-top:6px">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <button type="submit" name="action" value="tg_regen_secret" class="secondary">Сгенерировать новый секрет</button>
      </form>
    </div>
  </div>

  <div style="margin-top:10px">
    <?php
      $tgUrlCurrent = '';
      $tgPending = '';
      $tgLastErrMsg = '';
      if (is_array($tgInfo) && isset($tgInfo['result']) && is_array($tgInfo['result'])) {
        $tgUrlCurrent = (string)($tgInfo['result']['url'] ?? '');
        $tgPending = (string)($tgInfo['result']['pending_update_count'] ?? '');
        $tgLastErrMsg = (string)($tgInfo['result']['last_error_message'] ?? '');
      }
    ?>
    <?= pill('TG token: ' . ($tgConfigured ? 'есть' : 'нет (задать в admin.php)'), $tgConfigured ? 'ok' : 'bad') ?>
    <?= pill('Webhook установлен: ' . ($tgUrlCurrent !== '' ? 'да' : 'нет'), $tgUrlCurrent !== '' ? 'ok' : 'warn') ?>
    <?php if ($tgLastChecked): ?>
      <span class="pill">Проверено: <span class="mono"><?=h($tgLastChecked)?></span></span>
    <?php endif; ?>
    <?php if ($tgPending !== ''): ?>
      <span class="pill">Pending: <span class="mono"><?=h($tgPending)?></span></span>
    <?php endif; ?>
    <?php if ($tgLastErrMsg !== ''): ?>
      <div style="margin-top:8px;color:#b00020"><b>Last error:</b> <?=h($tgLastErrMsg)?></div>
    <?php elseif ($tgLastError !== ''): ?>
      <div style="margin-top:8px;color:#b00020"><b>Error:</b> <?=h($tgLastError)?></div>
    <?php endif; ?>
    <?php if ($tgUrlCurrent !== ''): ?>
      <div class="hint" style="margin-top:8px">Текущий webhook URL в Telegram: <code class="mono"><?=h($tgUrlCurrent)?></code></div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Настройки панели</h2>
  <div class="hint">
    Хранятся в <code class="mono">/avito/_private/panel_settings.json</code>. Avito/Telegram блоки раздельные.
  </div>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="save_panel_settings">

    <div class="row">
      <div>
        <h3>Avito</h3>
        <label>Webhook receiver URL (если пусто — авто)</label>
        <input name="avito_webhook_receiver_url" value="<?=h((string)$settings['avito_webhook_receiver_url'])?>" placeholder="<?=h($autoAvitoWebhookUrl)?>">

        <label>Secret header name</label>
        <input name="avito_webhook_secret_header" value="<?=h((string)$settings['avito_webhook_secret_header'])?>" placeholder="X-Webhook-Secret">

        <label>Secret value (если пусто — берём из config webhook_secret)</label>
        <input name="avito_webhook_secret_value" value="<?=h((string)$settings['avito_webhook_secret_value'])?>" placeholder="(пусто)">

        <label>Avito send URL (пока внешний endpoint)</label>
        <input name="avito_send_url" value="<?=h((string)$settings['avito_send_url'])?>" placeholder="https://.../sendMessage">

        <label>Avito send auth header (одной строкой)</label>
        <input name="avito_send_auth_header" value="<?=h((string)$settings['avito_send_auth_header'])?>" placeholder="Authorization: Bearer ...">
      </div>

      <div>
        <h3>Telegram</h3>
        <label>Telegram webhook URL (если пусто — авто)</label>
        <input name="tg_webhook_url" value="<?=h((string)$settings['tg_webhook_url'])?>" placeholder="<?=h($autoTgWebhookUrl)?>">

        <label>Secret token (Telegram header)</label>
        <input name="tg_secret_token" value="<?=h((string)$settings['tg_secret_token'])?>" placeholder="если пусто — сгенерится">

        <label>
          <input type="checkbox" name="tg_drop_pending_updates" value="1" <?=!empty($settings['tg_drop_pending_updates'])?'checked':''?>>
          drop_pending_updates (очистить очередь при set/delete webhook)
        </label>

        <label>allowed_updates (через запятую)</label>
        <input name="tg_allowed_updates" value="<?=h((string)$settings['tg_allowed_updates'])?>" placeholder="message,callback_query">

        <h3 style="margin-top:16px">UI</h3>
        <label>Лимит диалогов (20–200)</label>
        <input type="number" name="messages_limit" value="<?=h((string)$settings['messages_limit'])?>" min="20" max="200">

        <label>Хвост логов (50–2000)</label>
        <input type="number" name="log_tail_lines" value="<?=h((string)$settings['log_tail_lines'])?>" min="50" max="2000">
      </div>
    </div>

    <button type="submit">Сохранить настройки панели</button>
  </form>
</div>

<div class="card" id="messages">
  <h2>Диалоги Avito</h2>

  <?php if ($dbOk): ?>
    <div class="hint">Показывается из MySQL. Нажмите “Открыть”, чтобы увидеть ветку.</div>

    <div class="split">
      <div>
        <table>
          <thead>
            <tr>
              <th>Chat ID</th>
              <th>Когда</th>
              <th>Последнее входящее</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($conversations as $c): ?>
            <tr>
              <td class="mono"><?=h((string)$c['chat_id'])?></td>
              <td class="mono"><?=h((string)($c['last_message_at'] ?? $c['updated_at'] ?? ''))?></td>
              <td><?=h(mb_strimwidth((string)($c['last_user_text'] ?? ''), 0, 80, '…', 'UTF-8'))?></td>
              <td><a href="?chat=<?=urlencode((string)$c['chat_id'])?>#messages">Открыть</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card" style="margin:0">
        <h3>Ветка: <?= $selectedChat ? '<span class="mono">'.h($selectedChat).'</span>' : 'не выбрана' ?></h3>
        <?php if ($selectedChat && $thread): ?>
          <?php foreach ($thread as $m): ?>
            <div class="msg <?=h((string)$m['role'])?>">
              <div><b><?=h((string)$m['role'])?>:</b> <?=h((string)$m['text'])?></div>
              <div class="msgmeta mono"><?=h((string)$m['created_at'])?></div>
            </div>
          <?php endforeach; ?>
        <?php elseif ($selectedChat): ?>
          <div class="hint">Нет сообщений по этому chat_id.</div>
        <?php else: ?>
          <div class="hint">Выберите chat_id слева.</div>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="hint">
      MySQL не подключен — включите в <a href="/avito/admin.php">админке</a>, чтобы видеть диалоги. Сейчас можно смотреть логи ниже.
    </div>
  <?php endif; ?>
</div>

<div class="card" id="send">
  <h2>Ручная отправка</h2>
  <div class="hint">
    Telegram: отправит через Bot API. Avito: пока через <code class="mono">avito_send_url</code> (если задан).
  </div>

  <div class="split">
    <div class="card" style="margin:0">
      <h3>В Telegram</h3>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="send_tg_manual">

        <label>Кому (chat_id)</label>
        <input name="tg_to" value="<?=h((string)($cfg['tg_chat_id'] ?? ''))?>" placeholder="-100... или 123456">

        <label>Текст</label>
        <textarea name="tg_text" placeholder="сообщение"></textarea>

        <button type="submit">Отправить в Telegram</button>
      </form>
    </div>

    <div class="card" style="margin:0">
      <h3>В Avito (через send URL)</h3>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="send_avito_manual">

        <label>Avito chat_id</label>
        <input name="avito_chat_id" value="<?=h($selectedChat)?>" placeholder="chat_id">

        <label>Текст</label>
        <textarea name="avito_text" placeholder="сообщение клиенту в Avito"></textarea>

        <button type="submit">Отправить в Avito</button>
        <div class="hint">Текущий send URL: <code class="mono"><?=h((string)($settings['avito_send_url'] ?? ''))?></code></div>
      </form>
    </div>
  </div>
</div>

<div class="card" id="logs">
  <h2>Логи</h2>

  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <div>
      <label>Файл</label>
      <select name="log">
        <?php foreach ($knownLogs as $k => $_): ?>
          <option value="<?=h($k)?>" <?=$k===$selectedLog?'selected':''?>><?=h($k)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($selectedChat): ?>
      <input type="hidden" name="chat" value="<?=h($selectedChat)?>">
    <?php endif; ?>
    <button type="submit" class="secondary">Показать</button>
    <a class="hint" href="#logs" style="text-decoration:none">↻ обновить</a>
  </form>

  <pre class="mono" style="white-space:pre-wrap;margin:0"><?=h($logText)?></pre>
</div>

</body>
</html>
