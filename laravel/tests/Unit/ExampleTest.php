<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_basic_arithmetic_is_stable(): void
    {
        $this->assertSame(2, 1 + 1);
    }
}
