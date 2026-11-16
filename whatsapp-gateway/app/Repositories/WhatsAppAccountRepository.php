<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use PDO;

final class WhatsAppAccountRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getPdo();
    }

    public function findByUserId(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, phone_number, whatsapp_jid,
                    display_name, status, connected_at,
                    last_seen_at, created_at, updated_at
             FROM whatsapp_accounts
             WHERE user_id = :user_id
             LIMIT 1'
        );

        $statement->execute(['user_id' => $userId]);

        $account = $statement->fetch();

        return $account ?: null;
    }

    public function create(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO whatsapp_accounts (user_id)
             VALUES (:user_id)'
        );

        $statement->execute(['user_id' => $userId]);

        return (int) $this->pdo->lastInsertId();
    }
}
