<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\HuggingFaceModel;
use App\Security\UserRole;
use App\Tests\Support\AppWebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HuggingFaceModelControllerTest extends AppWebTestCase
{
    /** @var list<string> */
    private array $requestedUrls = [];

    /** @param array<string, mixed>|null $single the answer for one model, null for "unknown" */
    private function hub(?array $single = ['id' => 'acme/chat-8b'], bool $down = false): void
    {
        $this->client->disableReboot();
        $listing = [
            ['id' => 'acme/chat-8b', 'pipeline_tag' => 'text-generation', 'inferenceProviderMapping' => [
                ['provider' => 'groq', 'status' => 'live', 'task' => 'conversational'],
                ['provider' => 'together', 'status' => 'staging', 'task' => 'conversational'],
            ]],
            ['id' => 'acme/other', 'pipeline_tag' => 'text-generation', 'inferenceProviderMapping' => null],
        ];
        static::getContainer()->set('http_client', new MockHttpClient(function (string $method, string $url) use ($listing, $single, $down): MockResponse {
            $this->requestedUrls[] = $url;
            if ($down) {
                return new MockResponse('', ['error' => 'connection refused']);
            }
            $isListing = null === parse_url($url, \PHP_URL_PATH) || '/api/models' === parse_url($url, \PHP_URL_PATH);

            return new MockResponse(json_encode($isListing ? $listing : ($single ?? ['error' => 'Repository not found']), \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
        }));
    }

    private function loginAdmin(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
    }

    /** @return list<string> */
    private function storedModels(): array
    {
        $this->entityManager->clear();

        return array_map(static fn (HuggingFaceModel $model): string => $model->getModelId(), $this->entityManager->getRepository(HuggingFaceModel::class)->findBy([], ['modelId' => 'ASC']));
    }

    public function testAnonymousIsRedirectedAndNonAdminIsForbidden(): void
    {
        $this->client->request('GET', '/admin/llm-configuration/huggingface/models');
        self::assertResponseRedirects('http://localhost/login');

        $this->client->loginUser($this->persistUser('member'));
        $this->client->request('GET', '/admin/llm-configuration/huggingface/models');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/llm-configuration/huggingface/models', ['modelId' => 'acme/chat-8b']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testDiscoveryListsHubModelsWithLiveProvidersAndDefaultsToTextGeneration(): void
    {
        $this->hub();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/llm-configuration/huggingface/models');

        self::assertResponseIsSuccessful();
        self::assertSame(['acme/chat-8b', 'acme/other'], $this->titles($crawler));
        self::assertSelectorTextContains('tbody tr:first-child', 'groq');
        self::assertSelectorTextNotContains('tbody tr:first-child', 'together');
        self::assertStringContainsString('pipeline_tag=text-generation', $this->requestedUrls[0]);
        self::assertStringContainsString('inference_provider=all', $this->requestedUrls[0]);
    }

    public function testFiltersArePassedToTheHub(): void
    {
        $this->hub();
        $this->loginAdmin();

        $this->client->request('GET', '/admin/llm-configuration/huggingface/models', ['search' => 'chat', 'task' => '', 'provider' => 'groq', 'warm' => '1']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('search=chat', $this->requestedUrls[0]);
        self::assertStringNotContainsString('pipeline_tag=', $this->requestedUrls[0]);
        self::assertStringContainsString('inference_provider=groq', $this->requestedUrls[0]);
        self::assertStringContainsString('inference=warm', $this->requestedUrls[0]);
    }

    public function testInvalidFilterIsRefused(): void
    {
        $this->hub();
        $this->loginAdmin();

        $this->client->request('GET', '/admin/llm-configuration/huggingface/models', ['task' => 'Bad Task!']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->requestedUrls);
    }

    public function testUnreachableHubShowsAnErrorInsteadOfFailing(): void
    {
        $this->hub(down: true);
        $this->loginAdmin();

        $this->client->request('GET', '/admin/llm-configuration/huggingface/models');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash-error', 'could not be reached');
    }

    public function testAddingAModelStoresItAndKeepsTheSearch(): void
    {
        $this->hub();
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/admin/llm-configuration/huggingface/models', ['search' => 'chat']);

        $this->client->submit($crawler->selectButton('Add')->first()->form());

        self::assertResponseRedirects('/admin/llm-configuration/huggingface/models?search=chat');
        self::assertSame(['acme/chat-8b'], $this->storedModels());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'acme/chat-8b');
        self::assertStringContainsString('Added', $crawler->filter('tbody tr:first-child')->text());
        self::assertCount(1, $crawler->selectButton('Add'));
    }

    public function testAddingTwiceKeepsOneRow(): void
    {
        $this->entityManager->persist(new HuggingFaceModel('acme/chat-8b'));
        $this->entityManager->flush();
        $this->hub();
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/admin/llm-configuration/huggingface/models');
        $form = $crawler->selectButton('Add')->first()->form();
        $form['modelId'] = 'acme/chat-8b';

        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'already added');
        self::assertSame(['acme/chat-8b'], $this->storedModels());
    }

    public function testAModelUnknownToTheHubIsNotAdded(): void
    {
        $this->hub(single: null);
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/admin/llm-configuration/huggingface/models');
        $form = $crawler->selectButton('Add')->first()->form();
        $form['modelId'] = 'acme/made-up';

        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'does not know this model');
        self::assertSame([], $this->storedModels());
    }

    public function testAMalformedModelIdIsRefusedBeforeTheHubIsAsked(): void
    {
        $this->hub();
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/admin/llm-configuration/huggingface/models');
        $listingRequests = \count($this->requestedUrls);
        $form = $crawler->selectButton('Add')->first()->form();
        $form['modelId'] = '../../etc?x=1';

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount($listingRequests, $this->requestedUrls);
        self::assertSame([], $this->storedModels());
    }

    public function testAddingNeedsAValidCsrfToken(): void
    {
        $this->hub();
        $this->loginAdmin();

        $this->client->request('POST', '/admin/llm-configuration/huggingface/models', ['modelId' => 'acme/chat-8b', '_token' => 'invalid']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->storedModels());
    }

    public function testModelsAreListedOnTheLlmConfigurationPageAndCanBeRemoved(): void
    {
        $model = new HuggingFaceModel('acme/chat-8b');
        $this->entityManager->persist($model);
        $this->entityManager->flush();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/llm-configuration');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.provider-card:nth-child(5)', 'acme/chat-8b');
        self::assertSelectorExists('.provider-card:nth-child(5) a[href="/admin/llm-configuration/huggingface/models"]');

        $this->client->request('POST', '/admin/llm-configuration/huggingface/models/'.$model->getId().'/delete', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['acme/chat-8b'], $this->storedModels());

        $this->client->submit($crawler->filter('form[action*="/huggingface/models/"][action$="/delete"]')->form());
        self::assertResponseRedirects('/admin/llm-configuration');
        self::assertSame([], $this->storedModels());
    }
}
