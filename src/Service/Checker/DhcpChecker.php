<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Entity\Check;
use App\Entity\CheckExecution;
use App\Enum\UplinkState;
use Symfony\Component\Uid\Ulid;

class DhcpChecker implements CheckerInterface
{
    public function supports(string $type): bool
    {
        return $type === 'dhcp';
    }

    public function run(Check $check): CheckExecution
    {
        $results = $this->runMulti([$check]);
        return $results[$check->id];
    }

    public function runMulti(array $checks): array
    {
        $executions = [];
        $now = gmdate('Y-m-d H:i:s');

        foreach ($checks as $check) {
            $executions[$check->id] = $this->executeSingle($check, $now);
        }

        return $executions;
    }

    private function executeSingle(Check $check, string $timestamp): CheckExecution
    {
        $config = $check->config;
        $server = $config['server'] ?? null;
        if (!$server) {
            return $this->createErrorExecution($check, 'DHCP server IP is required', $timestamp, 'full');
        }

        $port = (int)($config['port'] ?? 67);
        $timeout = max(1, min(30, (int)($config['timeout'] ?? 5)));
        $clientMac = $config['client_mac'] ?? null;
        $expectedServerId = $config['expected_server_id'] ?? null;

        $macBytes = $this->parseMacAddress($clientMac);
        $xidBytes = random_bytes(4);
        $xidHex = bin2hex($xidBytes);
        
        $packet = $this->buildDiscoverPacket($macBytes, $xidBytes);
        
        $startTime = microtime(true);
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $permissionError = false;
        $mode = 'full';
        $offerReceived = false;
        $offeredIp = null;
        $serverId = null;
        $errorMessage = null;
        $expectedMatch = null;
        $state = UplinkState::UNKNOWN;

        if ($socket) {
            socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeout, 'usec' => 0]);
            
            // Try to bind to port 68 (DHCP Client). This often requires root.
            if (!@socket_bind($socket, '0.0.0.0', 68)) {
                $permissionError = true;
                $mode = 'fallback';
            }
        } else {
            $permissionError = true;
            $mode = 'fallback';
        }

        if (!$permissionError) {
            // Send to the specified server, or broadcast if 255.255.255.255
            @socket_sendto($socket, $packet, strlen($packet), 0, $server, $port);
            
            $buf = '';
            $from = '';
            $portFrom = 0;
            
            $bytes = @socket_recvfrom($socket, $buf, 4096, 0, $from, $portFrom);
            if ($bytes !== false && $bytes >= 240) {
                // Parse DHCP OFFER
                $op = ord($buf[0]);
                if ($op === 2) { // BOOTREPLY
                    $offerReceived = true;
                    $offeredIp = inet_ntop(substr($buf, 16, 4));
                    
                    // Parse options (after magic cookie)
                    $options = substr($buf, 240);
                    $idx = 0;
                    while ($idx < strlen($options)) {
                        $optCode = ord($options[$idx]);
                        if ($optCode === 255) {
                            break; // End
                        }
                        if ($optCode === 0) {
                            $idx++;
                            continue; // Pad
                        }
                        
                        $optLen = ord($options[$idx + 1]);
                        if ($optCode === 54 && $optLen === 4) { // Server Identifier
                            $serverId = inet_ntop(substr($options, $idx + 2, 4));
                        }
                        $idx += 2 + $optLen;
                    }
                }
            }
            if ($socket) {
                socket_close($socket);
            }
            
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            
            if (!$offerReceived) {
                $state = UplinkState::OFFLINE;
                $errorMessage = 'No DHCP OFFER received within timeout';
            } else {
                if ($expectedServerId) {
                    $expectedMatch = ($serverId === $expectedServerId);
                    if (!$expectedMatch) {
                        $state = UplinkState::DEGRADED;
                        $errorMessage = sprintf('Expected server ID %s, got %s', $expectedServerId, $serverId ?? 'none');
                    }
                }
                
                if ($state === UplinkState::UNKNOWN) {
                    $state = UplinkState::EXCELLENT;
                }
            }
        } else {
            if ($socket) {
                socket_close($socket);
            }
            // Fallback: simple TCP check on the UDP port doesn't work, so just check UDP reachability or general connectivity
            // We use fsockopen UDP
            $fp = @fsockopen("udp://$server", $port, $errno, $errstr, $timeout);
            if ($fp) {
                stream_set_timeout($fp, $timeout);
                fwrite($fp, "\x00"); // Send dummy byte
                fclose($fp);
                $state = UplinkState::GOOD;
                $errorMessage = 'Fallback mode: Network reachability assumed (full check requires elevated privileges)';
            } else {
                $state = UplinkState::OFFLINE;
                $errorMessage = sprintf('Fallback mode: Socket error: %s (%d)', $errstr, $errno);
            }
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        }

        $resultData = [
            'server' => $server,
            'port' => $port,
            'duration_ms' => $durationMs,
            'mode' => $mode,
            'offer_received' => $offerReceived,
            'offered_ip' => $offeredIp,
            'server_id' => $serverId,
            'xid' => $xidHex,
            'permission_error' => $permissionError,
            'expected_match' => $expectedMatch,
        ];

        return new CheckExecution(
            id: Ulid::generate(),
            checkId: $check->id,
            status: $state,
            durationMs: $durationMs,
            resultData: $resultData,
            errorMessage: $errorMessage,
            executedAt: $timestamp
        );
    }

    public function buildDiscoverPacket(string $macBytes, string $xidBytes): string
    {
        // Fixed parts:
        // op (1) = BOOTREQUEST
        // htype (1) = Ethernet
        // hlen (1) = 6
        // hops (1) = 0
        $header = "\x01\x01\x06\x00";
        
        // xid (4)
        $header .= $xidBytes;
        
        // secs (2), flags (2) = 0x8000 (Broadcast)
        $header .= "\x00\x00\x80\x00";
        
        // ciaddr, yiaddr, siaddr, giaddr (4x4 = 16 bytes of 0s)
        $header .= str_repeat("\x00", 16);
        
        // chaddr (16 bytes, but only 6 are used for MAC, rest 0s)
        $header .= str_pad($macBytes, 16, "\x00");
        
        // sname (64 bytes of 0s)
        $header .= str_repeat("\x00", 64);
        
        // file (128 bytes of 0s)
        $header .= str_repeat("\x00", 128);
        
        // Magic cookie (4 bytes): 99.130.83.99 (0x63825363)
        $header .= "\x63\x82\x53\x63";
        
        // Options:
        // 53: DHCP Message Type = 1 (DISCOVER)
        $options = "\x35\x01\x01";
        
        // 61: Client Identifier = 1 (Ethernet) + MAC
        $options .= "\x3d\x07\x01" . $macBytes;
        
        // 55: Parameter Request List = 1 (Subnet), 3 (Router), 6 (DNS), 15 (Domain Name)
        $options .= "\x37\x04\x01\x03\x06\x0f";
        
        // 255: End
        $options .= "\xff";
        
        return $header . $options;
    }

    public function parseMacAddress(?string $macStr): string
    {
        if ($macStr) {
            $cleaned = preg_replace('/[^0-9a-fA-F]/', '', $macStr);
            if (strlen($cleaned) === 12) {
                return hex2bin($cleaned);
            }
        }
        return random_bytes(6);
    }

    private function createErrorExecution(Check $check, string $message, string $timestamp, string $mode): CheckExecution
    {
        return new CheckExecution(
            id: Ulid::generate(),
            checkId: $check->id,
            status: UplinkState::OFFLINE,
            durationMs: 0.0,
            resultData: ['mode' => $mode],
            errorMessage: $message,
            executedAt: $timestamp
        );
    }
}
