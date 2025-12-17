<?php

namespace App\Controller;

use App\Rag\RagProfileManager;
use App\Service\ChatbotService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ChatController extends BaseController
{
    public const CONTROLLER_NAME = 'ChatController';

    public function __construct(
        private readonly RagProfileManager $profiles,
    ) {}

    /**
     * Console grafica del chatbot (ELARA).
     */
    #[Route('/ai/console', name: 'app_ai_console', methods: ['GET'])]
    public function console(): Response
    {
        return $this->render('chat/console.html.twig', [
            'controller_name' => self::CONTROLLER_NAME,
        ]);
    }

    #[Route('/engine/status', name: 'app_engine_status', methods: ['GET'])]
    public function engineStatus(): JsonResponse
    {
        $profile     = $this->profiles->getActiveProfile();
        $aiConfig    = $this->profiles->getAi();
        $backend     = $profile['backend'] ?? 'ollama';
        $profileName = $this->profiles->getActiveProfileName();
        $label       = $profile['label'] ?? ucfirst($backend);

        $status = [
            'ok' => true,
            'profile' => [
                'name'   => $profileName,
                'label'  => $label,
                'backend'=> $backend,
            ],
            'model' => $aiConfig['chat_model'] ?? 'n/d',
            'source' => ucfirst($backend),
            'test_mode'   => ($aiConfig['test_mode'] ?? false) ? 'Attivo' : 'Disabilitato',
            'offline_fallback' => ($aiConfig['offline_fallback'] ?? false) ? 'Attivo' : 'Disabilitato',
        ];
        return $this->json($status);
    }

    /**
     * API JSON del chatbot.
     * Accetta sia JSON ({"question": "..."}), sia form-encoded (question=...).
     */
    #[Route('/api/chat', name: 'app_api_chat', methods: ['POST'])]
    public function apiChat(Request $request, ChatbotService $bot): JsonResponse
    {
        // 1) Proviamo a leggere JSON
        $payload = json_decode($request->getContent() ?? '', true);
        $question = null;

        if (is_array($payload) && array_key_exists('question', $payload)) {
            $question = $payload['question'];
        } else {
            // 2) fallback su form-encoded (POST classico)
            $question = $request->request->get('question', '');
        }

        $question = trim((string) $question);

        if ($question === '') {
            return $this->json([
                'error' => 'Messaggio vuoto',
            ], 400);
        }

        $result = $bot->ask($question);

        return $this->json([
            'question' => $question,
            'answer'   => $result['answer'] ?? '',
            'sources'  => $result['sources'] ?? [],
        ]);
    }

    #[Route('/api/chat/stream', name: 'app_api_chat_stream', methods: ['POST'])]
    public function apiChatStream(Request $request, ChatbotService $bot): Response
    {
        $payload = json_decode($request->getContent() ?? '', true);
        $question = null;

        if (is_array($payload) && array_key_exists('question', $payload)) {
            $question = $payload['question'];
        } else {
            $question = $request->request->get('question', '');
        }

        $question = trim((string) $question);

        if ($question === '') {
            return $this->json([
                'error' => 'Messaggio vuoto',
            ], 400);
        }

        $response = new StreamedResponse(function () use ($bot, $question) {
            $flush = static function () {
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            };

            $sources = $bot->askStream($question, static function (string $chunk) use ($flush) {
                $payload = json_encode(['chunk' => $chunk], JSON_UNESCAPED_UNICODE);
                echo "data: " . $payload . "\n\n";
                $flush();
            });

            echo "data: " . json_encode(['done' => true, 'sources' => $sources], JSON_UNESCAPED_UNICODE) . "\n\n";
            $flush();
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }
}
