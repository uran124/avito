<?php
// /avito/config.php
declare(strict_types=1);

const AVITO_PRIVATE_DIR  = __DIR__ . '/_private';
const AVITO_CONFIG_FILE  = AVITO_PRIVATE_DIR . '/config.json';
const AVITO_SESSIONS_DIR = AVITO_PRIVATE_DIR . '/sessions';
const AVITO_LOG_DIR      = AVITO_PRIVATE_DIR . '/logs';

function avito_bootstrap_dirs(): void {
  foreach ([AVITO_PRIVATE_DIR, AVITO_SESSIONS_DIR, AVITO_LOG_DIR] as $dir) {
    if (!is_dir($dir)) {
      @mkdir($dir, 0750, true);
    }
  }
}

function avito_default_config(): array {
  return [
    // безопасность вебхука
    'webhook_secret' => '',          // если задан — проверяем заголовок X-Webhook-Secret
    'allow_ips' => [],               // если не пусто — пускать только эти IP

    // админка
    'admin_password_hash' => '',

    // Yandex AI Studio
    'yandex_api_key' => '',
    'yandex_folder_id' => '',
    'yandex_model' => 'yandexgpt/latest',
    'yandex_max_tokens' => 260,
    'yandex_temperature' => 0.2,

    // LLM provider
    'llm_provider' => 'yandex', // yandex

    // Avito API
    'avito_api_base' => 'https://api.avito.ru',
    'avito_client_id' => '',
    'avito_client_secret' => '',
    'avito_access_token' => '',
    'avito_refresh_token' => '',
    'avito_token_expires_at' => 0,
    'avito_user_id' => '',

    // Telegram
    'tg_bot_token' => '',
    'tg_chat_id' => '',
    'tg_thread_id' => '',
    'tg_notify_mode' => 'handoff',   // handoff | always | never

    // лидоген
    'lead_capture_mode' => 'soft',   // soft | ask_phone

    // MySQL
    'mysql_enabled' => false,
    'mysql_host' => '127.0.0.1',
    'mysql_port' => 3306,
    'mysql_db' => '',
    'mysql_user' => '',
    'mysql_pass' => '',
    'mysql_prefix' => '',            // опционально, например "bf_"
  ];
}

function avito_get_config(): array {
  avito_bootstrap_dirs();

  $cfg = avito_default_config();
  if (is_file(AVITO_CONFIG_FILE)) {
    $raw = @file_get_contents(AVITO_CONFIG_FILE);
    $json = json_decode($raw ?: '[]', true);
    if (is_array($json)) {
      $cfg = array_replace_recursive($cfg, $json);
    }
  }
  return $cfg;
}

function avito_save_config(array $cfg): bool {
  avito_bootstrap_dirs();

  // Сохраняем только ключи из default_config
  $base = avito_default_config();
  $clean = [];
  foreach ($base as $k => $v) {
    $clean[$k] = $cfg[$k] ?? $v;
  }

  $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) return false;

  return (bool)@file_put_contents(AVITO_CONFIG_FILE, $json . PHP_EOL, LOCK_EX);
}

function avito_token_is_expired(array $cfg): bool {
  $expiresAt = (int)($cfg['avito_token_expires_at'] ?? 0);
  if ($expiresAt <= 0) return false;
  return time() >= $expiresAt;
}

function avito_refresh_access_token(array &$cfg): array {
  $clientId = trim((string)($cfg['avito_client_id'] ?? ''));
  $clientSecret = trim((string)($cfg['avito_client_secret'] ?? ''));
  $refreshToken = trim((string)($cfg['avito_refresh_token'] ?? ''));
  $base = trim((string)($cfg['avito_api_base'] ?? 'https://api.avito.ru'));
  $base = rtrim($base, '/');

  if ($clientId === '' || $clientSecret === '') {
    return ['ok' => false, 'error' => 'Не заданы avito_client_id или avito_client_secret'];
  }
  if ($refreshToken === '') {
    return ['ok' => false, 'error' => 'avito_refresh_token пустой'];
  }

  $url = $base . '/token';
  $payload = [
    'grant_type' => 'refresh_token',
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'refresh_token' => $refreshToken,
  ];

  $raw = '';
  $err = '';
  $status = 0;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
      CURLOPT_POSTFIELDS => http_build_query($payload),
    ]);
    $raw = (string)curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
  } else {
    $context = stream_context_create([
      'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query($payload),
        'timeout' => 20,
      ],
    ]);
    $raw = (string)@file_get_contents($url, false, $context);
    if (isset($http_response_header) && is_array($http_response_header)) {
      foreach ($http_response_header as $line) {
        if (preg_match('/HTTP\\/[0-9.]+\\s+(\\d+)/', $line, $m)) {
          $status = (int)$m[1];
          break;
        }
      }
    }
  }

  if ($err !== '') return ['ok' => false, 'error' => $err];
  if ($status >= 400 || $raw === '') return ['ok' => false, 'error' => 'HTTP ' . $status, 'raw' => $raw];

  $json = json_decode($raw, true);
  if (!is_array($json)) return ['ok' => false, 'error' => 'Bad JSON', 'raw' => $raw];

  if (!empty($json['access_token'])) $cfg['avito_access_token'] = (string)$json['access_token'];
  if (!empty($json['refresh_token'])) $cfg['avito_refresh_token'] = (string)$json['refresh_token'];
  if (!empty($json['expires_in'])) $cfg['avito_token_expires_at'] = time() + (int)$json['expires_in'];
  avito_save_config($cfg);

  return ['ok' => true, 'data' => $json];
}

function avito_log(string $msg, string $file = 'app.log'): void {
  avito_bootstrap_dirs();
  $line = '[' . date('c') . '] ' . $msg . PHP_EOL;
  @file_put_contents(AVITO_LOG_DIR . '/' . $file, $line, FILE_APPEND);
}

function http_post_json(string $url, array $payload, array $headers = [], int $timeout = 20): array {
  $headerLines = array_merge(['Content-Type: application/json'], $headers);
  $context = stream_context_create([
    'http' => [
      'method' => 'POST',
      'header' => implode("\r\n", $headerLines),
      'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
      'timeout' => $timeout,
    ],
  ]);

  $raw = @file_get_contents($url, false, $context);
  $error = $raw === false ? 'HTTP request failed' : '';
  $status = 0;
  if (isset($http_response_header) && is_array($http_response_header)) {
    foreach ($http_response_header as $line) {
      if (preg_match('/HTTP\\/[0-9.]+\\s+(\\d+)/', $line, $m)) {
        $status = (int)$m[1];
        break;
      }
    }
  }

  return [
    'raw' => $raw === false ? '' : $raw,
    'status' => $status,
    'error' => $error,
  ];
}

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
