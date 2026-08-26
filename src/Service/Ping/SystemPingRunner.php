<?php

declare(strict_types=1);

namespace App\Service\Ping;

use App\DTO\PingResultDto;
use App\DTO\TargetDto;
use App\Enum\UplinkState;
use Symfony\Component\Process\Process;

class SystemPingRunner implements PingRunnerInterface
{
    public function ping(TargetDto $target, int $count = 3, int $timeoutSeconds = 2): PingResultDto
    {
        $host = $target->host;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if (PHP_OS_FAMILY === 'Windows') {
            $command = ['ping', '-n', (string)$count, '-w', (string)($timeoutSeconds * 1000), $host];
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $command = ['ping', '-c', (string)$count, '-t', (string)$timeoutSeconds, $host];
        } else {
            // Linux / Alpine
            $command = ['ping', '-c', (string)$count, '-W', (string)$timeoutSeconds, '-i', '0.2', $host];
        }

        $process = new Process($command);
        $process->setTimeout((float)($count * $timeoutSeconds) + 2);
        $process->run();

        $output = $process->getOutput() . "\n" . $process->getErrorOutput();
        return $this->parseOutput($target, $output, $count, $now);
    }

    private function parseOutput(TargetDto $target, string $output, int $expectedCount, \DateTimeImmutable $now): PingResultDto
    {
        $packetsSent = $expectedCount;
        $packetsReceived = 0;
        $lossPercent = 100.0;
        $minLatency = null;
        $avgLatency = null;
        $maxLatency = null;
        $jitter = null;
        $latencies = [];

        // Parse individual packet latencies
        if (preg_match_all('/time[=<]([0-9.]+)\s*ms/i', $output, $timeMatches)) {
            foreach ($timeMatches[1] as $timeStr) {
                $latencies[] = (float)$timeStr;
            }
        }

        // Parse packet transmission stats:
        // Linux: "3 packets transmitted, 3 received, 0% packet loss, time 402ms"
        // macOS: "3 packets transmitted, 3 packets received, 0.0% packet loss"
        if (preg_match('/(\d+)\s+packets\s+transmitted,\s+(\d+)\s+(?:packets\s+)?received(?:,\s+\+?\d+\s+errors)?,\s+([0-9.]+)%\s+packet\s+loss/i', $output, $lossMatches)) {
            $packetsSent = (int)$lossMatches[1];
            $packetsReceived = (int)$lossMatches[2];
            $lossPercent = (float)$lossMatches[3];
        } elseif (count($latencies) > 0) {
            $packetsReceived = count($latencies);
            $lossPercent = (($packetsSent - $packetsReceived) / max(1, $packetsSent)) * 100.0;
        }

        // Parse RTT stats summary:
        // macOS: "round-trip min/avg/max/stddev = 12.345/14.567/16.789/1.234 ms"
        // Linux: "rtt min/avg/max/mdev = 12.345/14.567/16.789/1.234 ms"
        if (preg_match('/(?:round-trip|rtt)\s+min\/avg\/max\/(?:stddev|mdev)\s*=\s*([0-9.]+)\/([0-9.]+)\/([0-9.]+)\/([0-9.]+)\s*ms/i', $output, $rttMatches)) {
            $minLatency = (float)$rttMatches[1];
            $avgLatency = (float)$rttMatches[2];
            $maxLatency = (float)$rttMatches[3];
            $jitter = (float)$rttMatches[4];
        } elseif (count($latencies) > 0) {
            $minLatency = min($latencies);
            $maxLatency = max($latencies);
            $avgLatency = array_sum($latencies) / count($latencies);
            if (count($latencies) > 1) {
                $variance = 0.0;
                foreach ($latencies as $l) {
                    $variance += pow($l - $avgLatency, 2);
                }
                $jitter = sqrt($variance / count($latencies));
            } else {
                $jitter = 0.0;
            }
        }

        // Classify uplink state
        $state = UplinkState::OFFLINE;
        $error = null;

        if ($packetsReceived === 0 || $lossPercent >= 100.0) {
            $state = UplinkState::OFFLINE;
            $error = 'Host unreachable or 100% packet loss';
        } elseif ($lossPercent > 0 || ($avgLatency !== null && $avgLatency > 150.0) || ($jitter !== null && $jitter > 30.0)) {
            $state = UplinkState::DEGRADED;
            if ($lossPercent > 0) {
                $error = sprintf('%.1f%% packet loss detected', $lossPercent);
            } elseif ($avgLatency > 150.0) {
                $error = sprintf('High latency: %.1f ms', $avgLatency);
            } else {
                $error = sprintf('High jitter: %.1f ms', $jitter);
            }
        } elseif ($avgLatency !== null && $avgLatency <= 45.0 && ($jitter === null || $jitter <= 10.0)) {
            $state = UplinkState::EXCELLENT;
        } else {
            $state = UplinkState::GOOD;
        }

        return new PingResultDto(
            targetId: $target->id,
            host: $target->host,
            state: $state,
            packetsSent: $packetsSent,
            packetsReceived: $packetsReceived,
            packetLossPercent: $lossPercent,
            minLatencyMs: $minLatency,
            avgLatencyMs: $avgLatency,
            maxLatencyMs: $maxLatency,
            jitterMs: $jitter,
            latencies: $latencies,
            errorMessage: $error,
            probedAt: $now
        );
    }
}
