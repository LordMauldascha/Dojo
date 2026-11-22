<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\ClaudeCodeModelClient;
use App\Ai\Credential;
use App\Ai\InvalidApiKeyException;
use App\Ai\KeyedPlatformProvider;
use App\Ai\LlmConfiguration;
use App\Ai\LlmGateway;
use App\Ai\MissingApiKeyException;
use App\Ai\Provider;
use App\Entity\ProviderApiKey;
use App\Run\TransientFailure;
use App\Security\ApiKeyStore;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Platform\Bridge\ClaudeCode\ClaudeCode;
use Symfony\AI\Platform\Bridge\ClaudeCode\ResultConverter;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException as PlatformRuntimeException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class KeyedPlatformProviderTest extends KernelTestCase
{
    private const SUCCESS = '{"type":"result","subtype":"success","is_error":false,"result":"OK","usage":{"input_tokens":7,"output_tokens":2}}';

    private ApiKeyStore $keys;
    private string $scratch;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->keys = self::getContainer()->get(ApiKeyStore::class);
        self::getContainer()->get(EntityManagerInterface::class)->createQuery('DELETE FROM '.ProviderApiKey::class)->execute();
        $this->scratch = sys_get_temp_dir().'/claude-client-test-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->scratch);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->scratch);
        parent::tearDown();
    }

    public function testMissingCredentialThrows(): void
    {
        $this->expectException(MissingApiKeyException::class);

        $this->provider()->forCredential(Credential::OpenAi);
    }

    public function testEveryStoredCredentialGetsAPlatform(): void
    {
        $provider = $this->provider();
        foreach (Credential::cases() as $credential) {
            // the OpenAI bridge refuses keys without the "sk-" prefix
            $this->keys->save($credential, 'sk-synthetic-key-0000000000');
            $provider->forCredential($credential);
            $this->addToAssertionCount(1);
        }
    }

    public function testClaudeCodeChildGetsOnlyItsCredential(): void
    {
        $workingDirectory = $this->projectDir().'/var/claude-runs';
        (new Filesystem())->remove($workingDirectory);
        $this->keys->save(Credential::Anthropic, 'synthetic-api-key-0000');
        $this->keys->save(Credential::ClaudeOAuth, 'synthetic-oauth-token-0000');
        $provider = $this->provider($this->fakeCli(self::SUCCESS, 0));

        // the worker's environment: every name app:ask-claude drops, plus a secret that is in no list
        $inherited = array_diff([...$this->namesDroppedForAskClaude(), 'SOME_INHERITED_SECRET'], array_keys($_ENV));
        foreach ($inherited as $name) {
            $_ENV[$name] = 'synthetic';
        }
        try {
            $this->invoke($provider->forCredential(Credential::Anthropic));
            $apiKeyEnvironment = $this->recorded('env');
            $this->invoke($provider->forCredential(Credential::ClaudeOAuth));
            $oauthEnvironment = $this->recorded('env');
        } finally {
            foreach ($inherited as $name) {
                unset($_ENV[$name]);
            }
        }

        $apiKeyVariables = $this->variables($apiKeyEnvironment);
        self::assertSame('synthetic-api-key-0000', $apiKeyVariables['ANTHROPIC_API_KEY'] ?? null);
        self::assertSame('synthetic-oauth-token-0000', $this->variables($oauthEnvironment)['CLAUDE_CODE_OAUTH_TOKEN'] ?? null);
        self::assertArrayNotHasKey('ANTHROPIC_API_KEY', $this->variables($oauthEnvironment));
        self::assertArrayHasKey('PATH', $apiKeyVariables);
        if (false !== getenv('HOME')) {
            self::assertArrayHasKey('HOME', $apiKeyVariables);
        }

        foreach ([...$this->namesDroppedForAskClaude(), 'SOME_INHERITED_SECRET', 'CLAUDE_CODE_OAUTH_TOKEN'] as $name) {
            self::assertArrayNotHasKey($name, $apiKeyVariables, $name);
        }
        // the shell adds PWD, SHLVL and _ itself
        self::assertSame([], array_diff(array_keys($apiKeyVariables), ['ANTHROPIC_API_KEY', 'PATH', 'HOME', 'PWD', 'SHLVL', '_']));

        self::assertSame($workingDirectory, trim($this->recorded('pwd')));
        self::assertSame([], array_diff((array) scandir($workingDirectory), ['.', '..']));
    }

    public function testPromptAndSkillsAreNotInTheProcessList(): void
    {
        $client = new ClaudeCodeModelClient($this->scratch.'/cwd', [], cliBinary: $this->fakeCli(self::SUCCESS, 0));
        (new Filesystem())->mkdir($this->scratch.'/cwd');
        $systemPrompt = "---\nname: alpha\n---\nskill-body";

        $result = new DeferredResult(new ResultConverter(), $client->request(new ClaudeCode('haiku'), ['prompt' => '--help', 'system_prompt' => $systemPrompt], ['tools' => [], 'effort' => 'low', 'cwd' => '/']));
        self::assertSame('OK', $result->asText());

        $argv = explode("\n", rtrim($this->recorded('argv'), "\n"));
        self::assertSame('-p', end($argv));
        self::assertNotContains('--help', $argv);
        self::assertStringNotContainsString('skill-body', implode("\n", $argv));
        self::assertNotContains('--', $argv);
        self::assertNotContains('--cwd', $argv);
        self::assertSame(['--model', 'haiku'], \array_slice($argv, (int) array_search('--model', $argv, true), 2));
        self::assertSame(['--effort', 'low'], \array_slice($argv, (int) array_search('--effort', $argv, true), 2));
        self::assertSame(['--tools', ''], \array_slice($argv, (int) array_search('--tools', $argv, true), 2));
        self::assertContains('--no-session-persistence', $argv);
        self::assertContains('--strict-mcp-config', $argv);

        self::assertSame('--help', $this->recorded('stdin'));
        self::assertSame($systemPrompt, $this->recorded('system'));
        self::assertSame('-rw-------', trim($this->recorded('mode')));
        $file = trim($this->recorded('systempath'));
        self::assertTrue(str_starts_with($file, sys_get_temp_dir()), $file);
        self::assertFileDoesNotExist($file);
        self::assertSame($this->scratch.'/cwd', trim($this->recorded('pwd')));
    }

    public function testNoSystemPromptMeansNoFile(): void
    {
        $client = new ClaudeCodeModelClient($this->scratch, [], cliBinary: $this->fakeCli(self::SUCCESS, 0));

        (new DeferredResult(new ResultConverter(), $client->request(new ClaudeCode('haiku'), ['prompt' => 'Hi'])))->asText();

        self::assertStringNotContainsString('--system-prompt', $this->recorded('argv'));
        self::assertSame('Hi', $this->recorded('stdin'));
    }

    public function testCliResultWith401IsAnInvalidKeyAndRemovesTheFile(): void
    {
        $line = '{"type":"result","subtype":"success","is_error":true,"api_error_status":401,"result":"Invalid API key"}';
        $client = new ClaudeCodeModelClient($this->scratch, [], cliBinary: $this->fakeCli($line, 1));

        try {
            (new DeferredResult(new ResultConverter(), $client->request(new ClaudeCode('haiku'), ['prompt' => 'Hi', 'system_prompt' => 's'])))->asText();
            self::fail('expected an invalid key');
        } catch (InvalidApiKeyException) {
        }

        self::assertFileDoesNotExist(trim($this->recorded('systempath')));
    }

    public function testCliResultIsConvertedWithTokenUsage(): void
    {
        $client = new ClaudeCodeModelClient($this->scratch, [], cliBinary: $this->fakeCli(self::SUCCESS, 0));

        $result = (new DeferredResult(new ResultConverter(), $client->request(new ClaudeCode('haiku'), 'Hi')))->getResult();

        self::assertSame('OK', $result->getContent());
        $usage = $result->getMetadata()->get('token_usage');
        self::assertInstanceOf(TokenUsageInterface::class, $usage);
        self::assertSame(7, $usage->getPromptTokens());
    }

    /** @return iterable<string, array{int, class-string<\Throwable>, string}> */
    public static function retryableCliStatuses(): iterable
    {
        yield 'rate limit' => [429, RateLimitExceededException::class, 'RateLimitExceededException 429'];
        yield 'server error' => [500, ServerException::class, 'ServerException 500'];
        yield 'overloaded' => [529, ServerException::class, 'ServerException 529'];
    }

    /** @param class-string<\Throwable> $class */
    #[DataProvider('retryableCliStatuses')]
    public function testCliRateLimitAndServerErrorsAreTransientWithTheirStatus(int $status, string $class, string $code): void
    {
        $line = '{"type":"result","subtype":"success","is_error":true,"api_error_status":'.$status.',"result":"API Error"}';
        $client = new ClaudeCodeModelClient($this->scratch, [], cliBinary: $this->fakeCli($line, 1));

        try {
            (new DeferredResult(new ResultConverter(), $client->request(new ClaudeCode('haiku'), ['prompt' => 'Hi', 'system_prompt' => 's'])))->asText();
            self::fail('expected '.$class);
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e);
            self::assertTrue(TransientFailure::isTransient($e));
            self::assertSame($code, TransientFailure::errorCode($e));
        }

        self::assertFileDoesNotExist(trim($this->recorded('systempath')));
    }

    public function testOtherCliFailuresKeepTheBridgeExceptionAndRemoveTheFile(): void
    {
        $line = '{"type":"result","subtype":"error_during_execution","is_error":true,"api_error_status":400}';
        $client = new ClaudeCodeModelClient($this->scratch, [], cliBinary: $this->fakeCli($line, 1));

        try {
            (new DeferredResult(new ResultConverter(), $client->request(new ClaudeCode('haiku'), ['prompt' => 'Hi', 'system_prompt' => 's'])))->asText();
            self::fail('expected the bridge exception');
        } catch (PlatformRuntimeException $e) {
            self::assertStringContainsString('error_during_execution', $e->getMessage());
            self::assertFalse(TransientFailure::isTransient($e));
        }

        self::assertFileDoesNotExist(trim($this->recorded('systempath')));
    }

    public function testTimeoutRemovesTheFile(): void
    {
        $client = new ClaudeCodeModelClient($this->scratch, [], 0.5, $this->fakeCli(self::SUCCESS, 0, 'sleep 5'));

        try {
            (new DeferredResult(new ResultConverter(), $client->request(new ClaudeCode('haiku'), ['prompt' => 'Hi', 'system_prompt' => 's'])))->asText();
            self::fail('expected a timeout');
        } catch (ProcessTimedOutException) {
        }

        self::assertFileDoesNotExist(trim($this->recorded('systempath')));
    }

    public function testUnreadResultRemovesTheFile(): void
    {
        $client = new ClaudeCodeModelClient($this->scratch, [], cliBinary: $this->fakeCli(self::SUCCESS, 0));

        $raw = $client->request(new ClaudeCode('haiku'), ['prompt' => 'Hi', 'system_prompt' => 's']);
        $process = $raw->getObject();
        self::assertInstanceOf(Process::class, $process);
        $process->wait();
        $file = trim($this->recorded('systempath'));
        unset($raw);

        self::assertFileDoesNotExist($file);
    }

    public function testRejectedHuggingFaceTokenOnTheHubLookupIsAnInvalidKey(): void
    {
        $this->keys->save(Credential::HuggingFace, 'synthetic-key-0000000000');
        $requested = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse('{"error":"Invalid credentials in Authorization header"}', ['http_code' => 401]);
        });

        try {
            (new LlmGateway(new KeyedPlatformProvider($this->keys, $http, $this->scratch)))->ask(new LlmConfiguration(Provider::HuggingFace, 'org/chat'), null, 'Hi');
            self::fail('expected an invalid key');
        } catch (InvalidApiKeyException) {
        }

        self::assertCount(1, $requested);
        self::assertStringStartsWith('https://huggingface.co/api/models', $requested[0]);
    }

    private function projectDir(): string
    {
        return self::getContainer()->getParameter('kernel.project_dir');
    }

    // not wired into a handler yet, so the container has removed the unused service
    private function provider(?string $cliBinary = null): KeyedPlatformProvider
    {
        return new KeyedPlatformProvider($this->keys, new MockHttpClient(), $this->projectDir().'/var/claude-runs', $cliBinary);
    }

    private function invoke(PlatformInterface $platform): void
    {
        self::assertSame('OK', $platform->invoke('haiku', new MessageBag(Message::ofUser('Hi')), ['tools' => []])->asText());
    }

    /** @return list<string> */
    private function namesDroppedForAskClaude(): array
    {
        $container = new ContainerBuilder();
        (new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config')))->load('services.yaml');
        $environment = $container->getDefinition(PlatformInterface::class)->getArgument('$environment');
        self::assertIsArray($environment);

        return array_keys(array_filter($environment, static fn (mixed $value): bool => false === $value));
    }

    /** @return array<string, string> */
    private function variables(string $env): array
    {
        $variables = [];
        foreach (explode("\n", trim($env)) as $line) {
            [$name, $value] = explode('=', $line, 2) + [1 => ''];
            $variables[$name] = $value;
        }

        return $variables;
    }

    private function recorded(string $name): string
    {
        return (string) file_get_contents($this->scratch.'/'.$name);
    }

    /** A stand-in for the CLI: records argv, stdin, cwd, environment and the system prompt file, prints one result line. */
    private function fakeCli(string $resultLine, int $exitCode, string $before = ''): string
    {
        $dir = $this->scratch;
        $path = $dir.'/claude';
        file_put_contents($path, <<<SH
            #!/bin/sh
            printf '%s\\n' "\$@" > $dir/argv
            cat > $dir/stdin
            pwd > $dir/pwd
            env > $dir/env
            prev=''
            for arg in "\$@"; do
              if [ "\$prev" = '--system-prompt-file' ]; then
                echo "\$arg" > $dir/systempath
                cat "\$arg" > $dir/system
                ls -l "\$arg" | cut -c1-10 > $dir/mode
              fi
              prev="\$arg"
            done
            $before
            echo '$resultLine'
            exit $exitCode

            SH);
        chmod($path, 0o755);

        return $path;
    }
}
