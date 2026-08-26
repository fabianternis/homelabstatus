<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Checker\DnsChecker;
use PHPUnit\Framework\TestCase;

class DnsCheckerTest extends TestCase
{
    public function testSupportsAllTypes(): void
    {
        $checker = new DnsChecker();
        
        $this->assertTrue($checker->supports('dns'));
        $this->assertTrue($checker->supports('dns_server'));
        $this->assertTrue($checker->supports('resolver'));
        
        $this->assertFalse($checker->supports('http'));
        $this->assertFalse($checker->supports('dhcp'));
    }

    public function testBuildQuery(): void
    {
        $checker = new DnsChecker();
        $txId = 12345;
        $domain = 'example.com';
        $typeCode = 1; // A record
        
        $packet = $checker->buildQuery($txId, $domain, $typeCode);
        
        // Header is 12 bytes
        $header = substr($packet, 0, 12);
        $unpacked = unpack('nid/nflags/nqdcount/nancount/nnscount/narcount', $header);
        
        $this->assertSame(12345, $unpacked['id']);
        $this->assertSame(0x0100, $unpacked['flags']); // Recursion desired
        $this->assertSame(1, $unpacked['qdcount']);
        
        // Rest is query
        $query = substr($packet, 12);
        
        // Expected domain label encoding: "\x07example\x03com\x00"
        $expectedQname = "\x07example\x03com\x00";
        $expectedQtype = pack('nn', 1, 1); // type 1, class 1
        
        $this->assertSame($expectedQname . $expectedQtype, $query);
    }
}
