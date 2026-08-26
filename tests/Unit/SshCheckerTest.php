<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Check;
use App\Enum\UplinkState;
use App\Service\Checker\SshChecker;
use PHPUnit\Framework\TestCase;

class SshCheckerTest extends TestCase
{
    public function testSupports(): void
    {
        $checker = new SshChecker();

        $this->assertTrue($checker->supports('ssh'));
        $this->assertTrue($checker->supports('sftp'));
        $this->assertFalse($checker->supports('http'));
        $this->assertFalse($checker->supports('tcp'));
    }

    public function testBannerParsingSuccess(): void
    {
        $checker = new SshChecker();
        
        $reflection = new \ReflectionClass(SshChecker::class);
        $method = $reflection->getMethod('parseBanner');

        $result = $method->invoke($checker, 'SSH-2.0-OpenSSH_8.9p1 Ubuntu-3ubuntu0.6');
        
        $this->assertEquals('SSH-2.0-OpenSSH_8.9p1', $result['ssh_version_string']);
        $this->assertEquals('2.0', $result['protocol_version']);
        $this->assertEquals('OpenSSH_8.9p1', $result['software_version']);
    }

    public function testBannerParsingFailure(): void
    {
        $checker = new SshChecker();
        
        $reflection = new \ReflectionClass(SshChecker::class);
        $method = $reflection->getMethod('parseBanner');

        $result = $method->invoke($checker, 'Invalid Banner');
        
        $this->assertNull($result['ssh_version_string']);
        $this->assertNull($result['protocol_version']);
        $this->assertNull($result['software_version']);
    }
}
