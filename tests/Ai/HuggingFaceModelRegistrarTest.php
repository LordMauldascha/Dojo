<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\HuggingFaceModelAddResult;
use App\Ai\HuggingFaceModelRegistrar;
use App\Entity\HuggingFaceModel;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HuggingFaceModelRegistrarTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.HuggingFaceModel::class)->execute();
    }

    private function registrar(MockResponse $hubAnswer): HuggingFaceModelRegistrar
    {
        static::getContainer()->set('http_client', new MockHttpClient($hubAnswer));

        return static::getContainer()->get(HuggingFaceModelRegistrar::class);
    }

    private function found(): MockResponse
    {
        return new MockResponse('{"id":"acme/chat-8b"}', ['response_headers' => ['content-type: application/json']]);
    }

    /** @return list<string> */
    private function storedModels(): array
    {
        $this->entityManager->clear();

        return array_map(static fn (HuggingFaceModel $model): string => $model->getModelId(), $this->entityManager->getRepository(HuggingFaceModel::class)->findAll());
    }

    public function testAddsAModelTheHubKnows(): void
    {
        self::assertSame(HuggingFaceModelAddResult::Added, $this->registrar($this->found())->add('acme/chat-8b'));
        self::assertSame(['acme/chat-8b'], $this->storedModels());
    }

    public function testAnAlreadyAddedModelIsNotAskedFromTheHub(): void
    {
        $this->entityManager->persist(new HuggingFaceModel('acme/chat-8b'));
        $this->entityManager->flush();
        $requests = 0;
        $hub = new MockResponse('', ['error' => 'must not be called']);
        static::getContainer()->set('http_client', new MockHttpClient(static function () use (&$requests, $hub): MockResponse {
            ++$requests;

            return $hub;
        }));

        self::assertSame(HuggingFaceModelAddResult::AlreadyAdded, static::getContainer()->get(HuggingFaceModelRegistrar::class)->add('acme/chat-8b'));
        self::assertSame(0, $requests);
        self::assertSame(['acme/chat-8b'], $this->storedModels());
    }

    public function testAModelUnknownToTheHubIsNotAdded(): void
    {
        $unknown = new MockResponse('{"error":"Repository not found"}', ['http_code' => 404, 'response_headers' => ['content-type: application/json']]);

        self::assertSame(HuggingFaceModelAddResult::NotFoundOnHub, $this->registrar($unknown)->add('acme/made-up'));
        self::assertSame([], $this->storedModels());
    }

    public function testAnUnreachableHubIsReportedAndNothingIsAdded(): void
    {
        self::assertSame(HuggingFaceModelAddResult::HubUnavailable, $this->registrar(new MockResponse('', ['error' => 'connection refused']))->add('acme/chat-8b'));
        self::assertSame([], $this->storedModels());
    }

    public function testAConcurrentAddOfTheSameModelIsReportedAsConflict(): void
    {
        $connection = $this->entityManager->getConnection();
        $this->entityManager->getEventManager()->addEventListener(Events::prePersist, new class($connection) {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function prePersist(PrePersistEventArgs $args): void
            {
                $this->connection->executeStatement("INSERT INTO huggingface_model (model_id, created_at) VALUES ('acme/chat-8b', NOW())");
            }
        });

        self::assertSame(HuggingFaceModelAddResult::Conflict, $this->registrar($this->found())->add('acme/chat-8b'));
    }
}
