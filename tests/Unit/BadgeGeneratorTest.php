<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\ServiceStatus;
use App\Service\BadgeGenerator;
use PHPUnit\Framework\TestCase;

class BadgeGeneratorTest extends TestCase
{
    private BadgeGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new BadgeGenerator();
    }

    public function testGenerateBasicBadge(): void
    {
        $svg = $this->generator->generate('Gateway', 'Operational', '#10b981');
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('Gateway', $svg);
        $this->assertStringContainsString('Operational', $svg);
        $this->assertStringContainsString('#10b981', $svg);
    }

    public function testForStatusOnline(): void
    {
        $svg = $this->generator->forStatus('DNS', ServiceStatus::ONLINE);
        $this->assertStringContainsString('DNS', $svg);
        $this->assertStringContainsString(ServiceStatus::ONLINE->label(), $svg);
    }

    public function testForUptime(): void
    {
        $svg = $this->generator->forUptime('uptime', 99.98);
        $this->assertStringContainsString('99.98%', $svg);
    }
}
