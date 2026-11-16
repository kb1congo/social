<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\WhatsAppAccountRepository;
use RuntimeException;

final class WhatsAppAccountService
{
    private WhatsAppAccountRepository $accounts;

    public function __construct()
    {
        $this->accounts = new WhatsAppAccountRepository();
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
        } catch (\PDOException $exception) {
            // Une création concurrente peut avoir déjà créé le compte.
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
}
