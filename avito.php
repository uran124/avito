<?php
// /avito/avito.php
declare(strict_types=1);

require_once __DIR__ . '/panel_lib.php';

require_admin();

function extract_last_log_time(string $logPath): ?int {
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

function avito_auth_header(string $token): string {
  $token = trim($token);
  if ($token === '') return '';
  if (stripos($token, 'bearer ') === 0) return 'Authorization: ' . $token;
  return 'Authorization: Bearer ' . $token;
}

function avito_send_message_api(array &$cfg, string $chatId, string $text): array {
  $userId = trim((string)($cfg['avito_user_id'] ?? ''));
  $base = trim((string)($cfg['avito_api_base'] ?? 'https://api.avito.ru'));
  $base = rtrim($base, '/');

  if ($userId === '') return ['ok' => false, 'error' => 'avito_user_id пустой', 'status' => 0];
  $tokenReady = avito_prepare_access_token($cfg);
  if (!$tokenReady['ok']) {
    return ['ok' => false, 'error' => 'Токен недоступен: ' . ($tokenReady['error'] ?? 'token error'), 'status' => 0];
  }

  $token = trim((string)($cfg['avito_access_token'] ?? ''));
  if ($token === '') return ['ok' => false, 'error' => 'avito_access_token пустой', 'status' => 0];

  $url = $base . '/messenger/v1/accounts/' . rawurlencode($userId) . '/chats/' . rawurlencode($chatId) . '/messages';
  $headers = [avito_auth_header($token)];
  $payload = [
    'type' => 'text',
    'message' => ['text' => $text],
  ];

  return http_request_json('POST', $url, $payload, $headers, 20);
}

$cfg = avito_get_config();
$settings = panel_load_settings();

$autoAvitoWebhookUrl = 'https://bunchflowers.ru/avito/webhook.php';
$avitoWebhookReceiverUrl = $autoAvitoWebhookUrl;

$avitoSecretHeader = 'X-Webhook-Secret';
$avitoSecretValue = (string)($cfg['webhook_secret'] ?? '');

$avitoWebhookEnabled = true;

$messagesLimit = max(20, min(200, (int)($settings['messages_limit'] ?? 60)));
$logTailLines = max(50, min(2000, (int)($settings['log_tail_lines'] ?? 200)));

$flash = '';
$flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'enable_webhook' || $action === 'disable_webhook') {
    $new = $settings;
    $new['avito_webhook_enabled'] = ($action === 'enable_webhook');
    if (panel_save_settings($new)) {
      $settings = $new;
      $avitoWebhookEnabled = (bool)$new['avito_webhook_enabled'];
      $flash = $avitoWebhookEnabled ? 'Webhook включен ✅' : 'Webhook отключен ✅';
      $flashType = 'ok';
    } else {
      $flash = 'Не удалось сохранить статус webhook ❌';
      $flashType = 'bad';
    }
  }

  if ($action === 'send_avito_manual') {
    $settings = panel_load_settings();
    $chatId = trim((string)($_POST['avito_chat_id'] ?? ''));
    $text = trim((string)($_POST['avito_text'] ?? ''));

    if (trim((string)($cfg['avito_user_id'] ?? '')) === '') {
      $flash = 'Не задан avito_user_id в админке ❌';
      $flashType = 'bad';
    } elseif (
      trim((string)($cfg['avito_access_token'] ?? '')) === ''
      && trim((string)($cfg['avito_client_id'] ?? '')) === ''
      && trim((string)($cfg['avito_client_secret'] ?? '')) === ''
    ) {
      $flash = 'Не задан avito_access_token или client_id/client_secret ❌';
      $flashType = 'bad';
    } elseif ($chatId === '') {
      $flash = 'Avito chat_id пустой ❌';
      $flashType = 'bad';
    } elseif ($text === '') {
      $flash = 'Текст пустой ❌';
      $flashType = 'bad';
    } else {
      $res = avito_send_message_api($cfg, $chatId, $text);
      if ($res['ok']) {
        $flash = 'Отправлено в Avito ✅';
        $flashType = 'ok';
      } else {
        $flash = 'Ошибка отправки в Avito ❌: ' . ($res['error'] ?: ('HTTP ' . $res['status']));
        $flashType = 'bad';
      }
    }
  }
}

$settings = panel_load_settings();

$avitoWebhookReceiverUrl = $autoAvitoWebhookUrl;
$avitoSecretHeader = 'X-Webhook-Secret';
$avitoSecretValue = (string)($cfg['webhook_secret'] ?? '');
$avitoWebhookEnabled = true;

$pdo = null;
$dbOk = false;
try {
  $pdo = avito_db();
  $dbOk = ($pdo instanceof PDO);
} catch (Throwable $e) {
  $dbOk = false;
}

$avitoLastInTs = null;
$avitoLastOutTs = null;

if ($dbOk && $pdo instanceof PDO) {
  try {
    $tMsg = avito_db_prefix() . 'avito_messages';
    $sqlIn = "SELECT MAX(created_at) AS last_in FROM {$tMsg} WHERE role = 'user'";
    $stIn = $pdo->query($sqlIn);
    $rowIn = $stIn ? $stIn->fetch() : null;
    if (is_array($rowIn) && !empty($rowIn['last_in'])) {
      $ts = strtotime((string)$rowIn['last_in']);
      if ($ts !== false) $avitoLastInTs = (int)$ts;
    }

    $sqlOut = "SELECT MAX(created_at) AS last_out FROM {$tMsg} WHERE role = 'assistant'";
    $stOut = $pdo->query($sqlOut);
    $rowOut = $stOut ? $stOut->fetch() : null;
    if (is_array($rowOut) && !empty($rowOut['last_out'])) {
      $ts = strtotime((string)$rowOut['last_out']);
      if ($ts !== false) $avitoLastOutTs = (int)$ts;
    }
  } catch (Throwable $e) {
    $avitoLastInTs = extract_last_log_time(AVITO_LOG_DIR . '/in.log');
    $avitoLastOutTs = extract_last_log_time(AVITO_LOG_DIR . '/out.log');
  }
} else {
  $avitoLastInTs = extract_last_log_time(AVITO_LOG_DIR . '/in.log');
  $avitoLastOutTs = extract_last_log_time(AVITO_LOG_DIR . '/out.log');
}

$now = time();
$avitoActive = ($avitoLastInTs !== null && ($now - $avitoLastInTs) <= 60 * 30);
$avitoLastInHuman = $avitoLastInTs ? date('Y-m-d H:i:s', $avitoLastInTs) : 'нет данных';
$avitoLastOutHuman = $avitoLastOutTs ? date('Y-m-d H:i:s', $avitoLastOutTs) : 'нет данных';

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

$knownLogs = [];
foreach (glob(AVITO_LOG_DIR . '/*.log') ?: [] as $path) {
  $name = basename($path);
  $knownLogs[$name] = $path;
}
if (!$knownLogs) {
  $knownLogs = [
    'in.log' => AVITO_LOG_DIR . '/in.log',
    'out.log' => AVITO_LOG_DIR . '/out.log',
    'db.log' => AVITO_LOG_DIR . '/db.log',
  ];
}
ksort($knownLogs);

$selectedLog = (string)($_GET['log'] ?? 'in.log');
if (!isset($knownLogs[$selectedLog])) $selectedLog = 'in.log';
$logText = tail_lines($knownLogs[$selectedLog], $logTailLines);

render_panel_header('Avito', 'avito');

if ($flash !== '') {
  echo '<div class="flash ' . h($flashType) . '">' . h($flash) . '</div>';
}
?>

<div class="grid">
<div class="card">
  <h2>Статус интеграции</h2>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
    <span class="pill <?= $avitoActive ? 'ok' : 'warn' ?>">Входящие: <?= $avitoActive ? 'активно' : 'нет событий' ?></span>
    <span class="pill">Последнее входящее: <span class="mono"><?=h($avitoLastInHuman)?></span></span>
    <span class="pill">Последнее исходящее: <span class="mono"><?=h($avitoLastOutHuman)?></span></span>
    <span class="pill <?= $dbOk ? 'ok' : 'warn' ?>">MySQL: <?= $dbOk ? 'подключено' : 'выключено/ошибка' ?></span>
    <span class="pill <?= $avitoWebhookEnabled ? 'ok' : 'warn' ?>">Webhook: <?= $avitoWebhookEnabled ? 'установлен' : 'отключен' ?></span>
  </div>
  <div class="hint" style="margin-top:8px">Webhook endpoint: <code class="mono"><?=h($avitoWebhookReceiverUrl)?></code></div>
  <div class="hint">Secret header: <code class="mono"><?=h($avitoSecretHeader)?></code>, value: <code class="mono"><?=h($avitoSecretValue ?: 'не задан')?></code></div>
  <form method="post" style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <button type="submit" name="action" value="enable_webhook">Установить webhook</button>
    <button type="submit" name="action" value="disable_webhook" class="secondary">Разорвать webhook</button>
    <?php
      $oauthState = bin2hex(random_bytes(12));
      $_SESSION['avito_oauth_state'] = $oauthState;
      $oauthUrl = 'https://avito.ru/oauth?' . http_build_query([
        'response_type' => 'code',
        'client_id' => (string)($cfg['avito_client_id'] ?? ''),
        'scope' => 'messenger:read messenger:write',
        'redirect_uri' => $baseUrl . '/avito/avito_oauth_callback.php',
        'state' => $oauthState,
      ]);
    ?>
    <a href="<?=h($oauthUrl)?>" class="pill">Запустить авторизацию</a>
  </form>
</div>

  <div class="card">
    <h2>Быстрые ссылки</h2>
    <div class="hint">Параметры OAuth задаются в админке. Ручная отправка — ниже.</div>
    <div style="margin-top:10px">
      <div class="pill">Client ID: <span class="mono"><?=h(mask_secret((string)($cfg['avito_client_id'] ?? '')))?></span></div>
      <div class="pill">Access token: <span class="mono"><?=h(mask_secret((string)($cfg['avito_access_token'] ?? '')))?></span></div>
    </div>
  </div>
</div>

<div class="card">
  <h2>Ручная отправка</h2>
  <?php
    $apiBase = rtrim((string)($cfg['avito_api_base'] ?? 'https://api.avito.ru'), '/');
    $apiUserId = (string)($cfg['avito_user_id'] ?? '');
    $apiToken = mask_secret((string)($cfg['avito_access_token'] ?? ''));
    $sendUrl = $apiUserId !== '' ? $apiBase . '/messenger/v1/accounts/' . $apiUserId . '/chats/{chat_id}/messages' : '';
  ?>
  <div class="hint">Отправка идёт через официальный Avito API. Настройте <strong>Access token</strong> и <strong>User ID</strong> в админке.</div>
  <div class="hint">API base: <code class="mono"><?=h($apiBase)?></code></div>
  <div class="hint">User ID: <code class="mono"><?=h($apiUserId ?: 'не задан')?></code>, token: <code class="mono"><?=h($apiToken ?: 'не задан')?></code></div>
  <div class="hint">Send URL: <code class="mono"><?=h($sendUrl ?: '—')?></code></div>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="send_avito_manual">

    <label>Avito chat_id</label>
    <input name="avito_chat_id" value="<?=h($selectedChat)?>" placeholder="chat_id">

    <label>Текст</label>
    <textarea name="avito_text" placeholder="сообщение клиенту"></textarea>

    <button type="submit">Отправить в Avito</button>
    <div class="hint">Используется <code class="mono">/messenger/v1/accounts/{user_id}/chats/{chat_id}/messages</code>.</div>
  </form>
</div>

<div class="card">
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
              <td><a href="?chat=<?=urlencode((string)$c['chat_id'])?>#dialogs">Открыть</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card" style="margin:0" id="dialogs">
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
    <div class="hint">MySQL не подключен — включите в <a href="/avito/admin.php">админке</a>, чтобы видеть диалоги.</div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Логи Avito</h2>
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
  </form>

  <pre class="mono" style="white-space:pre-wrap;margin:0"><?=h($logText)?></pre>
</div>

<?php render_panel_footer(); ?>
