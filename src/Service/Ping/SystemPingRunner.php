<?php

declare(strict_types=1);

namespace App\Service\Ping;

use App\DTO\PingResultDto;
use App\DTO\TargetDto;
use App\Enum\UplinkState;
use Symfony\Component\Process\Process;

class SystemPingRunner implements PingRunnerInterface
{
    public function ping(TargetDto $target, int $count = 2, int $timeoutSeconds = 2): PingResultDto
    {
        $results = $this->pingMulti([$target], $count, $timeoutSeconds);
        return $results[$target->id];
    }

    /**
     * @param TargetDto[] $targets
     * @return array<string, PingResultDto>
     */
    public function pingMulti(array $targets, int $count = 2, int $timeoutSeconds = 2): array
    {
        $processes = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Launch all ping commands asynchronously in parallel
        foreach ($targets as $target) {
            $host = $target->host;

            if (PHP_OS_FAMILY === 'Windows') {
                $command = ['ping', '-n', (string)$count, '-w', (string)($timeoutSeconds * 1000), $host];
            } elseif (PHP_OS_FAMILY === 'Darwin') {
                $command = ['ping', '-c', (string)$count, '-t', (string)$timeoutSeconds, $host];
            } else {
                // Linux (send packet every 0.2s for ultra-fast checks)
                $command = ['ping', '-c', (string)$count, '-W', (string)$timeoutSeconds, '-i', '0.2', $host];
            }

            $process = new Process($command);
            $process->setTimeout((float)($count * $timeoutSeconds) + 2);
            $process->start();

            $processes[$target->id] = [
                'target' => $target,
                'process' => $process,
            ];
        }

        // Wait for all parallel processes to complete
        $results = [];
        $maxWaitMs = (($count * $timeoutSeconds) + 3) * 1000;
        $startMs = (int)(microtime(true) * 1000);

        while (!empty($processes)) {
            foreach ($processes as $targetId => $data) {
                /** @var Process $proc */
                $proc = $data['process'];
                /** @var TargetDto $target */
                $target = $data['target'];

                if (!$proc->isRunning()) {
                    $output = $proc->getOutput() . "\n" . $proc->getErrorOutput();
                    $results[$targetId] = $this->parseOutput($target, $output, $count, $now);
                    unset($processes[$targetId]);
                }
            }

            if ((int)(microtime(true) * 1000) - $startMs > $maxWaitMs) {
                // Terminate any hanging processes
                foreach ($processes as $targetId => $data) {
                    $data['process']->stop();
                    $results[$targetId] = $this->parseOutput($data['target'], 'Timeout', $count, $now);
                }
                break;
            }

            usleep(10000); // 10ms poll
        }

        return $results;
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

        // Parse individual packet latencies (e.g. "time=12.345 ms")
        if (preg_match_all('/time[=<]([0-9.]+)\s*ms/i', $output, $timeMatches)) {
            foreach ($timeMatches[1] as $timeStr) {
                $latencies[] = (float)$timeStr;
            }
        }

        // Parse packet stats
        if (preg_match('/(\d+)\s+packets\s+transmitted,\s+(\d+)\s+(?:packets\s+)?received(?:,\s+\+?\d+\s+errors)?,\s+([0-9.]+)%\s+packet\s+loss/i', $output, $lossMatches)) {
            $packetsSent = (int)$lossMatches[1];
            $packetsReceived = (int)$lossMatches[2];
            $lossPercent = (float)$lossMatches[3];
        } elseif (count($latencies) > 0) {
            $packetsReceived = count($latencies);
            $lossPercent = (($packetsSent - $packetsReceived) / max(1, $packetsSent)) * 100.0;
        }

        // Parse round-trip RTT statistics
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

        // Classify uplink state with realistic homelab internet baseline:
        // EXCELLENT: Loss 0%, Avg RTT <= 80ms
        // GOOD: Loss <= 10%, Avg RTT <= 150ms
        // DEGRADED: Loss > 10% or Avg RTT > 150ms
        // OFFLINE: Loss >= 90% or 0 packets received
        $state = UplinkState::OFFLINE;
        $error = null;

        if ($packetsReceived === 0 || $lossPercent >= 90.0) {
            $state = UplinkState::OFFLINE;
            $error = 'Host unreachable or 100% packet loss';
        } elseif ($lossPercent > 15.0 || ($avgLatency !== null && $avgLatency > 200.0)) {
            $state = UplinkState::DEGRADED;
            if ($lossPercent > 15.0) {
                $error = sprintf('%.1f%% packet loss detected', $lossPercent);
            } else {
                $error = sprintf('High latency: %.1f ms', $avgLatency);
            }
        } elseif ($avgLatency !== null && $avgLatency <= 80.0 && $lossPercent === 0.0) {
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
