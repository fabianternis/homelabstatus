<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Database\Connection;
use App\Entity\Check;
use App\Enum\UplinkState;
use App\Repository\AuditLogRepository;
use App\Repository\CheckExecutionRepository;
use App\Repository\CheckRepository;
use App\Service\Audit\AuditLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

class AdminManagementTest extends TestCase
{
    private Connection $connection;
    private CheckRepository $checkRepo;
    private CheckExecutionRepository $executionRepo;
    private AuditLogger $auditLogger;

    protected function setUp(): void
    {
        $this->connection = new Connection(dirname(__DIR__, 2), ':memory:');
        $this->checkRepo = new CheckRepository($this->connection);
        $this->executionRepo = new CheckExecutionRepository($this->connection);
        $auditRepo = new AuditLogRepository($this->connection);
        $this->auditLogger = new AuditLogger($auditRepo);
    }

    public function testCreateToggleAndTrashCheckLifecycle(): void
    {
        $check = new Check(
            id: Ulid::generate(),
            name: 'Local Gateway Probe',
            slug: 'local-gateway-probe',
            type: 'uplink',
            groupName: 'LAN',
            description: 'Gateway ping test',
            isEnabled: true,
            status: UplinkState::UNKNOWN,
            config: ['host' => '192.168.1.1', 'packets' => 2, 'timeout' => 1]
        );

        $this->checkRepo->save($check);
        $this->auditLogger->log('created', "Created check {$check->name}", Check::class, $check->id);

        $found = $this->checkRepo->findById($check->id);
        $this->assertNotNull($found);
        $this->assertEquals('Local Gateway Probe', $found->name);
        $this->assertEquals('192.168.1.1', $found->config['host']);

        // Toggle disabled
        $found->isEnabled = false;
        $this->checkRepo->save($found);
        $this->assertFalse($this->checkRepo->findById($check->id)->isEnabled);

        // Soft delete
        $this->checkRepo->softDelete($check->id);
        $this->assertNull($this->checkRepo->findById($check->id, withTrashed: false));
        $this->assertNotNull($this->checkRepo->findById($check->id, withTrashed: true));

        // Restore
        $this->checkRepo->restore($check->id);
        $this->assertNotNull($this->checkRepo->findById($check->id, withTrashed: false));

        // Force delete
        $this->checkRepo->forceDelete($check->id);
        $this->assertNull($this->checkRepo->findById($check->id, withTrashed: true));
    }

    public function testPaginatedExecutions(): void
    {
        $pagination = $this->executionRepo->paginate(null, 1, 10);
        $this->assertArrayHasKey('total', $pagination);
        $this->assertArrayHasKey('items', $pagination);
        $this->assertArrayHasKey('total_pages', $pagination);
    }
}
