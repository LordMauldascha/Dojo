<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\Provider;
use App\Security\ApiKeyStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:api-keys:import-env', description: 'Copy API keys set in the environment (e.g. .env.local) into the database')]
final class ImportEnvApiKeysCommand extends Command
{
    public function __construct(private readonly ApiKeyStore $store)
    {
        parent::__construct();
    }

    public function __invoke(SymfonyStyle $io): int
    {
        foreach (Provider::cases() as $provider) {
            $value = $_SERVER[$provider->envVar()] ?? null;

            if (!\is_string($value) || '' === $value) {
                $io->writeln(\sprintf('%s: %s not set, skipped', $provider->label(), $provider->envVar()));
            } elseif ($this->store->has($provider)) {
                $io->writeln(\sprintf('%s: already in database, kept', $provider->label()));
            } else {
                $this->store->save($provider, $value);
                $io->writeln(\sprintf('%s: imported from %s', $provider->label(), $provider->envVar()));
            }
        }

        return Command::SUCCESS;
    }
}
