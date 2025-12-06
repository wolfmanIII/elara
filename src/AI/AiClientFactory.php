<?php

namespace App\AI;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiClientFactory
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ?string $openaiKey = null,
    ) {}

    public function create(string $backend): AiClientInterface
    {
        return match ($backend) {
            'ollama' => new OllamaClient(
                $this->httpClient,
                host: $_ENV['OLLAMA_HOST'] ?? 'http://localhost:11434',
                embedModel: $_ENV['OLLAMA_EMBED_MODEL'] ?? 'bge-m3',
                chatModel: $_ENV['OLLAMA_CHAT_MODEL'] ?? 'llama3.1:8b',
                dimension: $_ENV["OLLAMA_EMBED_DIMENSION"] ?? "1024"
            ),

            'openai' => new OpenAiClient(
                apiKey: $this->openaiKey ?? $_ENV['OPENAI_API_KEY'],
                chatModel: $_ENV['OPENAI_CHAT_MODEL'] ?? 'gpt-5.1-mini',
                embedModel: $_ENV['OPENAI_EMBED_MODEL'] ?? 'text-embedding-3-small',
                dimension: "1024"
            ),

            default => throw new \RuntimeException("Unknown AI_BACKEND: $backend"),
        };
    }
}
