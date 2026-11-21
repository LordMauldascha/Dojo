<?php

declare(strict_types=1);

namespace App\Ai;

/** What a run uses: a platform with its models. The credentials an admin stores for it are in Credential. */
enum Provider: string
{
    case OpenAi = 'openai';
    case Gemini = 'gemini';
    case DeepSeek = 'deepseek';
    case ClaudeCode = 'claude_code';

    /** Name of the provider for selects ("OpenAI"). */
    public function displayName(): string
    {
        return match ($this) {
            self::OpenAi => 'OpenAI',
            self::Gemini => 'Google Gemini',
            self::DeepSeek => 'DeepSeek',
            self::ClaudeCode => 'Claude Code',
        };
    }

    /**
     * Models selectable in a run, defined in code. The names come from the catalogs of the installed
     * symfony/ai platform packages (claude-code, openai, gemini, deepseek).
     *
     * @return list<string>
     */
    public function models(): array
    {
        return match ($this) {
            self::OpenAi => ['gpt-5.5', 'gpt-5.4', 'gpt-5.4-mini', 'gpt-5.4-nano', 'gpt-4.1'],
            self::Gemini => ['gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-3.5-flash', 'gemini-3.1-pro-preview'],
            self::DeepSeek => ['deepseek-chat', 'deepseek-reasoner', 'deepseek-v4-pro', 'deepseek-v4-flash'],
            self::ClaudeCode => ['opus', 'sonnet', 'haiku'],
        };
    }

    /**
     * The credentials that can be stored for this provider. Claude Code takes either an Anthropic API key
     * or an OAuth token of the Claude Code CLI; there is no separate Anthropic provider.
     *
     * @return list<Credential>
     */
    public function credentials(): array
    {
        return array_values(array_filter(Credential::cases(), fn (Credential $credential): bool => $this === $credential->provider()));
    }
}
