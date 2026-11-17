<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class NodeApiService
{
    private string $baseUrl;
    private string $apiSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            (string) \App\Config\Config::require('NODE_INTERNAL_URL'),
            '/'
        );

        $this->apiSecret = (string) \App\Config\Config::require(
            'NODE_API_SECRET'
        );

        if (!extension_loaded('curl')) {
            throw new RuntimeException(
                'L’extension cURL de PHP est indisponible.'
            );
        }
    }

    public function request(
        string $method,
        string $endpoint,
        ?array $payload = null
    ): array {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $curl = curl_init($url);

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiSecret
        ];

        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20
        ]);

        if ($payload !== null) {
            curl_setopt(
                $curl,
                CURLOPT_POSTFIELDS,
                json_encode($payload, JSON_THROW_ON_ERROR)
            );
        }

        $response = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);

        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException(
                'Communication avec le service WhatsApp impossible : '
                . $curlError
            );
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new RuntimeException(
                'Réponse JSON invalide du service WhatsApp.'
            );
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(
                $data['message'] ?? 'Erreur du service WhatsApp.'
            );
        }

        return $data;
    }
}
