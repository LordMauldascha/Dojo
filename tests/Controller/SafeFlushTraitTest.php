<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\SafeFlushDouble;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use PHPUnit\Framework\TestCase;

final class SafeFlushTraitTest extends TestCase
{
    public function testFlushesAndRunsTheFollowUpInsideOneTransaction(): void
    {
        $order = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static function (callable $callback) use (&$order): mixed {
            $order[] = 'begin';

            return $callback();
        });
        $entityManager->expects(self::once())->method('flush')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'flush';
        });
        $controller = new SafeFlushDouble();

        $result = $controller->run($entityManager, static function () use (&$order): void {
            $order[] = 'after';
        });

        self::assertTrue($result);
        self::assertSame(['begin', 'flush', 'after'], $order);
        self::assertSame([], $controller->flashes);
    }

    public function testAConcurrentUniqueViolationBecomesAnErrorFlash(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willThrowException(new UniqueConstraintViolationException($this->driverException(), null));
        $controller = new SafeFlushDouble();

        self::assertFalse($controller->run($entityManager));
        self::assertSame('error', $controller->flashes[0][0]);
        self::assertStringContainsString('Reload the page', $controller->flashes[0][1]);
    }

    public function testAForeignKeyViolationIsHandledTheSameWay(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willThrowException(new ForeignKeyConstraintViolationException($this->driverException(), null));
        $controller = new SafeFlushDouble();

        self::assertFalse($controller->run($entityManager));
        self::assertCount(1, $controller->flashes);
    }

    public function testAStaleOptimisticLockBecomesAnErrorFlash(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willThrowException(OptimisticLockException::lockFailed(new \stdClass()));
        $controller = new SafeFlushDouble();

        self::assertFalse($controller->run($entityManager));
        self::assertSame('error', $controller->flashes[0][0]);
        self::assertStringContainsString('Reload the page', $controller->flashes[0][1]);
    }

    public function testOtherErrorsAreNotSwallowed(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willThrowException(new \RuntimeException('database down'));

        $this->expectException(\RuntimeException::class);
        (new SafeFlushDouble())->run($entityManager);
    }

    private function driverException(): DriverException
    {
        return new class('violation') extends \Exception implements DriverException {
            public function getSQLState(): ?string
            {
                return null;
            }
        };
    }
}
