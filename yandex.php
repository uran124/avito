<?php
// /avito/yandex.php
declare(strict_types=1);

require_once __DIR__ . '/panel_lib.php';

require_admin();

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

$cfg = avito_get_config();
$settings = panel_load_settings();

$flash = '';
$flashType = 'ok';
$responseText = '';
$lastPrompt = '';
$lastInstructions = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'send_yandex') {
    $lastInstructions = trim((string)($_POST['instructions'] ?? ''));
    $lastPrompt = trim((string)($_POST['prompt'] ?? ''));
    if (empty($cfg['yandex_api_key'])) {
      $flash = 'Yandex API key не задан в админке ❌';
      $flashType = 'bad';
    } elseif (empty($cfg['yandex_folder_id'])) {
      $flash = 'Folder ID не задан в админке ❌';
      $flashType = 'bad';
    } elseif ($lastPrompt === '') {
      $flash = 'Введите сообщение для Yandex AI Studio ❌';
      $flashType = 'bad';
    } else {
      $resp = yandex_completion_create($cfg, $lastInstructions, $lastPrompt);
      if (isset($resp['_error'])) {
        $flash = 'Ошибка Yandex AI Studio ❌: ' . $resp['_error'];
        $flashType = 'bad';
        if (!empty($resp['_raw'])) {
          avito_log('Yandex AI Studio error raw: ' . substr((string)$resp['_raw'], 0, 2000), 'yandex.log');
        }
      } else {
        $responseText = extract_yandex_text($resp);
        $flash = 'Ответ получен ✅';
        $flashType = 'ok';
        avito_log('Yandex AI Studio prompt: ' . mb_substr($lastPrompt, 0, 1000), 'yandex.log');
        avito_log('Yandex AI Studio response: ' . mb_substr($responseText, 0, 2000), 'yandex.log');
      }
    }
  }
}

$logTailLines = max(50, min(2000, (int)($settings['log_tail_lines'] ?? 200)));
$logText = tail_lines(AVITO_LOG_DIR . '/yandex.log', $logTailLines);

render_panel_header('Yandex AI Studio', 'yandex');

if ($flash !== '') {
  echo '<div class="flash ' . h($flashType) . '">' . h($flash) . '</div>';
}

$yandexOk = !empty($cfg['yandex_api_key']) && !empty($cfg['yandex_folder_id']);
?>

<div class="grid">
  <div class="card">
    <h2>Статус подключения</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
      <span class="pill <?= $yandexOk ? 'ok' : 'bad' ?>">API key: <?= $yandexOk ? 'задан' : 'нет' ?></span>
      <span class="pill">Folder ID: <span class="mono"><?=h((string)($cfg['yandex_folder_id'] ?? ''))?></span></span>
      <span class="pill">Model: <span class="mono"><?=h((string)($cfg['yandex_model'] ?? ''))?></span></span>
      <span class="pill">Max tokens: <span class="mono"><?=h((string)($cfg['yandex_max_tokens'] ?? ''))?></span></span>
      <span class="pill">Temperature: <span class="mono"><?=h((string)($cfg['yandex_temperature'] ?? ''))?></span></span>
    </div>
    <div class="hint" style="margin-top:8px">Изменения ключа и модели — в <a href="/avito/admin.php">админке</a>.</div>
  </div>

  <div class="card">
    <h2>Подсказка</h2>
    <div class="hint">Эта страница предназначена для ручной отправки сообщений в Yandex AI Studio и проверки связи.</div>
  </div>
</div>

<div class="card">
  <h2>Ручной чат</h2>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="send_yandex">

    <label>Инструкции (опционально)</label>
    <textarea name="instructions" placeholder="Система: кратко опишите стиль/роль"><?=h($lastInstructions)?></textarea>

    <label>Сообщение</label>
    <textarea name="prompt" placeholder="Напишите запрос"><?=h($lastPrompt)?></textarea>

    <button type="submit">Отправить в Yandex AI Studio</button>
  </form>

  <?php if ($responseText !== ''): ?>
    <div class="card" style="margin-top:12px">
      <h3>Ответ</h3>
      <div class="msg assistant"><?=h($responseText)?></div>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Логи Yandex AI Studio</h2>
  <pre class="mono" style="white-space:pre-wrap;margin:0"><?=h($logText)?></pre>
</div>

<?php render_panel_footer(); ?>
