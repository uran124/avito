<?php
declare(strict_types=1);

require_once __DIR__ . '/../avito_config.php';
require_basic_auth();

$dbPath = AVITO_DB_FILE;

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function q(string $key, $default = null) {
    return $_GET[$key] ?? $default;
}

$pdo = db();

$action = (string)q('action', 'tables');
$table  = (string)q('table', '');
$limit  = (int)q('limit', 50);
$offset = (int)q('offset', 0);

if ($limit < 1) $limit = 1;
if ($limit > 500) $limit = 500;
if ($offset < 0) $offset = 0;

function list_tables(PDO $pdo): array {
    $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll();
    return array_map(fn($r) => (string)$r['name'], $rows);
}

function table_schema(PDO $pdo, string $table): array {
    $stmt = $pdo->prepare("PRAGMA table_info(" . $table . ")");
    $stmt->execute();
    return $stmt->fetchAll();
}

function table_count(PDO $pdo, string $table): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM " . $table);
    $stmt->execute();
    $r = $stmt->fetch();
    return (int)($r['c'] ?? 0);
}

function table_rows(PDO $pdo, string $table, int $limit, int $offset): array {
    $stmt = $pdo->prepare("SELECT * FROM " . $table . " ORDER BY rowid DESC LIMIT :lim OFFSET :off");
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

$tables = list_tables($pdo);

?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Avito SQLite viewer</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:Arial, sans-serif; margin:0; background:#f6f7f9;}
    header{padding:12px 16px; background:#111827; color:#fff; display:flex; gap:12px; flex-wrap:wrap; align-items:center;}
    header a{color:#fff; text-decoration:none; border:1px solid rgba(255,255,255,.25); padding:6px 10px; border-radius:8px;}
    .wrap{display:flex; height:calc(100vh - 60px);}
    .left{width:280px; background:#fff; border-right:1px solid #e5e7eb; overflow:auto;}
    .right{flex:1; overflow:auto; padding:14px;}
    .tbl{padding:10px 12px; border-bottom:1px solid #f0f0f0;}
    .tbl a{text-decoration:none; color:#111827; display:block;}
    .tbl.active{background:#eef2ff;}
    .meta{font-size:12px; color:#6b7280; margin-top:4px;}
    table{border-collapse:collapse; width:100%; background:#fff;}
    th,td{border:1px solid #e5e7eb; padding:8px; font-size:13px; vertical-align:top;}
    th{background:#f3f4f6; position:sticky; top:0;}
    code{background:#f3f4f6; padding:2px 4px; border-radius:4px;}
    .controls{display:flex; gap:10px; flex-wrap:wrap; margin:0 0 12px 0;}
    .controls a{background:#111827; color:#fff; padding:8px 10px; border-radius:8px; text-decoration:none;}
    .note{background:#fff; border:1px solid #e5e7eb; padding:10px 12px; border-radius:10px; margin-bottom:12px;}
    .small{font-size:12px; color:#6b7280;}
  </style>
</head>
<body>
<header>
  <div><b>Avito SQLite viewer</b></div>
  <div class="small">DB: <code><?=h($dbPath)?></code></div>
  <a href="<?=h(basename(__FILE__))?>">Таблицы</a>
  <a href="<?=h('../messenger.php')?>">Messenger</a>
</header>

<div class="wrap">
  <div class="left">
    <?php foreach ($tables as $t): ?>
      <?php $active = ($t === $table) ? 'active' : ''; ?>
      <?php $cnt = table_count($pdo, $t); ?>
      <div class="tbl <?=$active?>">
        <a href="?action=view&table=<?=h($t)?>">
          <div><b><?=h($t)?></b></div>
          <div class="meta">rows: <?= (int)$cnt ?></div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="right">
    <?php if ($table === '' || !in_array($table, $tables, true)): ?>
      <div class="note">
        Выберите таблицу слева. Полезное:
        <ul>
          <li><code>webhook_events</code> — сырые входящие вебхуки</li>
          <li><code>messages</code> — сохранённые сообщения</li>
          <li><code>chats</code> — чаты</li>
        </ul>
      </div>
    <?php else: ?>
      <?php
        $cnt = table_count($pdo, $table);
        $schema = table_schema($pdo, $table);
        $rows = table_rows($pdo, $table, $limit, $offset);
        $nextOffset = $offset + $limit;
        $prevOffset = $offset - $limit;
        if ($prevOffset < 0) $prevOffset = 0;
      ?>

      <div class="note">
        <div><b>Таблица:</b> <code><?=h($table)?></code></div>
        <div class="small">Всего строк: <?= (int)$cnt ?> • limit <?= (int)$limit ?> • offset <?= (int)$offset ?></div>
      </div>

      <div class="controls">
        <a href="?action=view&table=<?=h($table)?>&limit=<?= (int)$limit ?>&offset=<?= (int)$prevOffset ?>">← Назад</a>
        <a href="?action=view&table=<?=h($table)?>&limit=<?= (int)$limit ?>&offset=<?= (int)$nextOffset ?>">Вперёд →</a>
        <a href="?action=view&table=<?=h($table)?>&limit=50&offset=0">Первые 50</a>
        <a href="?action=view&table=<?=h($table)?>&limit=200&offset=0">Первые 200</a>
      </div>

      <div class="note">
        <b>Схема (PRAGMA table_info):</b>
        <table>
          <thead><tr><th>cid</th><th>name</th><th>type</th><th>notnull</th><th>dflt_value</th><th>pk</th></tr></thead>
          <tbody>
          <?php foreach ($schema as $col): ?>
            <tr>
              <td><?=h((string)$col['cid'])?></td>
              <td><b><?=h((string)$col['name'])?></b></td>
              <td><?=h((string)$col['type'])?></td>
              <td><?=h((string)$col['notnull'])?></td>
              <td><?=h((string)($col['dflt_value'] ?? ''))?></td>
              <td><?=h((string)$col['pk'])?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <table>
        <thead>
          <tr>
            <?php if (!empty($rows)): ?>
              <?php foreach (array_keys($rows[0]) as $k): ?>
                <th><?=h((string)$k)?></th>
              <?php endforeach; ?>
            <?php else: ?>
              <th>Нет данных</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <?php foreach ($r as $v): ?>
                <?php
                  if (is_null($v)) $s = 'NULL';
                  else $s = (string)$v;
                  if (strlen($s) > 2000) $s = substr($s, 0, 2000) . ' …';
                ?>
                <td><pre style="margin:0; white-space:pre-wrap;"><?=h($s)?></pre></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

    <?php endif; ?>
  </div>
</div>
</body>
</html>
