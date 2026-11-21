<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * The providers "anthropic" and "claude_oauth" become the single provider "claude_code" in the LLM configurations of
 * runs and of their (immutable) version snapshots. Data only, no schema change.
 */
final class Version20261121050500 extends AbstractMigration
{
    /** Claude Code knows only the aliases opus, sonnet and haiku. claude-fable-5-2 has no alias and is mapped to opus (assumption). */
    private const ANTHROPIC_MODELS = [
        'claude-opus-5-5' => 'opus',
        'claude-sonnet-5-5' => 'sonnet',
        'claude-haiku-5-5' => 'haiku',
        'claude-fable-5-2' => 'opus',
    ];

    public function getDescription(): string
    {
        return 'Merge the providers anthropic and claude_oauth into claude_code in run configurations and snapshots.';
    }

    public function up(Schema $schema): void
    {
        foreach (['run', 'run_version'] as $table) {
            /** @var list<array{id: int, configurations: string}> $rows */
            $rows = $this->connection->fetchAllAssociative(\sprintf('SELECT id, configurations FROM %s', $table));
            foreach ($rows as $row) {
                $configurations = json_decode($row['configurations'], true, 512, \JSON_THROW_ON_ERROR);
                \assert(\is_array($configurations));
                $migrated = self::migrate($configurations);

                if ($migrated !== $configurations) {
                    $this->addSql(
                        \sprintf('UPDATE %s SET configurations = CAST(:configurations AS json) WHERE id = :id', $table),
                        ['configurations' => json_encode($migrated, \JSON_THROW_ON_ERROR), 'id' => $row['id']],
                    );
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('The mapping of anthropic models to Claude Code aliases is lossy.');
    }

    /**
     * @param array<mixed> $configurations
     *
     * @return list<array{provider: string, model: string, effort: string|null}>
     */
    private static function migrate(array $configurations): array
    {
        $migrated = [];
        foreach ($configurations as $configuration) {
            \assert(\is_array($configuration));
            if ('anthropic' === $configuration['provider']) {
                $configuration['model'] = self::ANTHROPIC_MODELS[$configuration['model']] ?? $configuration['model'];
            }
            if (\in_array($configuration['provider'], ['anthropic', 'claude_oauth'], true)) {
                $configuration['provider'] = 'claude_code';
            }

            $key = $configuration['provider'].':'.$configuration['model'].':'.($configuration['effort'] ?? '-');
            $migrated[$key] ??= ['provider' => $configuration['provider'], 'model' => $configuration['model'], 'effort' => $configuration['effort']];
        }

        return array_values($migrated);
    }
}
