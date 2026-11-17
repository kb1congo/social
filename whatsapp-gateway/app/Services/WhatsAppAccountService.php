<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\WhatsAppAccountRepository;
use RuntimeException;
use PDOException;

final class WhatsAppAccountService
{
    private WhatsAppAccountRepository $accounts;
    private NodeApiService $nodeApi;

    public function __construct()
    {
        $this->accounts = new WhatsAppAccountRepository();
        $this->nodeApi = new NodeApiService();
    }

    public function getOrCreate(int $userId): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Identifiant utilisateur invalide.');
        }

        $account = $this->accounts->findByUserId($userId);

        if ($account !== null) {
            return $account;
        }

        try {
            $this->accounts->create($userId);
        } catch (PDOException $exception) {
            $account = $this->accounts->findByUserId($userId);

            if ($account === null) {
                throw $exception;
            }
        }

        $account = $this->accounts->findByUserId($userId);

        if ($account === null) {
            throw new RuntimeException(
                'Impossible de récupérer le compte WhatsApp.'
            );
        }

        return $account;
    }


    public function updateAccountStatus(
        int $accountId,
        array $data
    ): void {
        if ($accountId <= 0) {
            throw new RuntimeException('Identifiant de compte invalide.');
        }

        $status = $data['status'] ?? '';

        $allowedStatuses = [
            'disconnected',
            'connecting',
            'qr',
            'connected',
            'logged_out'
        ];

        if (!is_string($status) || !in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException('Statut WhatsApp invalide.');
        }

        $phoneNumber = $data['phoneNumber'] ?? null;
        if ($phoneNumber !== null && !is_string($phoneNumber)) {
            throw new RuntimeException('Numéro WhatsApp invalide.');
        }

        $connectedAt = $data['connectedAt'] ?? null;
        if ($connectedAt !== null) {
            if (!is_string($connectedAt) || $connectedAt === '') {
                throw new RuntimeException('Date de connexion invalide.');
            }

            $connectedAt = (new \DateTimeImmutable($connectedAt))
                ->format('Y-m-d H:i:s');
        }

        $this->accounts->updateConnectionStatus(
            $accountId,
            $status,
            $phoneNumber,
            $connectedAt
        );
    }

    public function getAccountsToRestore(): array
    {
        return array_map(
            'intval',
            $this->accounts->findAccountsToRestore()
        );
    }

    public function connect(int $userId): array
    {
        $account = $this->getOrCreate($userId);
        $accountId = (int) $account['id'];

        $result = $this->nodeApi->request(
            'POST',
            "internal/accounts/{$accountId}/connect"
        );

        return $this->synchronizeStatus($accountId, $result['data'] ?? []);
    }

    public function getStatus(int $userId): array
    {
        $account = $this->getOrCreate($userId);
        $accountId = (int) $account['id'];

        $result = $this->nodeApi->request(
            'GET',
            "internal/accounts/{$accountId}/status"
        );

        return $this->synchronizeStatus($accountId, $result['data'] ?? []);
    }

    public function disconnect(int $userId): array
    {
        return $this->performSessionAction($userId, 'disconnect');
    }

    public function logout(int $userId): array
    {
        return $this->performSessionAction($userId, 'logout');
    }

    private function performSessionAction(
        int $userId,
        string $action
    ): array {
        $account = $this->getOrCreate($userId);
        $accountId = (int) $account['id'];

        $result = $this->nodeApi->request(
            'POST',
            "internal/accounts/{$accountId}/{$action}"
        );

        return $this->synchronizeStatus($accountId, $result['data'] ?? []);
    }

    private function synchronizeStatus(
        int $accountId,
        array $data
    ): array {
        $status = $data['status'] ?? 'disconnected';

        $phoneNumber = $data['phoneNumber'] ?? null;
        $connectedAt = $data['connectedAt'] ?? null;

        if (is_string($connectedAt) && $connectedAt !== '') {
            $connectedAt = (new \DateTimeImmutable($connectedAt))
                ->format('Y-m-d H:i:s');
        }

        $this->accounts->updateConnectionStatus(
            $accountId,
            $status,
            $phoneNumber,
            $connectedAt
        );

        return [
            'accountId' => $accountId,
            'status' => $status,
            'phoneNumber' => $phoneNumber,
            'connectedAt' => $connectedAt,
            'qrImage' => $data['qrImage'] ?? null,
            'lastError' => $data['lastError'] ?? null
        ];
    }
}
