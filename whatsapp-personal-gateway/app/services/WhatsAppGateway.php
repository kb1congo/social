<?php
declare(strict_types=1);

final class WhatsAppGateway
{
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            getenv('NODE_INTERNAL_URL') ?: 'http://node:3000',
            '/'
        );

        $this->token = getenv('INTERNAL_API_TOKEN') ?: '';

        if ($this->token === '') {
            throw new RuntimeException('Jeton interne non configuré.');
        }
    }

    /**
     * Liste tous les comptes WhatsApp connus de Baileys.
     */
    public function listAccounts(): array
    {
        $result = $this->request(
            'GET',
            '/internal/whatsapp/accounts'
        );

        return $result['accounts'] ?? [];
    }

    /**
     * Démarre ou reprend une connexion WhatsApp.
     */
    public function connectAccount(string $accountId): array
    {
        $this->validateAccountId($accountId);

        return $this->request(
            'POST',
            '/internal/whatsapp/accounts/' . rawurlencode($accountId) . '/connect'
        );
    }

    /**
     * Récupère le statut d'un compte.
     */
    public function getAccountStatus(string $accountId): array
    {
        $this->validateAccountId($accountId);

        return $this->request(
            'GET',
            '/internal/whatsapp/accounts/' . rawurlencode($accountId) . '/status'
        );
    }

    /**
     * Récupère le QR code d'un compte.
     */
    public function getAccountQr(string $accountId): array
    {
        $this->validateAccountId($accountId);

        return $this->request(
            'GET',
            '/internal/whatsapp/accounts/' . rawurlencode($accountId) . '/qr'
        );
    }

    /**
     * Envoie un message depuis un compte déterminé.
     */
    public function sendMessage(
        string $accountId,
        string $recipient,
        string $message
    ): array {
        $this->validateAccountId($accountId);

        if (trim($recipient) === '' || trim($message) === '') {
            throw new InvalidArgumentException(
                'Le destinataire et le message sont obligatoires.'
            );
        }

        return $this->request(
            'POST',
            '/internal/whatsapp/accounts/' . rawurlencode($accountId) . '/send',
            [
                'to' => trim($recipient),
                'message' => trim($message),
            ]
        );
    }

    /**
     * Compatibilité temporaire avec les anciennes routes.
     */
    public function getStatus(): array
    {
        return $this->request(
            'GET',
            '/internal/whatsapp/status'
        );
    }

    public function getQr(): array
    {
        return $this->request(
            'GET',
            '/internal/whatsapp/qr'
        );
    }

    /**
     * Vérifie le format d'un identifiant Baileys.
     */
    private function validateAccountId(string $accountId): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $accountId)) {
            throw new InvalidArgumentException(
                'Identifiant de compte invalide.'
            );
        }
    }

    /**
     * Effectue une requête HTTP interne vers Node.js.
     */
    private function request(
        string $method,
        string $endpoint,
        ?array $payload = null
    ): array {
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ];

        $options = [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => 15,
            'ignore_errors' => true,
        ];

        if ($payload !== null) {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );

            $options['header'] .= "\r\nContent-Type: application/json";
            $options['content'] = $json;
        }

        $context = stream_context_create([
            'http' => $options,
        ]);

        $response = @file_get_contents(
            $this->baseUrl . $endpoint,
            false,
            $context
        );

        if ($response === false) {
            throw new RuntimeException(
                'Impossible de contacter la passerelle WhatsApp.'
            );
        }

        $statusLine = $http_response_header[0] ?? '';

        if (!preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
            throw new RuntimeException(
                'Réponse HTTP invalide de Node.js.'
            );
        }

        $httpStatus = (int) $matches[1];

        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new RuntimeException(
                'Réponse JSON invalide de Node.js.'
            );
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            $error = $data['error'] ?? 'whatsapp_gateway_error';

            throw new RuntimeException(
                'Erreur passerelle : ' . $error,
                $httpStatus
            );
        }

        return $data;
    }
}
