<?php
declare(strict_types=1);

final class Auth
{
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /**
     * Authentifie un utilisateur.
     */
    public function login(string $email, string $password): bool
    {
        $stmt = $this->database->getPdo()->prepare(
            'SELECT id, full_name, email, password_hash, role
             FROM users
             WHERE email = :email
               AND is_active = 1
             LIMIT 1'
        );

        $stmt->execute([
            'email' => trim(mb_strtolower($email)),
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$user ||
            !password_verify($password, $user['password_hash'])
        ) {
            return false;
        }

        // Renouvellement de l'identifiant pour éviter
        // la fixation de session.
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];

        return true;
    }

    /**
     * Retourne l'utilisateur connecté.
     */
    public function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    /**
     * Vérifie si un utilisateur est connecté.
     */
    public function check(): bool
    {
        return isset($_SESSION['user']['id']);
    }

    /**
     * Ferme la session de l'utilisateur.
     */
    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
