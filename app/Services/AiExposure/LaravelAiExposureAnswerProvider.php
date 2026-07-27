<?php

namespace App\Services\AiExposure;

use App\Contracts\AiExposure\AiExposureAnswerProvider;
use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use RuntimeException;
use Throwable;

use function Laravel\Ai\agent;

class LaravelAiExposureAnswerProvider implements AiExposureAnswerProvider
{
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    public function answer(AiModel $model, string $platform, string $question): string
    {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url);
        if ($providerUrl === '') {
            throw new RuntimeException('AI API Base URL is missing.');
        }

        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('AI API key is missing or cannot be decrypted.');
        }

        $modelId = trim((string) $model->model_id);
        if ($modelId === '') {
            throw new RuntimeException('AI model ID is missing.');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, $modelId);
        $providerName = OpenAiRuntimeProvider::registerProvider('ai_exposure_'.$platform, $driver, $providerUrl, $apiKey);
        $instructions = 'Answer the user question naturally using the knowledge and search capabilities available to you. '
            .'When you rely on online sources, include each source as a complete URL. Do not invent citations.';

        try {
            $response = agent($instructions)->prompt($question, [], $providerName, $modelId);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl),
                0,
                $exception
            );
        }

        $rawText = (string) ($response->text ?? '');
        $answer = OpenAiRuntimeProvider::normalizeGeneratedText($rawText);
        if ($answer === '') {
            throw new RuntimeException('The AI platform returned an empty answer.');
        }

        return $answer;
    }
}
