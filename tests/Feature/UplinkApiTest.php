<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Database\Connection;
use App\DTO\PingResultDto;
use App\DTO\TargetDto;
use App\Enum\UplinkState;
use App\Repository\ProbeLogRepository;
use App\Repository\TargetRepository;
use App\Service\Ping\PingRunnerInterface;
use App\Service\UplinkHealthEvaluator;
use App\Service\UplinkMonitorService;
use PHPUnit\Framework\TestCase;

class UplinkApiTest extends TestCase
{
    private Connection $connection;
    private TargetRepository $targetRepo;
    private ProbeLogRepository $probeRepo;
    private UplinkMonitorService $monitorService;

    protected function setUp(): void
    {
        $this->connection = new Connection(dirname(__DIR__, 2), ':memory:');
        $this->targetRepo = new TargetRepository($this->connection);
        $this->probeRepo = new ProbeLogRepository($this->connection);

        // Mock ping runner
        $mockPingRunner = new class implements PingRunnerInterface {
            public function ping(TargetDto $target, int $count = 3, int $timeoutSeconds = 2): PingResultDto
            {
                return new PingResultDto(
                    targetId: $target->id,
                    host: $target->host,
                    state: UplinkState::EXCELLENT,
                    packetsSent: $count,
                    packetsReceived: $count,
                    packetLossPercent: 0.0,
                    minLatencyMs: 10.0,
                    avgLatencyMs: 12.5,
                    maxLatencyMs: 15.0,
                    jitterMs: 1.2,
                    latencies: [10.0, 12.5, 15.0],
                    errorMessage: null,
                    probedAt: new \DateTimeImmutable()
                );
            }
        };

        $this->monitorService = new UplinkMonitorService(
            $this->targetRepo,
            $this->probeRepo,
            $mockPingRunner,
            new UplinkHealthEvaluator()
        );
    }

    public function testProbeAllAndHistoryFlow(): void
    {
        $summary = $this->monitorService->probeAll(3);

        $this->assertEquals(UplinkState::EXCELLENT, $summary->state);
        $this->assertGreaterThan(0, $summary->activeTargetsCount);
        $this->assertEquals(12.5, $summary->avgLatencyMs);

        $history = $this->monitorService->getHistoryWithSparklines(10);
        $this->assertArrayHasKey('targets', $history);
        $this->assertArrayHasKey('cloudflare-primary', $history['targets']);
        $this->assertNotEmpty($history['targets']['cloudflare-primary']['sparkline']);
    }
}
