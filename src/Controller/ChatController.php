<?php

namespace App\Controller;

use App\Service\ChatbotService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ChatController extends BaseController
{
    public const CONTROLLER_NAME = 'ChatController';

    /**
     * Console grafica del chatbot (ELARA).
     */
    #[Route('/ai/console', name: 'app_ai_console', methods: ['GET'])]
    public function console(): Response
    {
        $apiToken = $_ENV['APP_CHAT_CONSOLE_TOKEN'] ?? null;

        return $this->render('chat/console.html.twig', [
            'controller_name' => self::CONTROLLER_NAME,
            'chat_api_token' => $apiToken,
        ]);
    }

    #[Route('/engine/status', name: 'app_engine_status', methods: ['GET'])]
    public function engineStatus(): JsonResponse
    {
        $backend = $_ENV['AI_BACKEND'] ?? 'ollama';
        $modelMap = [
            'ollama' => $_ENV['OLLAMA_CHAT_MODEL'] ?? 'llama3.2',
            'openai' => $_ENV['OPENAI_CHAT_MODEL'] ?? 'gpt-5.1-mini',
            'gemini' => $_ENV['GEMINI_CHAT_MODEL'] ?? 'gemini-1.5-flash',
        ];

        $status = [
            'ok' => true,
            'model' => $modelMap[$backend] ?? 'n/d',
            'source' => ucfirst($backend),
            'test_mode'   => ($_ENV['APP_AI_TEST_MODE'] ?? 'false') === "true" ? "Attivo" : "Disabilitato",
            'offline_fallback' => ($_ENV['APP_AI_OFFLINE_FALLBACK'] ?? 'false') === "true" ? "Attivo" : "Disabilitato",
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

        $answer = $bot->ask($question);

        return $this->json([
            'question' => $question,
            'answer'   => $answer,
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

            $bot->askStream($question, static function (string $chunk) use ($flush) {
                $payload = json_encode(['chunk' => $chunk], JSON_UNESCAPED_UNICODE);
                echo "data: " . $payload . "\n\n";
                $flush();
            });

            echo "data: " . json_encode(['done' => true]) . "\n\n";
            $flush();
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }
}
