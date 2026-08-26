<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Connection;
use App\Entity\CheckExecution;
use PDO;

class CheckExecutionRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function record(CheckExecution $execution): void
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare('
            INSERT INTO check_executions (id, check_id, status, duration_ms, result_data, error_message, executed_at)
            VALUES (:id, :check_id, :status, :duration_ms, :result_data, :error_message, :executed_at)
        ');

        $stmt->execute([
            ':id' => $execution->id,
            ':check_id' => $execution->checkId,
            ':status' => $execution->status->value,
            ':duration_ms' => $execution->durationMs,
            ':result_data' => json_encode($execution->resultData, JSON_UNESCAPED_SLASHES),
            ':error_message' => $execution->errorMessage,
            ':executed_at' => $execution->executedAt,
        ]);
    }

    /**
     * @return CheckExecution[]
     */
    public function getHistoryForCheck(string $checkId, int $limit = 30): array
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare('
            SELECT * FROM check_executions
            WHERE check_id = ?
            ORDER BY executed_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $checkId, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $executions = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $executions[] = CheckExecution::fromArray($row);
        }

        // Return chronological order (oldest -> newest)
        return array_reverse($executions);
    }

    /**
     * @param string[] $checkIds
     * @return array<string, CheckExecution>
     */
    public function getLatestForChecks(array $checkIds): array
    {
        if (empty($checkIds)) {
            return [];
        }

        $pdo = $this->connection->getPdo();
        $placeholders = implode(',', array_fill(0, count($checkIds), '?'));

        $stmt = $pdo->prepare("
            SELECT e.* FROM check_executions e
            INNER JOIN (
                SELECT check_id, MAX(executed_at) as max_time
                FROM check_executions
                WHERE check_id IN ({$placeholders})
                GROUP BY check_id
            ) latest ON e.check_id = latest.check_id AND e.executed_at = latest.max_time
        ");

        $stmt->execute($checkIds);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $exec = CheckExecution::fromArray($row);
            $results[$exec->checkId] = $exec;
        }

        return $results;
    }

    /**
     * Aggregated statistics over recent minutes
     */
    public function getRollingAggregates(int $minutes = 60): array
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_probes,
                AVG(duration_ms) as avg_duration_ms,
                SUM(CASE WHEN status != 'offline' THEN 1 ELSE 0 END) as successful_probes
            FROM check_executions
            WHERE executed_at >= datetime('now', '-' || ? || ' minutes')
        ");
        $stmt->execute([$minutes]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $total = (int)($row['total_probes'] ?? 0);
        $successful = (int)($row['successful_probes'] ?? 0);
        $successRate = $total > 0 ? ($successful / $total) * 100.0 : 100.0;

        return [
            'total_probes' => $total,
            'avg_duration_ms' => round((float)($row['avg_duration_ms'] ?? 0.0), 2),
            'success_rate_percent' => round($successRate, 1),
        ];
    }

    public function prune(int $keepDays = 7): int
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare("
            DELETE FROM check_executions
            WHERE executed_at < datetime('now', '-' || ? || ' days')
        ");
        $stmt->execute([$keepDays]);
        return $stmt->rowCount();
    }
}
