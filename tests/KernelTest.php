<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    public function testProductionRefusesToBootWithoutASecret(): void
    {
        $previous = $_SERVER['APP_SECRET'] ?? null;
        $_SERVER['APP_SECRET'] = '';

        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('APP_SECRET');

            (new Kernel('prod', false))->boot();
        } finally {
            if (null === $previous) {
                unset($_SERVER['APP_SECRET']);
            } else {
                $_SERVER['APP_SECRET'] = $previous;
            }
        }
    }
}
