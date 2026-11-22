<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * The provider option an effort level is sent as. Parameters verified in the provider docs and probed with real
 * calls on 21.11.2026; option names are fixed here, so no user input can choose one.
 */
final class EffortMapper
{
    public const NOT_SUPPORTED = 'effort not supported';

    /** @return array{options: array<string, mixed>, note: ?string} */
    public static function map(LlmConfiguration $configuration): array
    {
        $effort = $configuration->effort;
        if (null === $effort) {
            return ['options' => [], 'note' => null];
        }

        $model = $configuration->model;

        return match ($configuration->provider) {
            Provider::ClaudeCode => self::sent($effort, $effort->value, static fn (string $value): array => ['effort' => $value]),
            // gpt-4.1 answers 400 "not supported with this model"; gpt-5.x accepts up to xhigh
            Provider::OpenAi => str_starts_with($model, 'gpt-5')
                ? self::sent($effort, Effort::Max === $effort ? 'xhigh' : $effort->value, static fn (string $value): array => ['reasoning' => ['effort' => $value]])
                : self::notSupported(),
            // DeepSeek maps medium and xhigh to high itself; the mapped value is sent so the stored options show it
            Provider::DeepSeek => self::sent($effort, match ($effort) {
                Effort::Low => 'low',
                Effort::Medium, Effort::High, Effort::XHigh => 'high',
                Effort::Max => 'max',
            }, static fn (string $value): array => ['reasoning_effort' => $value]),
            // Gemini 2.5 only takes a numeric thinkingBudget, Gemini 3 has no level above high
            Provider::Gemini => str_starts_with($model, 'gemini-3')
                ? self::sent($effort, match ($effort) {
                    Effort::Low, Effort::Medium, Effort::High => $effort->value,
                    Effort::XHigh, Effort::Max => 'high',
                }, static fn (string $value): array => ['thinkingConfig' => ['thinkingLevel' => $value]])
                : self::notSupported(),
            Provider::HuggingFace => self::notSupported(),
        };
    }

    /**
     * @param \Closure(string): array<string, mixed> $option
     *
     * @return array{options: array<string, mixed>, note: ?string}
     */
    private static function sent(Effort $effort, string $value, \Closure $option): array
    {
        // cases are ordered from low to max
        $capped = array_search(Effort::from($value), Effort::cases(), true) < array_search($effort, Effort::cases(), true);

        return ['options' => $option($value), 'note' => $capped ? 'effort capped at '.$value : null];
    }

    /** @return array{options: array<string, mixed>, note: ?string} */
    private static function notSupported(): array
    {
        return ['options' => [], 'note' => self::NOT_SUPPORTED];
    }
}
