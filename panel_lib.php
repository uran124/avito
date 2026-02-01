<?php
// /avito/panel_lib.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

session_start();
avito_bootstrap_dirs();

const PANEL_SETTINGS_FILE = AVITO_PRIVATE_DIR . '/panel_settings.json';

function require_admin(): void {
  if (empty($_SESSION['admin_ok'])) {
    header('Location: /avito/admin.php');
    exit;
  }
}

function panel_default_settings(): array {
  return [
    // Общие
    'messages_limit' => 60,
    'log_tail_lines' => 200,

    // Avito
    'avito_webhook_receiver_url' => '',
    'avito_webhook_secret_header' => 'X-Webhook-Secret',
    'avito_webhook_secret_value' => '',

    // Telegram
    'tg_webhook_url' => '',
    'tg_secret_token' => '',
    'tg_drop_pending_updates' => true,
    'tg_allowed_updates' => 'message',
    'tg_last_checked_at' => null,
    'tg_last_error' => '',
    'tg_last_info_json' => null,
  ];
}

function panel_load_settings(): array {
  $base = panel_default_settings();
  if (is_file(PANEL_SETTINGS_FILE)) {
    $raw = @file_get_contents(PANEL_SETTINGS_FILE);
    $json = json_decode($raw ?: '[]', true);
    if (is_array($json)) {
      foreach ($base as $k => $v) {
        if (array_key_exists($k, $json)) {
          $base[$k] = $json[$k];
        }
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
  return avito_current_base_url();
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

  if (!$out['ok'] && $out['error'] === '') {
    if (is_array($out['json'])) {
      if (!empty($out['json']['error_description'])) {
        $out['error'] = (string)$out['json']['error_description'];
      } elseif (!empty($out['json']['message'])) {
        $out['error'] = (string)$out['json']['message'];
      } elseif (!empty($out['json']['error'])) {
        $out['error'] = is_string($out['json']['error']) ? $out['json']['error'] : json_encode($out['json']['error'], JSON_UNESCAPED_UNICODE);
      }
    }
  }

  if (!$out['ok'] && $out['error'] === '' && is_string($raw) && trim($raw) !== '') {
    $out['error'] = trim($raw);
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

function render_panel_header(string $title, string $active): void {
  $nav = [
    'admin' => ['/avito/admin.php', 'Админка'],
    'telegram' => ['/avito/telegram.php', 'Telegram'],
    'avito' => ['/avito/avito.php', 'Avito'],
    'yandex' => ['/avito/yandex.php', 'Yandex AI Studio'],
  ];

  echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<title>' . h($title) . '</title>';
  echo '<style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;max-width:1180px;margin:24px auto;padding:0 12px;background:#fafafa;}
    a{color:#111}
    .top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
    .nav{display:flex;gap:10px;flex-wrap:wrap}
    .nav a{padding:8px 10px;border-radius:10px;background:#fff;border:1px solid #eee;text-decoration:none}
    .nav a.active{border-color:#111;background:#111;color:#fff}
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
  </style></head><body>';

  echo '<div class="top">';
  echo '<div><h1 style="margin:0">' . h($title) . '</h1>';
  echo '<div class="hint">Панель управления интеграциями.</div></div>';
  echo '<div class="nav">';
  foreach ($nav as $key => $item) {
    [$href, $label] = $item;
    $class = $key === $active ? 'active' : '';
    echo '<a class="' . $class . '" href="' . h($href) . '">' . h($label) . '</a>';
  }
  echo '</div></div>';
}

function render_panel_footer(): void {
  echo '</body></html>';
}
