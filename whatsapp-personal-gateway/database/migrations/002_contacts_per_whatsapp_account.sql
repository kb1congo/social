ALTER TABLE contacts
    DROP INDEX uq_contacts_whatsapp_id,
    ADD COLUMN whatsapp_account_id BIGINT UNSIGNED NOT NULL AFTER id,
    ADD UNIQUE KEY uq_contacts_account_whatsapp_id (whatsapp_account_id, whatsapp_id),
    ADD KEY idx_contacts_whatsapp_account (whatsapp_account_id),
    ADD CONSTRAINT fk_contacts_whatsapp_account
        FOREIGN KEY (whatsapp_account_id)
        REFERENCES whatsapp_accounts(id)
        ON DELETE RESTRICT;
