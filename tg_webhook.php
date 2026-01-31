<?php
// /avito/tg_webhook.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

avito_bootstrap_dirs();

const PANEL_SETTINGS_FILE = AVITO_PRIVATE_DIR . '/panel_settings.json';

function tg_load_panel_settings(): array {
  if (!is_file(PANEL_SETTINGS_FILE)) return [];
  $raw = @file_get_contents(PANEL_SETTINGS_FILE);
  $json = json_decode($raw ?: '[]', true);
  return is_array($json) ? $json : [];
}

function get_header(string $name): string {
  $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return (string)($_SERVER[$key] ?? '');
}

function tg_api_request(string $botToken, string $method, array $payload = []): array {
  $url = 'https://api.telegram.org/bot' . $botToken . '/' . $method;
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  ]);
  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  return [
    'ok' => ($err === '' && $status >= 200 && $status < 300),
    'status' => $status,
    'error' => $err,
    'raw' => $raw,
  ];
}

function tg_reply(string $botToken, string $chatId, string $text): void {
  $res = tg_api_request($botToken, 'sendMessage', [
    'chat_id' => $chatId,
    'text' => $text,
    'disable_web_page_preview' => true,
  ]);
  if (!$res['ok']) {
    avito_log("TG reply error: " . ($res['error'] ?: ('HTTP ' . $res['status'])), 'tg_webhook.log');
  }
}

function avito_send_message(string $url, string $authHeader, string $chatId, string $text): array {
  $headers = ['Content-Type: application/json'];
  if ($authHeader !== '') $headers[] = $authHeader;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => json_encode(['chat_id' => $chatId, 'text' => $text], JSON_UNESCAPED_UNICODE),
  ]);
  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  return [
    'ok' => ($err === '' && $status >= 200 && $status < 300),
    'status' => $status,
    'error' => $err,
    'raw' => $raw,
  ];
}

$cfg = avito_get_config();
$settings = tg_load_panel_settings();

$secret = (string)($settings['tg_secret_token'] ?? '');
if ($secret !== '') {
  $incoming = get_header('X-Telegram-Bot-Api-Secret-Token');
  if (!hash_equals($secret, $incoming)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Bad secret token'], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

$raw = file_get_contents('php://input') ?: '';
$update = json_decode($raw, true);
if (!is_array($update)) $update = $_POST ?: [];

avito_log('TG update: ' . substr($raw, 0, 4000), 'tg_webhook.log');

$message = $update['message'] ?? null;
$text = is_array($message) ? (string)($message['text'] ?? '') : '';
$chatId = is_array($message) ? (string)($message['chat']['id'] ?? '') : '';

if ($text === '' || $chatId === '') {
  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
  exit;
}

if (empty($cfg['tg_bot_token'])) {
  avito_log('TG bot token is empty', 'tg_webhook.log');
  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
  exit;
}

$cmd = trim($text);
if (mb_strpos($cmd, '/ping') === 0) {
  tg_reply((string)$cfg['tg_bot_token'], $chatId, '✅ Pong');
  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
  exit;
}

if (mb_strpos($cmd, '/help') === 0 || mb_strpos($cmd, '/start') === 0) {
  $help = "Команды:\n"
    . "/ping — проверка бота\n"
    . "/avito <chat_id> <текст> — отправить сообщение в Avito через avito_send_url";
  tg_reply((string)$cfg['tg_bot_token'], $chatId, $help);
  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
  exit;
}

if (mb_strpos($cmd, '/avito') === 0) {
  $sendUrl = trim((string)($settings['avito_send_url'] ?? ''));
  $authHeader = trim((string)($settings['avito_send_auth_header'] ?? ''));

  if ($sendUrl === '') {
    tg_reply((string)$cfg['tg_bot_token'], $chatId, 'Не задан avito_send_url на странице Avito (avito.php)');
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if (!preg_match('/^\\/avito\\s+(\\S+)\\s+(.+)$/su', $cmd, $m)) {
    tg_reply((string)$cfg['tg_bot_token'], $chatId, 'Формат: /avito <chat_id> <текст>');
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $targetChatId = $m[1];
  $msgText = trim($m[2]);
  if ($msgText === '') {
    tg_reply((string)$cfg['tg_bot_token'], $chatId, 'Текст пустой.');
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $res = avito_send_message($sendUrl, $authHeader, $targetChatId, $msgText);
  if ($res['ok']) {
    tg_reply((string)$cfg['tg_bot_token'], $chatId, "Отправлено в Avito: {$targetChatId}");
  } else {
    $err = $res['error'] ?: ('HTTP ' . $res['status']);
    tg_reply((string)$cfg['tg_bot_token'], $chatId, "Ошибка отправки в Avito: {$err}");
  }

  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
  exit;
}

tg_reply((string)$cfg['tg_bot_token'], $chatId, "Неизвестная команда. Напишите /help");
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
