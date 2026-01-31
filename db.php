<?php
// /avito/db.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function avito_db(): ?PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $cfg = avito_get_config();
  if (empty($cfg['mysql_enabled'])) return null;

  $host = (string)($cfg['mysql_host'] ?? '');
  $port = (int)($cfg['mysql_port'] ?? 3306);
  $db   = (string)($cfg['mysql_db'] ?? '');
  $user = (string)($cfg['mysql_user'] ?? '');
  $pass = (string)($cfg['mysql_pass'] ?? '');

  if ($host === '' || $db === '' || $user === '') return null;

  $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

  try {
    $pdo = new PDO($dsn, $user, $pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
  } catch (Throwable $e) {
    avito_log("DB connect error: " . $e->getMessage(), 'db.log');
    return null;
  }
}

function avito_db_prefix(): string {
  $cfg = avito_get_config();
  return (string)($cfg['mysql_prefix'] ?? '');
}

function db_table(string $name): string {
  return avito_db_prefix() . $name;
}

function db_get_or_create_conversation(PDO $pdo, string $chatId): array {
  $tConv = db_table('avito_conversations');

  $st = $pdo->prepare("SELECT * FROM {$tConv} WHERE chat_id = :chat_id LIMIT 1");
  $st->execute(['chat_id' => $chatId]);
  $row = $st->fetch();
  if (is_array($row)) return $row;

  $st = $pdo->prepare("INSERT INTO {$tConv} (chat_id, stage, collected_json) VALUES (:chat_id, 'start', JSON_OBJECT())");
  $st->execute(['chat_id' => $chatId]);

  $id = (int)$pdo->lastInsertId();
  $st = $pdo->prepare("SELECT * FROM {$tConv} WHERE id = :id LIMIT 1");
  $st->execute(['id' => $id]);
  $row = $st->fetch();

  return is_array($row) ? $row : ['id' => $id, 'chat_id' => $chatId, 'stage' => 'start', 'collected_json' => '{}'];
}

function db_append_message(PDO $pdo, int $conversationId, string $role, string $text): void {
  $tMsg = db_table('avito_messages');
  $st = $pdo->prepare("INSERT INTO {$tMsg} (conversation_id, role, text) VALUES (:cid, :role, :text)");
  $st->execute(['cid' => $conversationId, 'role' => $role, 'text' => $text]);
}

function db_get_last_messages(PDO $pdo, int $conversationId, int $limit = 12): array {
  $tMsg = db_table('avito_messages');
  $limit = max(1, min(50, $limit)); // ограничение
  $st = $pdo->prepare("SELECT role, text, created_at FROM {$tMsg} WHERE conversation_id = :cid ORDER BY id DESC LIMIT {$limit}");
  $st->execute(['cid' => $conversationId]);
  $rows = $st->fetchAll();
  if (!is_array($rows)) $rows = [];
  return array_reverse($rows);
}

function db_read_collected(array $convRow): array {
  $raw = $convRow['collected_json'] ?? null;
  if ($raw === null) return [];
  if (is_string($raw)) {
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
  }
  return is_array($raw) ? $raw : [];
}

function db_update_collected(PDO $pdo, int $conversationId, array $collected): void {
  $tConv = db_table('avito_conversations');
  $json = json_encode($collected, JSON_UNESCAPED_UNICODE);
  if ($json === false) $json = '{}';
  $st = $pdo->prepare("UPDATE {$tConv} SET collected_json = CAST(:j AS JSON) WHERE id = :id");
  $st->execute(['j' => $json, 'id' => $conversationId]);
}

function db_insert_lead(PDO $pdo, int $conversationId, string $chatId, ?string $phone, array $payload, string $status = 'handoff'): void {
  $tLead = db_table('avito_leads');
  $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
  if ($payloadJson === false) $payloadJson = '{}';

  $st = $pdo->prepare("
    INSERT INTO {$tLead} (conversation_id, chat_id, phone, payload_json, status)
    VALUES (:cid, :chat_id, :phone, CAST(:payload AS JSON), :status)
  ");
  $st->execute([
    'cid' => $conversationId,
    'chat_id' => $chatId,
    'phone' => $phone,
    'payload' => $payloadJson,
    'status' => $status,
  ]);
}
