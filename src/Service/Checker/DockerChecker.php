<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Enum\ServiceStatus;
use App\Model\Monitor;
use Symfony\Component\Process\Process;

class DockerChecker implements CheckerInterface
{
    public function check(Monitor $monitor): CheckResultDto
    {
        $container = $monitor->target;
        $start = microtime(true);

        $process = new Process(['docker', 'inspect', '--format', '{{.State.Status}}|{{.State.Health.Status}}', $container]);
        $process->setTimeout((float) $monitor->timeoutSeconds);
        $process->run();
        $elapsed = (microtime(true) - $start) * 1000.0;

        if (!$process->isSuccessful()) {
            return new CheckResultDto(
                status: ServiceStatus::OFFLINE,
                responseTimeMs: round($elapsed, 2),
                statusCode: null,
                errorMessage: "Docker container not found or Docker daemon unavailable: " . trim($process->getErrorOutput())
            );
        }

        $out = trim($process->getOutput());
        [$state, $health] = array_pad(explode('|', $out, 2), 2, '');

        if ($state === 'running') {
            if ($health === 'unhealthy') {
                return new CheckResultDto(
                    status: ServiceStatus::DEGRADED,
                    responseTimeMs: round($elapsed, 2),
                    statusCode: 200,
                    errorMessage: "Container is running but Docker health check reported unhealthy"
                );
            }

            return new CheckResultDto(
                status: ServiceStatus::ONLINE,
                responseTimeMs: round($elapsed, 2),
                statusCode: 200,
                errorMessage: null
            );
        }

        return new CheckResultDto(
            status: ServiceStatus::OFFLINE,
            responseTimeMs: round($elapsed, 2),
            statusCode: null,
            errorMessage: "Container is in '{$state}' state"
        );
    }
}
