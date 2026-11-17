<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class AuthController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function register(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        try {
            $data = (array) $request->getParsedBody();

            $userId = $this->auth->register(
                (string) ($data['full_name'] ?? ''),
                (string) ($data['email'] ?? ''),
                (string) ($data['password'] ?? '')
            );

            return JsonResponse::send($response, [
                'status' => 'success',
                'message' => 'Compte créé avec succès.',
                'user_id' => $userId
            ], 201);
        } catch (Throwable $exception) {
            return JsonResponse::send($response, [
                'status' => 'error',
                'message' => $exception->getMessage()
            ], 422);
        }
    }

    public function login(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        try {
            $data = (array) $request->getParsedBody();

            $result = $this->auth->login(
                (string) ($data['email'] ?? ''),
                (string) ($data['password'] ?? '')
            );

            return JsonResponse::send($response, [
                'status' => 'success',
                'message' => 'Connexion réussie.',
                'data' => $result
            ]);
        } catch (Throwable $exception) {
            return JsonResponse::send($response, [
                'status' => 'error',
                'message' => $exception->getMessage()
            ], 401);
        }
    }
}
