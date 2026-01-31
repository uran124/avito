<?php
// /avito/openai.php
declare(strict_types=1);

require_once __DIR__ . '/panel_lib.php';

require_admin();

function openai_responses_create(array $cfg, string $instructions, string $input): array {
  if (empty($cfg['openai_api_key'])) {
    return ['_error' => 'OpenAI key is empty'];
  }

  $url = 'https://api.openai.com/v1/responses';
  $body = [
    'model' => $cfg['openai_model'] ?: 'gpt-4.1-mini',
    'instructions' => $instructions,
    'input' => $input,
    'max_output_tokens' => (int)($cfg['openai_max_output_tokens'] ?? 260),
    'store' => false,
  ];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $cfg['openai_api_key'],
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
  if (!is_array($json)) return ['_error' => 'Bad JSON from OpenAI', '_raw' => $raw];

  return $json;
}

function extract_openai_text(array $resp): string {
  if (isset($resp['output_text']) && is_string($resp['output_text']) && trim($resp['output_text']) !== '') {
    return trim($resp['output_text']);
  }
  $texts = [];
  if (isset($resp['output']) && is_array($resp['output'])) {
    foreach ($resp['output'] as $item) {
      if (($item['type'] ?? '') === 'message' && isset($item['content']) && is_array($item['content'])) {
        foreach ($item['content'] as $part) {
          $t = $part['text'] ?? null;
          if (is_string($t) && $t !== '') $texts[] = $t;
        }
      }
    }
  }
  return trim(implode("\n", $texts));
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

  if ($action === 'send_openai') {
    $lastInstructions = trim((string)($_POST['instructions'] ?? ''));
    $lastPrompt = trim((string)($_POST['prompt'] ?? ''));
    if (empty($cfg['openai_api_key'])) {
      $flash = 'OpenAI API key не задан в админке ❌';
      $flashType = 'bad';
    } elseif ($lastPrompt === '') {
      $flash = 'Введите сообщение для OpenAI ❌';
      $flashType = 'bad';
    } else {
      $resp = openai_responses_create($cfg, $lastInstructions, $lastPrompt);
      if (isset($resp['_error'])) {
        $flash = 'Ошибка OpenAI ❌: ' . $resp['_error'];
        $flashType = 'bad';
        if (!empty($resp['_raw'])) {
          avito_log('OpenAI error raw: ' . substr((string)$resp['_raw'], 0, 2000), 'openai.log');
        }
      } else {
        $responseText = extract_openai_text($resp);
        $flash = 'Ответ получен ✅';
        $flashType = 'ok';
        avito_log('OpenAI prompt: ' . mb_substr($lastPrompt, 0, 1000), 'openai.log');
        avito_log('OpenAI response: ' . mb_substr($responseText, 0, 2000), 'openai.log');
      }
    }
  }
}

$logTailLines = max(50, min(2000, (int)($settings['log_tail_lines'] ?? 200)));
$logText = tail_lines(AVITO_LOG_DIR . '/openai.log', $logTailLines);

render_panel_header('OpenAI', 'openai');

if ($flash !== '') {
  echo '<div class="flash ' . h($flashType) . '">' . h($flash) . '</div>';
}

$openaiOk = !empty($cfg['openai_api_key']);
?>

<div class="grid">
  <div class="card">
    <h2>Статус подключения</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
      <span class="pill <?= $openaiOk ? 'ok' : 'bad' ?>">API key: <?= $openaiOk ? 'задан' : 'нет' ?></span>
      <span class="pill">Model: <span class="mono"><?=h((string)($cfg['openai_model'] ?? ''))?></span></span>
      <span class="pill">Max output tokens: <span class="mono"><?=h((string)($cfg['openai_max_output_tokens'] ?? ''))?></span></span>
    </div>
    <div class="hint" style="margin-top:8px">Изменения API ключа и модели — в <a href="/avito/admin.php">админке</a>.</div>
  </div>

  <div class="card">
    <h2>Подсказка</h2>
    <div class="hint">Эта страница предназначена для ручной отправки сообщений в ChatGPT и проверки связи.</div>
  </div>
</div>

<div class="card">
  <h2>Ручной чат</h2>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="send_openai">

    <label>Инструкции (опционально)</label>
    <textarea name="instructions" placeholder="Система: кратко опишите стиль/роль"><?=h($lastInstructions)?></textarea>

    <label>Сообщение</label>
    <textarea name="prompt" placeholder="Напишите запрос"><?=h($lastPrompt)?></textarea>

    <button type="submit">Отправить в OpenAI</button>
  </form>

  <?php if ($responseText !== ''): ?>
    <div class="card" style="margin-top:12px">
      <h3>Ответ</h3>
      <div class="msg assistant"><?=h($responseText)?></div>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Логи OpenAI</h2>
  <pre class="mono" style="white-space:pre-wrap;margin:0"><?=h($logText)?></pre>
</div>

<?php render_panel_footer(); ?>
