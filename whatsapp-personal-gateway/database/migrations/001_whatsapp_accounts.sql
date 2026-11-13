CREATE TABLE IF NOT EXISTS whatsapp_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    account_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    phone_number VARCHAR(32) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'idle',
    last_connected_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_accounts_account_id (account_id),
    UNIQUE KEY uq_whatsapp_accounts_phone_number (phone_number),
    KEY idx_whatsapp_accounts_user (user_id),
    KEY idx_whatsapp_accounts_status (status),
    CONSTRAINT fk_whatsapp_accounts_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
