<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Connection;
use App\Model\SystemMetric;
use PDO;

class SystemMetricRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function findLatest(): ?SystemMetric
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->query("SELECT * FROM system_metrics ORDER BY created_at DESC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? SystemMetric::fromArray($row) : null;
    }

    public function save(SystemMetric $metric): SystemMetric
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare("
            INSERT INTO system_metrics (
                cpu_usage_percent, memory_used_bytes, memory_total_bytes,
                disk_used_bytes, disk_total_bytes, load_1m, load_5m, load_15m,
                uptime_seconds, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
        ");
        $stmt->execute([
            $metric->cpuUsagePercent,
            $metric->memoryUsedBytes,
            $metric->memoryTotalBytes,
            $metric->diskUsedBytes,
            $metric->diskTotalBytes,
            $metric->load1m,
            $metric->load5m,
            $metric->load15m,
            $metric->uptimeSeconds,
        ]);
        $metric->id = (int) $pdo->lastInsertId();
        return $metric;
    }
}
