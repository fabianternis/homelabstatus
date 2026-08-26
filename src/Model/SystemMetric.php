<?php

declare(strict_types=1);

namespace App\Model;

class SystemMetric
{
    public function __construct(
        public ?int $id = null,
        public float $cpuUsagePercent = 0.0,
        public int $memoryUsedBytes = 0,
        public int $memoryTotalBytes = 0,
        public int $diskUsedBytes = 0,
        public int $diskTotalBytes = 0,
        public float $load1m = 0.0,
        public float $load5m = 0.0,
        public float $load15m = 0.0,
        public int $uptimeSeconds = 0,
        public ?string $createdAt = null,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            cpuUsagePercent: (float) ($row['cpu_usage_percent'] ?? 0.0),
            memoryUsedBytes: (int) ($row['memory_used_bytes'] ?? 0),
            memoryTotalBytes: (int) ($row['memory_total_bytes'] ?? 0),
            diskUsedBytes: (int) ($row['disk_used_bytes'] ?? 0),
            diskTotalBytes: (int) ($row['disk_total_bytes'] ?? 0),
            load1m: (float) ($row['load_1m'] ?? 0.0),
            load5m: (float) ($row['load_5m'] ?? 0.0),
            load15m: (float) ($row['load_15m'] ?? 0.0),
            uptimeSeconds: (int) ($row['uptime_seconds'] ?? 0),
            createdAt: $row['created_at'] ?? null,
        );
    }
}
