<?php
// /avito/avito_oauth_callback.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

session_start();

header('Content-Type: text/html; charset=utf-8');

function render_oauth_page(string $title, string $body): void {
  echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<title>' . h($title) . '</title>';
  echo '<style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;max-width:860px;margin:24px auto;padding:0 12px;}
    .card{border:1px solid #eee;border-radius:14px;padding:14px;margin:14px 0;background:#fff;}
    .ok{color:#0a7a2a;font-weight:600;}
    .bad{color:#b00020;font-weight:600;}
    code{background:#f6f6f6;padding:2px 6px;border-radius:8px;}
  </style></head><body>';
  echo '<div class="card">' . $body . '</div>';
  echo '</body></html>';
  exit;
}

function mask_secret(string $s, int $showLast = 4): string {
  $s = trim($s);
  if ($s === '') return '';
  $len = mb_strlen($s);
  if ($len <= $showLast) return str_repeat('•', $len);
  return str_repeat('•', $len - $showLast) . mb_substr($s, -$showLast);
}

$cfg = avito_get_config();

$state = (string)($_GET['state'] ?? '');
$code = (string)($_GET['code'] ?? '');
$expectedState = (string)($_SESSION['avito_oauth_state'] ?? '');

if ($code === '') {
  render_oauth_page('Avito OAuth', '<p class="bad">Не получен code от Avito.</p>');
}

if ($expectedState === '' || !hash_equals($expectedState, $state)) {
  render_oauth_page('Avito OAuth', '<p class="bad">Некорректный параметр state.</p>');
}

$clientId = trim((string)($cfg['avito_client_id'] ?? ''));
$clientSecret = trim((string)($cfg['avito_client_secret'] ?? ''));
$base = trim((string)($cfg['avito_api_base'] ?? 'https://api.avito.ru'));
$base = rtrim($base, '/');
$redirectUrl = avito_current_base_url() . '/avito/avito_oauth_callback.php';

if ($clientId === '' || $clientSecret === '') {
  render_oauth_page('Avito OAuth', '<p class="bad">Не заданы avito_client_id или avito_client_secret в админке.</p>');
}

$tokenUrl = $base . '/token';
$payload = [
  'grant_type' => 'authorization_code',
  'client_id' => $clientId,
  'client_secret' => $clientSecret,
  'code' => $code,
  'redirect_uri' => $redirectUrl,
];

$raw = '';
$err = '';
$status = 0;
if (function_exists('curl_init')) {
  $ch = curl_init($tokenUrl);
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
  $raw = (string)@file_get_contents($tokenUrl, false, $context);
  if (isset($http_response_header) && is_array($http_response_header)) {
    foreach ($http_response_header as $line) {
      if (preg_match('/HTTP\\/[0-9.]+\\s+(\\d+)/', $line, $m)) {
        $status = (int)$m[1];
        break;
      }
    }
  }
}

if ($err !== '') {
  render_oauth_page('Avito OAuth', '<p class="bad">Ошибка запроса токена: ' . h($err) . '</p>');
}
if ($status >= 400 || $raw === '') {
  render_oauth_page('Avito OAuth', '<p class="bad">Ошибка ответа токена: HTTP ' . h((string)$status) . '</p>');
}

$json = json_decode($raw, true);
if (!is_array($json)) {
  render_oauth_page('Avito OAuth', '<p class="bad">Некорректный JSON от Avito.</p>');
}

if (!empty($json['access_token'])) $cfg['avito_access_token'] = (string)$json['access_token'];
if (!empty($json['refresh_token'])) $cfg['avito_refresh_token'] = (string)$json['refresh_token'];
if (!empty($json['expires_in'])) $cfg['avito_token_expires_at'] = time() + (int)$json['expires_in'];
if (!empty($json['user_id'])) $cfg['avito_user_id'] = (string)$json['user_id'];
if (!empty($json['account_id'])) $cfg['avito_user_id'] = (string)$json['account_id'];

avito_save_config($cfg);

$maskedAccess = mask_secret((string)$cfg['avito_access_token']);
$maskedRefresh = mask_secret((string)$cfg['avito_refresh_token']);
$expiresAt = (string)($cfg['avito_token_expires_at'] ?? 0);
$userId = (string)($cfg['avito_user_id'] ?? '');

$body = '<p class="ok">Токен успешно сохранён.</p>'
  . '<p>Access token: <code>' . h($maskedAccess ?: '—') . '</code></p>'
  . '<p>Refresh token: <code>' . h($maskedRefresh ?: '—') . '</code></p>'
  . '<p>User ID: <code>' . h($userId ?: '—') . '</code></p>'
  . '<p>Expires at: <code>' . h($expiresAt) . '</code></p>'
  . '<p><a href="/avito/admin.php">Вернуться в админку</a></p>';

render_oauth_page('Avito OAuth', $body);
