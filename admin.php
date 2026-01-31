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
  </style></head><body>';
}

function render_footer(): void {
  echo '</body></html>';
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

  $new['tg_bot_token'] = trim((string)($_POST['tg_bot_token'] ?? ''));
  $new['tg_chat_id'] = trim((string)($_POST['tg_chat_id'] ?? ''));
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

render_header('Avito Bot Admin');

echo '<h1>Настройки бота</h1>';
if ($flash) echo '<p class="ok">' . h($flash) . '</p>';

echo '<div class="card">';
echo '<h3>Статус MySQL</h3>';
echo '<p class="' . ($dbStatus['ok'] ? 'ok' : 'bad') . '">' . h($dbStatus['msg']) . '</p>';
echo '<div class="hint">Таблицы создаются через migrate.sql в выбранной базе.</div>';
echo '</div>';

echo '<form method="post">';
echo '<input type="hidden" name="save_settings" value="1">';

echo '<div class="card">
  <h3>URL вебхука</h3>
  <code>' . h($webhookUrl) . '</code>
  <div class="hint">Этот URL указываете в интеграторе/CRM как endpoint для входящих сообщений.</div>
</div>';

echo '<div class="card">
  <h3>Безопасность вебхука</h3>
  <label>Webhook secret (проверяем заголовок <code>X-Webhook-Secret</code>)</label>
  <input name="webhook_secret" value="' . h((string)$cfg['webhook_secret']) . '" placeholder="секрет (опционально)">
  <label>Allow IPs (через запятую/пробел)</label>
  <input name="allow_ips" value="' . h(implode(', ', (array)$cfg['allow_ips'])) . '" placeholder="1.2.3.4, 5.6.7.8">
  <div class="hint">Если список пуст — принимаем запросы со всех IP.</div>
</div>';

echo '<div class="card">
  <h3>OpenAI</h3>
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
</div>';

echo '<div class="card">
  <h3>Telegram уведомления</h3>
  <label>Bot token</label>
  <input name="tg_bot_token" value="' . h((string)$cfg['tg_bot_token']) . '" placeholder="123:ABC...">
  <label>Chat ID</label>
  <input name="tg_chat_id" value="' . h((string)$cfg['tg_chat_id']) . '" placeholder="-100... или 123456">
  <label>Когда слать</label>
  <select name="tg_notify_mode">
    <option value="handoff" ' . ($cfg['tg_notify_mode']==='handoff'?'selected':'') . '>Только когда “передать менеджеру”</option>
    <option value="always" ' . ($cfg['tg_notify_mode']==='always'?'selected':'') . '>Всегда (каждое сообщение)</option>
    <option value="never" ' . ($cfg['tg_notify_mode']==='never'?'selected':'') . '>Никогда</option>
  </select>
</div>';

echo '<div class="card">
  <h3>Лидоген</h3>
  <label>Режим</label>
  <select name="lead_capture_mode">
    <option value="soft" ' . ($cfg['lead_capture_mode']==='soft'?'selected':'') . '>Soft (без просьбы телефона)</option>
    <option value="ask_phone" ' . ($cfg['lead_capture_mode']==='ask_phone'?'selected':'') . '>Ask phone (просим номер)</option>
  </select>
  <div class="hint">Soft — безопаснее: имя + дата/время, общение остаётся в чате.</div>
</div>';

echo '<div class="card">
  <h3>MySQL (сессии / история / лиды)</h3>
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
  <div class="hint">Таблицы создайте из файла <code>migrate.sql</code> в выбранной базе.</div>
</div>';

echo '<button type="submit">Сохранить</button>';
echo '</form>';

render_footer();
