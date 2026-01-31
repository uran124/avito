-- /avito/migrate.sql

CREATE TABLE IF NOT EXISTS avito_conversations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  chat_id VARCHAR(64) NOT NULL,
  stage VARCHAR(32) NOT NULL DEFAULT 'start',
  collected_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_chat_id (chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS avito_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  role ENUM('user','assistant','system') NOT NULL,
  text TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_conv_created (conversation_id, created_at),
  CONSTRAINT fk_messages_conv
    FOREIGN KEY (conversation_id) REFERENCES avito_conversations(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS avito_leads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  chat_id VARCHAR(64) NOT NULL,
  phone VARCHAR(32) NULL,
  payload_json JSON NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'handoff',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_chat_created (chat_id, created_at),
  KEY idx_status_created (status, created_at),
  CONSTRAINT fk_leads_conv
    FOREIGN KEY (conversation_id) REFERENCES avito_conversations(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
