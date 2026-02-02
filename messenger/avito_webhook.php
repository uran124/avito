<?php
declare(strict_types=1);

require_once __DIR__ . '/avito_config.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_headers_assoc(): array {
    $h = [];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            $h[(string)$k] = (string)$v;
        }
    } else {
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = str_replace('_', '-', substr($k, 5));
                $h[$name] = (string)$v;
            }
        }
    }
    return $h;
}

// Быстро принять тело
$raw = file_get_contents('php://input');
if ($raw === false) $raw = '';

$headers = get_headers_assoc();

// Логируем всегда (для диагностики)
log_webhook_event($headers, $raw);

// Опциональная проверка подписи (если включите в конфиге)
if (WEBHOOK_VERIFY_SIGNATURE) {
    $sig = $headers['X-Avito-Messenger-Signature'] ?? $headers['x-avito-messenger-signature'] ?? '';
    if ($sig === '' || WEBHOOK_SIGNATURE_SECRET === '') {
        // не валим вебхук, но отметим
        error_log("Webhook signature enabled but signature/secret missing");
    } else {
        // ВНИМАНИЕ: точный алгоритм у Авито может отличаться.
        // Это "best guess" — HMAC SHA256 hex.
        $calc = hash_hmac('sha256', $raw, WEBHOOK_SIGNATURE_SECRET);
        if (!hash_equals($calc, (string)$sig)) {
            error_log("Webhook signature mismatch");
            // Можно возвращать 200, чтобы Авито не ретраило бесконечно, но вы увидите это в логах
        }
    }
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    // Авито требует 200 OK очень быстро. Ошибку просто логируем.
    json_out(['ok' => true]);
}

/**
 * Варианты:
 * 1) root = { id, payload:{type,value}, timestamp, version }
 * 2) root = { type, value }  (на всякий случай)
 */
$eventType = '';
$value = null;

if (isset($payload['payload']) && is_array($payload['payload'])) {
    $eventType = (string)($payload['payload']['type'] ?? '');
    $value = $payload['payload']['value'] ?? null;
} else {
    $eventType = (string)($payload['type'] ?? '');
    $value = $payload['value'] ?? null;
}

// Нас интересует сообщение
if (!is_array($value)) {
    json_out(['ok' => true]);
}

if ($eventType !== '' && $eventType !== 'message') {
    // другие типы просто принимаем
    json_out(['ok' => true]);
}

$chatId  = (string)($value['chat_id'] ?? '');
$msgId   = (string)($value['id'] ?? '');
$author  = isset($value['author_id']) ? (int)$value['author_id'] : null;
$created = isset($value['created']) ? (int)$value['created'] : time();
$msgType = (string)($value['type'] ?? '');
$content = $value['content'] ?? null;

// direction вычислим грубо
$direction = ($author !== null && $author === (int)AVITO_ACCOUNT_USER_ID) ? 'out' : 'in';

if ($chatId !== '' && $msgId !== '') {
    // upsert chat (минимально)
    upsert_chat([
        'id' => $chatId,
        'chat_type' => (string)($value['chat_type'] ?? ''),
        'item_id' => isset($value['item_id']) ? (int)$value['item_id'] : null,
        'created' => $created,
        'updated' => $created,
        'context' => [
            'type' => (string)($value['chat_type'] ?? ''),
            'value' => [
                'id' => $value['item_id'] ?? null,
            ],
        ],
        'users' => [],
        'last_message' => ['id' => $msgId],
    ]);

    // upsert message
    upsert_message([
        'id' => $msgId,
        'author_id' => $author,
        'direction' => $direction,
        'type' => $msgType,
        'content' => is_array($content) ? $content : [],
        'created' => $created,
        'is_read' => isset($value['read']) ? true : false,
    ], $chatId);
}

json_out(['ok' => true]);
