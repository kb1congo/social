<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../services/Auth.php';
require_once __DIR__ . '/../controllers/WhatsAppAccountController.php';
require_once __DIR__ . '/../models/WhatsAppAccount.php';

$auth = new Auth(Database::getInstance());

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (is_string($path) && str_starts_with($path, '/api/whatsapp')) {
    header('Content-Type: application/json; charset=utf-8');

    $respond = static function (array $data, int $status = 200): never {
        http_response_code($status);
        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    };

    if (!$auth->check()) {
        $respond(['error' => 'Authentification requise.'], 401);
    }

    $method = $_SERVER['REQUEST_METHOD'];

    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        if (
            !is_string($csrf) ||
            !hash_equals($_SESSION['csrf_token'], $csrf)
        ) {
            $respond(['error' => 'Jeton CSRF invalide.'], 403);
        }
    }

    try {
        $user = $auth->user();

        if (!is_array($user)) {
            $respond(['error' => 'Utilisateur introuvable.'], 401);
        }

        $pdo = Database::getInstance()->getPdo();

        $controller = new WhatsAppAccountController(
            $pdo,
            (int) $user['id']
        );

        $accountModel = new WhatsAppAccount($pdo);

        $input = json_decode(file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];

        // Liste des comptes WhatsApp.
        if ($method === 'GET' && $path === '/api/whatsapp/accounts') {
            $respond([
                'accounts' => $controller->index(),
            ]);
        }

        // Liste des conversations d'un compte WhatsApp.
        if (
            $method === 'GET' &&
            preg_match(
                '#^/api/whatsapp/accounts/([a-zA-Z0-9_-]{1,64})/conversations$#',
                $path,
                $matches
            )
        ) {
            $account = $accountModel->findForUser(
                (int) $user['id'],
                $matches[1]
            );

            if (!$account) {
                $respond(['error' => 'Compte WhatsApp introuvable.'], 404);
            }

            $stmt = $pdo->prepare(
                'SELECT
                    v.id AS conversation_id,
                    c.id AS contact_id,
                    c.whatsapp_id,
                    c.display_name,
                    c.phone_number,
                    v.status,
                    v.last_message_at,
                    (
                        SELECT m.body
                        FROM messages m
                        WHERE m.conversation_id = v.id
                        ORDER BY m.id DESC
                        LIMIT 1
                    ) AS last_message,
                    (
                        SELECT m.direction
                        FROM messages m
                        WHERE m.conversation_id = v.id
                        ORDER BY m.id DESC
                        LIMIT 1
                    ) AS last_direction,
                    (
                        SELECT m.message_type
                        FROM messages m
                        WHERE m.conversation_id = v.id
                        ORDER BY m.id DESC
                        LIMIT 1
                    ) AS last_message_type
                 FROM conversations v
                 INNER JOIN contacts c ON c.id = v.contact_id
                 WHERE c.whatsapp_account_id = :account_id
                 ORDER BY v.last_message_at DESC, v.id DESC
                 LIMIT 200'
            );

            $stmt->execute([
                'account_id' => (int) $account['id'],
            ]);

            $respond([
                'conversations' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            ]);
        }

        // Historique des messages d'une conversation.
        if (
            $method === 'GET' &&
            preg_match(
                '#^/api/whatsapp/accounts/([a-zA-Z0-9_-]{1,64})/conversations/([0-9]+)/messages$#',
                $path,
                $matches
            )
        ) {
            $account = $accountModel->findForUser(
                (int) $user['id'],
                $matches[1]
            );

            if (!$account) {
                $respond(['error' => 'Compte WhatsApp introuvable.'], 404);
            }

            $conversationId = (int) $matches[2];

            // Vérifier que la conversation appartient au compte.
            $stmt = $pdo->prepare(
                'SELECT v.id
                 FROM conversations v
                 INNER JOIN contacts c ON c.id = v.contact_id
                 WHERE v.id = :conversation_id
                   AND c.whatsapp_account_id = :account_id
                 LIMIT 1'
            );

            $stmt->execute([
                'conversation_id' => $conversationId,
                'account_id' => (int) $account['id'],
            ]);

            if (!$stmt->fetchColumn()) {
                $respond(['error' => 'Conversation introuvable.'], 404);
            }

            // Récupérer les 100 derniers messages.
            $stmt = $pdo->prepare(
                'SELECT
                    id,
                    whatsapp_message_id,
                    direction,
                    message_type,
                    body,
                    whatsapp_timestamp,
                    created_at
                 FROM messages
                 WHERE conversation_id = :conversation_id
                 ORDER BY id DESC
                 LIMIT 100'
            );

            $stmt->execute([
                'conversation_id' => $conversationId,
            ]);

            $messages = array_reverse(
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );

            $respond([
                'conversationId' => $conversationId,
                'messages' => $messages,
            ]);
        }

        // Envoyer un message WhatsApp.
        if (
            $method === 'POST' &&
            preg_match(
                '#^/api/whatsapp/accounts/([a-zA-Z0-9_-]{1,64})/send$#',
                $path,
                $matches
            )
        ) {
            $result = $controller->sendMessage(
                $matches[1],
                (string) ($input['recipient'] ?? ''),
                (string) ($input['message'] ?? '')
            );

            $respond($result);
        }

        // Créer un compte WhatsApp.
        if ($method === 'POST' && $path === '/api/whatsapp/accounts') {
            $respond(
                $controller->create(
                    (string) ($input['displayName'] ?? '')
                ),
                201
            );
        }

        // Connexion, statut et QR code.
        if (
            preg_match(
                '#^/api/whatsapp/accounts/([a-zA-Z0-9_-]{1,64})/(connect|status|qr)$#',
                $path,
                $matches
            )
        ) {
            $accountId = $matches[1];
            $action = $matches[2];

            if ($method === 'POST' && $action === 'connect') {
                $respond($controller->connect($accountId));
            }

            if ($method === 'GET' && $action === 'status') {
                $respond($controller->status($accountId));
            }

            if ($method === 'GET' && $action === 'qr') {
                $respond($controller->qr($accountId));
            }
        }

        $respond(['error' => 'Route API introuvable.'], 404);

    } catch (InvalidArgumentException $e) {
        $respond(['error' => $e->getMessage()], 400);

    } catch (Throwable $e) {
        error_log('WhatsApp API: ' . $e->getMessage());

        $respond([
            'error' => 'Une erreur est survenue lors du traitement de la requête.',
        ], 500);
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        exit('Requête invalide. Rechargez la page.');
    }

    if (isset($_POST['logout'])) {
        $auth->logout();
        header('Location: /');
        exit;
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($auth->login($email, $password)) {
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        header('Location: /');
        exit;
    }

    $error = 'Adresse e-mail ou mot de passe incorrect.';
}

$user = $auth->user();

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="fr">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="<?= e($_SESSION['csrf_token']) ?>" />

    <link rel="stylesheet" href="/assets/css/app.css" />

    <title>WhatsApp Personal Gateway</title>
</head>

<body>

    <?php if ($auth->check() && is_array($user)): ?>

        <header class="topbar">
            <div class="brand">WhatsApp Personal Gateway</div>
            <div class="user-area">
                <span class="user-name"><?= e((string) ($user['full_name'] ?? 'Administrateur')) ?></span>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <button class="logout" type="submit" name="logout" value="1">Déconnexion</button>
                </form>
            </div>
        </header>

        <main class="container">
            <section class="heading">
                <div>
                    <h1>Mes comptes WhatsApp</h1>
                    <p class="subtitle">Gérez plusieurs numéros WhatsApp depuis une seule interface.</p>
                </div>
                <button class="primary" id="refreshButton" type="button">Actualiser</button>
            </section>

            <section class="card">
                <h2>Ajouter un compte WhatsApp</h2>
                <form id="addAccountForm" class="form-row">
                    <input type="text" id="displayName" maxlength="120"
                        placeholder="Ex. WhatsApp commercial" required>
                    <button class="primary" type="submit">Ajouter un numéro</button>
                </form>
                <div id="globalMessage" class="message" aria-live="polite"></div>
            </section>

            <section>
                <h2>Comptes enregistrés</h2>
                <div id="accountList" class="account-list">
                    <div class="empty">Chargement des comptes...</div>
                </div>
            </section>

            <div class="footer">WhatsApp Personal Gateway</div>
        </main>

        <script src="/assets/js/app.js" defer></script>

    <?php else: ?>

        <div class="login-wrap">
            <main class="login">
                <h1>WhatsApp Personal Gateway</h1>
                <p class="subtitle">Connectez-vous pour accéder à votre espace d'administration.</p>

                <?php if ($error !== ''): ?>
                    <div class="error"><?= e($error) ?></div>
                <?php endif; ?>

                <form method="post" autocomplete="on">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

                    <label for="email">Adresse e-mail</label>
                    <input type="email" id="email" name="email" autocomplete="username" required>

                    <label for="password">Mot de passe</label>
                    <input type="password" id="password" name="password" autocomplete="current-password" required>

                    <button class="primary" type="submit">Se connecter</button>
                </form>

                <div class="footer">WhatsApp Personal Gateway</div>
            </main>
        </div>

    <?php endif; ?>

</body>

</html>
