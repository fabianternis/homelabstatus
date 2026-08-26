<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Enum\ServiceStatus;
use App\Model\Monitor;
use Symfony\Component\Process\Process;

class PingChecker implements CheckerInterface
{
    public function check(Monitor $monitor): CheckResultDto
    {
        $host = $monitor->target;
        $timeout = max(1, $monitor->timeoutSeconds);

        if (PHP_OS_FAMILY === 'Windows') {
            $command = ['ping', '-n', '1', '-w', (string) ($timeout * 1000), $host];
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $command = ['ping', '-c', '1', '-t', (string) $timeout, $host];
        } else {
            $command = ['ping', '-c', '1', '-W', (string) $timeout, $host];
        }

        $start = microtime(true);
        $process = new Process($command);
        $process->setTimeout((float) $timeout + 1);
        $process->run();
        $elapsed = (microtime(true) - $start) * 1000.0;

        if (!$process->isSuccessful()) {
            return new CheckResultDto(
                status: ServiceStatus::OFFLINE,
                responseTimeMs: round($elapsed, 2),
                statusCode: null,
                errorMessage: "Ping to {$host} timed out or host unreachable"
            );
        }

        $output = $process->getOutput();
        if (preg_match('/time[=<]([0-9.]+)\s*ms/', $output, $matches)) {
            $elapsed = (float) $matches[1];
        }

        $status = ($elapsed > 500) ? ServiceStatus::DEGRADED : ServiceStatus::ONLINE;

        return new CheckResultDto(
            status: $status,
            responseTimeMs: round($elapsed, 2),
            statusCode: 200,
            errorMessage: null
        );
    }
}
