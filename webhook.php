<?php
// /avito/webhook.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/kb_client.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$cfg = avito_get_config();
$panelSettingsFile = AVITO_PRIVATE_DIR . '/panel_settings.json';
$panelSettings = [];
if (is_file($panelSettingsFile)) {
  $raw = @file_get_contents($panelSettingsFile);
  $json = json_decode($raw ?: '[]', true);
  if (is_array($json)) $panelSettings = $json;
}
$webhookEnabled = !array_key_exists('avito_webhook_enabled', $panelSettings)
  ? true
  : (bool)$panelSettings['avito_webhook_enabled'];
$webhookSecretHeader = trim((string)($panelSettings['avito_webhook_secret_header'] ?? ''));
if ($webhookSecretHeader === '') $webhookSecretHeader = 'X-Webhook-Secret';
$webhookSecretValue = trim((string)($panelSettings['avito_webhook_secret_value'] ?? ''));
if ($webhookSecretValue === '') $webhookSecretValue = trim((string)($cfg['webhook_secret'] ?? ''));

/** =========================
 * Helpers
 * ========================= */

function get_header(string $name): string {
  $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return (string)($_SERVER[$key] ?? '');
}

function client_ip(): string {
  return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function deny(int $code, string $msg): void {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

if (!$webhookEnabled) {
  deny(410, 'Webhook отключен');
}

function extract_text(array $payload): string {
  $candidates = [
    $payload['payload']['value']['message']['text'] ?? null,
    $payload['payload']['message']['text'] ?? null,
    $payload['message']['text'] ?? null,
    $payload['message_text'] ?? null,
    $payload['text'] ?? null,
    $payload['data']['text'] ?? null,
    $payload['body']['text'] ?? null,
    $payload['message']['content']['text'] ?? null,
  ];
  foreach ($candidates as $c) {
    if (is_string($c) && trim($c) !== '') return trim($c);
  }
  return '';
}

function extract_chat_id(array $payload): string {
  $candidates = [
    $payload['payload']['value']['conversation_id'] ?? null,
    $payload['payload']['value']['chat_id'] ?? null,
    $payload['payload']['conversation_id'] ?? null,
    $payload['chat_id'] ?? null,
    $payload['conversation_id'] ?? null,
    $payload['dialog_id'] ?? null,
    $payload['message']['chat_id'] ?? null,
    $payload['data']['chat_id'] ?? null,
  ];
  foreach ($candidates as $c) {
    if (is_string($c) && $c !== '') return $c;
    if (is_int($c)) return (string)$c;
  }
  return substr(hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE)), 0, 16);
}

function detect_phone(string $text): ?string {
  if (preg_match('/(\+?\d[\d\-\s\(\)]{8,}\d)/u', $text, $m)) {
    $p = preg_replace('/[^\d\+]/', '', $m[1]);
    return $p !== '' ? $p : null;
  }
  return null;
}

function tg_send(array $cfg, string $text): void {
  if (empty($cfg['tg_bot_token']) || empty($cfg['tg_chat_id'])) return;

  $url = 'https://api.telegram.org/bot' . $cfg['tg_bot_token'] . '/sendMessage';
  $payload = [
    'chat_id' => $cfg['tg_chat_id'],
    'text' => $text,
    'disable_web_page_preview' => true,
  ];
  $threadId = trim((string)($cfg['tg_thread_id'] ?? ''));
  if ($threadId !== '' && ctype_digit($threadId)) {
    $payload['message_thread_id'] = (int)$threadId;
  }

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  ]);
  $resp = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);

  if ($err) avito_log("TG error: $err", 'tg.log');
  else avito_log("TG ok: " . substr((string)$resp, 0, 200), 'tg.log');
}

function yandex_completion_create(array $cfg, string $instructions, string $input): array {
  if (empty($cfg['yandex_api_key'])) {
    return ['_error' => 'Yandex API key is empty'];
  }
  if (empty($cfg['yandex_folder_id'])) {
    return ['_error' => 'Yandex folder ID is empty'];
  }

  $url = 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion';
  $messages = [];
  if (trim($instructions) !== '') {
    $messages[] = ['role' => 'system', 'text' => $instructions];
  }
  $messages[] = ['role' => 'user', 'text' => $input];

  $model = trim((string)($cfg['yandex_model'] ?? 'yandexgpt/latest'));
  $modelUri = 'gpt://' . $cfg['yandex_folder_id'] . '/' . $model;

  $body = [
    'modelUri' => $modelUri,
    'completionOptions' => [
      'stream' => false,
      'temperature' => (float)($cfg['yandex_temperature'] ?? 0.2),
      'maxTokens' => (int)($cfg['yandex_max_tokens'] ?? 260),
    ],
    'messages' => $messages,
  ];

  $raw = '';
  $err = '';
  $status = 0;
  $headers = [
    'Authorization: Api-Key ' . $cfg['yandex_api_key'],
  ];

  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
      CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);

    $raw = (string)curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
  }

  if ($raw === '' || $err !== '') {
    $fallback = http_post_json($url, $body, $headers, 20);
    if ($fallback['error'] !== '') {
      $err = $err !== '' ? $err : $fallback['error'];
    }
    if ($raw === '') $raw = (string)$fallback['raw'];
    if ($status === 0) $status = (int)$fallback['status'];
  }

  if ($err) return ['_error' => "cURL error: $err"];
  if ($status >= 400) return ['_error' => "HTTP $status", '_raw' => $raw];

  $json = json_decode((string)$raw, true);
  if (!is_array($json)) return ['_error' => 'Bad JSON from Yandex AI Studio', '_raw' => $raw];

  return $json;
}

function extract_yandex_text(array $resp): string {
  if (isset($resp['result']['alternatives'][0]['message']['text'])) {
    $content = $resp['result']['alternatives'][0]['message']['text'];
    if (is_string($content) && trim($content) !== '') {
      return trim($content);
    }
  }
  return '';
}

/** =========================
 * File fallback (если MySQL недоступен)
 * ========================= */

function session_path(string $chatId): string {
  return AVITO_SESSIONS_DIR . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $chatId) . '.json';
}

function load_session(string $chatId): array {
  $path = session_path($chatId);
  if (!is_file($path)) {
    return ['stage' => 'start', 'collected' => [], 'history' => []];
  }
  $raw = @file_get_contents($path);
  $json = json_decode($raw ?: '[]', true);
  if (!is_array($json)) return ['stage' => 'start', 'collected' => [], 'history' => []];

  $json['history'] = is_array($json['history'] ?? null) ? $json['history'] : [];
  $json['collected'] = is_array($json['collected'] ?? null) ? $json['collected'] : [];
  $json['stage'] = (string)($json['stage'] ?? 'start');
  return $json;
}

function save_session(string $chatId, array $sess): void {
  avito_bootstrap_dirs();
  $path = session_path($chatId);

  // ограничим историю
  $hist = $sess['history'] ?? [];
  if (is_array($hist) && count($hist) > 12) {
    $sess['history'] = array_slice($hist, -12);
  }

  @file_put_contents($path, json_encode($sess, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

/** =========================
 * Security checks
 * ========================= */

if (!empty($cfg['allow_ips']) && is_array($cfg['allow_ips'])) {
  if (!empty($cfg['allow_ips']) && !in_array(client_ip(), $cfg['allow_ips'], true)) {
    deny(403, 'IP not allowed');
  }
}

if ($webhookSecretValue !== '') {
  $secret = get_header($webhookSecretHeader);
  if ($secret !== $webhookSecretValue) {
    deny(401, 'Bad webhook secret');
  }
}

/** =========================
 * Read request
 * ========================= */

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = $_POST ?: [];

$chatId = extract_chat_id($payload);
$text = extract_text($payload);

if ($text === '') {
  $rawPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
  if (!is_string($rawPayload)) $rawPayload = '';
  avito_log('IN empty payload=' . substr($rawPayload, 0, 2000), 'in.log');
  echo json_encode(['ok' => true, 'reply_text' => ''], JSON_UNESCAPED_UNICODE);
  exit;
}

avito_log("IN chat={$chatId} ip=" . client_ip() . " text=" . $text, 'in.log');

/** =========================
 * Storage: MySQL or file fallback
 * ========================= */

$pdo = avito_db();
$collected = [];
$historyTxt = "";
$convId = null;
$sess = null;

// Запишем входящее + получим историю
if ($pdo) {
  try {
    $conv = db_get_or_create_conversation($pdo, $chatId);
    $convId = (int)$conv['id'];

    $collected = db_read_collected($conv);

    db_append_message($pdo, $convId, 'user', $text);

    $phone = detect_phone($text);
    if ($phone) {
      $collected['phone'] = $phone;
      db_update_collected($pdo, $convId, $collected);
    }

    $rows = db_get_last_messages($pdo, $convId, 12);
    foreach ($rows as $r) {
      $role = strtoupper((string)$r['role']);
      $t = (string)$r['text'];
      $historyTxt .= $role . ": " . $t . "\n";
    }
  } catch (Throwable $e) {
    avito_log("DB runtime error (fallback to files): " . $e->getMessage(), 'db.log');
    $pdo = null;
  }
}

if (!$pdo) {
  $sess = load_session($chatId);
  $sess['history'][] = ['role' => 'user', 'text' => $text, 'ts' => time()];

  $phone = detect_phone($text);
  if ($phone) $sess['collected']['phone'] = $phone;

  $collected = $sess['collected'] ?? [];

  foreach (($sess['history'] ?? []) as $h) {
    $r = strtoupper((string)($h['role'] ?? 'user'));
    $t = (string)($h['text'] ?? '');
    if ($t !== '') $historyTxt .= $r . ": " . $t . "\n";
  }
}

/** =========================
 * Build instructions
 * ========================= */

$kb = bunch_kb_client_text();
$leadMode = (string)($cfg['lead_capture_mode'] ?? 'soft');

$handoffHint = ($leadMode === 'ask_phone')
  ? "Если человек готов оформить: попроси имя и номер телефона в одном сообщении. Но не упоминай сторонние мессенджеры и не давай ссылки."
  : "Если человек готов оформить: попроси имя и удобное время, чтобы менеджер подтвердил здесь в чате. Телефон не проси.";

$instructions =
  $kb . "\n\n" .
  "Контекст диалога (последние сообщения):\n" . $historyTxt . "\n" .
  $handoffHint . "\n" .
  "Пиши 1–3 коротких предложения. В конце — один вопрос, чтобы продвинуть к заказу.\n";

/** =========================
 * Call LLM provider
 * ========================= */

$resp = [];
$reply = '';
$fallbackReply = "Подскажите, пожалуйста: сколько роз нужно и на какую дату/время?";

$resp = yandex_completion_create($cfg, $instructions, $text);
if (isset($resp['_error'])) {
  avito_log("Yandex AI Studio error: " . $resp['_error'] . " raw=" . ($resp['_raw'] ?? ''), 'yandex.log');
  $reply = $fallbackReply;
} else {
  $reply = extract_yandex_text($resp);
}

if ($reply === '') {
  $reply = $fallbackReply;
}

// Guard: на всякий — если вдруг модель попыталась назвать страну/сорт и т.п.
// Здесь лишь примерные стоп-слова (можно расширять по вашим случаям).
$forbidden = [
  'эквадор', 'ecuador',
  'кения', 'kenya',
  'колумб', 'colomb',
  'сорт', 'variety', 'cultivar'
];

$low = mb_strtolower($reply);
foreach ($forbidden as $bad) {
  if (mb_strpos($low, $bad) !== false) {
    $reply = "Роза импортная, премиум-качества (не Россия и не Китай). Подскажите: сколько роз нужно и на какую дату/время?";
    break;
  }
}

avito_log("OUT chat={$chatId} reply=" . $reply, 'out.log');

/** =========================
 * Save assistant message
 * ========================= */

if ($pdo && $convId) {
  try {
    db_append_message($pdo, $convId, 'assistant', $reply);
  } catch (Throwable $e) {
    avito_log("DB save assistant error: " . $e->getMessage(), 'db.log');
  }
} else {
  $sess['history'][] = ['role' => 'assistant', 'text' => $reply, 'ts' => time()];
  save_session($chatId, $sess);
}

/** =========================
 * Telegram notify + lead insert
 * ========================= */

$notifyMode = (string)($cfg['tg_notify_mode'] ?? 'handoff');
$shouldNotify = false;

if ($notifyMode === 'always') $shouldNotify = true;
if ($notifyMode === 'never') $shouldNotify = false;

if ($notifyMode === 'handoff') {
  $r = mb_strtolower($reply);
  if (mb_strpos($r, 'имя') !== false || mb_strpos($r, 'удобн') !== false || mb_strpos($r, 'телефон') !== false) {
    $shouldNotify = true;
  }
}

if ($shouldNotify) {
  $leadLine = '';
  if (!empty($collected['phone'])) $leadLine = "\nТелефон: " . (string)$collected['phone'];

  tg_send($cfg, "🟣 Avito лид\nChat: {$chatId}\nВход: {$text}\nОтвет: {$reply}{$leadLine}");

  if ($pdo && $convId) {
    try {
      db_insert_lead($pdo, $convId, $chatId, $collected['phone'] ?? null, [
        'in' => $text,
        'out' => $reply,
        'collected' => $collected,
        'raw_payload' => $payload,
      ], 'handoff');
    } catch (Throwable $e) {
      avito_log("DB insert lead error: " . $e->getMessage(), 'db.log');
    }
  }
}

/** =========================
 * Response for integrator
 * ========================= */

echo json_encode([
  'ok' => true,
  'reply_text' => $reply,
  'lead' => [
    'chat_id' => $chatId,
    'phone' => $collected['phone'] ?? null,
  ],
], JSON_UNESCAPED_UNICODE);
