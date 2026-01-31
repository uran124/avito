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

    // OpenAI
    'openai_api_key' => '',
    'openai_model' => 'gpt-4.1-mini',
    'openai_max_output_tokens' => 260,

    // DeepSeek
    'deepseek_api_key' => '',
    'deepseek_model' => 'deepseek-chat',
    'deepseek_max_output_tokens' => 260,

    // LLM provider
    'llm_provider' => 'openai', // openai | deepseek

    // Avito API
    'avito_api_base' => 'https://api.avito.ru',
    'avito_client_id' => '',
    'avito_client_secret' => '',
    'avito_access_token' => '',
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
