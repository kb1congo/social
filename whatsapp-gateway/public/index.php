<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Controllers\WhatsAppAccountController;
use App\Middleware\AuthMiddleware;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/app/bootstrap.php';

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$app->addRoutingMiddleware();
$app->addErrorMiddleware(
    getenv('APP_DEBUG') === 'true',
    true,
    true
);

$healthController = new HealthController();
$authController = new AuthController();
$whatsappAccountController = new WhatsAppAccountController();

$app->get('/', function ($request, $response) {
    $response->getBody()->write(json_encode([
        'application' => 'WhatsApp Gateway',
        'version' => '1.0.0',
        'status' => 'running'
    ], JSON_UNESCAPED_UNICODE));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/api/health', [$healthController, 'application']);
$app->get('/api/health/database', [$healthController, 'database']);

$app->post('/api/auth/register', [$authController, 'register']);
$app->post('/api/auth/login', [$authController, 'login']);

$app->get('/api/auth/me', function ($request, $response) {
    return \App\Services\JsonResponse::send($response, [
        'status' => 'success',
        'data' => [
            'user' => $request->getAttribute('auth_user')
        ]
    ]);
})->add(new AuthMiddleware());

$app->get('/api/whatsapp/account', [$whatsappAccountController, 'show'])
    ->add(new AuthMiddleware());

$app->run();
