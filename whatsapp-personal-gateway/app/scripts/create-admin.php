<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Accès interdit.\n");
}

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: 'mysql',
        getenv('DB_PORT') ?: '3306',
        getenv('DB_DATABASE') ?: 'whatsapp_gateway'
    ),
    getenv('DB_USERNAME') ?: 'gateway',
    getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function ask(string $label): string
{
    echo $label;
    return trim((string) fgets(STDIN));
}

$name = ask('Nom complet : ');
$email = strtolower(ask('Adresse e-mail : '));

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Nom ou adresse e-mail invalide.\n");
}

echo "Mot de passe : ";

if (DIRECTORY_SEPARATOR === '\\') {
    $password = trim((string) fgets(STDIN));
} else {
    system('stty -echo');
    $password = rtrim((string) fgets(STDIN), "\r\n");
    system('stty echo');
    echo PHP_EOL;
}

if (strlen($password) < 12) {
    exit("Le mot de passe doit contenir au moins 12 caractères.\n");
}

$check = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$check->execute([$email]);

if ($check->fetch()) {
    exit("Un compte existe déjà avec cette adresse e-mail.\n");
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare(
    "INSERT INTO users (full_name, email, password_hash, role, is_active)
     VALUES (?, ?, ?, 'admin', 1)"
);

$stmt->execute([$name, $email, $hash]);

echo "Compte administrateur créé avec succès.\n";
