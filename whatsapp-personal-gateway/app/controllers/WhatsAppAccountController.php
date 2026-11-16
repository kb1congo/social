<?php

declare(strict_types=1);

require_once __DIR__ . '/../models/WhatsAppAccount.php';
require_once __DIR__ . '/../services/WhatsAppGateway.php';

final class WhatsAppAccountController
{
    private WhatsAppAccount $model;
    private WhatsAppGateway $gateway;
    private int $userId;

    public function __construct(PDO $pdo, int $userId)
    {
        $this->model = new WhatsAppAccount($pdo);
        $this->gateway = new WhatsAppGateway();
        $this->userId = $userId;
    }
    public function index(): array
    {
        $accounts = $this->model->listForUser($this->userId);

        foreach ($accounts as &$account) {
            try {
                $status = $this->gateway->getAccountStatus($account['account_id']);

                $currentStatus = $status['status'] ?? $account['status'];
                $phoneNumber = $status['phoneNumber'] ?? null;

                $this->model->updateStatusForUser(
                    $this->userId,
                    $account['account_id'],
                    $currentStatus,
                    $phoneNumber
                );

                $account['status'] = $currentStatus;

                if ($phoneNumber !== null) {
                    $account['phone_number'] = $phoneNumber;
                }
            } catch (Throwable $e) {
                // Conserver le dernier état connu si Node est momentanément inaccessible.
            }
        }

        unset($account);

        return $accounts;
    }

    public function create(string $displayName): array
    {
        $displayName = trim($displayName);

        if ($displayName === '' || strlen($displayName) > 120) {
            throw new InvalidArgumentException('Nom du compte invalide.');
        }

        $accountId = 'wa_' . bin2hex(random_bytes(12));

        $this->model->createForUser(
            $this->userId,
            $accountId,
            $displayName
        );

        try {
            $result = $this->gateway->connectAccount($accountId);
            $this->model->updateStatusForUser(
                $this->userId,
                $accountId,
                $result['status'] ?? 'connecting'
            );

            return [
                'accountId' => $accountId,
                'displayName' => $displayName,
                'connection' => $result,
            ];
        } catch (Throwable $e) {
            $this->model->updateStatusForUser(
                $this->userId,
                $accountId,
                'error'
            );

            throw $e;
        }
    }

    public function connect(string $accountId): array
    {
        $this->findOwnedAccount($accountId);

        $result = $this->gateway->connectAccount($accountId);

        $this->model->updateStatusForUser(
            $this->userId,
            $accountId,
            $result['status'] ?? 'connecting'
        );

        return $result;
    }

    public function status(string $accountId): array
    {
        $this->findOwnedAccount($accountId);

        $result = $this->gateway->getAccountStatus($accountId);

        $this->model->updateStatusForUser(
            $this->userId,
            $accountId,
            $result['status'] ?? 'disconnected',
            $result['phoneNumber'] ?? null
        );

        return $result;
    }

    public function qr(string $accountId): array
    {
        $this->findOwnedAccount($accountId);

        return $this->gateway->getAccountQr($accountId);
    }

    public function sendMessage(
        string $accountId,
        string $recipient,
        string $message
    ): array {
        // Vérifier que le compte appartient à l'utilisateur connecté.
        $this->findOwnedAccount($accountId);

        $recipient = trim($recipient);
        $message = trim($message);

        if ($recipient === '' || $message === '') {
            throw new InvalidArgumentException(
                'Le destinataire et le message sont obligatoires.'
            );
        }

        return $this->gateway->sendMessage(
            $accountId,
            $recipient,
            $message
        );
    }

    private function findOwnedAccount(string $accountId): array
    {
        $account = $this->model->findForUser($this->userId, $accountId);

        if ($account === null) {
            throw new RuntimeException('Compte WhatsApp introuvable.');
        }

        return $account;
    }
}
