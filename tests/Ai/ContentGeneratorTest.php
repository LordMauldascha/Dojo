<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\AiHelperSettingStore;
use App\Ai\ContentGenerator;
use App\Ai\Credential;
use App\Ai\InvalidGeneratedContent;
use App\Ai\LlmConfiguration;
use App\Ai\LlmGateway;
use App\Ai\ModelCatalog;
use App\Ai\NoAiHelperException;
use App\Ai\PlatformProvider;
use App\Ai\Provider;
use App\Entity\AiHelperSetting;
use App\Entity\ProviderApiKey;
use App\Security\ApiKeyStore;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Encoder\DecoderInterface;

final class ContentGeneratorTest extends KernelTestCase
{
    private AiHelperSettingStore $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM '.AiHelperSetting::class)->execute();
        $entityManager->createQuery('DELETE FROM '.ProviderApiKey::class)->execute();
        $this->settings = self::getContainer()->get(AiHelperSettingStore::class);
    }

    private function useHelper(): void
    {
        self::getContainer()->get(ApiKeyStore::class)->save(Credential::Anthropic, 'synthetic-key-0000000000');
        $this->settings->save(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
    }

    private function generator(?ScriptedPlatform $platform): ContentGenerator
    {
        $provider = new class($platform) implements PlatformProvider {
            public function __construct(private readonly ?PlatformInterface $platform)
            {
            }

            public function forCredential(Credential $credential): PlatformInterface
            {
                return $this->platform ?? throw new \LogicException('not called');
            }
        };

        return new ContentGenerator(new LlmGateway($provider), $this->settings, self::getContainer()->get(ModelCatalog::class), self::getContainer()->get(DecoderInterface::class));
    }

    public function testSkillIsParsedFromJson(): void
    {
        $this->useHelper();
        $platform = ScriptedPlatform::answering('{"title": "Code review", "description": "Reviews code", "content": "# Steps"}');

        $data = $this->generator($platform)->skill('Reviews code');

        self::assertSame(['Code review', 'Reviews code', '# Steps'], [$data->title, $data->description, $data->content]);
        self::assertSame('opus', $platform->model);
    }

    public function testFencedJsonIsAccepted(): void
    {
        $this->useHelper();
        $platform = ScriptedPlatform::answering("```json\n{\"title\": \"T\", \"description\": \"D\", \"prompt\": \"P\", \"outputCriteria\": \"A\\nB\"}\n```");

        $data = $this->generator($platform)->testcase('Anything');

        self::assertSame(['T', 'D', 'P', "A\nB"], [$data->title, $data->description, $data->prompt, $data->outputCriteria]);
    }

    #[DataProvider('unusableAnswers')]
    public function testUnusableAnswerIsRejectedWithoutQuotingIt(string $answer): void
    {
        $this->useHelper();

        try {
            $this->generator(ScriptedPlatform::answering($answer))->skill('Anything');
            self::fail('expected InvalidGeneratedContent');
        } catch (InvalidGeneratedContent $e) {
            self::assertStringNotContainsString('secret', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unusableAnswers(): iterable
    {
        yield 'not json' => ['secret text'];
        yield 'missing field' => ['{"title": "T", "description": "D"}'];
        yield 'not a string' => ['{"title": "T", "description": "D", "content": ["secret"]}'];
        yield 'a list' => ['["secret"]'];
    }

    public function testNoHelperSetMeansNoProviderCall(): void
    {
        $platform = ScriptedPlatform::answering('{}');

        $this->expectException(NoAiHelperException::class);
        try {
            $this->generator($platform)->skill('Anything');
        } finally {
            self::assertSame(0, $platform->invocations);
        }
    }

    public function testHelperWithoutKeyIsTreatedAsNotSet(): void
    {
        $this->settings->save(new LlmConfiguration(Provider::ClaudeCode, 'opus'));

        $this->expectException(NoAiHelperException::class);
        $this->generator(null)->testcase('Anything');
    }
}
