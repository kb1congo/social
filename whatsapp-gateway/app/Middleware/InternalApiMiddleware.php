<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class InternalApiMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $expectedSecret = getenv('NODE_API_SECRET') ?: '';
        $authorization = $request->getHeaderLine('Authorization');

        if (
            $expectedSecret === '' ||
            !preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) ||
            !hash_equals($expectedSecret, trim($matches[1]))
        ) {
            $response = new Response(401);
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Accès non autorisé.'
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
