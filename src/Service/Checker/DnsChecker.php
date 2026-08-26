<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Entity\Check;
use App\Entity\CheckExecution;
use App\Enum\UplinkState;
use Symfony\Component\Uid\Ulid;

class DnsChecker implements CheckerInterface
{
    private const RECORD_TYPES = [
        'A' => 1,
        'NS' => 2,
        'CNAME' => 5,
        'SOA' => 6,
        'PTR' => 12,
        'MX' => 15,
        'TXT' => 16,
        'AAAA' => 28,
    ];

    private const RCODES = [
        0 => 'NOERROR',
        1 => 'FORMERR',
        2 => 'SERVFAIL',
        3 => 'NXDOMAIN',
        4 => 'NOTIMP',
        5 => 'REFUSED',
        6 => 'YXDOMAIN',
        7 => 'YXRRSET',
        8 => 'NXRRSET',
        9 => 'NOTAUTH',
        10 => 'NOTZONE',
    ];

    public function supports(string $type): bool
    {
        return in_array($type, ['dns', 'dns_server', 'resolver'], true);
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
            return $this->createErrorExecution($check, 'DNS server IP is required', $timestamp);
        }

        $port = (int)($config['port'] ?? 53);
        $protocol = strtolower($config['protocol'] ?? 'udp');
        $timeout = max(1, min(30, (int)($config['timeout'] ?? 3)));
        $queryName = $config['query_name'] ?? 'cloudflare.com';
        $queryType = strtoupper($config['query_type'] ?? 'A');
        $expectedAnswer = $config['expected_answer'] ?? null;

        $typeCode = self::RECORD_TYPES[$queryType] ?? 1;

        $txId = random_int(1, 65535);
        $packet = $this->buildQuery($txId, $queryName, $typeCode);

        if ($protocol === 'tcp') {
            $packet = pack('n', strlen($packet)) . $packet;
        }

        $uri = sprintf('%s://%s', $protocol === 'tcp' ? 'tcp' : 'udp', $server);

        $startTime = microtime(true);
        $fp = @fsockopen($uri, $port, $errno, $errstr, $timeout);

        if (!$fp) {
            return $this->createErrorExecution($check, sprintf('Socket error: %s (%d)', $errstr, $errno), $timestamp);
        }

        stream_set_timeout($fp, $timeout);
        fwrite($fp, $packet);

        if ($protocol === 'tcp') {
            $lenBytes = fread($fp, 2);
            if (strlen($lenBytes) === 2) {
                $expectedLen = unpack('n', $lenBytes)[1];
                $response = '';
                while (!feof($fp) && strlen($response) < $expectedLen) {
                    $chunk = fread($fp, $expectedLen - strlen($response));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $response .= $chunk;
                }
            } else {
                $response = '';
            }
        } else {
            $response = fread($fp, 4096);
        }

        $info = stream_get_meta_data($fp);
        fclose($fp);

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        if ($info['timed_out']) {
            return $this->createErrorExecution($check, 'Connection timed out', $timestamp, $durationMs);
        }

        if (strlen($response) < 12) {
            return $this->createErrorExecution($check, 'Invalid or empty DNS response', $timestamp, $durationMs);
        }

        $header = unpack('nid/nflags/nqdcount/nancount/nnscount/narcount', substr($response, 0, 12));
        $flags = $header['flags'];
        $rcode = $flags & 0x000F;
        $ancount = $header['ancount'];

        $rcodeName = self::RCODES[$rcode] ?? "UNKNOWN({$rcode})";
        
        $answers = [];
        if ($ancount > 0) {
            $answers = $this->parseAnswers($response, $header['qdcount'], $ancount);
        }

        $expectedMatch = null;
        $state = UplinkState::UNKNOWN;
        $errorMessage = null;

        if ($rcode !== 0) {
            $state = UplinkState::DEGRADED;
            $errorMessage = sprintf('DNS Server returned error: %s', $rcodeName);
        } elseif ($ancount === 0) {
            $state = UplinkState::DEGRADED;
            $errorMessage = 'DNS response contains no answers';
        } else {
            if ($expectedAnswer !== null && $expectedAnswer !== '') {
                $expectedMatch = in_array((string)$expectedAnswer, $answers, true);
                if (!$expectedMatch) {
                    $state = UplinkState::DEGRADED;
                    $errorMessage = sprintf('Expected answer "%s" not found in results', $expectedAnswer);
                }
            }

            if ($state === UplinkState::UNKNOWN) {
                if ($durationMs <= 20.0) {
                    $state = UplinkState::EXCELLENT;
                } elseif ($durationMs <= 100.0) {
                    $state = UplinkState::GOOD;
                } elseif ($durationMs <= 300.0) {
                    $state = UplinkState::DEGRADED;
                    $errorMessage = sprintf('Slow response time: %.0f ms', $durationMs);
                } else {
                    $state = UplinkState::DEGRADED;
                    $errorMessage = sprintf('Very slow response time: %.0f ms', $durationMs);
                }
            }
        }

        $resultData = [
            'server' => $server,
            'port' => $port,
            'protocol' => $protocol,
            'query_name' => $queryName,
            'query_type' => $queryType,
            'duration_ms' => $durationMs,
            'rcode' => $rcode,
            'rcode_name' => $rcodeName,
            'answer_count' => $ancount,
            'answers' => $answers,
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

    public function buildQuery(int $txId, string $domain, int $typeCode): string
    {
        $flags = 0x0100; // Recursion Desired
        $header = pack('n6', $txId, $flags, 1, 0, 0, 0);

        $qname = '';
        foreach (explode('.', $domain) as $label) {
            $len = strlen($label);
            if ($len > 0) {
                $qname .= chr($len) . $label;
            }
        }
        $qname .= "\x00";
        $question = $qname . pack('nn', $typeCode, 1);

        return $header . $question;
    }

    private function parseAnswers(string $response, int $qdcount, int $ancount): array
    {
        $offset = 12;

        // Skip questions
        for ($i = 0; $i < $qdcount; $i++) {
            while ($offset < strlen($response) && ord($response[$offset]) !== 0) {
                if ((ord($response[$offset]) & 0xC0) === 0xC0) {
                    $offset += 2;
                    break 2;
                }
                $offset += ord($response[$offset]) + 1;
            }
            if ((ord($response[$offset]) & 0xC0) !== 0xC0) {
                $offset += 1;
            }
            $offset += 4; // qtype, qclass
        }

        $answers = [];
        for ($i = 0; $i < $ancount; $i++) {
            if ($offset >= strlen($response)) {
                break;
            }

            // Name
            if ((ord($response[$offset]) & 0xC0) === 0xC0) {
                $offset += 2;
            } else {
                while ($offset < strlen($response) && ord($response[$offset]) !== 0) {
                    $offset += ord($response[$offset]) + 1;
                }
                $offset += 1;
            }

            if ($offset + 10 > strlen($response)) {
                break;
            }

            $rr = unpack('ntype/nclass/Nttl/nrdlength', substr($response, $offset, 10));
            $offset += 10;
            $rdlength = $rr['rdlength'];
            $type = $rr['type'];

            if ($offset + $rdlength > strlen($response)) {
                break;
            }

            $rdata = substr($response, $offset, $rdlength);
            $offset += $rdlength;

            if ($type === 1 && $rdlength === 4) { // A
                $answers[] = inet_ntop($rdata);
            } elseif ($type === 28 && $rdlength === 16) { // AAAA
                $answers[] = inet_ntop($rdata);
            }
        }

        return $answers;
    }

    private function createErrorExecution(Check $check, string $message, string $timestamp, float $durationMs = 0.0): CheckExecution
    {
        return new CheckExecution(
            id: Ulid::generate(),
            checkId: $check->id,
            status: UplinkState::OFFLINE,
            durationMs: $durationMs,
            resultData: [],
            errorMessage: $message,
            executedAt: $timestamp
        );
    }
}
