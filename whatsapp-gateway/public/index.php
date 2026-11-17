<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\ConversationController;
use App\Controllers\HealthController;
use App\Controllers\InternalController;
use App\Controllers\WhatsAppAccountController;
use App\Middleware\AuthMiddleware;
use App\Middleware\InternalApiMiddleware;
use App\Services\JsonResponse;
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
$conversationController = new ConversationController();
$internalController = new InternalController();

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
    return JsonResponse::send($response, [
        'status' => 'success',
        'data' => [
            'user' => $request->getAttribute('auth_user')
        ]
    ]);
})->add(new AuthMiddleware());

// Gestion du compte WhatsApp de l'utilisateur authentifié.
$app->get(
    '/api/whatsapp/account',
    [$whatsappAccountController, 'show']
)->add(new AuthMiddleware());

$app->post(
    '/api/whatsapp/connect',
    [$whatsappAccountController, 'connect']
)->add(new AuthMiddleware());

$app->get(
    '/api/whatsapp/status',
    [$whatsappAccountController, 'status']
)->add(new AuthMiddleware());

$app->post(
    '/api/whatsapp/disconnect',
    [$whatsappAccountController, 'disconnect']
)->add(new AuthMiddleware());

$app->post(
    '/api/whatsapp/logout',
    [$whatsappAccountController, 'logout']
)->add(new AuthMiddleware());


// Consultation des conversations et de leurs messages.
$app->get(
    '/api/conversations',
    [$conversationController, 'index']
)->add(new AuthMiddleware());

$app->get(
    '/api/conversations/{id}/messages',
    [$conversationController, 'messages']
)->add(new AuthMiddleware());

$app->post(
    '/api/conversations/{id}/messages',
    [$conversationController, 'sendMessage']
)->add(new AuthMiddleware());


// Route interne réservée au service Node.js.
$app->get(
    '/internal/accounts/restore',
    [$internalController, 'restoreAccounts']
)->add(new InternalApiMiddleware());

$app->post(
    '/internal/accounts/status',
    [$internalController, 'updateAccountStatus']
)->add(new InternalApiMiddleware());

$app->post(
    '/internal/messages',
    [$internalController, 'receiveMessage']
)->add(new InternalApiMiddleware());

$app->run();
