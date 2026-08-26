<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Checker\DhcpChecker;
use PHPUnit\Framework\TestCase;

class DhcpCheckerTest extends TestCase
{
    public function testSupportsDhcpType(): void
    {
        $checker = new DhcpChecker();
        
        $this->assertTrue($checker->supports('dhcp'));
        $this->assertFalse($checker->supports('dns'));
        $this->assertFalse($checker->supports('http'));
    }

    public function testBuildDiscoverPacket(): void
    {
        $checker = new DhcpChecker();
        $macBytes = "\x01\x02\x03\x04\x05\x06";
        $xidBytes = "\x11\x22\x33\x44";
        
        $packet = $checker->buildDiscoverPacket($macBytes, $xidBytes);
        
        // Packet starts with 0x01 (BOOTREQUEST), 0x01 (Ethernet), 0x06 (hlen), 0x00 (hops)
        $this->assertStringStartsWith("\x01\x01\x06\x00", $packet);
        
        // Check XID
        $this->assertSame($xidBytes, substr($packet, 4, 4));
        
        // Check Magic Cookie at offset 236
        $magicCookie = substr($packet, 236, 4);
        $this->assertSame("\x63\x82\x53\x63", $magicCookie);
        
        // Check DHCP Message Type Option (53)
        $options = substr($packet, 240);
        // Should start with option 53, len 1, value 1
        $this->assertStringStartsWith("\x35\x01\x01", $options);
    }

    public function testParseMacAddress(): void
    {
        $checker = new DhcpChecker();
        
        // Valid MAC with colons
        $parsed = $checker->parseMacAddress('01:02:03:04:05:06');
        $this->assertSame("\x01\x02\x03\x04\x05\x06", $parsed);
        
        // Valid MAC with hyphens
        $parsed2 = $checker->parseMacAddress('AA-BB-CC-DD-EE-FF');
        $this->assertSame("\xaa\xbb\xcc\xdd\xee\xff", strtolower($parsed2));
        
        // Invalid MAC falls back to random 6 bytes
        $random = $checker->parseMacAddress('invalid');
        $this->assertSame(6, strlen($random));
    }
}
