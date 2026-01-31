<?php
// /avito/telegram.php
declare(strict_types=1);

require_once __DIR__ . '/panel_lib.php';

require_admin();

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

function tg_send_message(string $botToken, string $chatId, string $text, string $threadId = ''): array {
  $url = tg_api_base($botToken) . '/sendMessage';
  $payload = [
    'chat_id' => $chatId,
    'text' => $text,
    'disable_web_page_preview' => true,
  ];
  if (trim($threadId) !== '') {
    $payload['message_thread_id'] = (int)$threadId;
  }
  return http_request_json('POST', $url, $payload, [], 12);
}

function parse_allowed_updates(string $s): array {
  $s = trim($s);
  if ($s === '') return [];
  $parts = preg_split('/[\s,]+/', $s) ?: [];
  return array_values(array_filter(array_map('trim', $parts)));
}

function parse_tg_log_entries(string $logText): array {
  $entries = [];
  foreach (array_filter(array_map('trim', explode("\n", $logText))) as $line) {
    if (!str_contains($line, 'TG update:')) continue;
    $pos = mb_strpos($line, 'TG update:');
    if ($pos === false) continue;
    $jsonPart = trim(mb_substr($line, $pos + mb_strlen('TG update:')));
    $payload = json_decode($jsonPart, true);
    if (!is_array($payload)) continue;
    $message = $payload['message'] ?? [];
    if (!is_array($message)) continue;

    $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
    $first = (string)($chat['first_name'] ?? '');
    $last = (string)($chat['last_name'] ?? '');
    $name = trim($first . ' ' . $last);
    $username = (string)($chat['username'] ?? '');
    $chatId = (string)($chat['id'] ?? '');
    $text = (string)($message['text'] ?? '');
    $dateTs = (int)($message['date'] ?? 0);
    $date = $dateTs ? date('Y-m-d H:i:s', $dateTs) : '';

    $entries[] = [
      'chat_id' => $chatId,
      'name' => $name !== '' ? $name : ($username !== '' ? '@' . $username : ''),
      'username' => $username !== '' ? '@' . $username : '',
      'date' => $date,
      'text' => $text,
    ];
  }
  return $entries;
}

$cfg = avito_get_config();
$settings = panel_load_settings();

$baseUrl = current_base_url();
$autoTgWebhookUrl = $baseUrl . '/avito/tg_webhook.php';

$tgWebhookUrl = trim((string)$settings['tg_webhook_url']);
if ($tgWebhookUrl === '') $tgWebhookUrl = $autoTgWebhookUrl;

$tgSecretToken = trim((string)$settings['tg_secret_token']);
if ($tgSecretToken === '') {
  $tgSecretToken = bin2hex(random_bytes(16));
  $settings['tg_secret_token'] = $tgSecretToken;
  panel_save_settings($settings);
}

$dropPending = !empty($settings['tg_drop_pending_updates']);
$allowedUpdatesStr = (string)($settings['tg_allowed_updates'] ?? 'message');
$allowedUpdates = parse_allowed_updates($allowedUpdatesStr);

$flash = '';
$flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'save_tg_settings') {
    $new = $settings;
    $new['tg_webhook_url'] = trim((string)($_POST['tg_webhook_url'] ?? ''));
    $new['tg_secret_token'] = trim((string)($_POST['tg_secret_token'] ?? ''));
    $new['tg_drop_pending_updates'] = !empty($_POST['tg_drop_pending_updates']);
    $new['tg_allowed_updates'] = trim((string)($_POST['tg_allowed_updates'] ?? 'message'));

    if (panel_save_settings($new)) {
      $settings = $new;
      $flash = 'Настройки Telegram сохранены ✅';
      $flashType = 'ok';
    } else {
      $flash = 'Не удалось сохранить настройки Telegram ❌';
      $flashType = 'bad';
    }
  }

  if ($action === 'tg_refresh_status') {
    if (empty($cfg['tg_bot_token'])) {
      $flash = 'В config не задан tg_bot_token — webhook не настроить ❌';
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

  if ($action === 'tg_set_webhook') {
    if (empty($cfg['tg_bot_token'])) {
      $flash = 'В config не задан tg_bot_token — webhook не настроить ❌';
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

  if ($action === 'tg_delete_webhook') {
    if (empty($cfg['tg_bot_token'])) {
      $flash = 'В config не задан tg_bot_token — webhook не настроить ❌';
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

  if ($action === 'send_tg_manual') {
    $to = trim((string)($_POST['tg_to'] ?? ''));
    $threadId = trim((string)($_POST['tg_thread_id'] ?? ''));
    $text = trim((string)($_POST['tg_text'] ?? ''));
    if (empty($cfg['tg_bot_token'])) {
      $flash = 'В config не задан tg_bot_token ❌';
      $flashType = 'bad';
    } elseif ($to === '') {
      $flash = 'Telegram chat_id пустой ❌';
      $flashType = 'bad';
    } elseif ($threadId !== '' && !ctype_digit($threadId)) {
      $flash = 'Thread ID должен быть числом ❌';
      $flashType = 'bad';
    } elseif ($text === '') {
      $flash = 'Текст пустой ❌';
      $flashType = 'bad';
    } else {
      $res = tg_send_message((string)$cfg['tg_bot_token'], $to, $text, $threadId);
      if ($res['ok']) {
        $flash = 'Отправлено в Telegram ✅';
        $flashType = 'ok';
      } else {
        $flash = 'Ошибка отправки в Telegram ❌: ' . ($res['error'] ?: ('HTTP ' . $res['status']));
        $flashType = 'bad';
      }
    }
  }

  if ($action === 'tg_regen_secret') {
    $settings = panel_load_settings();
    $settings['tg_secret_token'] = bin2hex(random_bytes(16));
    if (panel_save_settings($settings)) {
      $flash = 'Секрет Telegram обновлён ✅ (нажмите “Установить webhook”)';
      $flashType = 'ok';
    } else {
      $flash = 'Не удалось сохранить новый секрет ❌';
      $flashType = 'bad';
    }
  }
}

$settings = panel_load_settings();
$tgLastChecked = (string)($settings['tg_last_checked_at'] ?? '');
$tgLastError = (string)($settings['tg_last_error'] ?? '');
$tgInfo = $settings['tg_last_info_json'] ?? null;

$logTailLines = max(50, min(2000, (int)($settings['log_tail_lines'] ?? 200)));
$tgLogText = tail_lines(AVITO_LOG_DIR . '/tg_webhook.log', $logTailLines);
$tgEntries = parse_tg_log_entries($tgLogText);

render_panel_header('Telegram', 'telegram');

if ($flash !== '') {
  echo '<div class="flash ' . h($flashType) . '">' . h($flash) . '</div>';
}

$tgConfigured = !empty($cfg['tg_bot_token']);
$tgNotifyReady = !empty($cfg['tg_bot_token']) && !empty($cfg['tg_chat_id']);

$tgUrlCurrent = '';
$tgPending = '';
$tgLastErrMsg = '';
if (is_array($tgInfo) && isset($tgInfo['result']) && is_array($tgInfo['result'])) {
  $tgUrlCurrent = (string)($tgInfo['result']['url'] ?? '');
  $tgPending = (string)($tgInfo['result']['pending_update_count'] ?? '');
  $tgLastErrMsg = (string)($tgInfo['result']['last_error_message'] ?? '');
}

?>

<div class="grid">
  <div class="card">
    <h2>Статус подключения</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
      <span class="pill <?= $tgConfigured ? 'ok' : 'bad' ?>">Bot token: <?= $tgConfigured ? 'есть' : 'нет' ?></span>
      <span class="pill <?= $tgNotifyReady ? 'ok' : 'warn' ?>">Chat ID: <?= $tgNotifyReady ? 'задан' : 'не задан' ?></span>
      <span class="pill <?= $tgUrlCurrent !== '' ? 'ok' : 'warn' ?>">Webhook: <?= $tgUrlCurrent !== '' ? 'установлен' : 'нет' ?></span>
      <?php if ($tgPending !== ''): ?>
        <span class="pill">Pending: <span class="mono"><?=h($tgPending)?></span></span>
      <?php endif; ?>
    </div>
    <div class="hint" style="margin-top:8px">
      Уведомления: <b><?=h((string)($cfg['tg_notify_mode'] ?? 'handoff'))?></b>, чат по умолчанию:
      <span class="mono"><?=h((string)($cfg['tg_chat_id'] ?? ''))?></span>
    </div>
    <?php if ($tgLastChecked): ?>
      <div class="hint">Проверено: <span class="mono"><?=h($tgLastChecked)?></span></div>
    <?php endif; ?>
    <?php if ($tgLastErrMsg !== ''): ?>
      <div style="margin-top:8px;color:#b00020"><b>Last error:</b> <?=h($tgLastErrMsg)?></div>
    <?php elseif ($tgLastError !== ''): ?>
      <div style="margin-top:8px;color:#b00020"><b>Error:</b> <?=h($tgLastError)?></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Быстрые действия</h2>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <button type="submit" name="action" value="tg_set_webhook">Установить webhook</button>
      <button type="submit" name="action" value="tg_delete_webhook" class="danger">Разорвать webhook</button>
      <button type="submit" name="action" value="tg_refresh_status" class="secondary">Обновить статус</button>
    </form>
  </div>
</div>

<div class="card">
  <h2>Настройки Telegram webhook</h2>
  <div class="hint">Хранятся в <code class="mono">/avito/_private/panel_settings.json</code>.</div>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="save_tg_settings">

    <div class="row">
      <div>
        <label>Webhook URL (если пусто — авто)</label>
        <input name="tg_webhook_url" value="<?=h((string)$settings['tg_webhook_url'])?>" placeholder="<?=h($autoTgWebhookUrl)?>">
        <div class="hint">По умолчанию: <code class="mono">/avito/tg_webhook.php</code></div>
      </div>
      <div>
        <label>Secret token (Telegram header)</label>
        <input name="tg_secret_token" value="<?=h((string)$settings['tg_secret_token'])?>" placeholder="если пусто — сгенерится">
        <div class="hint">Отправляется в заголовке <code class="mono">X-Telegram-Bot-Api-Secret-Token</code>.</div>
        <button type="submit" name="action" value="tg_regen_secret" class="secondary" style="margin-top:6px">Сгенерировать новый секрет</button>
      </div>
    </div>

    <label>
      <input type="checkbox" name="tg_drop_pending_updates" value="1" <?=!empty($settings['tg_drop_pending_updates'])?'checked':''?>>
      drop_pending_updates (очистить очередь при set/delete webhook)
    </label>

    <label>allowed_updates (через запятую)</label>
    <input name="tg_allowed_updates" value="<?=h((string)$settings['tg_allowed_updates'])?>" placeholder="message,callback_query">

    <button type="submit">Сохранить настройки</button>
  </form>
</div>

<div class="card">
  <h2>Ручная отправка</h2>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="send_tg_manual">

    <label>Кому (chat_id)</label>
    <input name="tg_to" value="<?=h((string)($cfg['tg_chat_id'] ?? ''))?>" placeholder="-100... или 123456">

    <label>Thread ID (topic)</label>
    <input name="tg_thread_id" value="<?=h((string)($cfg['tg_thread_id'] ?? ''))?>" placeholder="например 12345">
    <div class="hint">Укажите, если отправляете в тему группы (message_thread_id).</div>

    <label>Текст</label>
    <textarea name="tg_text" placeholder="сообщение"></textarea>

    <button type="submit">Отправить</button>
  </form>
</div>

<div class="card">
  <h2>Логи сообщений боту</h2>
  <div class="hint">Чат ID, имя, время, текст — по входящим сообщениям из Telegram.</div>

  <?php if ($tgEntries): ?>
    <table>
      <thead>
        <tr>
          <th>Chat ID</th>
          <th>Имя</th>
          <th>Время</th>
          <th>Текст</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tgEntries as $entry): ?>
          <tr>
            <td class="mono"><?=h($entry['chat_id'])?></td>
            <td><?=h($entry['name'])?><?php if ($entry['username'] !== ''): ?> <span class="hint"><?=h($entry['username'])?></span><?php endif; ?></td>
            <td class="mono"><?=h($entry['date'])?></td>
            <td><?=h($entry['text'])?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="hint">Пока нет записей в tg_webhook.log.</div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Сырые логи</h2>
  <pre class="mono" style="white-space:pre-wrap;margin:0"><?=h($tgLogText)?></pre>
</div>

<?php render_panel_footer(); ?>
