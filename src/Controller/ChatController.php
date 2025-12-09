<?php

namespace App\Controller;

use App\Service\ChatbotService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        return $this->render('chat/console.html.twig', [
            'controller_name' => self::CONTROLLER_NAME,
        ]);
    }

    #[Route('/engine/status', name: 'app_engine_status', methods: ['GET'])]
    public function engineStatus(): JsonResponse
    {
        $status = [
            'ok' => true,
            'model' => $_ENV["OLLAMA_CHAT_MODEL"],
            'source' => ucfirst($_ENV["AI_BACKEND"]),
            'test_mode'   => $_ENV['APP_AI_TEST_MODE'] === "true" ? "Attivo" : "Disabilitato",
            'offline_fallback' => $_ENV['APP_AI_OFFLINE_FALLBACK'] === "true" ? "Attivo" : "Disabilitato",
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
}
