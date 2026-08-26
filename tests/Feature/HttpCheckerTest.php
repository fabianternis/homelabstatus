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
use App\Service\Checker\CheckManager;
use App\Service\Checker\DatabaseChecker;
use App\Service\Checker\DhcpChecker;
use App\Service\Checker\DnsChecker;
use App\Service\Checker\HttpChecker;
use App\Service\Checker\S3BucketChecker;
use App\Service\Checker\SshChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

class HttpCheckerTest extends TestCase
{
    private Connection $connection;
    private CheckRepository $checkRepo;
    private CheckExecutionRepository $executionRepo;
    private HttpChecker $httpChecker;
    private CheckManager $checkManager;

    protected function setUp(): void
    {
        $this->connection = new Connection(dirname(__DIR__, 2), ':memory:');
        $this->checkRepo = new CheckRepository($this->connection);
        $this->executionRepo = new CheckExecutionRepository($this->connection);
        $auditRepo = new AuditLogRepository($this->connection);
        $auditLogger = new AuditLogger($auditRepo);

        $this->httpChecker = new HttpChecker();
        $this->checkManager = new CheckManager(
            $this->checkRepo,
            $this->executionRepo,
            $auditLogger,
            $this->httpChecker,
            new S3BucketChecker(),
            new DatabaseChecker(),
            new SshChecker(),
            new DnsChecker(),
            new DhcpChecker()
        );
    }


    public function testSupportsHttpTypes(): void
    {
        $this->assertTrue($this->httpChecker->supports('http'));
        $this->assertTrue($this->httpChecker->supports('https'));
        $this->assertTrue($this->httpChecker->supports('web'));
        $this->assertFalse($this->httpChecker->supports('uplink'));
        $this->assertFalse($this->httpChecker->supports('dns'));
    }

    public function testRunHttpCheckMock(): void
    {
        $check = new Check(
            id: Ulid::generate(),
            name: 'Cloudflare HTTPS',
            slug: 'cloudflare-https',
            type: 'http',
            groupName: 'Web Endpoints',
            config: [
                'url' => 'https://1.1.1.1',
                'expected_status' => 200,
                'timeout' => 5,
                'check_ssl' => true,
            ]
        );

        $this->checkRepo->save($check);
        $execution = $this->httpChecker->run($check);

        $this->assertEquals($check->id, $execution->checkId);
        $this->assertNotNull($execution->resultData);
        $this->assertArrayHasKey('http_code', $execution->resultData);
        $this->assertArrayHasKey('total_time_ms', $execution->resultData);
        $this->assertArrayHasKey('ttfb_ms', $execution->resultData);
    }
}
