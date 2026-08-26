<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\DTO\PingResultDto;
use App\Enum\UplinkState;
use App\Service\UplinkHealthEvaluator;
use PHPUnit\Framework\TestCase;

class UplinkHealthEvaluatorTest extends TestCase
{
    private UplinkHealthEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new UplinkHealthEvaluator();
    }

    public function testEvaluateHealthyUplink(): void
    {
        $results = [
            new PingResultDto(
                targetId: 't1',
                host: '1.1.1.1',
                state: UplinkState::EXCELLENT,
                packetsSent: 3,
                packetsReceived: 3,
                packetLossPercent: 0.0,
                minLatencyMs: 12.0,
                avgLatencyMs: 15.0,
                maxLatencyMs: 18.0,
                jitterMs: 2.0,
                latencies: [12.0, 15.0, 18.0],
                errorMessage: null,
                probedAt: new \DateTimeImmutable()
            ),
            new PingResultDto(
                targetId: 't2',
                host: '8.8.8.8',
                state: UplinkState::EXCELLENT,
                packetsSent: 3,
                packetsReceived: 3,
                packetLossPercent: 0.0,
                minLatencyMs: 14.0,
                avgLatencyMs: 16.0,
                maxLatencyMs: 20.0,
                jitterMs: 2.5,
                latencies: [14.0, 16.0, 20.0],
                errorMessage: null,
                probedAt: new \DateTimeImmutable()
            ),
        ];

        $summary = $this->evaluator->evaluate($results);

        $this->assertEquals(UplinkState::EXCELLENT, $summary->state);
        $this->assertGreaterThanOrEqual(95, $summary->healthScore);
        $this->assertEquals(0.0, $summary->avgPacketLossPercent);
        $this->assertEquals(2, $summary->healthyTargetsCount);
    }

    public function testEvaluateDegradedUplinkOnLoss(): void
    {
        $results = [
            new PingResultDto(
                targetId: 't1',
                host: '1.1.1.1',
                state: UplinkState::DEGRADED,
                packetsSent: 3,
                packetsReceived: 2,
                packetLossPercent: 33.3,
                minLatencyMs: 120.0,
                avgLatencyMs: 160.0,
                maxLatencyMs: 200.0,
                jitterMs: 35.0,
                latencies: [120.0, 200.0],
                errorMessage: 'Loss detected',
                probedAt: new \DateTimeImmutable()
            ),
        ];

        $summary = $this->evaluator->evaluate($results);
        $this->assertEquals(UplinkState::DEGRADED, $summary->state);
        $this->assertLessThan(80, $summary->healthScore);
    }
}
