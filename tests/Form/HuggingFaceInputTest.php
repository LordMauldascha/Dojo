<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\Model\HuggingFaceModelData;
use App\Form\Model\HuggingFaceSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class HuggingFaceInputTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function modelIds(): iterable
    {
        yield 'organization and name' => ['acme/chat-8b', true];
        yield 'dots and underscores' => ['meta.llama/Llama_3.1-8B', true];
        yield 'legacy id without organization' => ['gpt2', true];
        yield 'dot inside a segment' => ['acme/v1..2', true];
        yield 'trailing newline' => ["gpt2\n", false];
        yield 'trailing newline after the name' => ["acme/chat\n", false];
        yield 'parent segment' => ['x/..', false];
        yield 'parent segment first' => ['../x', false];
        yield 'current directory segment' => ['x/.', false];
        yield 'only dots' => ['..', false];
        yield 'three segments' => ['a/b/c', false];
        yield 'space' => ['a b', false];
    }

    #[DataProvider('modelIds')]
    public function testModelIdValidation(string $id, bool $valid): void
    {
        $violations = $this->validator()->validate(new HuggingFaceModelData($id));

        self::assertSame($valid, 0 === \count($violations), $id);
    }

    public function testSearchTaskAndProviderRejectATrailingNewline(): void
    {
        self::assertCount(0, $this->validator()->validate(new HuggingFaceSearch(task: 'text-generation', provider: 'together')));
        self::assertGreaterThan(0, \count($this->validator()->validate(new HuggingFaceSearch(task: "text-generation\n"))));
        self::assertGreaterThan(0, \count($this->validator()->validate(new HuggingFaceSearch(provider: "together\n"))));
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
