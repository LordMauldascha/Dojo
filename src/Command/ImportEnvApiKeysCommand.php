<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\Credential;
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
        foreach (Credential::cases() as $credential) {
            $value = $_SERVER[$credential->envVar()] ?? null;

            if (!\is_string($value) || '' === $value) {
                $io->writeln(\sprintf('%s: %s not set, skipped', $credential->label(), $credential->envVar()));
            } elseif ($this->store->has($credential)) {
                $io->writeln(\sprintf('%s: already in database, kept', $credential->label()));
            } else {
                $this->store->save($credential, $value);
                $io->writeln(\sprintf('%s: imported from %s', $credential->label(), $credential->envVar()));
            }
        }

        return Command::SUCCESS;
    }
}
