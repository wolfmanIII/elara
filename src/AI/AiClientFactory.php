<?php

namespace App\AI;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiClientFactory
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ?string $openaiKey = null,
        private ?string $geminiKey = null,
    ) {}

    public function create(string $backend): AiClientInterface
    {
        return match ($backend) {
            'ollama' => new OllamaClient(
                $this->httpClient,
                host: $_ENV['OLLAMA_HOST'] ?? 'http://localhost:11434',
                embedModel: $_ENV['OLLAMA_EMBED_MODEL'] ?? 'bge-m3',
                chatModel: $_ENV['OLLAMA_CHAT_MODEL'] ?? 'llama3.2',
                dimension: $_ENV["OLLAMA_EMBED_DIMENSION"] ?? 1024
            ),

            'openai' => new OpenAiClient(
                apiKey: $this->openaiKey ?? $_ENV['OPENAI_API_KEY'],
                chatModel: $_ENV['OPENAI_CHAT_MODEL'] ?? 'gpt-4.1-mini',
                embedModel: $_ENV['OPENAI_EMBED_MODEL'] ?? 'text-embedding-3-small',
                dimension: (int) ($_ENV['OPENAI_EMBED_DIMENSION'] ?? 1024)
            ),

            'gemini' => new GeminiClient(
                httpClient: $this->httpClient,
                apiKey: $this->geminiKey ?? ($_ENV['GEMINI_API_KEY'] ?? ''),
                chatModel: $_ENV['GEMINI_CHAT_MODEL'] ?? 'gemini-1.5-flash',
                embedModel: $_ENV['GEMINI_EMBED_MODEL'] ?? 'gemini-embedding-001',
                dimension: (int) ($_ENV['GEMINI_EMBED_DIMENSION'] ?? 768),
            ),

            default => throw new \RuntimeException("Unknown AI_BACKEND: $backend"),
        };
    }
}
