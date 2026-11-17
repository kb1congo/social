<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Repositories\ContactRepository;
use App\Repositories\ConversationRepository;
use App\Repositories\MessageRepository;
use InvalidArgumentException;
use PDO;
use Throwable;

final class WhatsAppMessageService
{
    private PDO $pdo;
    private ContactRepository $contacts;
    private ConversationRepository $conversations;
    private MessageRepository $messages;

    public function __construct()
    {
        $this->pdo = Database::getPdo();
        $this->contacts = new ContactRepository();
        $this->conversations = new ConversationRepository();
        $this->messages = new MessageRepository();
    }

    public function receive(array $data): int
    {
        $accountId = filter_var(
            $data['accountId'] ?? null,
            FILTER_VALIDATE_INT
        );

        $remoteJid = trim((string) ($data['remoteJid'] ?? ''));
        $selfJid = trim((string) ($data['selfJid'] ?? ''));
        $messageId = trim((string) ($data['messageId'] ?? ''));
        $fromMe = (bool) ($data['fromMe'] ?? false);

        if (
            $accountId === false || $accountId <= 0 ||
            $remoteJid === '' ||
            $selfJid === '' ||
            $messageId === ''
        ) {
            throw new InvalidArgumentException(
                'Informations obligatoires du message manquantes.'
            );
        }

        $type = $remoteJid === 'status@broadcast'
            ? null
            : (str_ends_with($remoteJid, '@g.us') ? 'group' : 'individual');

        if ($type === null) {
            throw new InvalidArgumentException(
                'Les messages de statut WhatsApp ne sont pas pris en charge.'
            );
        }

        $messageType = substr(
            trim((string) ($data['messageType'] ?? 'unknown')),
            0,
            40
        );

        if ($messageType === '') {
            $messageType = 'unknown';
        }

        $participantJid = trim(
            (string) ($data['participantJid'] ?? '')
        );

        $senderJid = $fromMe
            ? $selfJid
            : ($type === 'group'
                ? $participantJid
                : $remoteJid);

        if ($senderJid === '') {
            throw new InvalidArgumentException(
                'Identifiant de l’expéditeur manquant.'
            );
        }

        $timestamp = filter_var(
            $data['timestamp'] ?? time(),
            FILTER_VALIDATE_INT
        );

        if ($timestamp === false || $timestamp <= 0) {
            $timestamp = time();
        }

        $sentAt = gmdate('Y-m-d H:i:s', $timestamp) . '.000';

        $this->pdo->beginTransaction();

        try {
            $displayName = trim((string) ($data['pushName'] ?? ''));
            $displayName = $displayName !== '' ? $displayName : null;

            $phoneNumber = $this->phoneFromJid($remoteJid);

            $this->contacts->upsert(
                $accountId,
                $remoteJid,
                $phoneNumber,
                $displayName,
                $displayName
            );

            if ($senderJid !== $remoteJid && $senderJid !== $selfJid) {
                $this->contacts->upsert(
                    $accountId,
                    $senderJid,
                    $this->phoneFromJid($senderJid),
                    $displayName,
                    $displayName
                );
            }

            $conversationId = $this->conversations->upsert(
                $accountId,
                $remoteJid,
                $type,
                $data['subject'] ?? null,
                $sentAt
            );

            if ($type === 'individual') {
                $this->conversations->upsertParticipant(
                    $conversationId,
                    $remoteJid,
                    $phoneNumber,
                    $displayName,
                    false
                );

                $this->conversations->upsertParticipant(
                    $conversationId,
                    $selfJid,
                    $this->phoneFromJid($selfJid),
                    null,
                    true
                );
            } else {
                $this->conversations->upsertParticipant(
                    $conversationId,
                    $senderJid,
                    $this->phoneFromJid($senderJid),
                    $displayName,
                    $senderJid === $selfJid
                );

                $this->conversations->upsertParticipant(
                    $conversationId,
                    $selfJid,
                    $this->phoneFromJid($selfJid),
                    null,
                    true
                );
            }

            $messageDatabaseId = $this->messages->create(
                $accountId,
                $conversationId,
                $messageId,
                $senderJid,
                $fromMe,
                $messageType,
                isset($data['body']) ? (string) $data['body'] : null,
                isset($data['mediaMimeType'])
                    ? (string) $data['mediaMimeType']
                    : null,
                null,
                isset($data['payload']) && is_array($data['payload'])
                    ? $data['payload']
                    : null,
                $fromMe ? 'sent' : 'delivered',
                $sentAt
            );

            $this->pdo->commit();

            return $messageDatabaseId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function storeSent(
        int $accountId,
        int $conversationId,
        string $remoteJid,
        string $selfJid,
        string $messageId,
        string $body,
        int $timestamp
    ): int {
        if (
            $accountId <= 0 ||
            $conversationId <= 0 ||
            $remoteJid === '' ||
            $selfJid === '' ||
            $messageId === '' ||
            trim($body) === ''
        ) {
            throw new InvalidArgumentException(
                'Informations du message envoyé invalides.'
            );
        }

        $sentAt = gmdate('Y-m-d H:i:s', $timestamp) . '.000';

        $this->pdo->beginTransaction();

        try {
            $senderPhone = $this->phoneFromJid($selfJid);

            $this->conversations->upsertParticipant(
                $conversationId,
                $selfJid,
                $senderPhone,
                null,
                true
            );

            $messageDatabaseId = $this->messages->create(
                $accountId,
                $conversationId,
                $messageId,
                $selfJid,
                true,
                'conversation',
                trim($body),
                null,
                null,
                ['messageType' => 'conversation'],
                'sent',
                $sentAt
            );

            $this->pdo->commit();

            return $messageDatabaseId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function phoneFromJid(string $jid): ?string
    {
        if (!str_ends_with($jid, '@s.whatsapp.net')) {
            return null;
        }

        $number = explode('@', $jid, 2)[0];
        $number = preg_replace('/:\d+$/', '', $number);

        return $number !== '' ? $number : null;
    }
}
