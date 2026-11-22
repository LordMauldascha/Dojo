<?php

declare(strict_types=1);

namespace App\Run\Judge;

use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class JudgeAnswerParser
{
    public function __construct(
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * @return list<CriterionVerdict> ordered by index
     *
     * @throws InvalidJudgeAnswer unless there is exactly one valid verdict for each of the indices 1..$criteriaCount
     */
    public function parse(string $answer, int $criteriaCount): array
    {
        $json = trim($answer);
        if (1 === preg_match('/\A```[a-z]*\R(.*?)\R?```\z/si', $json, $fenced)) {
            $json = trim($fenced[1]);
        }

        try {
            $verdicts = $this->serializer->deserialize($json, CriterionVerdict::class.'[]', 'json');
        } catch (ExceptionInterface|\TypeError) {
            throw new InvalidJudgeAnswer();
        }

        if (!array_is_list($verdicts)) {
            throw new InvalidJudgeAnswer();
        }

        $indices = [];
        foreach ($verdicts as $verdict) {
            if (\count($this->validator->validate($verdict)) > 0) {
                throw new InvalidJudgeAnswer();
            }
            $indices[] = $verdict->index;
        }
        sort($indices);
        if (range(1, $criteriaCount) !== $indices) {
            throw new InvalidJudgeAnswer();
        }
        usort($verdicts, static fn (CriterionVerdict $a, CriterionVerdict $b): int => $a->index <=> $b->index);

        return $verdicts;
    }
}
