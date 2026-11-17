<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use PDO;

final class ContactRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getPdo();
    }

    public function upsert(
        int $accountId,
        string $jid,
        ?string $phoneNumber = null,
        ?string $displayName = null,
        ?string $pushName = null,
        ?string $profilePictureUrl = null
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO contacts (
                whatsapp_account_id,
                jid,
                phone_number,
                display_name,
                push_name,
                profile_picture_url
            ) VALUES (
                :account_id,
                :jid,
                :phone_number,
                :display_name,
                :push_name,
                :profile_picture_url
            )
            ON DUPLICATE KEY UPDATE
                phone_number = COALESCE(VALUES(phone_number), phone_number),
                display_name = COALESCE(VALUES(display_name), display_name),
                push_name = COALESCE(VALUES(push_name), push_name),
                profile_picture_url = COALESCE(
                    VALUES(profile_picture_url),
                    profile_picture_url
                )'
        );

        $statement->execute([
            'account_id' => $accountId,
            'jid' => $jid,
            'phone_number' => $phoneNumber,
            'display_name' => $displayName,
            'push_name' => $pushName,
            'profile_picture_url' => $profilePictureUrl
        ]);

        $contact = $this->findByJid($accountId, $jid);

        return (int) ($contact['id'] ?? 0);
    }

    public function findByJid(int $accountId, string $jid): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, whatsapp_account_id, jid, phone_number,
                    display_name, push_name, profile_picture_url,
                    created_at, updated_at
             FROM contacts
             WHERE whatsapp_account_id = :account_id
               AND jid = :jid
             LIMIT 1'
        );

        $statement->execute([
            'account_id' => $accountId,
            'jid' => $jid
        ]);

        $contact = $statement->fetch();

        return $contact ?: null;
    }

    public function findAllByAccount(int $accountId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, whatsapp_account_id, jid, phone_number,
                    display_name, push_name, profile_picture_url,
                    created_at, updated_at
             FROM contacts
             WHERE whatsapp_account_id = :account_id
             ORDER BY COALESCE(display_name, push_name, phone_number, jid) ASC'
        );

        $statement->execute(['account_id' => $accountId]);

        return $statement->fetchAll();
    }
}
