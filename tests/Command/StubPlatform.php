<?php

declare(strict_types=1);

namespace App\Tests\Command;

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

final class StubPlatform implements PlatformInterface
{
    public string $model = '';
    public mixed $input = null;
    /** @var array<string, mixed> */
    public array $options = [];

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $this->model = $model instanceof Model ? $model->getName() : $model;
        $this->input = $input;
        $this->options = $options;

        $converter = new class implements ResultConverterInterface {
            public function supports(Model $model): bool
            {
                return true;
            }

            public function convert(RawResultInterface $result, array $options = []): ResultInterface
            {
                return new TextResult('stub answer');
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
        throw new \LogicException('not used');
    }
}
