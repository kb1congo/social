<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\UserRepository;
use App\Services\JsonResponse;
use App\Services\JwtService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Throwable;

final class AuthMiddleware implements MiddlewareInterface
{
    private UserRepository $users;

    public function __construct()
    {
        $this->users = new UserRepository();
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $authorization = $request->getHeaderLine('Authorization');

        if (!preg_match('/^Bearer\s+([A-Za-z0-9_.-]+)$/i', trim($authorization), $matches)) {
            return $this->unauthorized();
        }

        try {
            $claims = JwtService::validate($matches[1]);

            if (
                !isset($claims->sub) ||
                !ctype_digit((string) $claims->sub)
            ) {
                return $this->unauthorized();
            }

            $user = $this->users->findById((int) $claims->sub);

            if ($user === null || $user['status'] !== 'active') {
                return $this->unauthorized();
            }

            $request = $request->withAttribute('auth_user', [
                'id' => (int) $user['id'],
                'full_name' => $user['full_name'],
                'email' => $user['email']
            ]);

            return $handler->handle($request);
        } catch (Throwable $exception) {
            return $this->unauthorized();
        }
    }

    private function unauthorized(): ResponseInterface
    {
        return JsonResponse::send(
            new Response(),
            [
                'status' => 'error',
                'message' => 'Authentification requise ou jeton invalide.'
            ],
            401
        );
    }
}
