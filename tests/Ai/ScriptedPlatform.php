<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use Symfony\AI\Platform\Bridge\HuggingFace\Completions;
use Symfony\AI\Platform\Bridge\HuggingFace\ModelCatalog as HuggingFaceCatalog;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

/** Answers or fails when the result is read, as the real bridges do. Its catalog knows the chat model "org/chat". */
final class ScriptedPlatform implements PlatformInterface
{
    public int $invocations = 0;
    public string $model = '';
    public mixed $input = null;
    /** @var array<string, mixed> */
    public array $options = [];

    /** @param \Closure(): ResultInterface $answer */
    public function __construct(private readonly \Closure $answer)
    {
    }

    public static function answering(string $text): self
    {
        return new self(static fn (): ResultInterface => new TextResult($text));
    }

    public static function failing(\Throwable $error): self
    {
        return new self(static fn (): ResultInterface => throw $error);
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        ++$this->invocations;
        $this->model = $model instanceof Model ? $model->getName() : $model;
        $this->input = $input;
        $this->options = $options;

        $converter = new class($this->answer) implements ResultConverterInterface {
            /** @param \Closure(): ResultInterface $answer */
            public function __construct(private readonly \Closure $answer)
            {
            }

            public function supports(Model $model): bool
            {
                return true;
            }

            public function convert(RawResultInterface $result, array $options = []): ResultInterface
            {
                return ($this->answer)();
            }

            public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
            {
                return null;
            }
        };

        return new DeferredResult($converter, new InMemoryRawResult());
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return new HuggingFaceCatalog(['org/chat' => ['class' => Completions::class, 'capabilities' => []]]);
    }
}
