<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Tui\SparklineGenerator;
use PHPUnit\Framework\TestCase;

class SparklineGeneratorTest extends TestCase
{
    public function testGenerateSparklineFromValues(): void
    {
        $values = [10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0, 80.0];
        $sparkline = SparklineGenerator::generate($values, 10);
        $this->assertEquals(8, mb_strlen($sparkline));
        $this->assertStringContainsString(' ', $sparkline);
        $this->assertStringContainsString('█', $sparkline);
    }

    public function testHandlesEmptyValues(): void
    {
        $sparkline = SparklineGenerator::generate([], 5);
        $this->assertEquals('─────', $sparkline);
    }

    public function testHandlesFlatValues(): void
    {
        $sparkline = SparklineGenerator::generate([25.0, 25.0, 25.0], 3);
        $this->assertEquals(3, mb_strlen($sparkline));
    }
}
