<?php
// /avito/admin.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

session_start();
$cfg = avito_get_config();

function is_logged_in(): bool {
  return !empty($_SESSION['admin_ok']);
}

function render_header(string $title): void {
  echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<title>' . h($title) . '</title>';
  echo '<style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;max-width:980px;margin:24px auto;padding:0 12px;}
    input,select,textarea{width:100%;padding:10px;margin:6px 0 14px;border:1px solid #ccc;border-radius:10px;}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    .card{border:1px solid #eee;border-radius:14px;padding:14px;margin:14px 0;background:#fff;}
    .hint{color:#666;font-size:13px;margin-top:-10px;margin-bottom:14px;line-height:1.4;}
    button{padding:10px 14px;border-radius:12px;border:0;background:#111;color:#fff;cursor:pointer;}
    code{background:#f6f6f6;padding:2px 6px;border-radius:8px;}
    .ok{color:#0a7a2a;font-weight:600;}
    .bad{color:#b00020;font-weight:600;}
    .nav{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0 18px}
    .nav a{padding:8px 10px;border-radius:10px;background:#fff;border:1px solid #eee;text-decoration:none;color:#111}
    .nav a.active{background:#111;border-color:#111;color:#fff}
  </style></head><body>';
}

function render_footer(): void {
  echo '</body></html>';
}

function render_nav(string $active): void {
  $links = [
    'admin' => ['/avito/admin.php', 'Админка'],
    'telegram' => ['/avito/telegram.php', 'Telegram'],
    'avito' => ['/avito/avito.php', 'Avito'],
    'openai' => ['/avito/openai.php', 'OpenAI'],
    'deepseek' => ['/avito/deepseek.php', 'DeepSeek'],
  ];
  echo '<nav class="nav">';
  foreach ($links as $key => $item) {
    [$href, $label] = $item;
    $class = $key === $active ? 'active' : '';
    echo '<a class="' . $class . '" href="' . h($href) . '">' . h($label) . '</a>';
  }
  echo '</nav>';
}

function require_login(array $cfg): void {
  // Если пароль ещё не задан — надо сначала установить
  if (empty($cfg['admin_password_hash'])) return;

  if (is_logged_in()) return;

  $err = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    $pass = (string)($_POST['admin_password'] ?? '');
    if (password_verify($pass, (string)$cfg['admin_password_hash'])) {
      $_SESSION['admin_ok'] = true;
      header('Location: ' . $_SERVER['PHP_SELF']);
      exit;
    } else {
      $err = 'Неверный пароль';
    }
  }

  render_header('Вход в админку');
  render_nav('admin');
  echo '<h1>Вход в админку</h1>';
  if ($err) echo '<p class="bad">' . h($err) . '</p>';
  echo '<form method="post">
    <label>Пароль</label>
    <input type="password" name="admin_password" placeholder="Введите пароль">
    <button type="submit">Войти</button>
  </form>';
  render_footer();
  exit;
}

// Первый запуск: если пароля нет — показываем установку и больше ничего
if (empty($cfg['admin_password_hash'])) {
  $flash = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_password'])) {
    $p1 = trim((string)($_POST['new_pass'] ?? ''));
    $p2 = trim((string)($_POST['new_pass2'] ?? ''));
    if ($p1 === '' || $p1 !== $p2 || mb_strlen($p1) < 8) {
      $flash = 'Пароль должен быть минимум 8 символов и совпадать в обоих полях.';
    } else {
      $cfg['admin_password_hash'] = password_hash($p1, PASSWORD_DEFAULT);
      if (avito_save_config($cfg)) {
        $_SESSION['admin_ok'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
      } else {
        $flash = 'Не удалось сохранить. Проверьте права на /avito/_private/.';
      }
    }
  }

  render_header('Установка пароля');
  render_nav('admin');
  echo '<h1>Первичная настройка</h1>';
  echo '<p class="hint">Сначала установите пароль для админки.</p>';
  if ($flash) echo '<p class="bad">' . h($flash) . '</p>';
  echo '<form method="post">
    <input type="hidden" name="set_password" value="1">
    <label>Новый пароль</label>
    <input type="password" name="new_pass" placeholder="минимум 8 символов">
    <label>Повторите пароль</label>
    <input type="password" name="new_pass2" placeholder="повтор">
    <button type="submit">Установить пароль</button>
  </form>';
  render_footer();
  exit;
}

// Если пароль уже есть — требуем логин
require_login($cfg);

$flash = '';
$dbStatus = ['ok' => false, 'msg' => 'MySQL выключен в настройках'];

if (!empty($_POST['save_settings'])) {
  $new = $cfg;

  $new['webhook_secret'] = trim((string)($_POST['webhook_secret'] ?? ''));
  $ips = trim((string)($_POST['allow_ips'] ?? ''));
  $new['allow_ips'] = $ips === '' ? [] : array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $ips) ?: [])));

  $new['openai_api_key'] = trim((string)($_POST['openai_api_key'] ?? ''));
  $new['openai_model'] = trim((string)($_POST['openai_model'] ?? 'gpt-4.1-mini'));
  $new['openai_max_output_tokens'] = (int)($_POST['openai_max_output_tokens'] ?? 260);

  $new['deepseek_api_key'] = trim((string)($_POST['deepseek_api_key'] ?? ''));
  $new['deepseek_model'] = trim((string)($_POST['deepseek_model'] ?? 'deepseek-chat'));
  $new['deepseek_max_output_tokens'] = (int)($_POST['deepseek_max_output_tokens'] ?? 260);

  $new['llm_provider'] = (string)($_POST['llm_provider'] ?? 'openai');

  $new['avito_api_base'] = trim((string)($_POST['avito_api_base'] ?? 'https://api.avito.ru'));
  $new['avito_client_id'] = trim((string)($_POST['avito_client_id'] ?? ''));
  $new['avito_client_secret'] = trim((string)($_POST['avito_client_secret'] ?? ''));
  $new['avito_access_token'] = trim((string)($_POST['avito_access_token'] ?? ''));
  $new['avito_refresh_token'] = trim((string)($_POST['avito_refresh_token'] ?? ''));
  $new['avito_token_expires_at'] = (int)($_POST['avito_token_expires_at'] ?? 0);
  $new['avito_user_id'] = trim((string)($_POST['avito_user_id'] ?? ''));

  $new['tg_bot_token'] = trim((string)($_POST['tg_bot_token'] ?? ''));
  $new['tg_chat_id'] = trim((string)($_POST['tg_chat_id'] ?? ''));
  $new['tg_thread_id'] = trim((string)($_POST['tg_thread_id'] ?? ''));
  $new['tg_notify_mode'] = (string)($_POST['tg_notify_mode'] ?? 'handoff');

  $new['lead_capture_mode'] = (string)($_POST['lead_capture_mode'] ?? 'soft');

  $new['mysql_enabled'] = !empty($_POST['mysql_enabled']);
  $new['mysql_host'] = trim((string)($_POST['mysql_host'] ?? '127.0.0.1'));
  $new['mysql_port'] = (int)($_POST['mysql_port'] ?? 3306);
  $new['mysql_db'] = trim((string)($_POST['mysql_db'] ?? ''));
  $new['mysql_user'] = trim((string)($_POST['mysql_user'] ?? ''));
  $new['mysql_pass'] = (string)($_POST['mysql_pass'] ?? '');
  $new['mysql_prefix'] = trim((string)($_POST['mysql_prefix'] ?? ''));

  if (avito_save_config($new)) {
    $cfg = $new;
    $flash = 'Сохранено ✅';
  } else {
    $flash = 'Ошибка сохранения ❌ (проверьте права на /avito/_private/)';
  }
}

// Проверка БД (не ломает страницу)
try {
  $pdo = avito_db();
  if ($pdo) {
    $dbStatus = ['ok' => true, 'msg' => 'Подключение к MySQL: OK'];
  } else {
    if (!empty($cfg['mysql_enabled'])) {
      $dbStatus = ['ok' => false, 'msg' => 'MySQL включен, но подключение не удалось. Проверьте host/db/user/pass.'];
    }
  }
} catch (Throwable $e) {
  $dbStatus = ['ok' => false, 'msg' => 'Ошибка MySQL: ' . $e->getMessage()];
}

$webhookUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
  . '://' . ($_SERVER['HTTP_HOST'] ?? 'bunchflowers.ru') . '/avito/webhook.php';
$oauthRedirectUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
  . '://' . ($_SERVER['HTTP_HOST'] ?? 'bunchflowers.ru') . '/avito/avito_oauth_callback.php';
$oauthState = bin2hex(random_bytes(12));
$_SESSION['avito_oauth_state'] = $oauthState;
$oauthUrl = 'https://avito.ru/oauth?' . http_build_query([
  'response_type' => 'code',
  'client_id' => (string)($cfg['avito_client_id'] ?? ''),
  'scope' => 'messenger:read messenger:write',
  'redirect_uri' => $oauthRedirectUrl,
  'state' => $oauthState,
]);

render_header('Avito Bot Admin');
render_nav('admin');

echo '<h1>Настройки бота</h1>';
if ($flash) echo '<p class="ok">' . h($flash) . '</p>';

echo '<form method="post">';
echo '<input type="hidden" name="save_settings" value="1">';

echo '<div class="card">
  <h3>1. Настройка базы данных (MySQL)</h3>
  <p class="' . ($dbStatus['ok'] ? 'ok' : 'bad') . '">' . h($dbStatus['msg']) . '</p>
  <label>
    <input type="checkbox" name="mysql_enabled" value="1" ' . (!empty($cfg['mysql_enabled']) ? 'checked' : '') . '>
    Включить MySQL-хранилище
  </label>
  <div class="row">
    <div>
      <label>Host</label>
      <input name="mysql_host" value="' . h((string)$cfg['mysql_host']) . '">
    </div>
    <div>
      <label>Port</label>
      <input type="number" name="mysql_port" value="' . h((string)$cfg['mysql_port']) . '">
    </div>
  </div>
  <label>Database</label>
  <input name="mysql_db" value="' . h((string)$cfg['mysql_db']) . '">
  <div class="row">
    <div>
      <label>User</label>
      <input name="mysql_user" value="' . h((string)$cfg['mysql_user']) . '">
    </div>
    <div>
      <label>Password</label>
      <input type="password" name="mysql_pass" value="' . h((string)$cfg['mysql_pass']) . '">
    </div>
  </div>
  <label>Table prefix (опционально)</label>
  <input name="mysql_prefix" value="' . h((string)$cfg['mysql_prefix']) . '" placeholder="bf_">
  <div class="hint">Таблицы создаются через <code>migrate.sql</code> в выбранной базе.</div>
</div>';

echo '<div class="card">
  <h3>2. Настройка связи с Telegram ботом</h3>
  <label>Bot token</label>
  <input name="tg_bot_token" value="' . h((string)$cfg['tg_bot_token']) . '" placeholder="123:ABC...">
  <label>Chat ID</label>
  <input name="tg_chat_id" value="' . h((string)$cfg['tg_chat_id']) . '" placeholder="-100... или 123456">
  <label>Thread ID (topic)</label>
  <input name="tg_thread_id" value="' . h((string)$cfg['tg_thread_id']) . '" placeholder="например 12345">
  <div class="hint">Если пишете в тему группы, укажите message_thread_id.</div>
  <label>Когда слать уведомления</label>
  <select name="tg_notify_mode">
    <option value="handoff" ' . ($cfg['tg_notify_mode']==='handoff'?'selected':'') . '>Только когда “передать менеджеру”</option>
    <option value="always" ' . ($cfg['tg_notify_mode']==='always'?'selected':'') . '>Всегда (каждое сообщение)</option>
    <option value="never" ' . ($cfg['tg_notify_mode']==='never'?'selected':'') . '>Никогда</option>
  </select>
  <div class="hint">Webhook и ручные сообщения настраиваются на странице <a href="/avito/telegram.php">Telegram</a>.</div>
</div>';

echo '<div class="card">
  <h3>3. Настройка связи с Avito по API</h3>
  <label>API base URL</label>
  <input name="avito_api_base" value="' . h((string)$cfg['avito_api_base']) . '" placeholder="https://api.avito.ru">
  <div class="row">
    <div>
      <label>Client ID</label>
      <input name="avito_client_id" value="' . h((string)$cfg['avito_client_id']) . '">
    </div>
    <div>
      <label>Client secret</label>
      <input type="password" name="avito_client_secret" value="' . h((string)$cfg['avito_client_secret']) . '">
    </div>
  </div>
  <label>Access token</label>
  <input name="avito_access_token" value="' . h((string)$cfg['avito_access_token']) . '" placeholder="ACCESS_TOKEN">
  <label>Refresh token</label>
  <input name="avito_refresh_token" value="' . h((string)($cfg['avito_refresh_token'] ?? '')) . '" placeholder="REFRESH_TOKEN">
  <label>Token expires at (unix)</label>
  <input name="avito_token_expires_at" value="' . h((string)($cfg['avito_token_expires_at'] ?? '')) . '" placeholder="0">
  <label>User ID (account id)</label>
  <input name="avito_user_id" value="' . h((string)$cfg['avito_user_id']) . '" placeholder="123456789">
  <label>OAuth redirect URL</label>
  <input value="' . h($oauthRedirectUrl) . '" readonly>
  <div class="hint">Зарегистрируйте этот URL в Avito как Redirect URL.</div>
  <div class="hint">
    <a href="' . h($oauthUrl) . '">Авторизоваться в Avito (OAuth)</a>
  </div>
  <div class="row">
    <div>
      <label>Webhook URL</label>
      <input value="' . h($webhookUrl) . '" readonly>
    </div>
    <div>
      <label>Webhook secret (заголовок <code>X-Webhook-Secret</code>)</label>
      <input name="webhook_secret" value="' . h((string)$cfg['webhook_secret']) . '" placeholder="секрет (опционально)">
    </div>
  </div>
  <label>Allow IPs (через запятую/пробел)</label>
  <input name="allow_ips" value="' . h(implode(', ', (array)$cfg['allow_ips'])) . '" placeholder="1.2.3.4, 5.6.7.8">
  <label>Лидоген</label>
  <select name="lead_capture_mode">
    <option value="soft" ' . ($cfg['lead_capture_mode']==='soft'?'selected':'') . '>Soft (без просьбы телефона)</option>
    <option value="ask_phone" ' . ($cfg['lead_capture_mode']==='ask_phone'?'selected':'') . '>Ask phone (просим номер)</option>
  </select>
  <div class="hint">Soft — безопаснее: имя + дата/время, общение остаётся в чате.</div>
</div>';

echo '<div class="card">
  <h3>4. Провайдер ответов (LLM)</h3>
  <label>Провайдер</label>
  <select name="llm_provider">
    <option value="openai" ' . ($cfg['llm_provider']==='openai'?'selected':'') . '>OpenAI</option>
    <option value="deepseek" ' . ($cfg['llm_provider']==='deepseek'?'selected':'') . '>DeepSeek</option>
  </select>
  <div class="hint">Выберите, через какой API формируется ответ для клиентов Avito.</div>
</div>';

echo '<div class="card">
  <h3>5. Настройка связи с OpenAI</h3>
  <label>API key</label>
  <input name="openai_api_key" value="' . h((string)$cfg['openai_api_key']) . '" placeholder="sk-...">
  <div class="row">
    <div>
      <label>Model</label>
      <input name="openai_model" value="' . h((string)$cfg['openai_model']) . '">
    </div>
    <div>
      <label>Max output tokens</label>
      <input type="number" name="openai_max_output_tokens" value="' . h((string)$cfg['openai_max_output_tokens']) . '">
    </div>
  </div>
  <div class="hint">Ручной чат доступен на странице <a href="/avito/openai.php">OpenAI</a>.</div>
</div>';

echo '<div class="card">
  <h3>6. Настройка связи с DeepSeek</h3>
  <label>API key</label>
  <input name="deepseek_api_key" value="' . h((string)$cfg['deepseek_api_key']) . '" placeholder="sk-...">
  <div class="row">
    <div>
      <label>Model</label>
      <input name="deepseek_model" value="' . h((string)$cfg['deepseek_model']) . '">
    </div>
    <div>
      <label>Max output tokens</label>
      <input type="number" name="deepseek_max_output_tokens" value="' . h((string)$cfg['deepseek_max_output_tokens']) . '">
    </div>
  </div>
  <div class="hint">Ручной чат доступен на странице <a href="/avito/deepseek.php">DeepSeek</a>.</div>
</div>';

echo '<button type="submit">Сохранить</button>';
echo '</form>';

render_footer();
