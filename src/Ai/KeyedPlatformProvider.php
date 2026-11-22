<?php

declare(strict_types=1);

namespace App\Ai;

use App\Security\ApiKeyStore;
use Symfony\AI\Platform\Bridge\ClaudeCode\Contract\ClaudeCodeContract;
use Symfony\AI\Platform\Bridge\ClaudeCode\ModelCatalog as ClaudeCodeCatalog;
use Symfony\AI\Platform\Bridge\ClaudeCode\ResultConverter as ClaudeCodeResultConverter;
use Symfony\AI\Platform\Bridge\DeepSeek\Factory as DeepSeekFactory;
use Symfony\AI\Platform\Bridge\Gemini\Factory as GeminiFactory;
use Symfony\AI\Platform\Bridge\HuggingFace\ApiClient as HuggingFaceApiClient;
use Symfony\AI\Platform\Bridge\HuggingFace\Factory as HuggingFaceFactory;
use Symfony\AI\Platform\Bridge\HuggingFace\ModelApiCatalog;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Provider as PlatformProviderImpl;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds a platform per call with the key from the database; no key is read from the environment. The LLM calls get
 * their own HTTP client, so request bodies (prompts) never reach the profiler or the HTTP client logger; only the
 * Hub model lookup uses the app's client, as HuggingFaceHub does.
 */
final readonly class KeyedPlatformProvider implements PlatformProvider
{
    public function __construct(
        private ApiKeyStore $keys,
        private HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%/var/claude-runs')]
        private string $claudeWorkingDirectory,
        private ?string $claudeCliBinary = null,
    ) {
    }

    public function forCredential(Credential $credential): PlatformInterface
    {
        return match ($credential) {
            Credential::OpenAi => OpenAiFactory::createPlatform($this->key($credential)),
            Credential::Gemini => GeminiFactory::createPlatform($this->key($credential)),
            Credential::DeepSeek => DeepSeekFactory::createPlatform($this->key($credential)),
            // the Hub catalog resolves any model an admin added, the bundled one only knows popular models
            Credential::HuggingFace => HuggingFaceFactory::createPlatform(
                $key = $this->key($credential),
                modelCatalog: new ModelApiCatalog(new HuggingFaceApiClient(new RejectedKeyHttpClient($this->httpClient), $key)),
            ),
            Credential::Anthropic, Credential::ClaudeOAuth => new Platform([new PlatformProviderImpl(
                'claudecode',
                [$this->claudeCodeClient($credential)],
                [new ClaudeCodeResultConverter()],
                new ClaudeCodeCatalog(),
                ClaudeCodeContract::create(),
            )]),
        };
    }

    /**
     * The CLI child gets only this credential, PATH and HOME; every other inherited variable is dropped,
     * so it never sees DATABASE_URL, APP_SECRET, API_KEY_ENCRYPTION_KEY or other provider keys.
     */
    private function claudeCodeClient(Credential $credential): ClaudeCodeModelClient
    {
        \assert(Provider::ClaudeCode === $credential->provider());
        $key = $this->key($credential);

        $environment = [];
        foreach ([...array_keys(getenv()), ...array_keys($_ENV), ...array_map(static fn (Credential $c): string => $c->envVar(), Credential::cases())] as $name) {
            $environment[(string) $name] = false;
        }
        unset($environment['PATH'], $environment['HOME']);
        $environment[$credential->envVar()] = $key;

        (new Filesystem())->mkdir($this->claudeWorkingDirectory);

        return new ClaudeCodeModelClient($this->claudeWorkingDirectory, $environment, cliBinary: $this->claudeCliBinary);
    }

    private function key(Credential $credential): string
    {
        return $this->keys->plainKey($credential) ?? throw new MissingApiKeyException();
    }
}
