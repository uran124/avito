<?php
// /avito/test_webhook.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

avito_bootstrap_dirs();

/**
 * Тестовый webhook от Avito (примерная структура)
 */
$testWebhook = [
    'payload' => [
        'value' => [
            'author_id' => 12345678,
            'chat_id'   => 'test_chat_' . time(),
            'content'   => [
                'text' => 'Тестовое сообщение для проверки webhook',
            ],
            'created'   => time(),
            'direction' => 'in',
            'id'        => 'test_msg_' . uniqid('', true),
            'type'      => 'text',
        ],
    ],
];

/**
 * ---------- AJAX API (ВАЖНО: ДО любого HTML вывода) ----------
 */
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $action = (string)$_GET['action'];

    if ($action === 'check_logs') {
        $logs = [
            'webhook_raw.log',
            'in.log',
            'out.log',
            'webhook_errors.log',
            'test.log',
        ];

        $result = [];
        foreach ($logs as $log) {
            $path = rtrim(AVITO_LOG_DIR, '/\\') . '/' . $log;
            $exists = file_exists($path);

            $result[$log] = [
                'exists'     => $exists,
                'size'       => $exists ? (int)filesize($path) : 0,
                'last_lines' => $exists ? tail_lines_simple($path, 3) : null,
            ];
        }

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'create_test_log') {
        $testFile = rtrim(AVITO_LOG_DIR, '/\\') . '/test.log';
        $testContent = '[' . date('Y-m-d H:i:s') . '] Test log entry from test_webhook.php' . PHP_EOL;

        $success = @file_put_contents($testFile, $testContent, FILE_APPEND);

        echo json_encode([
            'success' => (bool)$success,
            'file'    => $testFile,
            'error'   => $success ? null : (error_get_last()['message'] ?? 'Unknown error'),
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    if ($action === 'check_permissions') {
        $dir = (string)AVITO_LOG_DIR;

        echo json_encode([
            'log_dir'      => $dir,
            'exists'       => is_dir($dir),
            'readable'     => is_readable($dir),
            'writable'     => is_writable($dir),
            'permissions'  => is_dir($dir) ? substr(sprintf('%o', fileperms($dir)), -4) : 'N/A',
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    echo json_encode(['error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * ---------- HTML (после API) ----------
 */
header('Content-Type: text/html; charset=utf-8');

$testJsonPretty = json_encode($testWebhook, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

/**
 * Простой tail последних строк файла
 */
function tail_lines_simple(string $file, int $lines = 3): string {
    if (!is_file($file)) return '';
    $content = file_get_contents($file);
    if ($content === false) return '';
    $linesArray = explode("\n", trim($content));
    $linesArray = array_slice($linesArray, -$lines);
    return implode("\n", $linesArray);
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тест Webhook</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            max-width: 900px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        h1 { margin-top: 0; color: #111; }
        h2 { color: #333; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        .test-btn {
            background: #0066cc;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            margin-right: 10px;
        }
        .test-btn:hover { background: #0052a3; }
        .test-btn.secondary { background: #666; }
        .test-btn.secondary:hover { background: #444; }
        .result {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 16px;
            margin-top: 16px;
            font-family: monospace;
            font-size: 13px;
            white-space: pre-wrap;
            max-height: 400px;
            overflow-y: auto;
        }
        .success { color: #0a7a2a; font-weight: bold; }
        .error { color: #b00020; font-weight: bold; }
        .warning { color: #8a5b00; font-weight: bold; }
        pre {
            background: #f5f5f5;
            padding: 12px;
            border-radius: 6px;
            overflow-x: auto;
        }
        .checklist {
            list-style: none;
            padding: 0;
        }
        .checklist li {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .checklist li:last-child { border-bottom: none; }
    </style>
</head>
<body>
    <div class="card">
        <h1>🔍 Диагностика Webhook</h1>
        <p>Эта страница поможет найти проблему с webhook от Avito.</p>
    </div>

    <div class="card">
        <h2>1. Проверка доступности webhook</h2>
        <p>Проверим, доступен ли файл webhook.php из интернета.</p>
        <button class="test-btn" onclick="testWebhookAccess()">Проверить доступность</button>
        <div id="access-result" class="result" style="display:none;"></div>
    </div>

    <div class="card">
        <h2>2. Тест обработки webhook</h2>
        <p>Отправим тестовый webhook напрямую на ваш сервер (минуя Avito).</p>
        <button class="test-btn" onclick="testWebhookProcessing()">Отправить тестовый webhook</button>
        <div id="processing-result" class="result" style="display:none;"></div>
    </div>

    <div class="card">
        <h2>3. Проверка логов</h2>
        <p>Проверим что логи создаются и записываются.</p>
        <button class="test-btn" onclick="checkLogs()">Проверить логи</button>
        <button class="test-btn secondary" onclick="createTestLog()">Создать тестовую запись</button>
        <div id="logs-result" class="result" style="display:none;"></div>
    </div>

    <div class="card">
        <h2>4. Проверка прав доступа</h2>
        <p>Проверим права на запись в директорию логов.</p>
        <button class="test-btn" onclick="checkPermissions()">Проверить права</button>
        <div id="permissions-result" class="result" style="display:none;"></div>
    </div>

    <div class="card">
        <h2>5. Примерная структура webhook от Avito</h2>
        <p>Так выглядит реальный webhook от Avito:</p>
        <pre><?= htmlspecialchars($testJsonPretty ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
    </div>

    <div class="card">
        <h2>Чеклист диагностики</h2>
        <ul class="checklist">
            <li>✓ Webhook зарегистрирован в Avito</li>
            <li>✓ User ID заполнен (184792616)</li>
            <li>✓ Access Token есть</li>
            <li id="check-access">⏳ Файл webhook.php доступен из интернета</li>
            <li id="check-processing">⏳ Webhook обрабатывается корректно</li>
            <li id="check-logs">⏳ Логи создаются и пишутся</li>
            <li id="check-permissions">⏳ Права на запись в логи есть</li>
        </ul>
    </div>

    <script>
    function testWebhookAccess() {
        const result = document.getElementById("access-result");
        result.style.display = "block";
        result.textContent = "Проверяем доступность...";

        fetch("webhook.php", { method: "GET" })
        .then(response => {
            if (response.ok || response.status === 400) {
                result.textContent =
                    "✓ Webhook доступен\n" +
                    "HTTP Code: " + response.status + "\n" +
                    "Файл существует и отвечает на запросы.";
                document.getElementById("check-access").textContent = "✓ Файл webhook.php доступен из интернета";
            } else {
                result.textContent =
                    "✗ Webhook недоступен\n" +
                    "HTTP Code: " + response.status + "\n" +
                    "Возможно, проблема с конфигурацией сервера.";
                document.getElementById("check-access").textContent = "✗ Файл webhook.php недоступен";
            }
        })
        .catch(error => {
            result.textContent = "✗ Ошибка\n" + String(error);
            document.getElementById("check-access").textContent = "✗ Ошибка доступа к webhook.php";
        });
    }

    function testWebhookProcessing() {
        const result = document.getElementById("processing-result");
        result.style.display = "block";
        result.textContent = "Отправляем тестовый webhook...";

        const testData = <?= json_encode($testWebhook, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        fetch("webhook.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(testData)
        })
        .then(response => response.text())
        .then(text => {
            result.textContent =
                "✓ Ответ получен\n" +
                "Response: " + text + "\n\n" +
                "Теперь проверьте логи в /avito/_private/logs/";
            document.getElementById("check-processing").textContent = "✓ Webhook обрабатывается";

            setTimeout(checkLogs, 2000);
        })
        .catch(error => {
            result.textContent = "✗ Ошибка\n" + String(error);
            document.getElementById("check-processing").textContent = "✗ Ошибка обработки webhook";
        });
    }

    function checkLogs() {
        const result = document.getElementById("logs-result");
        result.style.display = "block";
        result.textContent = "Проверяем логи...";

        fetch("test_webhook.php?action=check_logs")
        .then(response => response.json())
        .then(data => {
            let text = "";
            let allOk = true;

            for (let log in data) {
                if (data[log].exists) {
                    text += "✓ " + log + ": " + data[log].size + " байт\n";
                    if (data[log].last_lines) {
                        text += "  Последние строки:\n";
                        text += "  " + String(data[log].last_lines).replace(/\n/g, "\n  ") + "\n";
                    }
                } else {
                    text += "✗ " + log + ": не существует\n";
                    allOk = false;
                }
            }

            result.textContent = text;

            if (allOk && data["webhook_raw.log"] && data["webhook_raw.log"].size > 0) {
                document.getElementById("check-logs").textContent = "✓ Логи создаются и пишутся";
            } else {
                document.getElementById("check-logs").textContent = "⚠ Логи пустые или не создаются";
            }
        })
        .catch(error => {
            result.textContent = "✗ Ошибка\n" + String(error);
        });
    }

    function createTestLog() {
        const result = document.getElementById("logs-result");
        result.style.display = "block";
        result.textContent = "Создаём тестовую запись...";

        fetch("test_webhook.php?action=create_test_log")
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                result.textContent =
                    "✓ Запись создана\n" +
                    "Файл: " + data.file + "\n" +
                    "Проверьте: /avito/_private/logs/test.log";
                checkLogs();
            } else {
                result.textContent = "✗ Ошибка\n" + (data.error || "Unknown error");
            }
        })
        .catch(error => {
            result.textContent = "✗ Ошибка\n" + String(error);
        });
    }

    function checkPermissions() {
        const result = document.getElementById("permissions-result");
        result.style.display = "block";
        result.textContent = "Проверяем права...";

        fetch("test_webhook.php?action=check_permissions")
        .then(response => response.json())
        .then(data => {
            let text = "";
            text += "Директория логов: " + data.log_dir + "\n";
            text += "Существует: " + (data.exists ? "✓ Да" : "✗ Нет") + "\n";
            text += "Читаемая: " + (data.readable ? "✓ Да" : "✗ Нет") + "\n";
            text += "Записываемая: " + (data.writable ? "✓ Да" : "✗ Нет") + "\n";
            text += "Права: " + data.permissions + "\n";

            if (data.writable) {
                text += "\n✓ Права в порядке";
                document.getElementById("check-permissions").textContent = "✓ Права на запись в логи есть";
            } else {
                text += "\n✗ Нет прав на запись!\n";
                text += "Выполните: chmod 755 " + data.log_dir;
                document.getElementById("check-permissions").textContent = "✗ Нет прав на запись";
            }

            result.textContent = text;
        })
        .catch(error => {
            result.textContent = "✗ Ошибка\n" + String(error);
        });
    }
    </script>
</body>
</html>
