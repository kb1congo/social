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
     * Récupère le statut de la connexion WhatsApp.
     */
    public function getStatus(): array
    {
        return $this->request('/internal/whatsapp/status');
    }

    /**
     * Récupère le QR code WhatsApp courant.
     */
    public function getQr(): array
    {
        return $this->request('/internal/whatsapp/qr');
    }

    private function request(string $endpoint): array
    {
        $url = $this->baseUrl . $endpoint;

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'Authorization: Bearer ' . $this->token,
                    'Accept: application/json',
                ],
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new RuntimeException(
                'Impossible de contacter la passerelle WhatsApp.'
            );
        }

        $statusLine = $http_response_header[0] ?? '';

        if (!preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
            throw new RuntimeException('Réponse HTTP invalide de Node.js.');
        }

        $httpStatus = (int) $matches[1];

        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new RuntimeException('Réponse JSON invalide de Node.js.');
        }

        if ($httpStatus >= 400) {
            throw new RuntimeException(
                $data['error'] ?? 'Erreur de la passerelle WhatsApp.'
            );
        }

        return $data;
    }
}
