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
     * @todo implementare interfaccia grafica
     */
    /* #[Route('/ai/console', name: 'app_ai_console', methods: ['GET'])]
    public function console(): Response
    {
        $testMode = ($_ENV['APP_AI_TEST_MODE'] ?? 'false') === 'true';
        $offlineFallback = ($_ENV['APP_AI_OFFLINE_FALLBACK'] ?? 'true') === 'true';

        return $this->render('ai/console.html.twig', [
            'controller_name'   => self::CONTROLLER_NAME,
            'test_mode'         => $testMode,
            'offline_fallback'  => $offlineFallback,
        ]);
    } */

    #[Route('/api/chat', name: 'api_chat', methods: ['POST'])]
    public function chat(Request $request, ChatbotService $bot): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $question = trim($data['message'] ?? '');

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
