<?php
// /avito/webhook_manager.php
declare(strict_types=1);

require_once __DIR__ . '/panel_lib.php';

require_admin();

$cfg = avito_get_config();
$settings = panel_load_settings();

$flash = '';
$flashType = 'ok';

$baseUrl = current_base_url();
$webhookUrl = $baseUrl . '/avito/webhook.php';

// Получение текущего статуса webhook
function avito_get_webhook_status(array $cfg): array {
  $token = trim((string)($cfg['avito_access_token'] ?? ''));
  if ($token === '') {
    return ['ok' => false, 'error' => 'Access token пустой'];
  }

  $url = 'https://api.avito.ru/messenger/v1/subscriptions';
  $headers = [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
  ];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => $headers,
  ]);

  $response = curl_exec($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = curl_error($ch);
  curl_close($ch);

  if ($curlError !== '') {
    return ['ok' => false, 'error' => $curlError];
  }

  if ($httpCode < 200 || $httpCode >= 300) {
    return ['ok' => false, 'error' => "HTTP {$httpCode}", 'response' => $response];
  }

  $json = json_decode((string)$response, true);
  return ['ok' => true, 'data' => $json];
}

// Регистрация webhook в Avito
function avito_register_webhook(array $cfg, string $url): array {
  $token = trim((string)($cfg['avito_access_token'] ?? ''));
  if ($token === '') {
    return ['ok' => false, 'error' => 'Access token пустой'];
  }

  $apiUrl = 'https://api.avito.ru/messenger/v3/webhook';
  $headers = [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
  ];

  $payload = ['url' => $url];

  $ch = curl_init($apiUrl);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  ]);

  $response = curl_exec($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = curl_error($ch);
  curl_close($ch);

  avito_log("Register webhook: url={$url}, code={$httpCode}, response={$response}", 'webhook_register.log');

  if ($curlError !== '') {
    return ['ok' => false, 'error' => $curlError];
  }

  if ($httpCode < 200 || $httpCode >= 300) {
    return ['ok' => false, 'error' => "HTTP {$httpCode}", 'response' => $response];
  }

  $json = json_decode((string)$response, true);
  return ['ok' => true, 'data' => $json];
}

// Удаление webhook из Avito
function avito_unregister_webhook(array $cfg, string $url): array {
  $token = trim((string)($cfg['avito_access_token'] ?? ''));
  if ($token === '') {
    return ['ok' => false, 'error' => 'Access token пустой'];
  }

  $apiUrl = 'https://api.avito.ru/messenger/v3/webhook/unsubscribe';
  $headers = [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
  ];

  $payload = ['url' => $url];

  $ch = curl_init($apiUrl);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  ]);

  $response = curl_exec($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = curl_error($ch);
  curl_close($ch);

  avito_log("Unregister webhook: url={$url}, code={$httpCode}, response={$response}", 'webhook_register.log');

  if ($curlError !== '') {
    return ['ok' => false, 'error' => $curlError];
  }

  if ($httpCode < 200 || $httpCode >= 300) {
    return ['ok' => false, 'error' => "HTTP {$httpCode}", 'response' => $response];
  }

  $json = json_decode((string)$response, true);
  return ['ok' => true, 'data' => $json];
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
      $flashType = 'bad';
    }
  }
}

// Получаем текущий статус
$currentStatus = avito_get_webhook_status($cfg);
$isRegistered = false;
$registeredUrls = [];

if ($currentStatus['ok']) {
  $subscriptions = $currentStatus['data']['subscriptions'] ?? [];
  $registeredUrls = array_column($subscriptions, 'url');
  $isRegistered = in_array($webhookUrl, $registeredUrls, true);
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
          <?php if ($url === $webhookUrl): ?>
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
  </div>
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
