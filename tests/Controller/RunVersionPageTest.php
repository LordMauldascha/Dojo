<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Ai\Effort;
use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use App\Entity\Run;
use App\Entity\RunVersion;
use App\Tests\Support\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class RunVersionPageTest extends AppWebTestCase
{
    public function testMatrixShowsScoresFailuresSkippedColumnAndCriteria(): void
    {
        [$user, $run] = $this->versionWithResults();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/1');

        self::assertResponseIsSuccessful();
        $matrix = $crawler->filter('table.result-matrix');
        self::assertSame(['Farewell (version 1)', 'Greeting (version 1)'], $matrix->filter('tbody th[scope="row"]')->each(static fn (Crawler $th): string => trim($th->text())));
        $headers = $matrix->filter('thead th[scope="col"]')->each(static fn (Crawler $th): string => trim(preg_replace('/\s+/', ' ', $th->text()) ?? ''));
        self::assertCount(4, $headers);
        self::assertStringContainsString('Claude Code / opus / no effort', $headers[1]);
        self::assertStringContainsString('Hugging Face', $headers[2]);
        self::assertStringContainsString('effort not supported', $headers[2]);
        self::assertStringContainsString('OpenAI / gpt-5.4 / no effort', $headers[3]);
        self::assertStringContainsString('skipped – no API key', $headers[3]);

        $firstRow = $matrix->filter('tbody tr')->eq(1)->filter('td');
        self::assertSame('80 %', trim($firstRow->eq(0)->filter('summary')->text()));
        self::assertSame('0.3 s · 10 in / 20 out', trim($firstRow->eq(0)->filter('.cell-usage')->text()));
        self::assertSame('0.0 s · – in / – out', trim($matrix->filter('tbody tr')->eq(0)->filter('td')->eq(1)->filter('.cell-usage')->text()), 'missing token counts show a dash');
        self::assertCount(0, $firstRow->eq(1)->filter('.cell-usage'), 'a cell that failed before answering has no usage');
        self::assertSame('failed – timeout', trim($firstRow->eq(1)->text()));
        self::assertSame('skipped – no API key', trim($firstRow->eq(2)->text()));
        self::assertSame('running', trim($matrix->filter('tbody tr')->eq(0)->filter('td')->eq(0)->text()));

        $detail = $firstRow->eq(0)->filter('details');
        self::assertSame('<b>answer</b> & more', $detail->filter('pre')->text());
        $criteria = $detail->filter('li')->each(static fn (Crawler $li): string => trim($li->text()));
        self::assertCount(5, $criteria);
        self::assertStringContainsString('met', $criteria[0]);
        self::assertStringNotContainsString('not met', $criteria[0]);
        self::assertStringContainsString('Mentions <the> topic', $criteria[0]);
        self::assertStringContainsString('reason 0', $criteria[0]);
        self::assertStringContainsString('not met', $criteria[4]);
        self::assertStringContainsString('reason 4', $criteria[4]);
        self::assertMatchesRegularExpression('/^\d+\|claude_code:opus:-$/', (string) $detail->attr('data-cell'));
        self::assertStringNotContainsString('<b>answer</b>', (string) $this->client->getResponse()->getContent(), 'output is escaped');
    }

    public function testPageShowsProgressAndPollingHookWhileRunning(): void
    {
        [$user, $run] = $this->versionWithResults();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/1');

        self::assertSelectorTextContains('.run-progress', '3 / 4 done');
        self::assertCount(1, $crawler->filter('p.visually-hidden[role="status"][data-run-progress-status]'));
        self::assertCount(1, $crawler->filter('p[data-run-progress-notice][hidden]'));
        self::assertCount(0, $crawler->filter('[data-progress-url] [data-run-progress-notice], [data-progress-url] [data-run-progress-status]'));
        $root = $crawler->filter('[data-progress-url]');
        self::assertSame('/runs/'.$run->getUuid().'/versions/1/progress', $root->attr('data-progress-url'));
        self::assertSame('running', $root->attr('data-status'));
    }

    public function testFinishedVersionHasNoRunningStatusOnThePage(): void
    {
        [$user, $run] = $this->versionWithResults();
        $this->finishAllResults();
        $this->entityManager->clear();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/1');

        self::assertSame('completed', $crawler->filter('[data-progress-url]')->attr('data-status'));
    }

    public function testProgressEndpointReturnsStatusCountsAndTheSameMatrix(): void
    {
        [$user, $run] = $this->versionWithResults();
        $this->client->loginUser($user);

        $page = $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/1');
        $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/1/progress');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('running', $data['status']);
        self::assertSame(3, $data['done']);
        self::assertSame(4, $data['total']);
        self::assertIsString($data['html']);
        self::assertSame(
            trim($page->filter('[data-progress-url]')->html()),
            trim($data['html']),
            'the page and the endpoint render the same partial',
        );
    }

    public function testProgressEndpointFailsStaleResultsBeforeReportingTheStatus(): void
    {
        [$user, $run] = $this->versionWithResults();
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE run_result SET status = 'running', status_changed_at = ? WHERE status = 'running'",
            [(new \DateTimeImmutable('-31 minutes'))->format('Y-m-d H:i:s')],
        );
        $this->entityManager->clear();
        $this->client->loginUser($user);

        $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/1/progress');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('completed_with_errors', $data['status']);
        self::assertSame(4, $data['done']);
    }

    public function testProgressEndpointIsForbiddenWithoutProjectAccess(): void
    {
        [, $run] = $this->versionWithResults();
        $this->client->loginUser($this->persistUser('mallory'));

        $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/1/progress');

        self::assertResponseStatusCodeSame(403);
    }

    public function testProgressEndpointRejectsAnonymousAndUnknownVersion(): void
    {
        [$user, $run] = $this->versionWithResults();

        $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/1/progress');
        self::assertResponseRedirects();

        $this->client->loginUser($user);
        $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/9/progress');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRowsAreSortedByTitleThenId(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user, testcases: [$this->persistTestcase('b second', $project), $this->persistTestcase('Zulu', $project), $this->persistTestcase('A first', $project), $this->persistTestcase('A first', $project)], configured: true);
        $this->startRun($run);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/1');

        self::assertSame(
            ['A first (version 1)', 'A first (version 1)', 'b second (version 1)', 'Zulu (version 1)'],
            $crawler->filter('tbody th[scope="row"]')->each(static fn (Crawler $th): string => trim($th->text())),
        );
    }

    public function testEmptyOutputGetsNoDetailsAndMissingScoreShowsADash(): void
    {
        [$user, $run] = $this->versionWithResults();
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement("UPDATE run_result SET output = '' WHERE output = 'ok'");
        $connection->executeStatement('UPDATE run_result SET criteria = NULL WHERE score = 100');
        $connection->executeStatement('UPDATE run_result SET score = NULL WHERE score = 100');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/1');

        self::assertResponseIsSuccessful();
        $cell = $crawler->filter('table.result-matrix tbody tr')->eq(0)->filter('td')->eq(1);
        self::assertSame('– 0.0 s · – in / – out', trim($cell->text()), 'score dash, then the usage line');
        self::assertCount(0, $cell->filter('details'));
        self::assertCount(1, $crawler->filter('table.result-matrix details'));
    }

    public function testVersionWithoutResultsExplainsItWasNotExecuted(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user, testcases: [$this->persistTestcase('Greeting', $project)], configured: true);
        $this->startRun($run);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement('DELETE FROM run_result');
        $this->entityManager->clear();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.run-progress', 'This version was not executed.');
        self::assertCount(0, $crawler->filter('table.result-matrix'));
        self::assertSame('not_executed', $crawler->filter('[data-progress-url]')->attr('data-status'));
    }

    /**
     * Two testcases; Claude Code and Hugging Face (effort) run, OpenAI is skipped. Greeting x Claude Code is done (4 of 5
     * criteria, 80 %), Greeting x Hugging Face failed with a timeout, Farewell x Claude Code is running, Farewell x Hugging Face done.
     *
     * @return array{0: \App\Entity\User, 1: Run}
     */
    private function versionWithResults(): array
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user, testcases: [$this->persistTestcase('Greeting', $project), $this->persistTestcase('Farewell', $project)]);
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $run->addConfiguration(new LlmConfiguration(Provider::HuggingFace, 'org/model', Effort::High));
        $run->addConfiguration(new LlmConfiguration(Provider::OpenAi, 'gpt-5.4'));
        $version = $run->start(static fn (Provider $provider): bool => Provider::OpenAi !== $provider, new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $this->entityManager->flush();

        $cells = $this->cells($version);
        $criteria = array_map(static fn (int $i): array => ['criterion' => 'Mentions <the> topic', 'met' => 4 !== $i, 'reason' => 'reason '.$i], range(0, 4));

        self::assertTrue($cells['Greeting|claude_code']->markRunning());
        self::assertTrue($cells['Greeting|claude_code']->markJudging('<b>answer</b> & more', 10, 20, 300));
        self::assertTrue($cells['Greeting|claude_code']->markDone($criteria));
        self::assertTrue($cells['Greeting|huggingface']->markRunning());
        self::assertTrue($cells['Greeting|huggingface']->markFailed('timeout', \App\Run\RunResultStatus::Running));
        self::assertTrue($cells['Farewell|claude_code']->markRunning());
        self::assertTrue($cells['Farewell|huggingface']->markRunning());
        self::assertTrue($cells['Farewell|huggingface']->markJudging('ok', null, null, 5));
        self::assertTrue($cells['Farewell|huggingface']->markDone([['criterion' => 'c', 'met' => true, 'reason' => 'r']]));
        $this->entityManager->flush();
        $this->entityManager->clear();

        return [$user, $run];
    }

    public function testFirstVersionShowsAHintInsteadOfCharts(): void
    {
        [$user, $run] = $this->versionWithResults();
        $this->client->loginUser($user);

        $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/1');

        self::assertSelectorTextContains('main', 'There is no previous version to compare against.');
        self::assertSelectorNotExists('.comparison');
    }

    public function testLaterVersionShowsChartsAgainstThePreviousVersion(): void
    {
        [$user, $run] = $this->twoVersions(true);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/2');

        self::assertResponseIsSuccessful();
        $root = $crawler->filter('.comparison[data-comparison]');
        self::assertCount(1, $root);
        self::assertSame('Version 1', $root->attr('data-from-label'));
        self::assertSame('Version 2', $root->attr('data-to-label'));
        self::assertSame(['score', 'durationMs', 'inputTokens', 'outputTokens', 'failureRate'], $root->filter('canvas')->each(static fn (Crawler $canvas): ?string => $canvas->attr('data-metric')));
        /** @var list<array{label: string, score: array<string, float>, durationMs: array<string, float>}> $rows */
        $rows = json_decode((string) $root->attr('data-comparison'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['Claude Code / opus / no effort', 'Total'], array_column($rows, 'label'));
        self::assertEquals(['from' => 50, 'to' => 100], $rows[1]['score']);
        self::assertEquals(['from' => 1000, 'to' => 500], $rows[1]['durationMs']);
        self::assertCount(1, $crawler->filter('.comparison table.visually-hidden[data-comparison-table]'));
        self::assertSelectorNotExists('main p.help-text:contains("no results that can be compared")');
    }

    public function testVersionWithoutCommonCellsExplainsThereIsNothingToCompare(): void
    {
        [$user, $run] = $this->twoVersions(false);
        $this->client->loginUser($user);

        $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/2');

        self::assertSelectorTextContains('main', 'Version 1 has no results that can be compared with this version.');
        self::assertSelectorNotExists('.comparison');
    }

    public function testProgressEndpointReportsTheComparisonAndNoneForTheFirstVersion(): void
    {
        [$user, $run] = $this->twoVersions(true);
        $this->client->loginUser($user);

        $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/2/progress');
        /** @var array{comparison: list<array{label: string}>} $second */
        $second = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/1/progress');
        /** @var array{comparison: list<array{label: string}>} $first */
        $first = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(['Claude Code / opus / no effort', 'Total'], array_column($second['comparison'], 'label'));
        self::assertSame([], $first['comparison']);
    }

    /**
     * Version 1 scores 50 in 1 s, version 2 scores 100 in 0.5 s. With $sameTestcase false version 2 runs another testcase.
     *
     * @return array{\App\Entity\User, Run}
     */
    private function twoVersions(bool $sameTestcase): array
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $testcase = $this->persistTestcase('Greeting', $project);
        $run = $this->persistRun($project, creator: $user, testcases: [$testcase], configured: true);
        $first = $this->startRun($run);
        $this->rate($first, 50, 1000);
        if (!$sameTestcase) {
            $run->setTestcases([$this->persistTestcase('Farewell', $project)]);
        }
        $second = $this->startRun($run);
        $this->rate($second, 100, 500);
        $this->entityManager->flush();
        $this->entityManager->clear();

        return [$user, $run];
    }

    private function rate(RunVersion $version, int $score, int $durationMs): void
    {
        foreach ($version->getResults() as $result) {
            $result->markRunning();
            $result->markJudging('answer', 10, 20, $durationMs);
            $result->markDone(array_map(static fn (int $i): array => ['criterion' => 'c'.$i, 'met' => $score > $i, 'reason' => 'r'], range(0, 99)));
        }
    }

    /** @return array<string, \App\Entity\RunResult> keyed by "testcase title|provider" */
    private function cells(RunVersion $version): array
    {
        $cells = [];
        foreach ($version->getResults() as $result) {
            $cells[$result->getTestcaseVersion()->getTitle().'|'.$result->getConfiguration()->provider->value] = $result;
        }

        return $cells;
    }
}
