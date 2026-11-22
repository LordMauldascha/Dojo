<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * For the Hugging Face Hub lookup: the bridge's ApiClient turns a 401 into a generic RuntimeException,
 * so the status is checked here. Waits for the headers, which the ApiClient does right after anyway.
 */
final class RejectedKeyHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $response = $this->client->request($method, $url, $options);
        if (401 === $response->getStatusCode()) {
            throw new InvalidApiKeyException();
        }

        return $response;
    }
}
