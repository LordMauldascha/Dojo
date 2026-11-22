<?php

declare(strict_types=1);

namespace App\Ai;

use App\Repository\HuggingFaceModelRepository;
use App\Security\ApiKeyStore;

/** The models a run or the judge can pick: fixed lists in code, plus the Hugging Face models an admin added. Only providers with a stored credential are offered. */
final readonly class ModelCatalog
{
    public function __construct(private HuggingFaceModelRepository $huggingFaceModels, private ApiKeyStore $keys)
    {
    }

    /** @return list<string> */
    public function models(Provider $provider): array
    {
        return Provider::HuggingFace === $provider ? $this->huggingFaceModels->modelIds() : $provider->models();
    }

    public function isAvailable(Provider $provider): bool
    {
        return $this->keys->hasProvider($provider);
    }

    /**
     * Options of a model select, grouped by provider name. Providers without models or credential are left out.
     *
     * @return array<string, array<string, string>>
     */
    public function choices(): array
    {
        $choices = [];
        foreach (Provider::cases() as $provider) {
            if (!$this->isAvailable($provider)) {
                continue;
            }
            foreach ($this->models($provider) as $model) {
                $choices[$provider->displayName()][$model] = LlmConfiguration::choiceValue($provider, $model);
            }
        }

        return $choices;
    }

    /** Parses the model select value; null if the provider has no credential or the provider or model is unknown. */
    public function fromChoice(string $choice, ?Effort $effort): ?LlmConfiguration
    {
        [$providerValue, $model] = explode(':', $choice, 2) + [1 => ''];
        $provider = Provider::tryFrom($providerValue);

        return null !== $provider && $this->isAvailable($provider) && \in_array($model, $this->models($provider), true) ? new LlmConfiguration($provider, $model, $effort) : null;
    }
}
