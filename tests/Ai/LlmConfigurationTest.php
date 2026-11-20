<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\Effort;
use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

final class LlmConfigurationTest extends PhpUnitTestCase
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
}
