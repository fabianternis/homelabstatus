<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Database\Connection;
use App\Enum\ServiceStatus;
use App\Model\Monitor;
use App\Repository\CheckResultRepository;
use App\Repository\IncidentRepository;
use App\Repository\MonitorRepository;
use App\Repository\SystemMetricRepository;
use App\Service\BadgeGenerator;
use App\Service\SystemMetricsCollector;
use App\Service\UptimeCalculator;
use PHPUnit\Framework\TestCase;

class DashboardTest extends TestCase
{
    private Connection $connection;
    private MonitorRepository $monitorRepository;
    private UptimeCalculator $uptimeCalculator;

    protected function setUp(): void
    {
        $this->connection = new Connection(dirname(__DIR__, 2), ':memory:');
        $this->monitorRepository = new MonitorRepository($this->connection);
        $this->uptimeCalculator = new UptimeCalculator($this->connection, $this->monitorRepository);
    }

    public function testUptimeCalculationAndSummary(): void
    {
        $monitor = new Monitor(
            id: null,
            name: 'Home Gateway',
            groupName: 'Networking',
            target: '192.168.1.1'
        );
        $saved = $this->monitorRepository->save($monitor);
        $this->assertNotNull($saved->id);

        $checkRepo = new CheckResultRepository($this->connection);
        $checkRepo->log($saved->id, ServiceStatus::ONLINE, 12.5, 200);
        $checkRepo->log($saved->id, ServiceStatus::ONLINE, 15.0, 200);

        $uptimes = $this->uptimeCalculator->calculateUptime($saved->id);
        $this->assertEquals(100.0, $uptimes['24h']);
        $this->assertEquals(13.8, $uptimes['avg_latency']);

        $summary = $this->uptimeCalculator->getGlobalSystemSummary();
        $this->assertEquals(1, $summary['total_count']);
    }
}
