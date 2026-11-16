<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../services/WhatsAppMessageStore.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Vérifier le token interne.
    $expectedToken = (string) getenv('INTERNAL_API_TOKEN');
    $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    $providedToken = preg_replace('/^Bearer\s+/i', '', $authorization);

    if (
        $expectedToken === '' ||
        !hash_equals($expectedToken, $providedToken)
    ) {
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'error' => 'unauthorized',
        ]);

        exit;
    }

    // Vérifier la méthode HTTP.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);

        echo json_encode([
            'success' => false,
            'error' => 'method_not_allowed',
        ]);

        exit;
    }

    // Lire et décoder le payload JSON.
    $rawPayload = file_get_contents('php://input');

    $payload = json_decode(
        $rawPayload,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!is_array($payload)) {
        throw new InvalidArgumentException('Payload invalide.');
    }

    // Initialiser le gestionnaire de stockage.
    $store = new WhatsAppMessageStore(
        Database::getInstance()->getPdo()
    );

    // Déterminer le type d'événement.
    $eventType = trim((string) ($payload['eventType'] ?? 'message'));

    switch ($eventType) {

        case 'message':

            // Enregistrement classique des messages.
            $result = $store->store($payload);

            break;

        case 'contact_mapping':

            // Enregistrement de la correspondance LID/JID.
            $result = $store->storeContactMapping($payload);

            break;

        default:

            throw new InvalidArgumentException(
                'Type d’événement WhatsApp non pris en charge.'
            );
    }

    // Réponse positive.
    echo json_encode([
        'success' => true,
        'eventType' => $eventType,
        'result' => $result,
    ], JSON_UNESCAPED_UNICODE);

} catch (InvalidArgumentException $error) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $error) {

    error_log('[WhatsApp webhook] ' . $error->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'internal_error',
    ], JSON_UNESCAPED_UNICODE);
}
