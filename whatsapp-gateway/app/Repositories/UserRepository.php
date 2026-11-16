<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use PDO;

final class UserRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getPdo();
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, full_name, email, password_hash, status,
                    email_verified_at, last_login_at, created_at
             FROM users
             WHERE email = :email
             LIMIT 1'
        );

        $statement->execute([
            'email' => strtolower(trim($email))
        ]);

        $user = $statement->fetch();

        return $user ?: null;
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, full_name, email, status,
                    email_verified_at, last_login_at, created_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );

        $statement->execute(['id' => $id]);

        $user = $statement->fetch();

        return $user ?: null;
    }

    public function create(
        string $fullName,
        string $email,
        string $passwordHash
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash)
             VALUES (:full_name, :email, :password_hash)'
        );

        $statement->execute([
            'full_name' => trim($fullName),
            'email' => strtolower(trim($email)),
            'password_hash' => $passwordHash
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateLastLogin(int $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET last_login_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $statement->execute(['id' => $id]);
    }
}
