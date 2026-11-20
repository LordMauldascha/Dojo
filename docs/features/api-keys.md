# API keys

Credentials for the LLM providers. Admins manage them at `/admin/api-keys`; nobody else has access.

## Providers

One credential exists per provider:

| Provider | Credential |
| --- | --- |
| OpenAI | API key |
| Google Gemini | API key |
| DeepSeek | API key |
| Anthropic | API key |
| Claude (OAuth) | OAuth token of the Claude Code CLI |

The list is defined in `App\Ai\Provider`. Providers and their models are global and available in every project.

## Handling

- Saving a key for a provider replaces the existing one. Deleting removes it.
- A stored key is never shown again. The page only shows `••••` plus the last four characters and the time of the last
  change.
- The home page shows per provider whether a key is set.

## Storage

Keys are stored encrypted with libsodium (`secretbox`, XSalsa20-Poly1305) using the key in the environment variable
`API_KEY_ENCRYPTION_KEY`. It must be a base64 encoded 32 byte key. Generate one with:

```bash
php -r 'echo base64_encode(sodium_crypto_secretbox_keygen());'
```

The encryption is reversible on purpose, because a provider needs the plain key on every request. Losing the
encryption key means the stored keys cannot be read anymore and have to be entered again.

## Import from the environment

`bin/console app:api-keys:import-env` copies provider keys set in the environment (for example `.env.local`) into the
database. `composer setup` runs it during installation. See [Commands.md](../Commands.md).

## Current limits

Runs do not call the providers yet, so the stored keys are not used by the web app so far.
