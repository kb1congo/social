<?php

declare(strict_types=1);

final class WhatsAppMessageStore
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Enregistre un événement de message reçu ou envoyé par Baileys.
     * Accepte les anciens et les nouveaux noms de champs.
     */
    public function store(array $event): array
    {
        $accountId = trim((string) ($event['accountId'] ?? ''));

        $remoteJid = trim((string) (
            $event['remoteJid'] ?? $event['from'] ?? ''
        ));

        $messageId = trim((string) (
            $event['messageId'] ?? $event['id'] ?? ''
        ));

        $body = $event['body'] ?? null;
        $fromMe = (bool) ($event['fromMe'] ?? false);

        if (
            !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $accountId) ||
            $remoteJid === '' ||
            strlen($remoteJid) > 80
        ) {
            throw new InvalidArgumentException('Événement WhatsApp invalide.');
        }

        if ($messageId === '' || strlen($messageId) > 120) {
            throw new InvalidArgumentException('Identifiant du message invalide.');
        }

        $direction = $fromMe ? 'outbound' : 'inbound';
        $phoneNumber = $this->extractPhoneNumber($remoteJid);

        $timestamp = (int) ($event['timestamp'] ?? 0);

        $messageDate = $timestamp > 0
            ? date('Y-m-d H:i:s', $timestamp)
            : date('Y-m-d H:i:s');

        $this->pdo->beginTransaction();

        try {
            // Vérifier le compte WhatsApp et récupérer son numéro.
            $stmt = $this->pdo->prepare(
                'SELECT id, phone_number
                 FROM whatsapp_accounts
                 WHERE account_id = :account_id
                 LIMIT 1'
            );

            $stmt->execute([
                'account_id' => $accountId,
            ]);

            $account = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$account) {
                throw new RuntimeException('Compte WhatsApp introuvable.');
            }

            $accountDbId = (int) $account['id'];

            $ownPhoneNumber = preg_replace(
                '/\D+/',
                '',
                (string) ($account['phone_number'] ?? '')
            );

            // Ignorer uniquement les conversations directes avec son propre numéro.
            // Ne pas bloquer les messages sortants vers les autres contacts.
            if (
                !$this->isGroupJid($remoteJid) &&
                !$this->isLidJid($remoteJid) &&
                $phoneNumber !== null &&
                $ownPhoneNumber !== '' &&
                $phoneNumber === $ownPhoneNumber
            ) {
                $this->pdo->commit();

                return [
                    'stored' => false,
                    'ignored' => true,
                    'reason' => 'own_account',
                ];
            }

            // Vérifier si le message existe déjà AVANT de créer un contact.
            $stmt = $this->pdo->prepare(
                'SELECT id, conversation_id
                 FROM messages
                 WHERE whatsapp_account_id = :account_id
                   AND whatsapp_message_id = :message_id
                 LIMIT 1'
            );

            $stmt->execute([
                'account_id' => $accountDbId,
                'message_id' => $messageId,
            ]);

            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $this->pdo->commit();

                return [
                    'stored' => false,
                    'duplicate' => true,
                    'messageId' => (int) $existing['id'],
                    'conversationId' => (int) $existing['conversation_id'],
                ];
            }

            // Créer ou actualiser le contact.
            $displayName = trim((string) ($event['pushName'] ?? ''));

            $stmt = $this->pdo->prepare(
                'INSERT INTO contacts
                    (whatsapp_account_id, whatsapp_id, display_name, phone_number)
                 VALUES
                    (:account_id, :whatsapp_id, :display_name, :phone_number)
                 ON DUPLICATE KEY UPDATE
                    display_name = COALESCE(
                        NULLIF(VALUES(display_name), \'\'),
                        display_name
                    ),
                    phone_number = COALESCE(
                        VALUES(phone_number),
                        phone_number
                    )'
            );

            $stmt->execute([
                'account_id' => $accountDbId,
                'whatsapp_id' => $remoteJid,
                'display_name' => $displayName !== '' ? $displayName : null,
                'phone_number' => $phoneNumber,
            ]);

            $stmt = $this->pdo->prepare(
                'SELECT id
                 FROM contacts
                 WHERE whatsapp_account_id = :account_id
                   AND whatsapp_id = :whatsapp_id
                 LIMIT 1'
            );

            $stmt->execute([
                'account_id' => $accountDbId,
                'whatsapp_id' => $remoteJid,
            ]);

            $contact = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$contact) {
                throw new RuntimeException(
                    'Impossible de récupérer le contact WhatsApp.'
                );
            }

            $contactId = (int) $contact['id'];

            // Récupérer ou créer la conversation.
            $stmt = $this->pdo->prepare(
                'SELECT id
                 FROM conversations
                 WHERE contact_id = :contact_id
                 LIMIT 1'
            );

            $stmt->execute([
                'contact_id' => $contactId,
            ]);

            $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$conversation) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO conversations
                        (contact_id, status, last_message_at)
                     VALUES
                        (:contact_id, \'open\', :last_message_at)'
                );

                $stmt->execute([
                    'contact_id' => $contactId,
                    'last_message_at' => $messageDate,
                ]);

                $conversationId = (int) $this->pdo->lastInsertId();

            } else {
                $conversationId = (int) $conversation['id'];

                $stmt = $this->pdo->prepare(
                    'UPDATE conversations
                     SET last_message_at = GREATEST(
                         COALESCE(last_message_at, :message_date),
                         :message_date_2
                     )
                     WHERE id = :id'
                );

                $stmt->execute([
                    'message_date' => $messageDate,
                    'message_date_2' => $messageDate,
                    'id' => $conversationId,
                ]);
            }

            // Déterminer le type du message.
            $messageType = trim((string) ($event['messageType'] ?? 'chat'));

            if ($messageType === '' || strlen($messageType) > 40) {
                $messageType = 'chat';
            }

            // Insérer le message avec protection contre les doublons concurrents.
            $stmt = $this->pdo->prepare(
                'INSERT INTO messages
                    (conversation_id, whatsapp_account_id, whatsapp_message_id,
                     direction, message_type, body, whatsapp_timestamp)
                 VALUES
                    (:conversation_id, :account_id, :message_id,
                     :direction, :message_type, :body, :message_date)
                 ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id)'
            );

            $stmt->execute([
                'conversation_id' => $conversationId,
                'account_id' => $accountDbId,
                'message_id' => $messageId,
                'direction' => $direction,
                'message_type' => $messageType,
                'body' => is_string($body) ? $body : null,
                'message_date' => $messageDate,
            ]);

            $messageDbId = (int) $this->pdo->lastInsertId();

            $inserted = $stmt->rowCount() === 1;

            $this->pdo->commit();

            return [
                'stored' => $inserted,
                'duplicate' => !$inserted,
                'messageId' => $messageDbId,
                'conversationId' => $conversationId,
            ];

        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }
    }

    /**
     * Enregistre une correspondance entre un LID et un JID.
     * Une correspondance contradictoire n'est jamais remplacée silencieusement.
     */
    public function storeContactMapping(array $event): array
    {
        $accountId = trim((string) ($event['accountId'] ?? ''));
        $lid = trim((string) ($event['lid'] ?? ''));
        $jid = trim((string) ($event['jid'] ?? ''));

        if (
            !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $accountId) ||
            !preg_match('/^[0-9]{1,30}@lid$/', $lid) ||
            !preg_match('/^[0-9]{5,20}@s\.whatsapp\.net$/', $jid)
        ) {
            throw new InvalidArgumentException(
                'Correspondance LID/JID invalide.'
            );
        }

        $this->pdo->beginTransaction();

        try {
            // Vérifier que le compte WhatsApp existe.
            $stmt = $this->pdo->prepare(
                'SELECT account_id
                 FROM whatsapp_accounts
                 WHERE account_id = :account_id
                 LIMIT 1'
            );

            $stmt->execute([
                'account_id' => $accountId,
            ]);

            if (!$stmt->fetchColumn()) {
                throw new RuntimeException(
                    'Compte WhatsApp introuvable pour cette correspondance.'
                );
            }

            // Rechercher une correspondance existante sur le LID ou le JID.
            $stmt = $this->pdo->prepare(
                'SELECT lid, jid
                 FROM whatsapp_contact_mappings
                 WHERE account_id = :account_id
                   AND (lid = :lid OR jid = :jid)
                 LIMIT 1
                 FOR UPDATE'
            );

            $stmt->execute([
                'account_id' => $accountId,
                'lid' => $lid,
                'jid' => $jid,
            ]);

            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if (
                    $existing['lid'] !== $lid ||
                    $existing['jid'] !== $jid
                ) {
                    throw new RuntimeException(
                        'Conflit : ce LID ou ce JID est déjà associé à un autre identifiant.'
                    );
                }

                $this->pdo->commit();

                return [
                    'stored' => false,
                    'duplicate' => true,
                ];
            }

            // Insérer la nouvelle correspondance.
            $stmt = $this->pdo->prepare(
                'INSERT INTO whatsapp_contact_mappings
                    (account_id, lid, jid)
                 VALUES
                    (:account_id, :lid, :jid)'
            );

            $stmt->execute([
                'account_id' => $accountId,
                'lid' => $lid,
                'jid' => $jid,
            ]);

            $this->pdo->commit();

            return [
                'stored' => true,
                'duplicate' => false,
            ];

        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }
    }

    /**
     * Extrait le numéro d'un identifiant WhatsApp individuel.
     */
    private function extractPhoneNumber(string $jid): ?string
    {
        if (!preg_match('/^([0-9]{5,20})@s\.whatsapp\.net$/', $jid, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Vérifie si l'identifiant correspond à un groupe WhatsApp.
     */
    private function isGroupJid(string $jid): bool
    {
        return str_ends_with($jid, '@g.us');
    }

    /**
     * Vérifie si l'identifiant est un LID.
     */
    private function isLidJid(string $jid): bool
    {
        return str_ends_with($jid, '@lid');
    }
}
