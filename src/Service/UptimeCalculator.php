<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\Connection;
use App\Enum\ServiceStatus;
use App\Repository\MonitorRepository;
use PDO;

class UptimeCalculator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MonitorRepository $monitorRepository
    ) {}

    /**
     * Calculate uptime % for a specific monitor over various timeframes (24h, 7d, 30d, 90d)
     * @return array{24h: float, 7d: float, 30d: float, 90d: float, avg_latency: float}
     */
    public function calculateUptime(int $monitorId): array
    {
        $pdo = $this->connection->getPdo();

        $getStats = function(int $days) use ($pdo, $monitorId): array {
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'online' THEN 1 WHEN status = 'degraded' THEN 0.5 ELSE 0 END) as successful,
                    AVG(response_time_ms) as avg_latency
                FROM check_results
                WHERE monitor_id = ?
                  AND checked_at >= datetime('now', '-' || ? || ' days')
            ");
            $stmt->execute([$monitorId, $days]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $total = (int) ($row['total'] ?? 0);
            if ($total === 0) {
                return ['uptime' => 100.0, 'latency' => 0.0];
            }

            $successful = (float) ($row['successful'] ?? 0);
            $uptime = round(($successful / $total) * 100.0, 2);
            $latency = round((float) ($row['avg_latency'] ?? 0.0), 1);

            return ['uptime' => $uptime, 'latency' => $latency];
        };

        $stats24h = $getStats(1);
        $stats7d = $getStats(7);
        $stats30d = $getStats(30);
        $stats90d = $getStats(90);

        return [
            '24h' => $stats24h['uptime'],
            '7d' => $stats7d['uptime'],
            '30d' => $stats30d['uptime'],
            '90d' => $stats90d['uptime'],
            'avg_latency' => $stats24h['latency'] ?: $stats7d['latency'],
        ];
    }

    /**
     * Get day-by-day status bars for the last N days (default 30 days)
     * @return array<int, array{date: string, status: string, uptime: float, avg_latency: float}>
     */
    public function getDailyHistory(int $monitorId, int $days = 30): array
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare("
            SELECT 
                strftime('%Y-%m-%d', checked_at) as day_date,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) as online_count,
                SUM(CASE WHEN status = 'degraded' THEN 1 ELSE 0 END) as degraded_count,
                SUM(CASE WHEN status = 'offline' THEN 1 ELSE 0 END) as offline_count,
                AVG(response_time_ms) as avg_latency
            FROM check_results
            WHERE monitor_id = ?
              AND checked_at >= datetime('now', '-' || ? || ' days')
            GROUP BY day_date
            ORDER BY day_date ASC
        ");
        $stmt->execute([$monitorId, $days]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $historyByDate = [];
        foreach ($rows as $row) {
            $total = (int) $row['total'];
            $online = (int) $row['online_count'];
            $degraded = (int) $row['degraded_count'];
            $offline = (int) $row['offline_count'];

            $status = 'online';
            if ($offline > 0 && ($offline / $total) > 0.1) {
                $status = 'offline';
            } elseif ($degraded > 0 || $offline > 0) {
                $status = 'degraded';
            }

            $uptime = $total > 0 ? round((($online + ($degraded * 0.5)) / $total) * 100, 1) : 100.0;

            $historyByDate[$row['day_date']] = [
                'date' => $row['day_date'],
                'status' => $status,
                'uptime' => $uptime,
                'avg_latency' => round((float) $row['avg_latency'], 1),
            ];
        }

        // Fill missing days with 100% / online
        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = gmdate('Y-m-d', strtotime("-{$i} days"));
            if (isset($historyByDate[$date])) {
                $daily[] = $historyByDate[$date];
            } else {
                $daily[] = [
                    'date' => $date,
                    'status' => 'online',
                    'uptime' => 100.0,
                    'avg_latency' => 0.0,
                ];
            }
        }

        return $daily;
    }

    /**
     * Overall system status aggregation
     * @return array{status: ServiceStatus, online_count: int, total_count: int, counts: array<string, int>, system_uptime: float}
     */
    public function getGlobalSystemSummary(): array
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->query("
            SELECT status, COUNT(*) as count
            FROM monitors
            WHERE is_active = 1
            GROUP BY status
        ");

        $counts = [
            'online' => 0,
            'degraded' => 0,
            'offline' => 0,
            'maintenance' => 0,
            'pending' => 0,
        ];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $counts[$row['status']] = (int) $row['count'];
        }

        $total = array_sum($counts);
        $online = $counts['online'];

        $overallStatus = ServiceStatus::ONLINE;
        if ($counts['offline'] > 0) {
            $overallStatus = ServiceStatus::OFFLINE;
        } elseif ($counts['degraded'] > 0) {
            $overallStatus = ServiceStatus::DEGRADED;
        } elseif ($counts['maintenance'] > 0 && $counts['online'] === 0) {
            $overallStatus = ServiceStatus::MAINTENANCE;
        }

        $systemUptime = $total > 0 ? round(($online / $total) * 100.0, 1) : 100.0;

        return [
            'status' => $overallStatus,
            'online_count' => $online,
            'total_count' => $total,
            'counts' => $counts,
            'system_uptime' => $systemUptime,
        ];
    }
}
