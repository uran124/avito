<?php
declare(strict_types=1);

/**
 * avito_config.php
 * Общий конфиг + OAuth + API + SQLite.
 * ВАЖНО: по возможности вынесите секреты в env-переменные.
 */

// Не даём открыть конфиг напрямую
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Europe/Moscow'); // или ваша
ini_set('log_errors', '1');

define('AVITO_DATA_DIR', __DIR__ . '/data');
if (!is_dir(AVITO_DATA_DIR)) {
    @mkdir(AVITO_DATA_DIR, 0775, true);
}
ini_set('error_log', AVITO_DATA_DIR . '/error.log');

/** ========= ВАШИ НАСТРОЙКИ ========= */

// Лучше: хранить в env, а тут читать
define('AVITO_CLIENT_ID', getenv('AVITO_CLIENT_ID') ?: 'pVaQ6UXXz1KOQEZuCMTY');
define('AVITO_CLIENT_SECRET', getenv('AVITO_CLIENT_SECRET') ?: 'Mn9-d3RopfABBi3Rt3kIKEcKyQg-Ztu3jrXSEuBU');

define('AVITO_REDIRECT_URI', 'https://bunchflowers.ru/avito/avito_oauth_callback.php');

// Ваш Avito user_id (аккаунт, на который подписываем вебхуки и от имени которого шлём сообщения)
define('AVITO_ACCOUNT_USER_ID', 184792616);

// Нужные scope для мессенджера
define('AVITO_SCOPE', 'messenger:read messenger:write');

// URL вашего webhook (куда Авито будет слать события)
define('AVITO_WEBHOOK_URL', 'https://bunchflowers.ru/avito/avito_webhook.php');

// Basic Auth для messenger.php (интерфейс оператора)
define('MESSENGER_BASIC_USER', getenv('MESSENGER_BASIC_USER') ?: 'admin');
define('MESSENGER_BASIC_PASS', getenv('MESSENGER_BASIC_PASS') ?: '4455');

// Если захотите пытаться проверять подпись webhook (заголовок x-avito-messenger-signature),
// включите true и задайте секрет. По умолчанию выключено (т.к. алгоритм/секрет у Авито могут отличаться).
define('WEBHOOK_VERIFY_SIGNATURE', false);
define('WEBHOOK_SIGNATURE_SECRET', getenv('AVITO_WEBHOOK_SECRET') ?: '');

/** ========= КОНЕЦ НАСТРОЕК ========= */

define('AVITO_OAUTH_AUTHORIZE_URL', 'https://avito.ru/oauth');
define('AVITO_OAUTH_TOKEN_URL', 'https://api.avito.ru/token');
define('AVITO_API_BASE', 'https://api.avito.ru');

define('AVITO_TOKENS_FILE', AVITO_DATA_DIR . '/tokens.json');
define('AVITO_DB_FILE', AVITO_DATA_DIR . '/avito.sqlite');
define('AVITO_WEBHOOK_LOG', AVITO_DATA_DIR . '/webhook.log');

/** ----------------- Basic Auth ----------------- */
function require_basic_auth(): void {
    $u = $_SERVER['PHP_AUTH_USER'] ?? '';
    $p = $_SERVER['PHP_AUTH_PW'] ?? '';

    if ($u !== MESSENGER_BASIC_USER || $p !== MESSENGER_BASIC_PASS) {
        header('WWW-Authenticate: Basic realm="Bunchflowers Avito Messenger"');
        http_response_code(401);
        echo "Auth required";
        exit;
    }
}

/** ----------------- SQLite ----------------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $pdo = new PDO('sqlite:' . AVITO_DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Миграции
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chats (
            id TEXT PRIMARY KEY,
            chat_type TEXT,
            item_id INTEGER,
            created INTEGER,
            updated INTEGER,
            context_json TEXT,
            users_json TEXT,
            last_message_id TEXT
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS messages (
            id TEXT PRIMARY KEY,
            chat_id TEXT,
            author_id INTEGER,
            direction TEXT,
            type TEXT,
            content_json TEXT,
            created INTEGER,
            is_read INTEGER,
            raw_json TEXT
        );
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_messages_chat_created ON messages(chat_id, created);");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS webhook_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            received_at INTEGER,
            headers_json TEXT,
            raw_body TEXT
        );
    ");

    return $pdo;
}

/** ----------------- Tokens storage ----------------- */
function load_tokens(): ?array {
    if (!file_exists(AVITO_TOKENS_FILE)) return null;
    $raw = file_get_contents(AVITO_TOKENS_FILE);
    if ($raw === false) return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function save_tokens(array $tokens): void {
    $tokens['saved_at'] = time();
    file_put_contents(AVITO_TOKENS_FILE, json_encode($tokens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

/** ----------------- OAuth helpers ----------------- */
function oauth_authorize_url(string $state): string {
    $params = [
        'response_type' => 'code',
        'client_id'     => AVITO_CLIENT_ID,
        'redirect_uri'  => AVITO_REDIRECT_URI,
        'scope'         => AVITO_SCOPE,
        'state'         => $state,
    ];
    return AVITO_OAUTH_AUTHORIZE_URL . '?' . http_build_query($params);
}

function token_request(array $postFields): array {
    $ch = curl_init(AVITO_OAUTH_TOKEN_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException("Token request failed: $err");
    }

    $json = json_decode($resp, true);
    if (!is_array($json)) {
        throw new RuntimeException("Token response not JSON (HTTP $code): $resp");
    }
    if ($code >= 400) {
        throw new RuntimeException("Token HTTP $code: " . $resp);
    }
    return $json;
}

function exchange_code_for_token(string $code): array {
    $t = token_request([
        'grant_type'    => 'authorization_code',
        'client_id'     => AVITO_CLIENT_ID,
        'client_secret' => AVITO_CLIENT_SECRET,
        'code'          => $code,
        'redirect_uri'  => AVITO_REDIRECT_URI,
    ]);

    // Нормализуем время истечения
    if (isset($t['expires_in'])) {
        $t['expires_at'] = time() + (int)$t['expires_in'];
    }
    save_tokens($t);
    return $t;
}

function refresh_access_token(string $refreshToken): array {
    $t = token_request([
        'grant_type'    => 'refresh_token',
        'client_id'     => AVITO_CLIENT_ID,
        'client_secret' => AVITO_CLIENT_SECRET,
        'refresh_token' => $refreshToken,
    ]);

    if (isset($t['expires_in'])) {
        $t['expires_at'] = time() + (int)$t['expires_in'];
    }

    // если refresh_token не вернули — оставим старый
    if (!isset($t['refresh_token'])) {
        $t['refresh_token'] = $refreshToken;
    }

    save_tokens($t);
    return $t;
}

/**
 * Возвращает валидный access_token (обновляет при необходимости).
 * Если токена нет — вернёт null.
 */
function get_access_token(): ?string {
    $t = load_tokens();
    if (!$t || empty($t['access_token'])) return null;

    $expiresAt = (int)($t['expires_at'] ?? 0);
    $refresh   = (string)($t['refresh_token'] ?? '');

    // обновляем за 60 секунд до истечения
    if ($expiresAt > 0 && time() >= ($expiresAt - 60)) {
        if ($refresh !== '') {
            $t = refresh_access_token($refresh);
        } else {
            return null;
        }
    }
    return (string)$t['access_token'];
}

/** ----------------- Avito API request ----------------- */
function avito_api(string $method, string $path, array $query = [], ?array $jsonBody = null): array {
    $token = get_access_token();
    if (!$token) {
        throw new RuntimeException("No access token. Need OAuth.");
    }

    $url = rtrim(AVITO_API_BASE, '/') . '/' . ltrim($path, '/');
    if (!empty($query)) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];

    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException("Avito API request failed: $err");
    }

    $json = json_decode($resp, true);
    if ($code >= 400) {
        $msg = is_array($json)
            ? json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : trim($resp);
        if ($msg === '') $msg = '(empty response body)';
        throw new RuntimeException("Avito API HTTP $code: " . $msg);
    }
    return is_array($json) ? $json : [];
}

/** ----------------- DB upsert helpers ----------------- */
function upsert_chat(array $chat): void {
    $pdo = db();

    $id = (string)($chat['id'] ?? '');
    if ($id === '') return;

    $stmt = $pdo->prepare("
        INSERT INTO chats (id, chat_type, item_id, created, updated, context_json, users_json, last_message_id)
        VALUES (:id, :chat_type, :item_id, :created, :updated, :context_json, :users_json, :last_message_id)
        ON CONFLICT(id) DO UPDATE SET
            chat_type=excluded.chat_type,
            item_id=excluded.item_id,
            created=excluded.created,
            updated=excluded.updated,
            context_json=excluded.context_json,
            users_json=excluded.users_json,
            last_message_id=excluded.last_message_id
    ");

    $context = $chat['context'] ?? null;
    $users   = $chat['users'] ?? null;
    $lastMsg = $chat['last_message']['id'] ?? ($chat['last_message_id'] ?? null);

    $stmt->execute([
        ':id'             => $id,
        ':chat_type'      => (string)($chat['chat_type'] ?? ($chat['context']['type'] ?? '')),
        ':item_id'        => isset($chat['item_id']) ? (int)$chat['item_id'] : (isset($chat['context']['value']['id']) ? (int)$chat['context']['value']['id'] : null),
        ':created'        => isset($chat['created']) ? (int)$chat['created'] : null,
        ':updated'        => isset($chat['updated']) ? (int)$chat['updated'] : null,
        ':context_json'   => $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':users_json'     => $users ? json_encode($users, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':last_message_id'=> $lastMsg ? (string)$lastMsg : null,
    ]);
}

function upsert_message(array $msg, string $chatId): void {
    $pdo = db();

    $id = (string)($msg['id'] ?? '');
    if ($id === '') return;

    $stmt = $pdo->prepare("
        INSERT INTO messages (id, chat_id, author_id, direction, type, content_json, created, is_read, raw_json)
        VALUES (:id, :chat_id, :author_id, :direction, :type, :content_json, :created, :is_read, :raw_json)
        ON CONFLICT(id) DO UPDATE SET
            chat_id=excluded.chat_id,
            author_id=excluded.author_id,
            direction=excluded.direction,
            type=excluded.type,
            content_json=excluded.content_json,
            created=excluded.created,
            is_read=excluded.is_read,
            raw_json=excluded.raw_json
    ");

    $stmt->execute([
        ':id'         => $id,
        ':chat_id'    => $chatId,
        ':author_id'  => isset($msg['author_id']) ? (int)$msg['author_id'] : null,
        ':direction'  => (string)($msg['direction'] ?? ''),
        ':type'       => (string)($msg['type'] ?? ''),
        ':content_json' => isset($msg['content']) ? json_encode($msg['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':created'    => isset($msg['created']) ? (int)$msg['created'] : null,
        ':is_read'    => isset($msg['is_read']) ? ((int)(bool)$msg['is_read']) : null,
        ':raw_json'   => json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function log_webhook_event(array $headers, string $rawBody): void {
    // быстро пишем файл-лог
    $line = '[' . date('c') . '] ' . json_encode([
        'headers' => $headers,
        'body'    => $rawBody
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    file_put_contents(AVITO_WEBHOOK_LOG, $line, FILE_APPEND);

    // и в БД (для диагностики)
    try {
        $pdo = db();
        $stmt = $pdo->prepare("INSERT INTO webhook_events (received_at, headers_json, raw_body) VALUES (:t,:h,:b)");
        $stmt->execute([
            ':t' => time(),
            ':h' => json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':b' => $rawBody
        ]);
    } catch (Throwable $e) {
        // не валим webhook из-за логов
        error_log("webhook log db error: " . $e->getMessage());
    }
}
