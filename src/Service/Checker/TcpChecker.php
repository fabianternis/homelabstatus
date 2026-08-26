<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Enum\ServiceStatus;
use App\Model\Monitor;

class TcpChecker implements CheckerInterface
{
    public function check(Monitor $monitor): CheckResultDto
    {
        $target = $monitor->target;
        $port = 80;

        if (str_contains($target, ':')) {
            [$host, $portStr] = explode(':', $target, 2);
            $port = (int) $portStr;
        } else {
            $host = $target;
        }

        $start = microtime(true);
        $errno = 0;
        $errstr = '';

        $fp = @fsockopen($host, $port, $errno, $errstr, (float) $monitor->timeoutSeconds);
        $elapsed = (microtime(true) - $start) * 1000.0;

        if (!$fp) {
            return new CheckResultDto(
                status: ServiceStatus::OFFLINE,
                responseTimeMs: round($elapsed, 2),
                statusCode: null,
                errorMessage: $errstr ?: "TCP connection to {$host}:{$port} failed"
            );
        }

        fclose($fp);

        $status = ($elapsed > 2000) ? ServiceStatus::DEGRADED : ServiceStatus::ONLINE;

        return new CheckResultDto(
            status: $status,
            responseTimeMs: round($elapsed, 2),
            statusCode: 200,
            errorMessage: null
        );
    }
}
