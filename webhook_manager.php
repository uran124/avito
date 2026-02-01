<?php
// /avito/webhook_manager.php
declare(strict_types=1);

require_once __DIR__ . '/panel_lib.php';

require_admin();

$cfg = avito_get_config();
$settings = panel_load_settings();

$flash = '';
$flashType = 'ok';
$testResult = null;

$baseUrl = current_base_url();
$autoWebhookUrl = $baseUrl . '/avito/webhook.php';
$webhookReceiverUrlRaw = trim((string)($settings['avito_webhook_receiver_url'] ?? ''));
$webhookUrl = $webhookReceiverUrlRaw === '' ? $autoWebhookUrl : $webhookReceiverUrlRaw;
$webhookSecretHeader = trim((string)($settings['avito_webhook_secret_header'] ?? ''));
if ($webhookSecretHeader === '') $webhookSecretHeader = 'X-Webhook-Secret';
$webhookSecretValue = trim((string)($settings['avito_webhook_secret_value'] ?? ''));
if ($webhookSecretValue === '') $webhookSecretValue = trim((string)($cfg['webhook_secret'] ?? ''));

function avito_auth_header(string $token): string {
  $token = trim($token);
  if ($token === '') return '';
  if (stripos($token, 'bearer ') === 0) return 'Authorization: ' . $token;
  return 'Authorization: Bearer ' . $token;
}

function avito_api_base(array $cfg): string {
  $base = trim((string)($cfg['avito_api_base'] ?? 'https://api.avito.ru'));
  if ($base === '') $base = 'https://api.avito.ru';
  return rtrim($base, '/');
}

function avito_is_route_not_found(array $res): bool {
  if (($res['status'] ?? 0) === 404) return true;
  $raw = strtolower((string)($res['raw'] ?? ''));
  if ($raw === '') return false;
  return str_contains($raw, 'route') && str_contains($raw, 'not found');
}

function avito_webhook_endpoints(array $cfg): array {
  $base = avito_api_base($cfg);
  return [
    $base . '/messenger/v3/webhook',
    $base . '/messenger/v1/webhook',
    $base . '/messenger/v1/subscriptions',
  ];
}

function avito_request_webhook(array $cfg, string $method, array $payload = []): array {
  $token = trim((string)($cfg['avito_access_token'] ?? ''));
  if ($token === '') {
    return ['ok' => false, 'error' => 'Access token пустой', 'status' => 0, 'raw' => ''];
  }

  $headers = [avito_auth_header($token)];
  $last = ['ok' => false, 'status' => 0, 'error' => 'Unknown error', 'raw' => '', 'json' => null];

  foreach (avito_webhook_endpoints($cfg) as $endpoint) {
    $res = http_request_json($method, $endpoint, $payload, $headers, 20);
    $last = $res;
    if ($res['ok']) {
      $res['endpoint'] = $endpoint;
      return $res;
    }
    if (!avito_is_route_not_found($res)) {
      $res['endpoint'] = $endpoint;
      return $res;
    }
  }

  $last['endpoint'] = avito_webhook_endpoints($cfg)[0] ?? '';
  return $last;
}

function avito_webhook_headers(string $headerName, string $headerValue): array {
  if ($headerValue === '') return [];
  return [$headerName . ': ' . $headerValue];
}

function avito_normalize_url(string $url): string {
  return rtrim(trim($url), '/');
}

function avito_test_payload(array $cfg): array {
  $userId = trim((string)($cfg['avito_user_id'] ?? ''));
  return [
    'payload' => [
      'value' => [
        'author_id' => $userId !== '' ? (int)$userId : 12345678,
        'chat_id' => 'test_chat_' . time(),
        'content' => [
          'text' => 'Тестовое сообщение для проверки webhook (' . date('Y-m-d H:i:s') . ')',
        ],
        'created' => time(),
        'direction' => 'in',
        'id' => 'test_msg_' . uniqid('', true),
        'type' => 'text',
      ],
    ],
  ];
}

function avito_log_dir_status(): array {
  $dir = AVITO_LOG_DIR;
  return [
    'dir' => $dir,
    'exists' => is_dir($dir),
    'readable' => is_readable($dir),
    'writable' => is_writable($dir),
    'permissions' => is_dir($dir) ? substr(sprintf('%o', fileperms($dir)), -4) : 'N/A',
  ];
}

// Получение текущего статуса webhook
function avito_get_webhook_status(array $cfg): array {
  $res = avito_request_webhook($cfg, 'GET');
  if (!$res['ok']) {
    return ['ok' => false, 'error' => $res['error'] !== '' ? $res['error'] : ("HTTP " . $res['status']), 'response' => $res['raw']];
  }

  $data = $res['json'] ?? [];
  $subscriptions = [];

  if (isset($data['subscriptions']) && is_array($data['subscriptions'])) {
    $subscriptions = $data['subscriptions'];
  } elseif (isset($data['url']) && is_string($data['url']) && $data['url'] !== '') {
    $subscriptions = [['url' => $data['url']]];
  } elseif (isset($data['webhook']) && is_array($data['webhook']) && isset($data['webhook']['url'])) {
    $subscriptions = [['url' => (string)$data['webhook']['url']]];
  }

  return ['ok' => true, 'data' => ['subscriptions' => $subscriptions]];
}

// Регистрация webhook в Avito
function avito_register_webhook(array $cfg, string $url): array {
  $payload = ['url' => $url];

  $res = avito_request_webhook($cfg, 'POST', $payload);

  avito_log("Register webhook: url={$url}, endpoint=" . ($res['endpoint'] ?? 'n/a') . ", code={$res['status']}, response={$res['raw']}", 'webhook_register.log');

  if (!$res['ok']) {
    return ['ok' => false, 'error' => $res['error'] !== '' ? $res['error'] : ("HTTP " . $res['status']), 'response' => $res['raw']];
  }

  return ['ok' => true, 'data' => $res['json'] ?? []];
}

// Удаление webhook из Avito
function avito_unregister_webhook(array $cfg, string $url): array {
  $payload = ['url' => $url];

  $res = avito_request_webhook($cfg, 'DELETE', $payload);

  avito_log("Unregister webhook: url={$url}, endpoint=" . ($res['endpoint'] ?? 'n/a') . ", code={$res['status']}, response={$res['raw']}", 'webhook_register.log');

  if (!$res['ok']) {
    return ['ok' => false, 'error' => $res['error'] !== '' ? $res['error'] : ("HTTP " . $res['status']), 'response' => $res['raw']];
  }

  return ['ok' => true, 'data' => $res['json'] ?? []];
}

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'check_status') {
    $result = avito_get_webhook_status($cfg);
    if ($result['ok']) {
      $subscriptions = $result['data']['subscriptions'] ?? [];
      if (empty($subscriptions)) {
        $flash = 'Webhook НЕ зарегистрирован в Avito ⚠️';
        $flashType = 'bad';
      } else {
        $urls = array_column($subscriptions, 'url');
        $flash = 'Зарегистрированные webhook: ' . implode(', ', $urls) . ' ✅';
        $flashType = 'ok';
      }
    } else {
      $flash = 'Ошибка проверки webhook: ' . ($result['error'] ?? 'unknown') . ' ❌';
      $flashType = 'bad';
    }
  }

  if ($action === 'register') {
    $result = avito_register_webhook($cfg, $webhookUrl);
    if ($result['ok']) {
      $flash = 'Webhook успешно зарегистрирован в Avito ✅';
      $flashType = 'ok';
      
      // Обновляем локальный статус
      $newSettings = $settings;
      $newSettings['avito_webhook_enabled'] = true;
      panel_save_settings($newSettings);
    } else {
      $flash = 'Ошибка регистрации webhook: ' . ($result['error'] ?? 'unknown') . ' ❌';
      if (!empty($result['response'])) {
        $flash .= ' | Response: ' . $result['response'];
      }
      $flashType = 'bad';
    }
  }

  if ($action === 'unregister') {
    $result = avito_unregister_webhook($cfg, $webhookUrl);
    if ($result['ok']) {
      $flash = 'Webhook успешно удалён из Avito ✅';
      $flashType = 'ok';
      
      // Обновляем локальный статус
      $newSettings = $settings;
      $newSettings['avito_webhook_enabled'] = false;
      panel_save_settings($newSettings);
    } else {
      $flash = 'Ошибка удаления webhook: ' . ($result['error'] ?? 'unknown') . ' ❌';
      if (!empty($result['response'])) {
        $flash .= ' | Response: ' . $result['response'];
      }
      $flashType = 'bad';
    }
  }

  if ($action === 'test_access') {
    $headers = avito_webhook_headers($webhookSecretHeader, $webhookSecretValue);
    $res = http_request_json('GET', $webhookUrl, [], $headers, 15);
    $ok = $res['ok'] || $res['status'] === 400;
    $testResult = [
      'title' => 'Проверка доступности webhook',
      'ok' => $ok,
      'status' => $res['status'],
      'error' => $res['error'] !== '' ? $res['error'] : '',
      'response' => is_string($res['raw']) ? $res['raw'] : '',
    ];
  }

  if ($action === 'test_webhook') {
    $headers = avito_webhook_headers($webhookSecretHeader, $webhookSecretValue);
    $payload = avito_test_payload($cfg);
    $res = http_request_json('POST', $webhookUrl, $payload, $headers, 20);
    $testResult = [
      'title' => 'Отправка тестового webhook',
      'ok' => $res['ok'],
      'status' => $res['status'],
      'error' => $res['error'] !== '' ? $res['error'] : '',
      'response' => is_string($res['raw']) ? $res['raw'] : '',
      'payload' => $payload,
    ];
  }

  if ($action === 'check_logs') {
    $logStatus = avito_log_dir_status();
    $testResult = [
      'title' => 'Проверка прав на логи',
      'ok' => $logStatus['exists'] && $logStatus['writable'],
      'status' => 0,
      'error' => '',
      'response' => json_encode($logStatus, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    ];
  }
}

// Получаем текущий статус
$currentStatus = avito_get_webhook_status($cfg);
$isRegistered = false;
$registeredUrls = [];
$normalizedWebhookUrl = avito_normalize_url($webhookUrl);

if ($currentStatus['ok']) {
  $subscriptions = $currentStatus['data']['subscriptions'] ?? [];
  $registeredUrls = array_column($subscriptions, 'url');
  $normalizedRegisteredUrls = array_map('avito_normalize_url', $registeredUrls);
  $isRegistered = in_array($normalizedWebhookUrl, $normalizedRegisteredUrls, true);
}

render_panel_header('Управление Webhook', 'avito');

if ($flash !== '') {
  echo '<div class="flash ' . h($flashType) . '">' . h($flash) . '</div>';
}
?>

<div class="card">
  <h2>Статус Webhook</h2>
  
  <div style="margin:12px 0">
    <div class="pill <?= $isRegistered ? 'ok' : 'bad' ?>">
      Статус в Avito: <?= $isRegistered ? 'ЗАРЕГИСТРИРОВАН ✅' : 'НЕ ЗАРЕГИСТРИРОВАН ❌' ?>
    </div>
  </div>

  <div class="hint">
    <strong>Ваш webhook URL:</strong><br>
    <code class="mono"><?=h($webhookUrl)?></code>
  </div>

  <?php if (!empty($registeredUrls)): ?>
    <div class="hint" style="margin-top:12px">
      <strong>Зарегистрированные webhook в Avito:</strong>
      <?php foreach ($registeredUrls as $url): ?>
        <div style="margin:4px 0">
          <code class="mono"><?=h($url)?></code>
          <?php if (avito_normalize_url($url) === $normalizedWebhookUrl): ?>
            <span style="color:#0a7a2a">✓ Совпадает</span>
          <?php else: ?>
            <span style="color:#b00020">⚠️ Не совпадает с текущим</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    
    <button type="submit" name="action" value="check_status" class="secondary">
      Проверить статус
    </button>

    <?php if (!$isRegistered): ?>
      <button type="submit" name="action" value="register">
        ✅ Зарегистрировать webhook
      </button>
    <?php else: ?>
      <button type="submit" name="action" value="unregister" class="danger">
        ❌ Удалить webhook
      </button>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <h2>Диагностика</h2>
  
  <div class="hint">
    <strong>Проверка требований:</strong>
  </div>

  <?php
    $userId = trim((string)($cfg['avito_user_id'] ?? ''));
    $token = trim((string)($cfg['avito_access_token'] ?? ''));
    $clientId = trim((string)($cfg['avito_client_id'] ?? ''));
  ?>

  <div style="margin-top:12px">
    <div class="pill <?= $userId !== '' ? 'ok' : 'bad' ?>">
      User ID: <?= $userId !== '' ? h($userId) : 'НЕ ЗАДАН ❌' ?>
    </div>
  </div>

  <div style="margin-top:8px">
    <div class="pill <?= $token !== '' ? 'ok' : 'bad' ?>">
      Access Token: <?= $token !== '' ? 'ЗАДАН ✅' : 'НЕ ЗАДАН ❌' ?>
    </div>
  </div>

  <div style="margin-top:8px">
    <div class="pill <?= $clientId !== '' ? 'ok' : 'bad' ?>">
      Client ID: <?= $clientId !== '' ? 'ЗАДАН ✅' : 'НЕ ЗАДАН ❌' ?>
    </div>
  </div>

  <?php
    $allowIps = (array)($cfg['allow_ips'] ?? []);
    $tokenExpired = avito_token_is_expired($cfg);
  ?>

  <div style="margin-top:8px">
    <div class="pill <?= !$tokenExpired ? 'ok' : 'bad' ?>">
      Token expiration: <?= !$tokenExpired ? 'Не истёк ✅' : 'Истёк ❌' ?>
    </div>
  </div>

  <div style="margin-top:8px">
    <div class="pill <?= empty($allowIps) ? 'ok' : 'bad' ?>">
      Allow IPs: <?= empty($allowIps) ? 'НЕ ОГРАНИЧЕНО ✅' : 'НАСТРОЕНО ⚠️' ?>
    </div>
    <?php if (!empty($allowIps)): ?>
      <div style="margin-top:6px;font-size:13px;color:#555">
        Разрешённые IP: <?= h(implode(', ', $allowIps)) ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($userId === ''): ?>
    <div style="margin-top:12px;padding:12px;background:#fdecee;border:1px solid #f3b5bd;border-radius:8px">
      <strong style="color:#b00020">⚠️ User ID не задан!</strong><br>
      <div style="font-size:13px;margin-top:6px">
        Откройте <a href="/avito/admin.php">админку</a> и получите токен через:<br>
        • "Получить токен (client_credentials)" или<br>
        • "Авторизоваться в Avito (OAuth)"
      </div>
    </div>
  <?php endif; ?>

  <?php if ($token === ''): ?>
    <div style="margin-top:12px;padding:12px;background:#fdecee;border:1px solid #f3b5bd;border-radius:8px">
      <strong style="color:#b00020">⚠️ Access Token не задан!</strong><br>
      <div style="font-size:13px;margin-top:6px">
        Откройте <a href="/avito/admin.php">админку</a> и получите токен.
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Тестирование webhook</h2>
  
  <div class="hint">
    После регистрации webhook напишите тестовое сообщение себе в Avito с другого аккаунта.
  </div>

  <div class="hint" style="margin-top:12px">
    <strong>Проверьте логи:</strong>
  </div>

  <div style="margin-top:8px;font-family:monospace;font-size:13px">
    <div>📝 Сырые webhook: <code>/avito/_private/logs/webhook_raw.log</code></div>
    <div>📥 Входящие: <code>/avito/_private/logs/in.log</code></div>
    <div>📤 Исходящие: <code>/avito/_private/logs/out.log</code></div>
    <div>❌ Ошибки: <code>/avito/_private/logs/webhook_errors.log</code></div>
  </div>

  <div style="margin-top:12px">
    <a href="/avito/avito.php" class="pill">Перейти к логам →</a>
    <a href="/avito/test_webhook.php" class="pill">Открыть тестовую страницу →</a>
  </div>

  <form method="post" style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <button type="submit" name="action" value="test_access" class="secondary">
      Проверить доступность webhook
    </button>
    <button type="submit" name="action" value="test_webhook">
      Отправить тестовый webhook
    </button>
    <button type="submit" name="action" value="check_logs" class="secondary">
      Проверить права на логи
    </button>
  </form>

  <?php if (is_array($testResult)): ?>
    <div style="margin-top:12px;padding:12px;background:#f7f7f7;border:1px solid #eee;border-radius:8px">
      <strong><?= h((string)($testResult['title'] ?? 'Тест')) ?></strong><br>
      <div style="margin-top:6px">
        Статус: <?= !empty($testResult['ok']) ? '<span style="color:#0a7a2a">OK ✅</span>' : '<span style="color:#b00020">Ошибка ❌</span>' ?>
        <?php if (!empty($testResult['status'])): ?>
          <span style="margin-left:8px">HTTP <?= h((string)$testResult['status']) ?></span>
        <?php endif; ?>
      </div>
      <?php if (!empty($testResult['error'])): ?>
        <div style="margin-top:6px;color:#b00020;font-size:13px">
          Ошибка: <?= h((string)$testResult['error']) ?>
        </div>
      <?php endif; ?>
      <?php if (!empty($testResult['response'])): ?>
        <div style="margin-top:8px;font-family:monospace;font-size:12px;white-space:pre-wrap">
          <?= h((string)$testResult['response']) ?>
        </div>
      <?php endif; ?>
      <?php if (!empty($testResult['payload'])): ?>
        <div style="margin-top:8px;font-family:monospace;font-size:12px;white-space:pre-wrap">
          <?= h(json_encode($testResult['payload'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Инструкция</h2>
  
  <div style="font-size:14px;line-height:1.6">
    <strong>Шаг 1:</strong> Убедитесь что User ID и Access Token заполнены<br>
    <strong>Шаг 2:</strong> Нажмите "Зарегистрировать webhook"<br>
    <strong>Шаг 3:</strong> Отправьте тестовое сообщение в Avito<br>
    <strong>Шаг 4:</strong> Проверьте логи на странице <a href="/avito/avito.php">Avito</a>
  </div>

  <?php if (!$isRegistered): ?>
    <div style="margin-top:12px;padding:12px;background:#fff6df;border:1px solid #ffe3a5;border-radius:8px">
      <strong>💡 Webhook не зарегистрирован</strong><br>
      <div style="font-size:13px;margin-top:6px">
        Это означает, что Avito не будет отправлять уведомления о новых сообщениях на ваш сервер.
        Бот не будет работать автоматически.
      </div>
    </div>
  <?php endif; ?>
</div>

<div style="margin:20px 0;text-align:center">
  <a href="/avito/admin.php" style="padding:10px 20px;background:#fff;border:1px solid #eee;border-radius:10px;text-decoration:none;color:#111">
    ← Вернуться в админку
  </a>
  <a href="/avito/avito.php" style="padding:10px 20px;background:#fff;border:1px solid #eee;border-radius:10px;text-decoration:none;color:#111">
    Перейти к Avito →
  </a>
</div>

<?php render_panel_footer(); ?>
