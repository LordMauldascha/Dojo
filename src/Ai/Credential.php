<?php

declare(strict_types=1);

namespace App\Ai;

/** A secret an admin stores. The backing values are the ones already stored in provider_api_key.provider. */
enum Credential: string
{
    case OpenAi = 'openai';
    case Gemini = 'gemini';
    case DeepSeek = 'deepseek';
    case Anthropic = 'anthropic';
    case ClaudeOAuth = 'claude_oauth';
    case HuggingFace = 'huggingface';

    public function label(): string
    {
        return match ($this) {
            self::OpenAi => 'OpenAI API key',
            self::Gemini => 'Google Gemini API key',
            self::DeepSeek => 'DeepSeek API key',
            self::Anthropic => 'Anthropic API key',
            self::ClaudeOAuth => 'Claude OAuth token',
            self::HuggingFace => 'Hugging Face access token',
        };
    }

    public function provider(): Provider
    {
        return match ($this) {
            self::OpenAi => Provider::OpenAi,
            self::Gemini => Provider::Gemini,
            self::DeepSeek => Provider::DeepSeek,
            self::Anthropic, self::ClaudeOAuth => Provider::ClaudeCode,
            self::HuggingFace => Provider::HuggingFace,
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
            self::HuggingFace => 'HUGGINGFACE_API_KEY',
        };
    }
}
