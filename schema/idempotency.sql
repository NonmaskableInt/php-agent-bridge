CREATE TABLE IF NOT EXISTS wp_agent_idempotency (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    idem_key    VARCHAR(128) NOT NULL UNIQUE,
    method      VARCHAR(10)  NOT NULL,
    route       VARCHAR(255) NOT NULL,
    response    LONGTEXT     NOT NULL,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME     NOT NULL,
    INDEX idx_expires (expires_at)
);
