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

            // Exposer uniquement une URL API, jamais le chemin physique du média.
            foreach ($messages as &$message) {
                if (!empty($message['media_path'])) {
                    $message['media_url'] = '/api/messages/' .
                        (int) $message['id'] . '/media';
                } else {
                    $message['media_url'] = null;
                }

                unset($message['media_path']);
            }
            unset($message);

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

    public function media(
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

            $messageId = filter_var(
                $args['id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if ($messageId === false || $messageId === null || $messageId <= 0) {
                return JsonResponse::send($response, [
                    'status' => 'error',
                    'message' => 'Identifiant de message invalide.'
                ], 400);
            }

            $account = $this->accountRepository->findByUserId($userId);

            if (!$account) {
                return JsonResponse::send($response, [
                    'status' => 'error',
                    'message' => 'Média introuvable.'
                ], 404);
            }

            $accountId = (int) $account['id'];
            $message = $this->messageRepository->findById(
                $messageId,
                $accountId
            );

            if (!$message || empty($message['media_path'])) {
                return JsonResponse::send($response, [
                    'status' => 'error',
                    'message' => 'Média introuvable.'
                ], 404);
            }

            $relativePath = (string) $message['media_path'];

            if (!preg_match(
                '#^' . preg_quote((string) $accountId, '#') . '/[a-f0-9]{64}(?:\.[A-Za-z0-9]{1,10})?$#D',
                $relativePath
            )) {
                return JsonResponse::send($response, [
                    'status' => 'error',
                    'message' => 'Chemin du média invalide.'
                ], 404);
            }

            $storageRoot = getenv('MEDIA_STORAGE_PATH');

            if ($storageRoot === false || $storageRoot === '') {
                throw new \RuntimeException('MEDIA_STORAGE_PATH non configuré.');
            }

            $realRoot = realpath($storageRoot);

            if ($realRoot === false || !is_dir($realRoot)) {
                throw new \RuntimeException('Répertoire des médias inaccessible.');
            }

            $filePath = realpath(
                $realRoot . DIRECTORY_SEPARATOR .
                str_replace('/', DIRECTORY_SEPARATOR, $relativePath)
            );

            if (
                $filePath === false ||
                !is_file($filePath) ||
                !is_readable($filePath) ||
                strpos($filePath, $realRoot . DIRECTORY_SEPARATOR) !== 0
            ) {
                return JsonResponse::send($response, [
                    'status' => 'error',
                    'message' => 'Fichier média introuvable.'
                ], 404);
            }

            $mimeType = (string) ($message['media_mime_type'] ?? '');

            if (!preg_match('/^[a-zA-Z0-9.+-]+\/[a-zA-Z0-9.+-]+$/D', $mimeType)) {
                $mimeType = 'application/octet-stream';
            }

            $inlineTypes = [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp'
            ];

            $disposition = in_array($mimeType, $inlineTypes, true)
                ? 'inline'
                : 'attachment';

            $fileName = (string) ($message['file_name'] ?? 'media');

            $fileName = preg_replace(
                '/[\\\\\/:*?"<>|\x00-\x1F\x7F]/',
                '_',
                $fileName
            ) ?? 'media';

            $fileName = trim($fileName);

            if ($fileName === '') {
                $fileName = 'media';
            }

            $fallbackName = preg_replace(
                '/[^A-Za-z0-9._-]/',
                '_',
                $fileName
            ) ?? 'media';

            $fallbackName = trim($fallbackName, '._');

            if ($fallbackName === '') {
                $fallbackName = 'media';
            }

            $fileSize = filesize($filePath);

            if ($fileSize === false) {
                throw new \RuntimeException('Impossible de déterminer la taille du média.');
            }

            $handle = fopen($filePath, 'rb');

            if ($handle === false) {
                throw new \RuntimeException('Impossible d’ouvrir le média.');
            }

            try {
                while (!feof($handle)) {
                    $chunk = fread($handle, 8192);

                    if ($chunk === false) {
                        throw new \RuntimeException('Erreur de lecture du média.');
                    }

                    if ($chunk === '') {
                        break;
                    }

                    $response->getBody()->write($chunk);
                }
            } finally {
                fclose($handle);
            }

            return $response
                ->withHeader('Content-Type', $mimeType)
                ->withHeader('Content-Length', (string) $fileSize)
                ->withHeader('Content-Disposition',
                    $disposition . '; filename="' . $fallbackName .
                    '"; filename*=UTF-8\'\'' . rawurlencode($fileName)
                )
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', 'private, no-store');

        } catch (Throwable $e) {
            error_log('[ConversationController::media] ' . $e->getMessage());

            return JsonResponse::send($response, [
                'status' => 'error',
                'message' => 'Impossible de récupérer le média.'
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
