<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\Effort;
use App\Ai\EffortMapper;
use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EffortMapperTest extends TestCase
{
    /** @return iterable<string, array{Provider, string, ?Effort, array<string, mixed>, ?string}> */
    public static function rows(): iterable
    {
        foreach (Provider::cases() as $provider) {
            yield $provider->value.' without effort' => [$provider, $provider->models()[0] ?? 'org/chat', null, [], null];
        }

        foreach (['sonnet', 'opus', 'haiku'] as $model) {
            foreach (Effort::cases() as $effort) {
                yield "claude $model {$effort->value}" => [Provider::ClaudeCode, $model, $effort, ['effort' => $effort->value], null];
            }
        }

        foreach (['gpt-5.5', 'gpt-5.4', 'gpt-5.4-mini', 'gpt-5.4-nano'] as $model) {
            foreach ([Effort::Low, Effort::Medium, Effort::High, Effort::XHigh] as $effort) {
                yield "$model {$effort->value}" => [Provider::OpenAi, $model, $effort, ['reasoning' => ['effort' => $effort->value]], null];
            }
            yield "$model max" => [Provider::OpenAi, $model, Effort::Max, ['reasoning' => ['effort' => 'xhigh']], 'effort capped at xhigh'];
        }
        yield 'gpt-4.1' => [Provider::OpenAi, 'gpt-4.1', Effort::Low, [], 'effort not supported'];

        foreach (['deepseek-chat', 'deepseek-reasoner', 'deepseek-v4-pro', 'deepseek-v4-flash'] as $model) {
            yield "$model low" => [Provider::DeepSeek, $model, Effort::Low, ['reasoning_effort' => 'low'], null];
            yield "$model medium" => [Provider::DeepSeek, $model, Effort::Medium, ['reasoning_effort' => 'high'], null];
            yield "$model high" => [Provider::DeepSeek, $model, Effort::High, ['reasoning_effort' => 'high'], null];
            yield "$model xhigh" => [Provider::DeepSeek, $model, Effort::XHigh, ['reasoning_effort' => 'high'], 'effort capped at high'];
            yield "$model max" => [Provider::DeepSeek, $model, Effort::Max, ['reasoning_effort' => 'max'], null];
        }

        foreach (['gemini-3.5-flash', 'gemini-3.1-pro-preview'] as $model) {
            foreach ([Effort::Low, Effort::Medium, Effort::High] as $effort) {
                yield "$model {$effort->value}" => [Provider::Gemini, $model, $effort, ['thinkingConfig' => ['thinkingLevel' => $effort->value]], null];
            }
            foreach ([Effort::XHigh, Effort::Max] as $effort) {
                yield "$model {$effort->value}" => [Provider::Gemini, $model, $effort, ['thinkingConfig' => ['thinkingLevel' => 'high']], 'effort capped at high'];
            }
        }
        foreach (['gemini-2.5-pro', 'gemini-2.5-flash'] as $model) {
            yield $model => [Provider::Gemini, $model, Effort::High, [], 'effort not supported'];
        }

        foreach (Effort::cases() as $effort) {
            yield "huggingface {$effort->value}" => [Provider::HuggingFace, 'org/chat', $effort, [], 'effort not supported'];
        }
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('rows')]
    public function testMapsEffortPerProviderAndModel(Provider $provider, string $model, ?Effort $effort, array $options, ?string $note): void
    {
        self::assertSame(['options' => $options, 'note' => $note], EffortMapper::map(new LlmConfiguration($provider, $model, $effort)));
    }
}
