<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Enum\ServiceStatus;
use App\Model\Monitor;
use App\Service\SystemMetricsCollector;

class SystemResourceChecker implements CheckerInterface
{
    public function __construct(
        private readonly SystemMetricsCollector $metricsCollector
    ) {}

    public function check(Monitor $monitor): CheckResultDto
    {
        $start = microtime(true);
        $metric = $this->metricsCollector->collectAndSave();
        $elapsed = (microtime(true) - $start) * 1000.0;

        $target = strtolower($monitor->target); // e.g. "disk", "cpu", "ram", "all"
        $status = ServiceStatus::ONLINE;
        $error = null;

        $memPercent = $metric->memoryTotalBytes > 0
            ? ($metric->memoryUsedBytes / $metric->memoryTotalBytes) * 100.0
            : 0;

        $diskPercent = $metric->diskTotalBytes > 0
            ? ($metric->diskUsedBytes / $metric->diskTotalBytes) * 100.0
            : 0;

        if (str_contains($target, 'disk') && $diskPercent > 90) {
            $status = ($diskPercent > 98) ? ServiceStatus::OFFLINE : ServiceStatus::DEGRADED;
            $error = "Disk usage is at " . round($diskPercent, 1) . "%";
        } elseif (str_contains($target, 'cpu') && $metric->cpuUsagePercent > 90) {
            $status = ServiceStatus::DEGRADED;
            $error = "CPU usage is high: " . round($metric->cpuUsagePercent, 1) . "%";
        } elseif (str_contains($target, 'ram') && $memPercent > 90) {
            $status = ServiceStatus::DEGRADED;
            $error = "Memory usage is high: " . round($memPercent, 1) . "%";
        } elseif ($diskPercent > 95 || $memPercent > 95 || $metric->cpuUsagePercent > 95) {
            $status = ServiceStatus::DEGRADED;
            $error = "System resources heavily utilized";
        }

        return new CheckResultDto(
            status: $status,
            responseTimeMs: round($elapsed, 2),
            statusCode: 200,
            errorMessage: $error,
            metadata: [
                'cpu_percent' => $metric->cpuUsagePercent,
                'mem_percent' => round($memPercent, 1),
                'disk_percent' => round($diskPercent, 1),
                'load_1m' => $metric->load1m,
            ]
        );
    }
}
