<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../services/Auth.php';
require_once __DIR__ . '/../models/WhatsAppAccount.php';

$auth = new Auth(Database::getInstance());

if (!$auth->check()) {
    header('Location: /');
    exit;
}

$user = $auth->user();

if (!is_array($user)) {
    header('Location: /');
    exit;
}

$accountId = trim((string) ($_GET['account'] ?? ''));

if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $accountId)) {
    header('Location: /');
    exit;
}

$accountModel = new WhatsAppAccount(Database::getInstance()->getPdo());
$account = $accountModel->findForUser((int) $user['id'], $accountId);

if (!$account) {
    http_response_code(404);
    exit('Compte WhatsApp introuvable ou accès non autorisé.');
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($_SESSION['csrf_token'] ?? '') ?>">

    <link rel="stylesheet" href="/assets/css/dialog.css">

    <title>Discussions WhatsApp</title>
</head>

<body>

<header class="dialog-topbar">
    <div class="dialog-brand">WhatsApp Personal Gateway</div>

    <div class="dialog-account">
        <span><?= e((string) $account['display_name']) ?></span>
        <span class="dialog-status">
            <?= e((string) $account['status']) ?>
        </span>
    </div>

    <a href="/" class="back-link">Mes comptes</a>
</header>

<main class="dialog-layout">

    <aside class="conversation-sidebar">
        <div class="sidebar-heading">
            <h1>Discussions</h1>
        </div>

        <div class="conversation-search">
            <input type="search" id="conversationSearch"
                   placeholder="Rechercher une discussion...">
        </div>

        <div id="conversationList" class="conversation-list">
            <div class="empty-state">
                Les discussions apparaîtront ici.
            </div>
        </div>
    </aside>

    <section class="chat-panel">

        <div id="chatHeader" class="chat-header">
            <div>
                <h2 id="contactName">Sélectionnez une discussion</h2>
                <p id="contactNumber"></p>
            </div>
        </div>

        <div id="messageList" class="message-list">
            <div class="empty-state">
                Sélectionnez un correspondant pour afficher les messages.
            </div>
        </div>

        <form id="messageForm" class="message-form">
            <input type="text" id="messageInput"
                   placeholder="Écrire un message..."
                   maxlength="4096" autocomplete="off" disabled required>

            <button type="submit" id="sendButton" disabled>
                Envoyer
            </button>
        </form>

        <div id="messageFeedback" class="message-feedback"
             aria-live="polite"></div>

    </section>

</main>

<script>
    window.WHATSAPP_ACCOUNT_ID = <?= json_encode(
        $accountId,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    ) ?>;
</script>

<script src="/assets/js/dialog.js" defer></script>

</body>
</html>