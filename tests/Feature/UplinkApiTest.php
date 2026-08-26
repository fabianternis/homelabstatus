<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Database\Connection;
use App\DTO\PingResultDto;
use App\DTO\TargetDto;
use App\Enum\UplinkState;
use App\Repository\AuditLogRepository;
use App\Repository\CheckExecutionRepository;
use App\Repository\CheckRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Ping\PingRunnerInterface;
use App\Service\UplinkHealthEvaluator;
use App\Service\UplinkMonitorService;
use PHPUnit\Framework\TestCase;

class UplinkApiTest extends TestCase
{
    private Connection $connection;
    private CheckRepository $checkRepo;
    private CheckExecutionRepository $executionRepo;
    private UplinkMonitorService $monitorService;

    protected function setUp(): void
    {
        $this->connection = new Connection(dirname(__DIR__, 2), ':memory:');
        $this->checkRepo = new CheckRepository($this->connection);
        $this->executionRepo = new CheckExecutionRepository($this->connection);
        $auditRepo = new AuditLogRepository($this->connection);
        $auditLogger = new AuditLogger($auditRepo);

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

            public function pingMulti(array $targets, int $count = 2, int $timeoutSeconds = 2): array
            {
                $results = [];
                foreach ($targets as $target) {
                    $results[$target->id] = $this->ping($target, $count, $timeoutSeconds);
                }
                return $results;
            }
        };

        $this->monitorService = new UplinkMonitorService(
            $this->checkRepo,
            $this->executionRepo,
            $mockPingRunner,
            new UplinkHealthEvaluator(),
            $auditLogger
        );
    }

    public function testProbeAllAndDatabaseHistoryFlow(): void
    {
        $summary = $this->monitorService->probeAll(2);

        $this->assertEquals(UplinkState::EXCELLENT, $summary->state);
        $this->assertGreaterThan(0, $summary->activeTargetsCount);
        $this->assertEquals(12.5, $summary->avgLatencyMs);

        // Verify database records
        $checks = $this->checkRepo->findByType('uplink');
        $this->assertNotEmpty($checks);

        $firstCheck = $checks[0];
        $this->assertEquals(UplinkState::EXCELLENT, $firstCheck->status);
        $this->assertNotNull($firstCheck->lastMetrics);
        $this->assertNotNull($firstCheck->id);
        $this->assertEquals(26, strlen($firstCheck->id)); // ULID length is 26 chars

        $history = $this->monitorService->getHistoryWithSparklines(10);
        $this->assertArrayHasKey('targets', $history);
        $this->assertArrayHasKey($firstCheck->id, $history['targets']);
        $this->assertNotEmpty($history['targets'][$firstCheck->id]['history']);
    }

    public function testSoftDeleteCheck(): void
    {
        $checks = $this->checkRepo->findByType('uplink');
        $check = $checks[0];

        $this->checkRepo->softDelete($check->id);

        $afterDelete = $this->checkRepo->findByType('uplink');
        $this->assertCount(count($checks) - 1, $afterDelete);

        $trashed = $this->checkRepo->findById($check->id, withTrashed: true);
        $this->assertNotNull($trashed);
        $this->assertTrue($trashed->isTrashed());

        // Restore
        $this->checkRepo->restore($check->id);
        $restored = $this->checkRepo->findByType('uplink');
        $this->assertCount(count($checks), $restored);
    }
}
