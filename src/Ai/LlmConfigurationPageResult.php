<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\ProviderApiKey;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Response;

/**
 * Outcome of a request to the LLM configuration page: either a flash and a redirect after a save, or the data to render.
 *
 * @phpstan-type CredentialRow array{credential: Credential, key: ProviderApiKey|null, form: FormView}
 * @phpstan-type ProviderCard array{provider: Provider, credentials: list<CredentialRow>, configured: bool}
 */
final readonly class LlmConfigurationPageResult
{
    /**
     * @param array{type: string, message: string}|null $flash set when the page redirects
     * @param list<ProviderCard>                        $cards
     */
    private function __construct(
        public ?array $flash,
        public int $status = Response::HTTP_OK,
        public array $cards = [],
        public ?LlmConfiguration $judge = null,
        public ?FormView $judgeForm = null,
        public ?LlmConfiguration $helper = null,
        public ?FormView $helperForm = null,
    ) {
    }

    public static function redirect(string $type, string $message): self
    {
        return new self(['type' => $type, 'message' => $message]);
    }

    /** @param list<ProviderCard> $cards */
    public static function render(int $status, array $cards, ?LlmConfiguration $judge, FormView $judgeForm, ?LlmConfiguration $helper, FormView $helperForm): self
    {
        return new self(null, $status, $cards, $judge, $judgeForm, $helper, $helperForm);
    }
}
