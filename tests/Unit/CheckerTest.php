<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\CheckType;
use App\Enum\ServiceStatus;
use App\Model\Monitor;
use App\Service\Checker\TcpChecker;
use PHPUnit\Framework\TestCase;

class CheckerTest extends TestCase
{
    public function testTcpCheckerFailsOnClosedPort(): void
    {
        $checker = new TcpChecker();
        $monitor = new Monitor(
            id: 1,
            name: 'Closed Port',
            type: CheckType::TCP,
            target: '127.0.0.1:59999',
            timeoutSeconds: 1
        );

        $result = $checker->check($monitor);
        $this->assertEquals(ServiceStatus::OFFLINE, $result->status);
        $this->assertNotNull($result->errorMessage);
    }
}
