<?php
declare(strict_types=1);

require_once __DIR__ . '/avito_config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

/**
 * ensure_token():
 * - если есть refresh_token → refresh
 * - иначе (или токена нет) → client_credentials
 */
function ensure_token(): void {
    $t = load_tokens();

    $now = time();
    $hasAccess = is_array($t) && !empty($t['access_token']);
    $expiresAt = $hasAccess ? (int)($t['expires_at'] ?? 0) : 0;

    // если токен есть и ещё живой (с запасом 90 сек) — ок
    if ($hasAccess && ($expiresAt === 0 || $now < ($expiresAt - 90))) {
        return;
    }

    // если есть refresh_token — обновим
    if (is_array($t) && !empty($t['refresh_token']) && is_string($t['refresh_token'])) {
        refresh_access_token($t['refresh_token']);
        return;
    }

    // иначе берём новый через client_credentials
    $new = token_request([
        'grant_type'    => 'client_credentials',
        'client_id'     => AVITO_CLIENT_ID,
        'client_secret' => AVITO_CLIENT_SECRET,
    ]);
    if (isset($new['expires_in'])) {
        $new['expires_at'] = time() + (int)$new['expires_in'];
    }
    save_tokens($new);
}

function status_payload(): array {
    $now = time();

    $exists = file_exists(AVITO_TOKENS_FILE);
    $readable = $exists ? is_readable(AVITO_TOKENS_FILE) : false;
    $size = ($exists && $readable) ? filesize(AVITO_TOKENS_FILE) : null;

    $raw = null;
    $json = null;
    $jsonError = null;
    $jsonDecodeIsArray = false;

    if ($exists && $readable) {
        $raw = @file_get_contents(AVITO_TOKENS_FILE);
        if ($raw === false) $raw = null;
        if ($raw !== null) {
            $json = json_decode($raw, true);
            $jsonDecodeIsArray = is_array($json);
            if (!$jsonDecodeIsArray) {
                $jsonError = json_last_error_msg();
            }
        }
    }

    $t = load_tokens();
    $hasTokens = is_array($t) && !empty($t['access_token']);
    $savedAt = $hasTokens ? ($t['saved_at'] ?? null) : null;
    $expiresAt = $hasTokens ? ($t['expires_at'] ?? null) : null;
    $hasRefresh = $hasTokens && !empty($t['refresh_token']);

    $connected = false;
    if ($hasTokens) {
        $ea = (int)($t['expires_at'] ?? 0);
        $connected = ($ea === 0) ? true : ($now < ($ea - 30));
    }

    return [
        'ok' => true,
        'connected' => $connected,

        'tokens_file_exists' => $exists,
        'tokens_file_readable' => $readable,
        'tokens_file_size' => $size,

        'data_dir_writable' => is_writable(AVITO_DATA_DIR),

        'has_tokens' => $hasTokens,
        'saved_at' => $savedAt,
        'expires_at' => $expiresAt,
        'has_refresh' => $hasRefresh,

        'json_decode_is_array' => $jsonDecodeIsArray,
        'json_error' => $jsonError,

        'now' => $now,
    ];
}

$action = (string)($_GET['action'] ?? 'ui');

/**
 * status оставим публичным (как у вас сейчас), чтобы вы могли пинговать URL.
 * Остальное — под Basic Auth.
 */
if ($action === 'status') {
    json_out(status_payload());
}

// Всё остальное — под паролем
require_basic_auth();

try {
    if ($action === 'start_oauth') {
        $state = bin2hex(random_bytes(16));
        $_SESSION['avito_oauth_state'] = $state;

        $stateFile = AVITO_DATA_DIR . '/oauth_state.json';
        @file_put_contents($stateFile, json_encode([
            'state' => $state,
            'created_at' => time(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $url = oauth_authorize_url($state);

        // ВАЖНО: используем https://avito.ru/oauth (без www) — так в документации.
        json_out([
            'ok' => true,
            'auth_url' => $url,
            'state' => $state,
            'redirect_uri' => AVITO_REDIRECT_URI,
            'hint' => 'Откройте auth_url в браузере, разрешите доступ. Потом вернёт на callback и сохранит tokens.json.',
        ]);
    }
    if ($action === 'webhook_log_tail') {
    $file = AVITO_WEBHOOK_LOG;
    if (!file_exists($file)) json_out(['ok'=>true,'lines'=>[],'note'=>'no log file yet']);
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) $lines = [];
    $tail = array_slice($lines, -20);
    json_out(['ok'=>true,'lines'=>$tail]);
}

    if ($action === 'connect_cc') {
        ensure_token();
        json_out([
            'ok' => true,
            'mode' => 'client_credentials',
            'status' => status_payload(),
        ]);
    }

    if ($action === 'disconnect') {
        if (file_exists(AVITO_TOKENS_FILE)) @unlink(AVITO_TOKENS_FILE);
        json_out(['ok' => true, 'status' => status_payload()]);
    }

    if ($action === 'register_webhook') {
        ensure_token();
        $r = avito_api('POST', '/messenger/v3/webhook', [], ['url' => AVITO_WEBHOOK_URL]);
        json_out(['ok' => true, 'result' => $r]);
    }

    if ($action === 'subscriptions') {
        ensure_token();
        $r = avito_api('POST', '/messenger/v1/subscriptions', [], []);
        json_out(['ok' => true, 'result' => $r]);
    }

    if ($action === 'sync_chats') {
        ensure_token();

        $limit = (int)($_GET['limit'] ?? 100);
        $offset = (int)($_GET['offset'] ?? 0);
        if ($limit < 1) $limit = 1;
        if ($limit > 100) $limit = 100;
        if ($offset < 0) $offset = 0;

        $query = [
            'limit' => $limit,
            'offset' => $offset,
            'unread_only' => false,
            'chat_types' => ['u2i','u2u'],
        ];

        $r = avito_api('GET', '/messenger/v2/accounts/' . AVITO_ACCOUNT_USER_ID . '/chats', [
    'limit' => 100,
    'offset' => 0,
    'unread_only' => 'false',
    'chat_types' => 'u2i,u2u',
], null);
        $chats = $r['chats'] ?? [];
        $count = 0;

        if (is_array($chats)) {
            foreach ($chats as $c) {
                if (is_array($c)) {
                    upsert_chat($c);
                    $count++;
                }
            }
        }

        json_out(['ok' => true, 'saved' => $count]);
    }

    if ($action === 'sync_messages') {
        ensure_token();

        $chatId = (string)($_GET['chat_id'] ?? '');
        if ($chatId === '') json_out(['ok' => false, 'error' => 'chat_id required'], 400);

        $limit = (int)($_GET['limit'] ?? 100);
        $offset = (int)($_GET['offset'] ?? 0);
        if ($limit < 1) $limit = 1;
        if ($limit > 100) $limit = 100;
        if ($offset < 0) $offset = 0;

        $r = avito_api('GET',
            '/messenger/v3/accounts/' . AVITO_ACCOUNT_USER_ID . '/chats/' . rawurlencode($chatId) . '/messages/',
            ['limit' => $limit, 'offset' => $offset],
            null
        );

        $saved = 0;
        if (is_array($r)) {
            foreach ($r as $m) {
                if (is_array($m) && !empty($m['id'])) {
                    upsert_message($m, $chatId);
                    $saved++;
                }
            }
        }

        json_out(['ok' => true, 'saved' => $saved]);
    }

    if ($action === 'list_chats') {
        $pdo = db();
        $rows = $pdo->query("SELECT id, chat_type, item_id, created, updated, context_json, users_json, last_message_id FROM chats ORDER BY updated DESC LIMIT 200")->fetchAll();
        json_out(['ok' => true, 'chats' => $rows]);
    }

    if ($action === 'get_messages') {
        $chatId = (string)($_GET['chat_id'] ?? '');
        if ($chatId === '') json_out(['ok' => false, 'error' => 'chat_id required'], 400);

        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM messages WHERE chat_id = :cid ORDER BY created ASC LIMIT 300");
        $stmt->execute([':cid' => $chatId]);
        $rows = $stmt->fetchAll();

        json_out(['ok' => true, 'chat_id' => $chatId, 'messages' => $rows]);
    }

    if ($action === 'send') {
        ensure_token();

        $body = read_json_body();
        $chatId = (string)($_GET['chat_id'] ?? ($body['chat_id'] ?? ''));
        $text = (string)($_GET['text'] ?? ($body['text'] ?? ''));

        $chatId = trim($chatId);
        $text = trim($text);

        if ($chatId === '' || $text === '') {
            json_out(['ok' => false, 'error' => 'chat_id and text required'], 400);
        }

        if (mb_strlen($text) > 1000) {
            $text = mb_substr($text, 0, 1000);
        }

        $r = avito_api(
            'POST',
            '/messenger/v1/accounts/' . AVITO_ACCOUNT_USER_ID . '/chats/' . rawurlencode($chatId) . '/messages',
            [],
            [
                'message' => ['text' => $text],
                'type' => 'text',
            ]
        );

        // Сохраним в БД как исходящее
        if (is_array($r) && !empty($r['id'])) {
            upsert_message([
                'id' => (string)$r['id'],
                'author_id' => (int)AVITO_ACCOUNT_USER_ID,
                'direction' => 'out',
                'type' => (string)($r['type'] ?? 'text'),
                'content' => (array)($r['content'] ?? ['text' => $text]),
                'created' => isset($r['created']) ? (int)$r['created'] : time(),
                'is_read' => null,
            ], $chatId);

            // обновим last_message_id
            upsert_chat([
                'id' => $chatId,
                'updated' => isset($r['created']) ? (int)$r['created'] : time(),
                'last_message' => ['id' => (string)$r['id']],
            ]);
        }

        json_out(['ok' => true, 'result' => $r]);
    }

    // UI
    $st = status_payload();
    ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Bunchflowers — Avito Messenger</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; }
        .top { padding: 12px 16px; border-bottom: 1px solid #ddd; display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
        .wrap { display:flex; height: calc(100vh - 58px); }
        .left { width: 360px; border-right:1px solid #ddd; overflow:auto; }
        .right { flex:1; display:flex; flex-direction:column; }
        .chat { padding: 10px 12px; border-bottom:1px solid #eee; cursor:pointer; }
        .chat:hover { background:#f6f6f6; }
        .chat.active { background:#eef3ff; }
        .msgs { flex:1; padding: 12px; overflow:auto; background:#fafafa; }
        .msg { margin: 6px 0; padding: 8px 10px; border-radius: 10px; max-width: 70%; }
        .in { background:#fff; border:1px solid #e7e7e7; }
        .out { background:#dff3df; margin-left:auto; }
        .composer { display:flex; gap:8px; padding: 10px; border-top:1px solid #ddd; }
        .composer textarea { flex:1; resize:none; height:52px; padding:8px; }
        .composer button { padding: 10px 14px; }
        .btn { padding: 6px 10px; border:1px solid #aaa; background:#fff; cursor:pointer; border-radius:6px; }
        .ok { color: #0a7a0a; }
        .bad { color: #b00020; }
        .small { font-size: 12px; color: #666; }
        code { background:#f2f2f2; padding:2px 4px; border-radius:4px; }
        .left { width: 420px; border-right:1px solid #ddd; overflow:auto; background:#fff; }

.chat { padding: 12px; border-bottom:1px solid #f0f0f0; cursor:pointer; }
.chat:hover { background:#f7f7f7; }
.chat.active { background:#eef3ff; }

.chatRow { display:flex; gap:10px; }
.thumb { width:72px; height:54px; border-radius:10px; object-fit:cover; flex:0 0 auto; border:1px solid #e7e7e7; background:#fafafa; }
.thumb.placeholder { background:#f3f4f6; }

.chatMain { flex:1; min-width:0; }

.chatTop { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
.chatTitle { font-weight:700; font-size:14px; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.itemlink { color:#111; text-decoration:none; }
.itemlink:hover { text-decoration:underline; }

.chatTime { font-size:12px; color:#666; white-space:nowrap; }

.chatSub { display:flex; justify-content:space-between; gap:10px; margin-top:6px; }
.chatPrice { font-size:13px; color:#111; }
.chatId { font-size:11px; color:#888; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:220px; }

.chatUser { display:flex; align-items:center; gap:8px; margin-top:8px; }
.avatar { width:22px; height:22px; border-radius:50%; object-fit:cover; border:1px solid #e7e7e7; background:#fafafa; }
.avatar.placeholder { background:#f3f4f6; }
.chatUserName { font-size:12px; color:#444; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    </style>
</head>
<body>
<div class="top">
    <div>
        <b>Avito Messenger</b>
        <span class="small">user_id: <?= (int)AVITO_ACCOUNT_USER_ID ?></span>
    </div>

    <div>
        Статус:
        <?php if (!empty($st['connected'])): ?>
            <b class="ok">connected</b>
        <?php else: ?>
            <b class="bad">not connected</b>
        <?php endif; ?>
        <span class="small">(now: <?= (int)$st['now'] ?>)</span>
    </div>

    <button class="btn" onclick="api('connect_cc')">Получить токен (client_credentials)</button>
    <button class="btn" onclick="api('start_oauth')">OAuth URL</button>
    <button class="btn" onclick="api('register_webhook')">Подключить webhook</button>
    <button class="btn" onclick="api('sync_chats')">Sync chats</button>
    <button class="btn" onclick="api('disconnect')">Disconnect</button>

    <span class="small">Webhook URL: <code><?= htmlspecialchars(AVITO_WEBHOOK_URL, ENT_QUOTES) ?></code></span>
</div>

<div class="wrap">
    <div class="left" id="chatList"></div>

    <div class="right">
        <div class="msgs" id="msgs"></div>
        <div class="composer">
            <textarea id="text" placeholder="Сообщение..."></textarea>
            <button onclick="sendMsg()">Отправить</button>
        </div>
    </div>
</div>

<script>
let activeChatId = '';

function fmtTime(unix) {
  unix = Number(unix || 0);
  if (!unix) return '';
  const d = new Date(unix * 1000);
  const pad = (n) => String(n).padStart(2,'0');
  return `${pad(d.getDate())}.${pad(d.getMonth()+1)} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function parseJsonSafe(s) {
  try { return s ? JSON.parse(s) : null; } catch(e) { return null; }
}

function getChatMeta(row) {
  const ctx = parseJsonSafe(row.context_json) || {};
  const users = parseJsonSafe(row.users_json) || [];

  // По swagger: context.value может содержать title, price_string, images.main["140x105"], url
  const cv = (ctx && ctx.value) ? ctx.value : {};
  const itemId = row.item_id || cv.id || 0;

  // Авито в чате присылает users[] с name и public_user_profile.avatar.images["64x64"/"96x96"]
  // Иногда users_json пустой — тогда просто не показываем.
  let other = null;
  if (Array.isArray(users) && users.length) {
    // Вы — AVITO_ACCOUNT_USER_ID, собеседник — другой
    other = users.find(u => String(u.user_id || u.id || '') !== String(<?= (int)AVITO_ACCOUNT_USER_ID ?>)) || users[0];
  }

  const otherName = other?.name || 'Покупатель';
  const otherAvatar =
    other?.public_user_profile?.avatar?.images?.['64x64'] ||
    other?.public_user_profile?.avatar?.images?.['48x48'] ||
    other?.public_user_profile?.avatar?.default ||
    '';

  const title = cv.title || (itemId ? `Объявление #${itemId}` : row.id);
  const price = cv.price_string || '';
  const itemUrl = cv.url || '';

  const img =
    cv.images?.main?.['140x105'] ||
    cv.images?.main?.['128x96'] ||
    '';

  return { title, price, itemId, itemUrl, img, otherName, otherAvatar };
}

async function api(action, params = {}, method = 'GET') {
  const url = new URL(location.href);
  url.searchParams.set('action', action);
  Object.entries(params).forEach(([k,v]) => url.searchParams.set(k, v));

  let res;
  if (method === 'POST') {
    res = await fetch(url.toString(), {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(params || {})
    });
  } else {
    res = await fetch(url.toString());
  }
  const j = await res.json().catch(()=>({ok:false, error:'bad_json'}));
  if (!j.ok) alert(JSON.stringify(j, null, 2));
  else if (action === 'start_oauth' && j.auth_url) prompt('Откройте URL в браузере:', j.auth_url);
  return j;
}

async function loadChats() {
  const j = await api('list_chats');
  const list = document.getElementById('chatList');
  list.innerHTML = '';

  (j.chats || []).forEach(row => {
    const meta = getChatMeta(row);
    const div = document.createElement('div');
    div.className = 'chat' + (row.id === activeChatId ? ' active' : '');
    div.onclick = () => openChat(row.id);

    const imgHtml = meta.img
      ? `<img class="thumb" src="${escapeHtml(meta.img)}" alt="">`
      : `<div class="thumb placeholder"></div>`;

    const avatarHtml = meta.otherAvatar
      ? `<img class="avatar" src="${escapeHtml(meta.otherAvatar)}" alt="">`
      : `<div class="avatar placeholder"></div>`;

    const titleLinkOpen = meta.itemUrl ? `<a class="itemlink" href="${escapeHtml(meta.itemUrl)}" target="_blank" rel="noopener">` : '';
    const titleLinkClose = meta.itemUrl ? `</a>` : '';

    div.innerHTML = `
      <div class="chatRow">
        ${imgHtml}
        <div class="chatMain">
          <div class="chatTop">
            <div class="chatTitle">${titleLinkOpen}${escapeHtml(meta.title)}${titleLinkClose}</div>
            <div class="chatTime">${escapeHtml(fmtTime(row.updated))}</div>
          </div>
          <div class="chatSub">
            <div class="chatPrice">${escapeHtml(meta.price)}</div>
            <div class="chatId">${escapeHtml(row.id)}</div>
          </div>
          <div class="chatUser">
            ${avatarHtml}
            <div class="chatUserName">${escapeHtml(meta.otherName)}</div>
          </div>
        </div>
      </div>
    `;
    list.appendChild(div);
  });
}

async function openChat(chatId) {
  activeChatId = chatId;
  await loadChats();
  await loadMessages();
}

function renderMsg(m) {
  let content = '';
  try {
    const cj = m.content_json ? JSON.parse(m.content_json) : {};
    if (cj.text) content = cj.text;
    else if (cj.link && cj.link.url) content = '[link] ' + cj.link.url;
    else if (cj.image && cj.image.sizes) content = '[image]';
    else if (cj.voice && cj.voice.voice_id) content = '[voice] ' + cj.voice.voice_id;
    else content = JSON.stringify(cj);
  } catch(e) {
    content = m.content_json || '';
  }

  const dir = (m.direction === 'out') ? 'out' : 'in';
  const div = document.createElement('div');
  div.className = 'msg ' + dir;
  div.innerHTML = '<div>' + escapeHtml(content) + '</div>'
    + '<div class="small">' + escapeHtml(fmtTime(m.created)) + '</div>';
  return div;
}

async function loadMessages() {
  if (!activeChatId) return;
  await api('sync_messages', {chat_id: activeChatId, limit: 100, offset: 0});
  const j = await api('get_messages', {chat_id: activeChatId});
  const box = document.getElementById('msgs');
  box.innerHTML = '';
  (j.messages || []).forEach(m => box.appendChild(renderMsg(m)));
  box.scrollTop = box.scrollHeight;
}

async function sendMsg() {
  if (!activeChatId) { alert('Выберите чат'); return; }
  const ta = document.getElementById('text');
  const text = ta.value.trim();
  if (!text) return;

  ta.value = '';
  await api('send', {chat_id: activeChatId, text: text}, 'POST');
  await loadMessages();
}

function escapeHtml(s) {
  return String(s)
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}

loadChats();
setInterval(loadChats, 8000);
</script>

</body>
</html>
<?php
    exit;

} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
