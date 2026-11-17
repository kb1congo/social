<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Services\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class HealthController
{
    public function application(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return JsonResponse::send($response, [
            'status' => 'ok',
            'service' => 'php-api'
        ]);
    }

    public function database(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        try {
            $pdo = Database::getPdo();
            $databaseName = $pdo->query('SELECT DATABASE()')->fetchColumn();

            return JsonResponse::send($response, [
                'status' => 'ok',
                'service' => 'php-api',
                'database' => [
                    'status' => 'connected',
                    'name' => $databaseName
                ]
            ]);
        } catch (Throwable $exception) {
            return JsonResponse::send($response, [
                'status' => 'error',
                'service' => 'php-api',
                'database' => [
                    'status' => 'unavailable'
                ]
            ], 503);
        }
    }

    public function __construct()
    {
    }
}
