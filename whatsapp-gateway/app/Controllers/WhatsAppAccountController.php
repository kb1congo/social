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
}
