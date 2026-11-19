# Dojo

Symfony project with a Webpack Encore frontend.

## Installation

Requirements: Git, Docker with Compose, Node.js (version in `.nvmrc`).

```bash
git clone <repository-url> dojo
cd dojo

# Start the app, database and mailer
docker compose up -d --build

# Install PHP dependencies (runs inside the app container)
docker compose exec app composer install

# Create the database schema and import API keys from .env.local into the database
docker compose exec app composer setup

# Install frontend dependencies and build the assets (Node runs on the host)
npm ci
npm run build

# Enable the pre-commit hook
git config core.hooksPath .githooks
```

The app is available at http://localhost:8000.

For frontend development run `npm run watch` instead of `npm run build`.
