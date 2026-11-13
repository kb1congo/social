<?php
declare(strict_types=1);

final class WhatsAppAccount
{
    private PDO $pdo;

    private const ALLOWED_STATUSES = [
        'idle',
        'connecting',
        'qr',
        'connected',
        'disconnected',
        'error',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Crée un compte WhatsApp appartenant à un utilisateur.
     */
    public function createForUser(
        int $userId,
        string $accountId,
        string $displayName
    ): int {
        $displayName = trim($displayName);

        if ($userId <= 0) {
            throw new InvalidArgumentException('Utilisateur invalide.');
        }

        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $accountId)) {
            throw new InvalidArgumentException('Identifiant de compte invalide.');
        }

        if ($displayName === '' || strlen($displayName) > 120) {
            throw new InvalidArgumentException('Nom du compte invalide.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO whatsapp_accounts
                (user_id, account_id, display_name, status)
             VALUES
                (:user_id, :account_id, :display_name, :status)'
        );

        $stmt->execute([
            'user_id' => $userId,
            'account_id' => $accountId,
            'display_name' => $displayName,
            'status' => 'idle',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Liste uniquement les comptes d'un utilisateur.
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, account_id, display_name, phone_number,
                    status, last_connected_at, created_at, updated_at
             FROM whatsapp_accounts
             WHERE user_id = :user_id
             ORDER BY created_at DESC'
        );

        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Recherche un compte en vérifiant son propriétaire.
     */
    public function findForUser(
        int $userId,
        string $accountId
    ): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT id, account_id, display_name, phone_number,
                    status, last_connected_at, created_at, updated_at
             FROM whatsapp_accounts
             WHERE user_id = :user_id
               AND account_id = :account_id
             LIMIT 1'
        );

        $stmt->execute([
            'user_id' => $userId,
            'account_id' => $accountId,
        ]);

        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        return $account ?: null;
    }

    /**
     * Met à jour le statut d'un compte appartenant à l'utilisateur.
     */
    public function updateStatusForUser(
        int $userId,
        string $accountId,
        string $status,
        ?string $phoneNumber = null
    ): bool {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('Statut WhatsApp invalide.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE whatsapp_accounts
             SET status = :status,
                 phone_number = COALESCE(:phone_number, phone_number),
                 last_connected_at = CASE
                     WHEN :connected_status = 1 THEN CURRENT_TIMESTAMP
                     ELSE last_connected_at
                 END
             WHERE user_id = :user_id
               AND account_id = :account_id'
        );

        $stmt->execute([
            'status' => $status,
            'phone_number' => $phoneNumber,
            'connected_status' => $status === 'connected' ? 1 : 0,
            'user_id' => $userId,
            'account_id' => $accountId,
        ]);

        return $stmt->rowCount() > 0;
    }
}
