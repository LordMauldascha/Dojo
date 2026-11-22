<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\Credential;
use App\Ai\Effort;
use App\Ai\ModelCatalog;
use App\Ai\Provider;
use App\Entity\HuggingFaceModel;
use App\Entity\ProviderApiKey;
use App\Security\ApiKeyStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ModelCatalogTest extends KernelTestCase
{
    private ModelCatalog $catalog;
    private EntityManagerInterface $entityManager;
    private ApiKeyStore $keys;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->catalog = self::getContainer()->get(ModelCatalog::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->keys = self::getContainer()->get(ApiKeyStore::class);
        $this->entityManager->createQuery('DELETE FROM '.HuggingFaceModel::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.ProviderApiKey::class)->execute();
    }

    public function testStaticProvidersKeepTheirModelsAndHuggingFaceHasNoneUntilAdded(): void
    {
        $this->keys->save(Credential::HuggingFace, 'synthetic-key-0000000000');
        self::assertSame(Provider::OpenAi->models(), $this->catalog->models(Provider::OpenAi));
        self::assertSame([], $this->catalog->models(Provider::HuggingFace));
        self::assertArrayNotHasKey('Hugging Face', $this->catalog->choices());
    }

    public function testAddedHuggingFaceModelsAreOfferedSortedInTheirOwnGroup(): void
    {
        $this->keys->save(Credential::HuggingFace, 'synthetic-key-0000000000');
        $this->keys->save(Credential::OpenAi, 'synthetic-key-0000000000');
        $this->entityManager->persist(new HuggingFaceModel('org/zeta'));
        $this->entityManager->persist(new HuggingFaceModel('org/alpha'));
        $this->entityManager->flush();

        self::assertSame(['org/alpha', 'org/zeta'], $this->catalog->models(Provider::HuggingFace));
        self::assertSame(
            ['org/alpha' => 'huggingface:org/alpha', 'org/zeta' => 'huggingface:org/zeta'],
            $this->catalog->choices()['Hugging Face'],
        );
        self::assertSame(['gpt-5.5' => 'openai:gpt-5.5'], \array_slice($this->catalog->choices()['OpenAI'], 0, 1));
    }

    public function testChoiceIsParsedBackAndUnknownValuesAreRefused(): void
    {
        foreach ([Credential::Gemini, Credential::HuggingFace] as $credential) {
            $this->keys->save($credential, 'synthetic-key-0000000000');
        }
        $this->entityManager->persist(new HuggingFaceModel('org/alpha'));
        $this->entityManager->flush();

        self::assertSame('gemini:gemini-3.5-flash:medium', $this->catalog->fromChoice('gemini:gemini-3.5-flash', Effort::Medium)?->key());
        self::assertSame('gemini:gemini-3.5-flash:-', $this->catalog->fromChoice('gemini:gemini-3.5-flash', null)?->key());
        self::assertSame('huggingface:org/alpha:-', $this->catalog->fromChoice('huggingface:org/alpha', null)?->key());
        self::assertNull($this->catalog->fromChoice('huggingface:org/not-added', null));
        self::assertNull($this->catalog->fromChoice('gemini:opus', null));
        self::assertNull($this->catalog->fromChoice('nobody:model', null));
        self::assertNull($this->catalog->fromChoice('no-colon', null));
    }

    public function testChoicesLeaveOutProvidersWithoutCredential(): void
    {
        $this->keys->save(Credential::OpenAi, 'synthetic-key-0000000000');

        self::assertSame(['OpenAI'], array_keys($this->catalog->choices()));
        self::assertTrue($this->catalog->isAvailable(Provider::OpenAi));
        self::assertFalse($this->catalog->isAvailable(Provider::Gemini));
        self::assertNull($this->catalog->fromChoice('gemini:gemini-3.5-flash', null));
    }

    public function testClaudeCodeIsAvailableWithEitherCredential(): void
    {
        self::assertFalse($this->catalog->isAvailable(Provider::ClaudeCode));

        $this->keys->save(Credential::ClaudeOAuth, 'synthetic-key-0000000000');
        self::assertTrue($this->catalog->isAvailable(Provider::ClaudeCode));

        $this->keys->delete(Credential::ClaudeOAuth);
        $this->keys->save(Credential::Anthropic, 'synthetic-key-0000000000');
        self::assertTrue($this->catalog->isAvailable(Provider::ClaudeCode));
    }
}
