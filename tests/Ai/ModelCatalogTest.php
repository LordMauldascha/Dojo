<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\Effort;
use App\Ai\ModelCatalog;
use App\Ai\Provider;
use App\Entity\HuggingFaceModel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ModelCatalogTest extends KernelTestCase
{
    private ModelCatalog $catalog;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->catalog = self::getContainer()->get(ModelCatalog::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.HuggingFaceModel::class)->execute();
    }

    public function testStaticProvidersKeepTheirModelsAndHuggingFaceHasNoneUntilAdded(): void
    {
        self::assertSame(Provider::OpenAi->models(), $this->catalog->models(Provider::OpenAi));
        self::assertSame([], $this->catalog->models(Provider::HuggingFace));
        self::assertArrayNotHasKey('Hugging Face', $this->catalog->choices());
    }

    public function testAddedHuggingFaceModelsAreOfferedSortedInTheirOwnGroup(): void
    {
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
        $this->entityManager->persist(new HuggingFaceModel('org/alpha'));
        $this->entityManager->flush();

        self::assertSame('gemini:gemini-2.5-pro:medium', $this->catalog->fromChoice('gemini:gemini-2.5-pro', Effort::Medium)?->key());
        self::assertSame('gemini:gemini-2.5-pro:-', $this->catalog->fromChoice('gemini:gemini-2.5-pro', null)?->key());
        self::assertSame('huggingface:org/alpha:-', $this->catalog->fromChoice('huggingface:org/alpha', null)?->key());
        self::assertNull($this->catalog->fromChoice('huggingface:org/not-added', null));
        self::assertNull($this->catalog->fromChoice('gemini:opus', null));
        self::assertNull($this->catalog->fromChoice('nobody:model', null));
        self::assertNull($this->catalog->fromChoice('no-colon', null));
    }
}
