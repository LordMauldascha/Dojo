<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Group;
use App\Entity\Project;
use App\Form\FormData;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class FormDataTest extends TestCase
{
    public function testKeepsOnlyTheRequestedEntitiesOfACollectionOrArray(): void
    {
        $a = new Project();
        $b = new Project();
        $group = new Group();

        self::assertSame([$a, $b], FormData::entities(Project::class, new ArrayCollection([5 => $a, 'x' => $group, 7 => $b])));
        self::assertSame([$a], FormData::entities(Project::class, [$a, 'text', null]));
    }

    public function testNothingSelectedGivesAnEmptyList(): void
    {
        self::assertSame([], FormData::entities(Project::class, null));
        self::assertSame([], FormData::entities(Project::class, 'not iterable'));
    }
}
