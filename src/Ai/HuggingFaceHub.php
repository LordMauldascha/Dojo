<?php

declare(strict_types=1);

namespace App\Ai;

use App\Security\ApiKeyStore;
use Symfony\AI\Platform\Bridge\HuggingFace\ApiClient;
use Symfony\AI\Platform\Bridge\HuggingFace\Provider as InferenceProvider;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Model discovery on the Hugging Face Hub with the access token stored in the LLM Configuration.
 * The web UI and the ai:huggingface:model-list / model-info commands share the same ApiClient.
 *
 * @phpstan-type HubEntry array{id: string, task: string|null, providers: list<string>}
 */
final readonly class HuggingFaceHub
{
    public const MAX_RESULTS = 50;

    public function __construct(
        private ApiKeyStore $keys,
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Inference providers the Hub filter knows, as in the bridge.
     *
     * @return list<string>
     */
    public static function inferenceProviders(): array
    {
        return array_values(array_filter((new \ReflectionClass(InferenceProvider::class))->getConstants(), is_string(...)));
    }

    public function apiClient(): ApiClient
    {
        return new ApiClient($this->httpClient, $this->keys->plainKey(Credential::HuggingFace));
    }

    /**
     * Models served by inference providers. Empty filters are ignored.
     *
     * @return list<HubEntry>
     *
     * @throws ExceptionInterface|\RuntimeException on transport or Hub errors
     */
    public function search(?string $search, ?string $task, ?string $inferenceProvider, bool $warm): array
    {
        $filters = array_filter([
            'search' => $search,
            'pipeline_tag' => $task,
            'inference_provider' => $inferenceProvider,
            ...$warm ? ['inference' => 'warm'] : [],
        ], static fn (?string $value): bool => null !== $value && '' !== $value);

        $entries = [];
        foreach ($this->apiClient()->listModels($filters, self::MAX_RESULTS) as $model) {
            $providers = [];
            foreach ($model['inferenceProviderMapping'] ?? [] as $key => $mapping) {
                if ('live' === $mapping['status'] && 'tag-filter' !== ($mapping['type'] ?? null)) {
                    $providers[] = $mapping['provider'] ?? (string) $key;
                }
            }
            $entries[] = ['id' => $model['id'], 'task' => $model['pipeline_tag'] ?? null, 'providers' => $providers];
        }

        return $entries;
    }

    public function exists(string $modelId): bool
    {
        try {
            $this->apiClient()->getModel($modelId);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }
}
