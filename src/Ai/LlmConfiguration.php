<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * One LLM configuration of a run: provider, model and an optional effort level.
 * Not validated against Provider::models(), so stored snapshots stay readable if the model list changes.
 */
final readonly class LlmConfiguration
{
    /** Route requirement for key(): provider:model:effort. */
    public const KEY_PATTERN = '[a-z_]+:[A-Za-z0-9._-]+:[a-z-]+';

    public function __construct(
        public Provider $provider,
        public string $model,
        public ?Effort $effort = null,
    ) {
    }

    /** The value of the model select: "provider:model". */
    public static function choiceValue(Provider $provider, string $model): string
    {
        return $provider->value.':'.$model;
    }

    /** Parses the model select value; null if the provider or model is unknown. */
    public static function fromChoice(string $choice, ?Effort $effort): ?self
    {
        [$providerValue, $model] = explode(':', $choice, 2) + [1 => ''];
        $provider = Provider::tryFrom($providerValue);

        return null !== $provider && \in_array($model, $provider->models(), true) ? new self($provider, $model, $effort) : null;
    }

    /** Identity of a configuration within a run. */
    public function key(): string
    {
        return self::choiceValue($this->provider, $this->model).':'.($this->effort->value ?? '-');
    }

    public function label(): string
    {
        return \sprintf('%s / %s / %s', $this->provider->displayName(), $this->model, null === $this->effort ? 'no effort' : 'effort '.$this->effort->value);
    }

    /** @return array{provider: string, model: string, effort: string|null} */
    public function toArray(): array
    {
        return ['provider' => $this->provider->value, 'model' => $this->model, 'effort' => $this->effort?->value];
    }

    /** @param array{provider: string, model: string, effort: string|null} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Provider::from($data['provider']),
            $data['model'],
            null === $data['effort'] ? null : Effort::from($data['effort']),
        );
    }
}
