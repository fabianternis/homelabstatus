<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Connection;
use App\Enum\ServiceStatus;
use App\Model\CheckResult;
use PDO;

class CheckResultRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function log(
        int $monitorId,
        ServiceStatus $status,
        float $responseTimeMs,
        ?int $statusCode = null,
        ?string $errorMessage = null
    ): void {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare("
            INSERT INTO check_results (monitor_id, status, response_time_ms, status_code, error_message, checked_at)
            VALUES (?, ?, ?, ?, ?, datetime('now'))
        ");
        $stmt->execute([
            $monitorId,
            $status->value,
            $responseTimeMs,
            $statusCode,
            $errorMessage,
        ]);
    }

    /**
     * @return array<int, array{status: string, response_time_ms: float, checked_at: string}>
     */
    public function getRecentForMonitor(int $monitorId, int $limit = 60): array
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare("
            SELECT status, response_time_ms, checked_at
            FROM check_results
            WHERE monitor_id = ?
            ORDER BY checked_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $monitorId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_reverse($results);
    }

    public function cleanupOldRecords(int $retentionDays = 90): int
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare("
            DELETE FROM check_results
            WHERE checked_at < datetime('now', '-' || ? || ' days')
        ");
        $stmt->execute([$retentionDays]);
        return $stmt->rowCount();
    }
}
