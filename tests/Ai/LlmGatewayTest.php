<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\Credential;
use App\Ai\Effort;
use App\Ai\InvalidApiKeyException;
use App\Ai\LlmConfiguration;
use App\Ai\LlmGateway;
use App\Ai\MissingApiKeyException;
use App\Ai\PlatformProvider;
use App\Ai\Provider;
use App\Ai\UnsupportedModelException;
use App\Entity\Skill;
use App\Entity\SkillVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class LlmGatewayTest extends TestCase
{
    /** @var \ArrayObject<int, Credential> */
    private \ArrayObject $requested;

    public function testOptionsAreOnlyWhitelisted(): void
    {
        foreach (Provider::cases() as $provider) {
            foreach ([null, ...Effort::cases()] as $effort) {
                $model = Provider::HuggingFace === $provider ? 'org/chat' : $provider->models()[0];
                $options = $this->optionsFor($provider, $model, $effort);

                self::assertSame([], array_diff(array_keys($options), ['tools', 'effort', 'reasoning', 'reasoning_effort', 'thinkingConfig']), $provider->value);
                if (Provider::ClaudeCode === $provider) {
                    self::assertSame([], $options['tools']);
                } else {
                    self::assertFalse(\array_key_exists('tools', $options), $provider->value);
                }
            }
        }
    }

    public function testEffortIsMappedPerProvider(): void
    {
        self::assertSame(['tools' => [], 'effort' => 'max'], $this->optionsFor(Provider::ClaudeCode, 'haiku', Effort::Max));
        self::assertSame(['reasoning' => ['effort' => 'low']], $this->optionsFor(Provider::OpenAi, 'gpt-5.5', Effort::Low));
        self::assertSame(['reasoning_effort' => 'high'], $this->optionsFor(Provider::DeepSeek, 'deepseek-chat', Effort::Medium));
        self::assertSame(['thinkingConfig' => ['thinkingLevel' => 'high']], $this->optionsFor(Provider::Gemini, 'gemini-3.5-flash', Effort::Max));
        self::assertSame([], $this->optionsFor(Provider::OpenAi, 'gpt-4.1', Effort::High));
        self::assertSame([], $this->optionsFor(Provider::HuggingFace, 'org/chat', Effort::High));
        self::assertSame(['tools' => []], $this->optionsFor(Provider::ClaudeCode, 'haiku', null));
    }

    public function testSystemPromptJoinsSkillsByTitle(): void
    {
        $skill = new Skill('Skill', null, 'content');
        $prompt = LlmGateway::systemPrompt([
            new SkillVersion($skill, 1, false, 'Beta', null, 'second'),
            new SkillVersion($skill, 1, false, 'Alpha', null, 'first'),
        ]);

        self::assertNotNull($prompt);
        $parts = explode("\n\n---\n\n", $prompt);
        self::assertCount(2, $parts);
        self::assertStringEndsWith('first', $parts[0]);
        self::assertStringContainsString('name: alpha', $parts[0]);
        self::assertStringEndsWith('second', $parts[1]);

        $platform = ScriptedPlatform::answering('ok');
        $this->gateway(['anthropic' => $platform])->ask(new LlmConfiguration(Provider::ClaudeCode, 'haiku'), $prompt, 'Hi');

        self::assertInstanceOf(MessageBag::class, $platform->input);
        self::assertInstanceOf(SystemMessage::class, $platform->input->getSystemMessage());
        self::assertSame($prompt, $platform->input->getSystemMessage()->getContent());
    }

    public function testNoSkillsMeansNoSystemPrompt(): void
    {
        self::assertNull(LlmGateway::systemPrompt([]));

        $platform = ScriptedPlatform::answering('ok');
        $this->gateway(['openai' => $platform])->ask(new LlmConfiguration(Provider::OpenAi, 'gpt-5.5'), null, 'Hi');

        self::assertInstanceOf(MessageBag::class, $platform->input);
        $messages = $platform->input->getMessages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(UserMessage::class, $messages[0]);
        self::assertSame('Hi', $messages[0]->asText());
    }

    public function testAnswerCarriesTextTokensAndDuration(): void
    {
        $platform = new ScriptedPlatform(static function (): ResultInterface {
            $result = new TextResult('the answer');
            $result->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 12, completionTokens: 3));

            return $result;
        });

        $answer = $this->gateway(['openai' => $platform])->ask(new LlmConfiguration(Provider::OpenAi, 'gpt-5.5'), null, 'Hi');

        self::assertSame('the answer', $answer->text);
        self::assertSame(12, $answer->inputTokens);
        self::assertSame(3, $answer->outputTokens);
        self::assertGreaterThanOrEqual(0, $answer->durationMs);

        $answer = $this->gateway(['openai' => ScriptedPlatform::answering('x')])->ask(new LlmConfiguration(Provider::OpenAi, 'gpt-5.5'), null, 'Hi');
        self::assertNull($answer->inputTokens);
        self::assertNull($answer->outputTokens);
    }

    public function testMissingKeyThrows(): void
    {
        $this->expectException(MissingApiKeyException::class);

        $this->gateway([])->ask(new LlmConfiguration(Provider::ClaudeCode, 'haiku'), null, 'Hi');
    }

    public function testClaudeCodeFallsBackToOAuthTokenOn401(): void
    {
        $apiKey = ScriptedPlatform::failing(new InvalidApiKeyException());
        $oauth = ScriptedPlatform::answering('from oauth');

        $answer = $this->gateway(['anthropic' => $apiKey, 'claude_oauth' => $oauth])->ask(new LlmConfiguration(Provider::ClaudeCode, 'haiku'), null, 'Hi');

        self::assertSame('from oauth', $answer->text);
        self::assertSame(1, $apiKey->invocations);
        self::assertSame(1, $oauth->invocations);
        self::assertSame([Credential::Anthropic, Credential::ClaudeOAuth], $this->requested->getArrayCopy());
    }

    public function testOnlyStoredCredentialIsUsed(): void
    {
        $oauth = ScriptedPlatform::answering('from oauth');

        $answer = $this->gateway(['claude_oauth' => $oauth])->ask(new LlmConfiguration(Provider::ClaudeCode, 'haiku'), null, 'Hi');

        self::assertSame('from oauth', $answer->text);
    }

    public function testNoFallbackOnOtherErrors(): void
    {
        $timeout = new ProcessTimedOutException(new Process(['true']), ProcessTimedOutException::TYPE_GENERAL);
        $apiKey = ScriptedPlatform::failing($timeout);
        $oauth = ScriptedPlatform::answering('from oauth');

        try {
            $this->gateway(['anthropic' => $apiKey, 'claude_oauth' => $oauth])->ask(new LlmConfiguration(Provider::ClaudeCode, 'haiku'), null, 'Hi');
            self::fail('expected the timeout');
        } catch (ProcessTimedOutException $e) {
            self::assertSame($timeout, $e);
        }

        self::assertSame(0, $oauth->invocations);
    }

    public function testAllCredentialsRejectedThrowsInvalidApiKey(): void
    {
        $this->expectException(InvalidApiKeyException::class);

        $this->gateway([
            'anthropic' => ScriptedPlatform::failing(new InvalidApiKeyException()),
            'claude_oauth' => ScriptedPlatform::failing(new InvalidApiKeyException()),
        ])->ask(new LlmConfiguration(Provider::ClaudeCode, 'haiku'), null, 'Hi');
    }

    public function testHttpAuthenticationErrorIsAnInvalidKey(): void
    {
        $this->expectException(InvalidApiKeyException::class);

        $this->gateway(['openai' => ScriptedPlatform::failing(new AuthenticationException('Unauthorized'))])
            ->ask(new LlmConfiguration(Provider::OpenAi, 'gpt-5.5'), null, 'Hi');
    }

    public function testUnknownModelIsUnsupported(): void
    {
        $this->expectException(UnsupportedModelException::class);

        $this->gateway(['gemini' => ScriptedPlatform::failing(new ModelNotFoundException('Not Found'))])
            ->ask(new LlmConfiguration(Provider::Gemini, 'gemini-3.5-flash'), null, 'Hi');
    }

    /** @return iterable<string, array{Provider, string}> */
    public static function modelsOutsideTheCatalog(): iterable
    {
        yield 'option injection' => [Provider::ClaudeCode, 'haiku?permission_mode=bypassPermissions'];
        yield 'option injection openai' => [Provider::OpenAi, 'gpt-5.5?reasoning[effort]=max'];
        yield 'option injection huggingface' => [Provider::HuggingFace, 'org/chat?provider=evil'];
        yield 'unknown claude model' => [Provider::ClaudeCode, 'claude-unknown'];
        yield 'removed gemini model' => [Provider::Gemini, 'gemini-2.5-pro'];
        yield 'model of another provider' => [Provider::DeepSeek, 'gpt-5.5'];
        yield 'empty' => [Provider::ClaudeCode, ''];
        yield 'not a hub id' => [Provider::HuggingFace, '../org/chat'];
    }

    #[DataProvider('modelsOutsideTheCatalog')]
    public function testModelOutsideTheCatalogIsRejectedWithoutACall(Provider $provider, string $model): void
    {
        $platforms = array_fill_keys(array_map(static fn (Credential $c): string => $c->value, $provider->credentials()), ScriptedPlatform::answering('ok'));

        try {
            $this->gateway($platforms)->ask(new LlmConfiguration($provider, $model), null, 'Hi');
            self::fail('expected unsupported model');
        } catch (UnsupportedModelException) {
        }

        self::assertSame([], $this->requested->getArrayCopy());
        foreach ($platforms as $platform) {
            self::assertSame(0, $platform->invocations);
        }
    }

    public function testOtherBadRequestPropagatesExceptForHuggingFace(): void
    {
        $badRequest = new BadRequestException('Bad Request');
        try {
            $this->gateway(['openai' => ScriptedPlatform::failing($badRequest)])->ask(new LlmConfiguration(Provider::OpenAi, 'gpt-5.5'), null, 'Hi');
            self::fail('expected the bad request');
        } catch (BadRequestException $e) {
            self::assertSame($badRequest, $e);
        }

        $this->expectException(UnsupportedModelException::class);
        $this->gateway(['huggingface' => ScriptedPlatform::failing($badRequest)])->ask(new LlmConfiguration(Provider::HuggingFace, 'org/chat'), null, 'Hi');
    }

    public function testHuggingFaceModelThatIsNotChatIsUnsupportedWithoutACall(): void
    {
        $platform = ScriptedPlatform::answering('ok');

        try {
            $this->gateway(['huggingface' => $platform])->ask(new LlmConfiguration(Provider::HuggingFace, 'BAAI/bge-m3'), null, 'Hi');
            self::fail('expected unsupported model');
        } catch (UnsupportedModelException) {
        }

        self::assertSame(0, $platform->invocations);
    }

    /** @return array<string, mixed> */
    private function optionsFor(Provider $provider, string $model, ?Effort $effort): array
    {
        $platform = ScriptedPlatform::answering('ok');
        $this->gateway([$provider->credentials()[0]->value => $platform])->ask(new LlmConfiguration($provider, $model, $effort), null, 'Hi');

        return $platform->options;
    }

    /** @param array<string, ScriptedPlatform> $platforms keyed by credential value */
    private function gateway(array $platforms): LlmGateway
    {
        $requested = new \ArrayObject();
        $this->requested = $requested;

        return new LlmGateway(new class($platforms, $requested) implements PlatformProvider {
            /**
             * @param array<string, ScriptedPlatform> $platforms
             * @param \ArrayObject<int, Credential>   $requested
             */
            public function __construct(private readonly array $platforms, private readonly \ArrayObject $requested)
            {
            }

            public function forCredential(Credential $credential): PlatformInterface
            {
                $platform = $this->platforms[$credential->value] ?? throw new MissingApiKeyException();
                $this->requested->append($credential);

                return $platform;
            }
        });
    }
}
