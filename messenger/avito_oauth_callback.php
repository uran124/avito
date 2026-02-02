<?php
declare(strict_types=1);

require_once __DIR__ . '/avito_config.php';

header('Content-Type: text/html; charset=utf-8');

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$err   = (string)($_GET['error'] ?? '');
$code  = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');

// OAuth state check (мягкий)
$stateFile = AVITO_DATA_DIR . '/oauth_state.json';
$stateOk = false;

if (isset($_SESSION) === false) {
    // если сессии выключены — просто продолжаем
} else {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (!empty($_SESSION['avito_oauth_state']) && hash_equals((string)$_SESSION['avito_oauth_state'], $state)) {
        $stateOk = true;
    }
}

if (!$stateOk && file_exists($stateFile)) {
    $raw = @file_get_contents($stateFile);
    $j = $raw ? json_decode($raw, true) : null;
    if (is_array($j) && !empty($j['state']) && is_string($j['state']) && hash_equals($j['state'], $state)) {
        // опционально проверим TTL
        $createdAt = (int)($j['created_at'] ?? 0);
        if ($createdAt === 0 || (time() - $createdAt) < 1800) {
            $stateOk = true;
        }
    }
}

echo "<!doctype html><html><head><meta charset='utf-8'><title>Avito OAuth callback</title></head><body style='font-family:Arial,sans-serif; padding:20px'>";

if ($err !== '') {
    echo "<h2>OAuth ошибка</h2>";
    echo "<p><b>error:</b> " . h($err) . "</p>";
    echo "<p><b>error_description:</b> " . h((string)($_GET['error_description'] ?? '')) . "</p>";
    echo "<p><a href='messenger.php'>Открыть messenger</a></p>";
    echo "</body></html>";
    exit;
}

if ($code === '') {
    echo "<h2>Нет параметра code</h2>";
    echo "<pre>" . h(json_encode($_GET, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) . "</pre>";
    echo "<p><a href='messenger.php'>Открыть messenger</a></p>";
    echo "</body></html>";
    exit;
}

try {
    // если state не сошёлся — не блокируем, но логируем
    if (!$stateOk) {
        error_log("OAuth state mismatch. state={$state}");
    }

    $t = exchange_code_for_token($code);

    // попробуем сразу зарегистрировать вебхук (не критично)
    $webhookResult = null;
    try {
        $webhookResult = avito_api('POST', '/messenger/v3/webhook', [], ['url' => AVITO_WEBHOOK_URL]);
    } catch (Throwable $e) {
        $webhookResult = ['ok' => false, 'error' => $e->getMessage()];
    }

    echo "<h2>OK: токен сохранён</h2>";
    echo "<p>tokens.json: " . h(AVITO_TOKENS_FILE) . "</p>";
    echo "<p><b>access_token:</b> сохранён</p>";
    echo "<p><b>expires_at:</b> " . h((string)($t['expires_at'] ?? '')) . "</p>";
    echo "<p><b>refresh_token:</b> " . h(!empty($t['refresh_token']) ? 'yes' : 'no') . "</p>";

    echo "<h3>Webhook</h3>";
    echo "<pre>" . h(json_encode($webhookResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) . "</pre>";

    echo "<p><a href='messenger.php'>Открыть messenger</a></p>";
    echo "<p><a href='messenger.php?action=status'>Проверить status</a></p>";

} catch (Throwable $e) {
    echo "<h2>Ошибка обмена code → token</h2>";
    echo "<pre>" . h($e->getMessage()) . "</pre>";
    echo "<p><a href='messenger.php'>Открыть messenger</a></p>";
}

echo "</body></html>";
