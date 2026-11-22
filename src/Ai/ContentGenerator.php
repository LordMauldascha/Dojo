<?php

declare(strict_types=1);

namespace App\Ai;

use App\Form\Model\SkillData;
use App\Form\Model\TestcaseData;
use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/** Drafts a skill or testcase from a description with the model of the AI helper. The result is never saved here. */
final readonly class ContentGenerator
{
    private const SKILL_PROMPT = <<<'TXT'
        You write skills (SKILL.md instructions for an LLM) from the description of the user.
        Answer with one JSON object and nothing else: {"title": string, "description": string, "content": string}.
        - title: at most 60 characters, only letters, digits, spaces and hyphens.
        - description: one or two sentences saying what the skill does and when to use it.
        - content: the instructions in Markdown, without front matter.
        Write in English, unless the description asks for another language.
        Use synthetic data only, never personal data.
        TXT;

    private const TESTCASE_PROMPT = <<<'TXT'
        You write testcases for LLM skills from the description of the user.
        Answer with one JSON object and nothing else: {"title": string, "description": string, "prompt": string, "outputCriteria": string}.
        - title: at most 100 characters.
        - description: one or two sentences.
        - prompt: the input that is sent to the model under test.
        - outputCriteria: one checkable criterion per line, no numbering, no bullets.
        Write in English, unless the description asks for another language.
        Use synthetic data only, never personal data.
        TXT;

    public function __construct(
        private LlmGateway $gateway,
        private AiHelperSettingStore $settings,
        private ModelCatalog $catalog,
        private DecoderInterface $decoder,
    ) {
    }

    /**
     * @throws NoAiHelperException
     * @throws InvalidGeneratedContent
     */
    public function skill(string $description): SkillData
    {
        $fields = $this->generate(self::SKILL_PROMPT, $description, ['title', 'description', 'content']);

        $data = new SkillData();
        $data->title = $fields['title'];
        $data->description = $fields['description'];
        $data->content = $fields['content'];

        return $data;
    }

    /**
     * @throws NoAiHelperException
     * @throws InvalidGeneratedContent
     */
    public function testcase(string $description): TestcaseData
    {
        $fields = $this->generate(self::TESTCASE_PROMPT, $description, ['title', 'description', 'prompt', 'outputCriteria']);

        $data = new TestcaseData();
        $data->title = $fields['title'];
        $data->description = $fields['description'];
        $data->prompt = $fields['prompt'];
        $data->outputCriteria = $fields['outputCriteria'];

        return $data;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private function generate(string $systemPrompt, string $description, array $keys): array
    {
        $helper = $this->settings->get();
        if (null === $helper || !$this->catalog->isAvailable($helper->provider) || !\in_array($helper->model, $this->catalog->models($helper->provider), true)) {
            throw new NoAiHelperException();
        }

        $json = trim($this->gateway->ask($helper, $systemPrompt, $description)->text);
        if (1 === preg_match('/\A```[a-z]*\R(.*?)\R?```\z/si', $json, $fenced)) {
            $json = trim($fenced[1]);
        }

        try {
            $decoded = $this->decoder->decode($json, 'json');
        } catch (ExceptionInterface) {
            throw new InvalidGeneratedContent();
        }

        $fields = [];
        foreach ($keys as $key) {
            if (!\is_array($decoded) || !\is_string($decoded[$key] ?? null)) {
                throw new InvalidGeneratedContent();
            }
            $fields[$key] = $decoded[$key];
        }

        return $fields;
    }
}
