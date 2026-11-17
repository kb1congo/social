<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\WhatsAppAccountRepository;
use App\Repositories\ConversationRepository;
use App\Repositories\MessageRepository;
use App\Services\JsonResponse;
use App\Services\NodeApiService;
use App\Services\WhatsAppMessageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class ConversationController
{
    private WhatsAppAccountRepository $accountRepository;
    private ConversationRepository $conversationRepository;
    private MessageRepository $messageRepository;
    private NodeApiService $nodeApi;
    private WhatsAppMessageService $whatsappMessages;

    public function __construct()
    {
        $this->accountRepository = new WhatsAppAccountRepository();
        $this->conversationRepository = new ConversationRepository();
        $this->messageRepository = new MessageRepository();
        $this->nodeApi = new NodeApiService();
        $this->whatsappMessages = new WhatsAppMessageService();
    }

    public function index(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        try {
            $user = $request->getAttribute('auth_user');
            $userId = (int) ($user['id'] ?? 0);

            if ($userId <= 0) {
                return JsonResponse::send($response, [
                    'status' => 'error',
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            $account = $this->accountRepository->findByUserId($userId);

            if (!$account) {
                return JsonResponse::send($response, [
                    'status' => 'success',
                    'data' => ['conversations' => []]
                ]);
            }

            $conversations = $this->conversationRepository->findAllByAccount(
                (int) $account['id']
            );

            return JsonResponse::send($response, [
                'status' => 'success',
                'data' => [
                    'conversations' => $conversations
                ]
            ]);
        } catch (Throwable $e) {
            error_log('[ConversationController::index] ' . $e->getMessage());

            return JsonResponse::send($response, [
                'status' => 'error',
                'message' => 'Impossible de récupérer les conversations.'
            ], 500);
        }
    }

    public function messages(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        try {
            $user = $request->getAttribute('auth_user');
            $userId = (int) ($user['id'] ?? 0);

            if ($userId <= 0) {
                return JsonResponse::send($response, [
                    'status' => 'error',
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            $conversationId = filter_var(
                $args['id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if ($conversationId === false || $conversationId === null || $conversationId <= 0) {
                return JsonResponse::send($response, [
                    'status' => 'error',
                    'message' => 'Identifiant de conversation invalide.'
                ], 400);
            }

            $query = $request->getQueryParams();

            $limit = 50;
            if (isset($query['limit'])) {
                $validatedLimit = filter_var($query['limit'], FILTER_VALIDATE_INT);

                if ($validatedLimit === false || $validatedLimit <= 0) {
                    return JsonResponse::send($response, [
                        'status' => 'error',
                        'message' => 'Le paramètre limit doit être un entier positif.'
                    ], 400);
                }

                $limit = min($validatedLimit, 100);
            }

            $beforeId = null;
            if (isset($query['before_id']) && $query['before_id'] !== '') {
                $validatedBeforeId = filter_var(
                    $query['before_id'],
                    FILTER_VALIDATE_INT
                );

                if ($validatedBeforeId === false || $validatedBeforeId <= 0) {
                    return JsonResponse::send($response, [
                        'status' => 'error',
                        'message' => 'Le paramètre before_id doit être un entier positif.'
                    ], 400);
                }

                $beforeId = $validatedBeforeId;
            }

            $account = $this->accountRepository->findByUserId($userId);

            if (!$account) {
                return JsonResponse::send($response, [
                    'status' => 'error',
                    'message' => 'Conversation introuvable.'
                ], 404);
            }

            $accountId = (int) $account['id'];

            $conversation = $this->conversationRepository->findById(
                $conversationId,
                $accountId
            );

            if (!$conversation) {
                return JsonResponse::send($response, [
                    'status' => 'error',
                    'message' => 'Conversation introuvable.'
                ], 404);
            }

            $messages = $this->messageRepository->findByConversation(
                $conversationId,
                $accountId,
                $limit,
                $beforeId
            );

            $participants = $this->conversationRepository->findParticipants(
                $conversationId
            );

            return JsonResponse::send($response, [
                'status' => 'success',
                'data' => [
                    'conversation' => $conversation,
                    'participants' => $participants,
                    'messages' => $messages,
                    'pagination' => [
                        'limit' => $limit,
                        'before_id' => $beforeId
                    ]
                ]
            ]);
        } catch (Throwable $e) {
            error_log('[ConversationController::messages] ' . $e->getMessage());

            return JsonResponse::send($response, [
                'status' => 'error',
                'message' => 'Impossible de récupérer les messages.'
            ], 500);
        }
    }

    public function sendMessage(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        try {
            $user = $request->getAttribute('auth_user');
            $userId = (int) ($user['id'] ?? 0);

            if ($userId <= 0) {
                return JsonResponse::send($response, [
                    'status' => 'error',
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            $conversationId = filter_var(
                $args['id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if ($conversationId === false || $conversationId === null || $conversationId <= 0) {
                return JsonResponse::send($response, [
                    'status' => 'error',
                    'message' => 'Identifiant de conversation invalide.'
                ], 400);
            }

            $body = $request->getParsedBody();
            $text = is_array($body) && isset($body['text']) && is_string($body['text'])
                ? trim($body['text'])
                : '';

            if ($text === '') {
                return JsonResponse::send($response, [
                    'status' => 'error',
                    'message' => 'Le texte du message est obligatoire.'
                ], 400);
            }

            $account = $this->accountRepository->findByUserId($userId);

            if (!$account || ($account['status'] ?? '') !== 'connected') {
                return JsonResponse::send($response, [
                    'status' => 'error',
                    'message' => 'Aucun compte WhatsApp connecté.'
                ], 409);
            }

            $accountId = (int) $account['id'];

            $conversation = $this->conversationRepository->findById(
                $conversationId,
                $accountId
            );

            if (!$conversation) {
                return JsonResponse::send($response, [
                    'status' => 'error',
                    'message' => 'Conversation introuvable.'
                ], 404);
            }

            $nodeResponse = $this->nodeApi->request(
                'POST',
                "/internal/accounts/{$accountId}/messages/text",
                [
                    'remoteJid' => $conversation['remote_jid'],
                    'text' => $text
                ]
            );

            $sentMessage = $nodeResponse['data'] ?? null;

            if (
                !is_array($sentMessage) ||
                empty($sentMessage['messageId']) ||
                empty($sentMessage['selfJid'])
            ) {
                return JsonResponse::send($response, [
                    'status' => 'error',
                    'message' => 'Réponse invalide du service WhatsApp.'
                ], 502);
            }

            $timestamp = filter_var(
                $sentMessage['timestamp'] ?? time(),
                FILTER_VALIDATE_INT
            );

            if ($timestamp === false || $timestamp <= 0) {
                $timestamp = time();
            }

            $databaseId = $this->whatsappMessages->storeSent(
                $accountId,
                $conversationId,
                (string) $conversation['remote_jid'],
                (string) $sentMessage['selfJid'],
                (string) $sentMessage['messageId'],
                $text,
                $timestamp
            );

            return JsonResponse::send($response, [
                'status' => 'success',
                'data' => [
                    'id' => $databaseId,
                    'conversation_id' => $conversationId,
                    'whatsapp_message_id' => $sentMessage['messageId'],
                    'body' => $text,
                    'from_me' => true,
                    'delivery_status' => 'sent',
                    'sent_at' => gmdate('Y-m-d H:i:s', $timestamp)
                ]
            ], 201);

        } catch (Throwable $e) {
            error_log('[ConversationController::sendMessage] ' . $e->getMessage());

            return JsonResponse::send($response, [
                'status' => 'error',
                'message' => 'Impossible d’envoyer le message.'
            ], 500);
        }
    }


}
