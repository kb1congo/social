<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use PDO;
use InvalidArgumentException;

final class ConversationRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getPdo();
    }

    public function upsert(
        int $accountId,
        string $remoteJid,
        string $type = 'individual',
        ?string $subject = null,
        ?string $lastMessageAt = null
    ): int {
        if (!in_array($type, ['individual', 'group'], true)) {
            throw new InvalidArgumentException('Type de conversation invalide.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO conversations (
                whatsapp_account_id,
                remote_jid,
                type,
                subject,
                last_message_at
            ) VALUES (
                :account_id,
                :remote_jid,
                :type,
                :subject,
                :last_message_at
            )
            ON DUPLICATE KEY UPDATE
                type = VALUES(type),
                subject = COALESCE(VALUES(subject), subject),
                last_message_at = CASE
                    WHEN VALUES(last_message_at) IS NULL THEN last_message_at
                    WHEN last_message_at IS NULL
                         OR VALUES(last_message_at) > last_message_at
                    THEN VALUES(last_message_at)
                    ELSE last_message_at
                END'
        );

        $statement->execute([
            'account_id' => $accountId,
            'remote_jid' => $remoteJid,
            'type' => $type,
            'subject' => $subject,
            'last_message_at' => $lastMessageAt
        ]);

        return $this->findIdByJid($accountId, $remoteJid);
    }

    public function findById(int $conversationId, int $accountId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, whatsapp_account_id, remote_jid,
                    type, subject, is_archived, is_pinned,
                    last_message_at, created_at, updated_at
             FROM conversations
             WHERE id = :conversation_id
               AND whatsapp_account_id = :account_id
             LIMIT 1'
        );

        $statement->execute([
            'conversation_id' => $conversationId,
            'account_id' => $accountId
        ]);

        $conversation = $statement->fetch();

        return $conversation ?: null;
    }

    public function findByJid(int $accountId, string $remoteJid): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, whatsapp_account_id, remote_jid,
                    type, subject, is_archived, is_pinned,
                    last_message_at, created_at, updated_at
             FROM conversations
             WHERE whatsapp_account_id = :account_id
               AND remote_jid = :remote_jid
             LIMIT 1'
        );

        $statement->execute([
            'account_id' => $accountId,
            'remote_jid' => $remoteJid
        ]);

        $conversation = $statement->fetch();

        return $conversation ?: null;
    }

    public function findAllByAccount(int $accountId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                c.id,
                c.whatsapp_account_id,
                c.remote_jid,
                c.type,
                c.subject,
                c.is_archived,
                c.is_pinned,
                c.last_message_at,
                c.created_at,
                COALESCE(NULLIF(ct.display_name, \'\'), NULLIF(cp.display_name, \'\')) AS display_name,
                ct.push_name,
                COALESCE(NULLIF(ct.phone_number, \'\'), NULLIF(cp.phone_number, \'\')) AS phone_number,
                m.body AS last_message_body,
                m.message_type AS last_message_type,
                m.from_me AS last_message_from_me
             FROM conversations c
             LEFT JOIN contacts ct
                ON ct.whatsapp_account_id = c.whatsapp_account_id
               AND ct.jid = c.remote_jid
             LEFT JOIN conversation_participants cp
                ON cp.conversation_id = c.id
               AND cp.participant_jid = c.remote_jid
               AND cp.is_self = 0
             LEFT JOIN messages m
                ON m.id = (
                    SELECT m2.id
                    FROM messages m2
                    WHERE m2.conversation_id = c.id
                    ORDER BY m2.sent_at DESC, m2.id DESC
                    LIMIT 1
                )
             WHERE c.whatsapp_account_id = :account_id
             ORDER BY c.is_pinned DESC,
                      c.last_message_at DESC,
                      c.id DESC'
        );

        $statement->execute(['account_id' => $accountId]);

        return $statement->fetchAll();
    }

    public function upsertParticipant(
        int $conversationId,
        string $participantJid,
        ?string $phoneNumber = null,
        ?string $displayName = null,
        bool $isSelf = false,
        bool $isAdmin = false
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO conversation_participants (
                conversation_id,
                participant_jid,
                phone_number,
                display_name,
                is_self,
                is_admin
            ) VALUES (
                :conversation_id,
                :participant_jid,
                :phone_number,
                :display_name,
                :is_self,
                :is_admin
            )
            ON DUPLICATE KEY UPDATE
                phone_number = COALESCE(VALUES(phone_number), phone_number),
                display_name = COALESCE(VALUES(display_name), display_name),
                is_self = VALUES(is_self),
                is_admin = VALUES(is_admin)'
        );

        $statement->execute([
            'conversation_id' => $conversationId,
            'participant_jid' => $participantJid,
            'phone_number' => $phoneNumber,
            'display_name' => $displayName,
            'is_self' => $isSelf ? 1 : 0,
            'is_admin' => $isAdmin ? 1 : 0
        ]);
    }

    public function findParticipants(int $conversationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, conversation_id, participant_jid,
                    phone_number, display_name, is_self,
                    is_admin, created_at, updated_at
             FROM conversation_participants
             WHERE conversation_id = :conversation_id
             ORDER BY is_self DESC, display_name ASC, participant_jid ASC'
        );

        $statement->execute([
            'conversation_id' => $conversationId
        ]);

        return $statement->fetchAll();
    }

    public function updateFlags(
        int $accountId,
        string $remoteJid,
        ?bool $isArchived = null,
        ?bool $isPinned = null
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE conversations
             SET is_archived = COALESCE(:is_archived, is_archived),
                 is_pinned = COALESCE(:is_pinned, is_pinned)
             WHERE whatsapp_account_id = :account_id
               AND remote_jid = :remote_jid'
        );

        $statement->execute([
            'is_archived' => $isArchived === null ? null : (int) $isArchived,
            'is_pinned' => $isPinned === null ? null : (int) $isPinned,
            'account_id' => $accountId,
            'remote_jid' => $remoteJid
        ]);
    }

    private function findIdByJid(int $accountId, string $remoteJid): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id
             FROM conversations
             WHERE whatsapp_account_id = :account_id
               AND remote_jid = :remote_jid
             LIMIT 1'
        );

        $statement->execute([
            'account_id' => $accountId,
            'remote_jid' => $remoteJid
        ]);

        return (int) $statement->fetchColumn();
    }
}
