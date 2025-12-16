<?php

namespace App\AI;

use App\Rag\RagProfileManager;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiClientFactory
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ?string $openaiKey = null,
        private ?string $geminiKey = null,
    ) {}

    public function create(RagProfileManager $profiles): AiClientInterface
    {
        $activeProfile = $profiles->getActiveProfile();
        $backend       = $activeProfile['backend'] ?? ($_ENV['AI_BACKEND'] ?? 'ollama');
        $aiConfig      = $profiles->getAi();

        return match ($backend) {
            'ollama' => new OllamaClient(
                $this->httpClient,
                host: $_ENV['OLLAMA_HOST'] ?? 'http://localhost:11434',
                embedModel: $aiConfig['embed_model'] ?? ($_ENV['OLLAMA_EMBED_MODEL'] ?? 'bge-m3'),
                chatModel: $aiConfig['chat_model'] ?? ($_ENV['OLLAMA_CHAT_MODEL'] ?? 'llama3.2'),
                dimension: (int) ($aiConfig['embed_dimension'] ?? ($_ENV["OLLAMA_EMBED_DIMENSION"] ?? 1024))
            ),

            'openai' => new OpenAiClient(
                apiKey: $this->openaiKey ?? $_ENV['OPENAI_API_KEY'],
                chatModel: $aiConfig['chat_model'] ?? ($_ENV['OPENAI_CHAT_MODEL'] ?? 'gpt-4.1-mini'),
                embedModel: $aiConfig['embed_model'] ?? ($_ENV['OPENAI_EMBED_MODEL'] ?? 'text-embedding-3-small'),
                dimension: (int) ($aiConfig['embed_dimension'] ?? ($_ENV['OPENAI_EMBED_DIMENSION'] ?? 768))
            ),

            'gemini' => new GeminiClient(
                httpClient: $this->httpClient,
                apiKey: $this->geminiKey ?? ($_ENV['GEMINI_API_KEY'] ?? ''),
                chatModel: $aiConfig['chat_model'] ?? ($_ENV['GEMINI_CHAT_MODEL'] ?? 'gemini-2.5-flash'),
                embedModel: $aiConfig['embed_model'] ?? ($_ENV['GEMINI_EMBED_MODEL'] ?? 'gemini-embedding-001'),
                dimension: (int) ($aiConfig['embed_dimension'] ?? ($_ENV['GEMINI_EMBED_DIMENSION'] ?? 768)),
            ),

            default => throw new \RuntimeException(sprintf('Unknown AI backend "%s"', $backend)),
        };
    }
}
