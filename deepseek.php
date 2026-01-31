<?php
// /avito/deepseek.php
declare(strict_types=1);

require_once __DIR__ . '/panel_lib.php';

require_admin();

function deepseek_chat_create(array $cfg, string $instructions, string $input): array {
  if (empty($cfg['deepseek_api_key'])) {
    return ['_error' => 'DeepSeek key is empty'];
  }

  $url = 'https://api.deepseek.com/v1/chat/completions';
  $messages = [];
  if (trim($instructions) !== '') {
    $messages[] = ['role' => 'system', 'content' => $instructions];
  }
  $messages[] = ['role' => 'user', 'content' => $input];

  $body = [
    'model' => $cfg['deepseek_model'] ?: 'deepseek-chat',
    'messages' => $messages,
    'max_tokens' => (int)($cfg['deepseek_max_output_tokens'] ?? 260),
  ];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $cfg['deepseek_api_key'],
    ],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
  ]);

  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($err) return ['_error' => "cURL error: $err"];
  if ($status >= 400) return ['_error' => "HTTP $status", '_raw' => $raw];

  $json = json_decode((string)$raw, true);
  if (!is_array($json)) return ['_error' => 'Bad JSON from DeepSeek', '_raw' => $raw];

  return $json;
}

function extract_deepseek_text(array $resp): string {
  if (isset($resp['choices'][0]['message']['content'])) {
    $content = $resp['choices'][0]['message']['content'];
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

  if ($action === 'send_deepseek') {
    $lastInstructions = trim((string)($_POST['instructions'] ?? ''));
    $lastPrompt = trim((string)($_POST['prompt'] ?? ''));
    if (empty($cfg['deepseek_api_key'])) {
      $flash = 'DeepSeek API key не задан в админке ❌';
      $flashType = 'bad';
    } elseif ($lastPrompt === '') {
      $flash = 'Введите сообщение для DeepSeek ❌';
      $flashType = 'bad';
    } else {
      $resp = deepseek_chat_create($cfg, $lastInstructions, $lastPrompt);
      if (isset($resp['_error'])) {
        $flash = 'Ошибка DeepSeek ❌: ' . $resp['_error'];
        $flashType = 'bad';
        if (!empty($resp['_raw'])) {
          avito_log('DeepSeek error raw: ' . substr((string)$resp['_raw'], 0, 2000), 'deepseek.log');
        }
      } else {
        $responseText = extract_deepseek_text($resp);
        $flash = 'Ответ получен ✅';
        $flashType = 'ok';
        avito_log('DeepSeek prompt: ' . mb_substr($lastPrompt, 0, 1000), 'deepseek.log');
        avito_log('DeepSeek response: ' . mb_substr($responseText, 0, 2000), 'deepseek.log');
      }
    }
  }
}

$logTailLines = max(50, min(2000, (int)($settings['log_tail_lines'] ?? 200)));
$logText = tail_lines(AVITO_LOG_DIR . '/deepseek.log', $logTailLines);

render_panel_header('DeepSeek', 'deepseek');

if ($flash !== '') {
  echo '<div class="flash ' . h($flashType) . '">' . h($flash) . '</div>';
}

$deepseekOk = !empty($cfg['deepseek_api_key']);
?>

<div class="grid">
  <div class="card">
    <h2>Статус подключения</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
      <span class="pill <?= $deepseekOk ? 'ok' : 'bad' ?>">API key: <?= $deepseekOk ? 'задан' : 'нет' ?></span>
      <span class="pill">Model: <span class="mono"><?=h((string)($cfg['deepseek_model'] ?? ''))?></span></span>
      <span class="pill">Max output tokens: <span class="mono"><?=h((string)($cfg['deepseek_max_output_tokens'] ?? ''))?></span></span>
    </div>
    <div class="hint" style="margin-top:8px">Изменения API ключа и модели — в <a href="/avito/admin.php">админке</a>.</div>
  </div>

  <div class="card">
    <h2>Подсказка</h2>
    <div class="hint">Эта страница предназначена для ручной отправки сообщений в DeepSeek и проверки связи.</div>
  </div>
</div>

<div class="card">
  <h2>Ручной чат</h2>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="send_deepseek">

    <label>Инструкции (опционально)</label>
    <textarea name="instructions" placeholder="Система: кратко опишите стиль/роль"><?=h($lastInstructions)?></textarea>

    <label>Сообщение</label>
    <textarea name="prompt" placeholder="Напишите запрос"><?=h($lastPrompt)?></textarea>

    <button type="submit">Отправить в DeepSeek</button>
  </form>

  <?php if ($responseText !== ''): ?>
    <div class="card" style="margin-top:12px">
      <h3>Ответ</h3>
      <div class="msg assistant"><?=h($responseText)?></div>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Логи DeepSeek</h2>
  <pre class="mono" style="white-space:pre-wrap;margin:0"><?=h($logText)?></pre>
</div>

<?php render_panel_footer(); ?>
