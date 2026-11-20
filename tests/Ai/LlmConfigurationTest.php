<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\Effort;
use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use PHPUnit\Framework\TestCase;

final class LlmConfigurationTest extends TestCase
{
    public function testKeyDistinguishesProviderModelAndEffort(): void
    {
        $base = new LlmConfiguration(Provider::OpenAi, 'gpt-5.4', Effort::High);

        self::assertSame('openai:gpt-5.4:high', $base->key());
        self::assertSame('openai:gpt-5.4:-', (new LlmConfiguration(Provider::OpenAi, 'gpt-5.4'))->key());
        self::assertNotSame($base->key(), (new LlmConfiguration(Provider::OpenAi, 'gpt-5.4', Effort::Low))->key());
        self::assertNotSame($base->key(), (new LlmConfiguration(Provider::OpenAi, 'gpt-5.5', Effort::High))->key());
    }

    public function testArrayRoundTripKeepsAllFields(): void
    {
        foreach ([Effort::XHigh, null] as $effort) {
            $configuration = new LlmConfiguration(Provider::Anthropic, 'claude-opus-5-5', $effort);

            self::assertEquals($configuration, LlmConfiguration::fromArray($configuration->toArray()));
        }
    }

    public function testStoredModelsStayReadableEvenIfTheModelListChanges(): void
    {
        $stored = ['provider' => 'openai', 'model' => 'a-model-removed-from-the-list', 'effort' => null];

        self::assertSame('a-model-removed-from-the-list', LlmConfiguration::fromArray($stored)->model);
    }

    public function testLabelIsHumanReadable(): void
    {
        self::assertSame('OpenAI / gpt-4.1 / no effort', (new LlmConfiguration(Provider::OpenAi, 'gpt-4.1'))->label());
        self::assertSame('Claude (OAuth) / opus / effort max', (new LlmConfiguration(Provider::ClaudeOAuth, 'opus', Effort::Max))->label());
    }

    public function testChoiceValueIsParsedBackAndUnknownValuesAreRefused(): void
    {
        $choice = LlmConfiguration::choiceValue(Provider::Gemini, 'gemini-2.5-pro');

        self::assertSame('gemini:gemini-2.5-pro', $choice);
        self::assertSame('gemini:gemini-2.5-pro:medium', LlmConfiguration::fromChoice($choice, Effort::Medium)?->key());
        self::assertSame('gemini:gemini-2.5-pro:-', LlmConfiguration::fromChoice($choice, null)?->key());
        self::assertNull(LlmConfiguration::fromChoice('gemini:opus', null));
        self::assertNull(LlmConfiguration::fromChoice('nobody:model', null));
        self::assertNull(LlmConfiguration::fromChoice('no-colon', null));
    }

    public function testEveryKeyMatchesTheRoutePatternOfTheRemoveRoute(): void
    {
        foreach (Provider::cases() as $provider) {
            foreach ($provider->models() as $model) {
                foreach ([null, ...Effort::cases()] as $effort) {
                    $key = (new LlmConfiguration($provider, $model, $effort))->key();
                    self::assertMatchesRegularExpression('/^'.LlmConfiguration::KEY_PATTERN.'$/', $key);
                }
            }
        }
    }
}
