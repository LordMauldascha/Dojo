<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\HuggingFaceModel;
use App\Entity\SkillVersion;
use Symfony\AI\Platform\Bridge\HuggingFace\Completions;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * The only code that calls a provider for runs. Options are built here from a fixed whitelist (tools: [] for Claude Code
 * plus the effort option of EffortMapper), never from user input. Logs nothing of prompt or answer.
 */
final readonly class LlmGateway
{
    public function __construct(private PlatformProvider $platforms)
    {
    }

    /**
     * Tries the stored credentials in Provider::credentials() order; only a rejected key moves on to the next one.
     *
     * @throws MissingApiKeyException    no credential of the provider is stored
     * @throws InvalidApiKeyException    every stored credential was rejected
     * @throws UnsupportedModelException the provider cannot serve the model as chat completion
     */
    public function ask(LlmConfiguration $configuration, ?string $systemPrompt, string $userPrompt): LlmAnswer
    {
        $model = $configuration->model;
        // the catalogs read "model?key=value" as options, which the Claude Code client would turn into CLI flags
        if ('' === $model || str_contains($model, '?') || (Provider::HuggingFace === $configuration->provider
            ? 1 !== preg_match('~'.HuggingFaceModel::ID_PATTERN.'~', $model)
            : !\in_array($model, $configuration->provider->models(), true))) {
            throw new UnsupportedModelException();
        }

        $messages = null === $systemPrompt
            ? new MessageBag(Message::ofUser($userPrompt))
            : new MessageBag(Message::forSystem($systemPrompt), Message::ofUser($userPrompt));
        // an empty tool list disables the CLI's built-in tools; the HTTP bridges get no tools option at all
        $options = [...Provider::ClaudeCode === $configuration->provider ? ['tools' => []] : [], ...EffortMapper::map($configuration)['options']];

        $rejected = null;
        foreach ($configuration->provider->credentials() as $credential) {
            try {
                $platform = $this->platforms->forCredential($credential);
            } catch (MissingApiKeyException) {
                continue;
            }

            try {
                return $this->call($platform, $configuration, $model, $messages, $options);
            } catch (InvalidApiKeyException $e) {
                $rejected = $e;
            }
        }

        throw null === $rejected ? new MissingApiKeyException() : new InvalidApiKeyException($rejected);
    }

    /**
     * Skill files ordered by title, separated by a horizontal rule; null without skills.
     *
     * @param iterable<SkillVersion> $skills
     */
    public static function systemPrompt(iterable $skills): ?string
    {
        $skills = [...$skills];
        usort($skills, static fn (SkillVersion $a, SkillVersion $b): int => strcmp($a->getTitle(), $b->getTitle()));

        return [] === $skills ? null : implode("\n\n---\n\n", array_map(static fn (SkillVersion $skill): string => $skill->getSkillFile(), $skills));
    }

    /**
     * @param non-empty-string     $model
     * @param array<string, mixed> $options
     */
    private function call(PlatformInterface $platform, LlmConfiguration $configuration, string $model, MessageBag $messages, array $options): LlmAnswer
    {
        $huggingFace = Provider::HuggingFace === $configuration->provider;
        $start = hrtime(true);

        try {
            if ($huggingFace && !$platform->getModelCatalog()->getModel($model) instanceof Completions) {
                throw new UnsupportedModelException();
            }

            $result = $platform->invoke($model, $messages, $options);
            $text = $result->asText();
        } catch (AuthenticationException $e) {
            throw new InvalidApiKeyException($e);
        } catch (ModelNotFoundException $e) {
            throw new UnsupportedModelException($e);
        } catch (BadRequestException $e) {
            // the router answers 400 for a model none of its inference providers serves as chat completion
            throw $huggingFace ? new UnsupportedModelException($e) : $e;
        }

        $usage = $result->getMetadata()->get('token_usage');
        $usage = $usage instanceof TokenUsageInterface ? $usage : null;

        return new LlmAnswer($text, $usage?->getPromptTokens(), $usage?->getCompletionTokens(), intdiv(hrtime(true) - $start, 1_000_000));
    }
}
