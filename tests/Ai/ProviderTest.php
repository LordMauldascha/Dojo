<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\Provider;
use PHPUnit\Framework\TestCase;

final class ProviderTest extends TestCase
{
    public function testEveryProviderOffersUniqueNonEmptyModelsAndADisplayName(): void
    {
        foreach (Provider::cases() as $provider) {
            $models = $provider->models();
            // Hugging Face models are added by an admin, see ModelCatalogTest
            if (Provider::HuggingFace !== $provider) {
                self::assertNotSame([], $models, $provider->value);
            }
            self::assertSame($models, array_values(array_unique($models)), $provider->value);
            self::assertSame($models, array_filter($models, static fn (string $model): bool => '' !== trim($model)), $provider->value);
            self::assertNotSame('', $provider->displayName());
        }
    }
}
