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
