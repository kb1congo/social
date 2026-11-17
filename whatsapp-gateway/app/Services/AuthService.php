<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;
use PDOException;
use RuntimeException;

final class AuthService
{
    private UserRepository $users;

    public function __construct()
    {
        $this->users = new UserRepository();
    }

    public function register(
        string $fullName,
        string $email,
        string $password
    ): int {
        $fullName = trim($fullName);
        $email = strtolower(trim($email));

        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 150) {
            throw new RuntimeException('Nom complet invalide.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new RuntimeException('Adresse email invalide.');
        }

        if (strlen($password) < 12 || strlen($password) > 72) {
            throw new RuntimeException(
                'Le mot de passe doit contenir entre 12 et 72 caractères.'
            );
        }

        if ($this->users->findByEmail($email) !== null) {
            throw new RuntimeException('Cette adresse email est déjà utilisée.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        try {
            return $this->users->create(
                $fullName,
                $email,
                $passwordHash
            );
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new RuntimeException(
                    'Cette adresse email est déjà utilisée.',
                    0,
                    $exception
                );
            }

            throw $exception;
        }
    }

    public function login(string $email, string $password): array
    {
        $user = $this->users->findByEmail(strtolower(trim($email)));

        if (
            $user === null ||
            !password_verify($password, $user['password_hash'])
        ) {
            throw new RuntimeException('Identifiants incorrects.');
        }

        if ($user['status'] !== 'active') {
            throw new RuntimeException('Ce compte n’est pas actif.');
        }

        $this->users->updateLastLogin((int) $user['id']);

        return [
            'user' => [
                'id' => (int) $user['id'],
                'full_name' => $user['full_name'],
                'email' => $user['email']
            ],
            'token' => JwtService::generate((int) $user['id'])
        ];
    }
}
