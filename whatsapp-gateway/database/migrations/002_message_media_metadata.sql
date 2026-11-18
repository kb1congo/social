ALTER TABLE messages
    ADD COLUMN file_name VARCHAR(255) NULL AFTER media_path,
    ADD COLUMN file_size BIGINT UNSIGNED NULL AFTER file_name;
