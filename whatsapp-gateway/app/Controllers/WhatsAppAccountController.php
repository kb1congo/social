<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\JsonResponse;
use App\Services\WhatsAppAccountService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class WhatsAppAccountController
{
    private WhatsAppAccountService $accounts;

    public function __construct()
    {
        $this->accounts = new WhatsAppAccountService();
    }

    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        try {
            $user = $request->getAttribute('auth_user');

            $account = $this->accounts->getOrCreate(
                (int) $user['id']
            );

            return JsonResponse::send($response, [
                'status' => 'success',
                'data' => [
                    'whatsapp_account' => $account
                ]
            ]);
        } catch (Throwable $exception) {
            return JsonResponse::send($response, [
                'status' => 'error',
                'message' => 'Impossible de récupérer le compte WhatsApp.'
            ], 500);
        }
    }

    public function connect(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return $this->executeAction(
            $request,
            $response,
            fn (int $userId): array => $this->accounts->connect($userId),
            'Impossible de démarrer la connexion WhatsApp.'
        );
    }

    public function status(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return $this->executeAction(
            $request,
            $response,
            fn (int $userId): array => $this->accounts->getStatus($userId),
            'Impossible de récupérer le statut WhatsApp.'
        );
    }

    public function disconnect(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return $this->executeAction(
            $request,
            $response,
            fn (int $userId): array => $this->accounts->disconnect($userId),
            'Impossible de déconnecter WhatsApp.'
        );
    }

    public function logout(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return $this->executeAction(
            $request,
            $response,
            fn (int $userId): array => $this->accounts->logout($userId),
            'Impossible de fermer la session WhatsApp.'
        );
    }

    private function executeAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        callable $action,
        string $errorMessage
    ): ResponseInterface {
        try {
            $user = $request->getAttribute('auth_user');
            $result = $action((int) $user['id']);

            return JsonResponse::send($response, [
                'status' => 'success',
                'data' => $result
            ]);
        } catch (Throwable $exception) {
            error_log(
                'WhatsAppAccountController: '
                . get_class($exception)
                . ' - '
                . $exception->getMessage()
            );
            return JsonResponse::send($response, [
                'status' => 'error',
                'message' => $errorMessage
            ], 500);
        }
    }
}
