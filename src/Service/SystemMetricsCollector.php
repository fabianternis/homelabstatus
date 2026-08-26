<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\SystemMetric;
use App\Repository\SystemMetricRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class SystemMetricsCollector
{
    public function __construct(
        private readonly SystemMetricRepository $repository,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir
    ) {}

    public function collect(): SystemMetric
    {
        // 1. CPU Load
        $loads = sys_getloadavg() ?: [0.0, 0.0, 0.0];
        $load1m = (float) ($loads[0] ?? 0.0);
        $load5m = (float) ($loads[1] ?? 0.0);
        $load15m = (float) ($loads[2] ?? 0.0);

        // Core count detection
        $cpuCount = 1;
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            $cpuCount = substr_count($cpuinfo, 'processor');
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $cpuCount = (int) shell_exec('sysctl -n hw.ncpu 2>/dev/null') ?: 1;
        }
        $cpuPercent = min(100.0, max(0.0, ($load1m / max(1, $cpuCount)) * 100.0));

        // 2. Memory
        $memTotal = 0;
        $memFree = 0;
        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $memTotal = (int) $m[1] * 1024;
            }
            if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $memFree = (int) $m[1] * 1024;
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $memTotal = (int) shell_exec('sysctl -n hw.memsize 2>/dev/null') ?: (8 * 1024 * 1024 * 1024);
            $vmStat = shell_exec('vm_stat 2>/dev/null') ?: '';
            if (preg_match('/Pages free:\s+(\d+)/', $vmStat, $m)) {
                $memFree = (int) $m[1] * 4096;
            } else {
                $memFree = (int) ($memTotal * 0.35);
            }
        }
        $memUsed = max(0, $memTotal - $memFree);

        // 3. Disk Space
        $diskTotal = (int) @disk_total_space($this->projectDir);
        $diskFree = (int) @disk_free_space($this->projectDir);
        $diskUsed = max(0, $diskTotal - $diskFree);

        // 4. Uptime
        $uptime = 0;
        if (is_readable('/proc/uptime')) {
            $uptimeStr = file_get_contents('/proc/uptime');
            $uptime = (int) floatval(explode(' ', $uptimeStr)[0]);
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $boottime = shell_exec('sysctl -n kern.boottime 2>/dev/null') ?: '';
            if (preg_match('/sec = (\d+)/', $boottime, $m)) {
                $uptime = time() - (int) $m[1];
            }
        }

        return new SystemMetric(
            id: null,
            cpuUsagePercent: round($cpuPercent, 1),
            memoryUsedBytes: $memUsed,
            memoryTotalBytes: $memTotal,
            diskUsedBytes: $diskUsed,
            diskTotalBytes: $diskTotal,
            load1m: round($load1m, 2),
            load5m: round($load5m, 2),
            load15m: round($load15m, 2),
            uptimeSeconds: max(0, $uptime)
        );
    }

    public function collectAndSave(): SystemMetric
    {
        $metric = $this->collect();
        return $this->repository->save($metric);
    }
}
