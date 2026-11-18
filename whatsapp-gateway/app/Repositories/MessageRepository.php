<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use PDO;

final class MessageRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getPdo();
    }

    public function create(
        int $accountId,
        int $conversationId,
        string $whatsappMessageId,
        string $senderJid,
        bool $fromMe,
        string $messageType,
        ?string $body = null,
        ?string $mediaMimeType = null,
        ?string $mediaPath = null,
        ?array $payload = null,
        string $deliveryStatus = 'sent',
        ?string $sentAt = null,
        ?string $fileName = null,
        ?int $fileSize = null
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO messages (
                whatsapp_account_id,
                conversation_id,
                whatsapp_message_id,
                sender_jid,
                from_me,
                message_type,
                body,
                media_mime_type,
                media_path,
                file_name,
                file_size,
                payload,
                delivery_status,
                sent_at
            ) VALUES (
                :account_id,
                :conversation_id,
                :message_id,
                :sender_jid,
                :from_me,
                :message_type,
                :body,
                :media_mime_type,
                :media_path,
                :file_name,
                :file_size,
                :payload,
                :delivery_status,
                :sent_at
            ) AS new
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                media_path = COALESCE(new.media_path, messages.media_path),
                file_name = COALESCE(new.file_name, messages.file_name),
                file_size = COALESCE(new.file_size, messages.file_size),
                media_mime_type = COALESCE(new.media_mime_type, messages.media_mime_type)'
        );

        $statement->execute([
            'account_id' => $accountId,
            'conversation_id' => $conversationId,
            'message_id' => $whatsappMessageId,
            'sender_jid' => $senderJid,
            'from_me' => $fromMe ? 1 : 0,
            'message_type' => $messageType,
            'body' => $body,
            'media_mime_type' => $mediaMimeType,
            'media_path' => $mediaPath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'payload' => $payload === null
                ? null
                : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'delivery_status' => $deliveryStatus,
            'sent_at' => $sentAt
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findByWhatsAppId(
        int $accountId,
        string $whatsappMessageId
    ): ?array {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM messages
             WHERE whatsapp_account_id = :account_id
               AND whatsapp_message_id = :message_id
             LIMIT 1'
        );

        $statement->execute([
            'account_id' => $accountId,
            'message_id' => $whatsappMessageId
        ]);

        $message = $statement->fetch();

        return $message ?: null;
    }

    public function findById(int $messageId, int $accountId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM messages
             WHERE id = :id
               AND whatsapp_account_id = :account_id
             LIMIT 1'
        );

        $statement->execute([
            'id' => $messageId,
            'account_id' => $accountId
        ]);

        $message = $statement->fetch();

        return $message ?: null;
    }

    public function findByConversation(
        int $conversationId,
        int $accountId,
        int $limit = 50,
        ?int $beforeId = null
    ): array {
        $limit = max(1, min($limit, 100));

        $sql = 'SELECT *
                FROM messages
                WHERE conversation_id = :conversation_id
                  AND whatsapp_account_id = :account_id';

        $parameters = [
            'conversation_id' => $conversationId,
            'account_id' => $accountId
        ];

        if ($beforeId !== null) {
            $sql .= ' AND id < :before_id';
            $parameters['before_id'] = $beforeId;
        }

        $sql .= ' ORDER BY sent_at DESC, id DESC LIMIT ' . $limit;

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return array_reverse($statement->fetchAll());
    }

    public function updateDeliveryStatus(
        int $accountId,
        string $whatsappMessageId,
        string $deliveryStatus
    ): void {
        $allowedStatuses = [
            'pending',
            'sent',
            'delivered',
            'read',
            'failed'
        ];

        if (!in_array($deliveryStatus, $allowedStatuses, true)) {
            throw new \InvalidArgumentException('Statut de livraison invalide.');
        }

        $statement = $this->pdo->prepare(
            'UPDATE messages
             SET delivery_status = :status
             WHERE whatsapp_account_id = :account_id
               AND whatsapp_message_id = :message_id'
        );

        $statement->execute([
            'status' => $deliveryStatus,
            'account_id' => $accountId,
            'message_id' => $whatsappMessageId
        ]);
    }
}
