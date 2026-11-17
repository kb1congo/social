<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\WhatsAppAccountService;
use App\Services\WhatsAppMessageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class InternalController
{
    public function restoreAccounts(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $service = new WhatsAppAccountService();

        $response->getBody()->write(json_encode([
            'status' => 'ok',
            'account_ids' => $service->getAccountsToRestore()
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function updateAccountStatus(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $data = $request->getParsedBody();

        if (!is_array($data)) {
            return $this->jsonResponse($response, 400, [
                'status' => 'error',
                'message' => 'Corps de requête invalide.'
            ]);
        }

        $accountId = filter_var(
            $data['accountId'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($accountId === false || $accountId <= 0) {
            return $this->jsonResponse($response, 400, [
                'status' => 'error',
                'message' => 'Identifiant de compte invalide.'
            ]);
        }

        try {
            $service = new WhatsAppAccountService();
            $service->updateAccountStatus($accountId, $data);

            return $this->jsonResponse($response, 200, [
                'status' => 'ok',
                'message' => 'Statut du compte mis à jour.'
            ]);
        } catch (Throwable $exception) {
            return $this->jsonResponse($response, 400, [
                'status' => 'error',
                'message' => $exception->getMessage()
            ]);
        }
    }

    public function receiveMessage(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $data = $request->getParsedBody();

        if (!is_array($data)) {
            return $this->jsonResponse($response, 400, [
                'status' => 'error',
                'message' => 'Corps de requête invalide.'
            ]);
        }

        try {
            $service = new WhatsAppMessageService();
            $messageId = $service->receive($data);

            return $this->jsonResponse($response, 200, [
                'status' => 'ok',
                'message' => 'Message traité.',
                'messageId' => $messageId
            ]);
        } catch (Throwable $exception) {
            return $this->jsonResponse($response, 400, [
                'status' => 'error',
                'message' => $exception->getMessage()
            ]);
        }
    }

    private function jsonResponse(
        ResponseInterface $response,
        int $statusCode,
        array $data
    ): ResponseInterface {
        $response->getBody()->write(json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
        ));

        return $response
            ->withStatus($statusCode)
            ->withHeader('Content-Type', 'application/json');
    }
}
