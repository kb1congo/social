<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use PDO;
use InvalidArgumentException;

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

    public function updateConnectionStatus(
        int $accountId,
        string $status,
        ?string $phoneNumber = null,
        ?string $connectedAt = null
    ): void {
        $allowedStatuses = [
            'disconnected',
            'connecting',
            'qr',
            'connected',
            'logged_out'
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            throw new InvalidArgumentException(
                'Statut WhatsApp invalide.'
            );
        }

        $statement = $this->pdo->prepare(
            'UPDATE whatsapp_accounts
             SET status = :status,
                 phone_number = COALESCE(:phone_number, phone_number),
                 connected_at = CASE
                     WHEN :connected_status = "connected"
                     THEN COALESCE(:connected_at, CURRENT_TIMESTAMP)
                     ELSE connected_at
                 END,
                 last_seen_at = CASE
                     WHEN :last_seen_status = "connected"
                     THEN CURRENT_TIMESTAMP
                     ELSE last_seen_at
                 END
             WHERE id = :account_id'
        );

        $statement->execute([
            'status' => $status,
            'phone_number' => $phoneNumber,
            'connected_status' => $status,
            'connected_at' => $connectedAt,
            'last_seen_status' => $status,
            'account_id' => $accountId
        ]);
    }

    public function findAccountsToRestore(): array
    {
        $statement = $this->pdo->query(
            "SELECT id
             FROM whatsapp_accounts
             WHERE status IN ('connected', 'connecting', 'qr')
             ORDER BY id ASC"
        );

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }
}
