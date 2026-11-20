<?php

declare(strict_types=1);

namespace App\Ai;

enum Provider: string
{
    case OpenAi = 'openai';
    case Gemini = 'gemini';
    case DeepSeek = 'deepseek';
    case Anthropic = 'anthropic';
    case ClaudeOAuth = 'claude_oauth';

    public function label(): string
    {
        return match ($this) {
            self::OpenAi => 'OpenAI API Key',
            self::Gemini => 'Google Gemini API Key',
            self::DeepSeek => 'DeepSeek API Key',
            self::Anthropic => 'Anthropic API Key',
            self::ClaudeOAuth => 'Claude OAuth Token',
        };
    }

    /** Name of the provider for selects ("OpenAI"), unlike label() which names the credential. */
    public function displayName(): string
    {
        return match ($this) {
            self::OpenAi => 'OpenAI',
            self::Gemini => 'Google Gemini',
            self::DeepSeek => 'DeepSeek',
            self::Anthropic => 'Anthropic',
            self::ClaudeOAuth => 'Claude (OAuth)',
        };
    }

    /**
     * Models selectable in a run, defined in code. The names come from the catalogs of the installed
     * symfony/ai platform packages (claude_oauth, openai, gemini, deepseek) and from the Anthropic model list.
     *
     * @return list<string>
     */
    public function models(): array
    {
        return match ($this) {
            self::OpenAi => ['gpt-5.5', 'gpt-5.4', 'gpt-5.4-mini', 'gpt-5.4-nano', 'gpt-4.1'],
            self::Gemini => ['gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-3.5-flash', 'gemini-3.1-pro-preview'],
            self::DeepSeek => ['deepseek-chat', 'deepseek-reasoner', 'deepseek-v4-pro', 'deepseek-v4-flash'],
            self::Anthropic => ['claude-fable-5-2', 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'],
            self::ClaudeOAuth => ['opus', 'sonnet', 'haiku'],
        };
    }

    /** Name of the environment variable that holds this credential. */
    public function envVar(): string
    {
        return match ($this) {
            self::OpenAi => 'OPENAI_API_KEY',
            self::Gemini => 'GEMINI_API_KEY',
            self::DeepSeek => 'DEEPSEEK_API_KEY',
            self::Anthropic => 'ANTHROPIC_API_KEY',
            self::ClaudeOAuth => 'CLAUDE_CODE_OAUTH_TOKEN',
        };
    }
}
