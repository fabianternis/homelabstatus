<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Connection;
use App\DTO\PingResultDto;
use App\Enum\UplinkState;
use PDO;

class ProbeLogRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function record(PingResultDto $result): void
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare('
            INSERT INTO probe_logs (
                target_id, host, state, packets_sent, packets_received,
                packet_loss_percent, min_latency_ms, avg_latency_ms, max_latency_ms,
                jitter_ms, error_message, probed_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            $result->targetId,
            $result->host,
            $result->state->value,
            $result->packetsSent,
            $result->packetsReceived,
            $result->packetLossPercent,
            $result->minLatencyMs,
            $result->avgLatencyMs,
            $result->maxLatencyMs,
            $result->jitterMs,
            $result->errorMessage,
            $result->probedAt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return PingResultDto[]
     */
    public function getLatestForAllTargets(): array
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->query('
            SELECT p.*
            FROM probe_logs p
            INNER JOIN (
                SELECT target_id, MAX(id) as max_id
                FROM probe_logs
                GROUP BY target_id
            ) latest ON p.id = latest.max_id
            ORDER BY p.target_id ASC
        ');

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[$row['target_id']] = $this->hydrate($row);
        }

        return $results;
    }

    /**
     * @return PingResultDto[]
     */
    public function getHistoryForTarget(string $targetId, int $limit = 60): array
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare('
            SELECT * FROM probe_logs
            WHERE target_id = ?
            ORDER BY probed_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $targetId, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $logs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $logs[] = $this->hydrate($row);
        }

        return array_reverse($logs); // Chronological order
    }

    /**
     * @return array<string, mixed>
     */
    public function getRollingAggregates(int $minutes = 60): array
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_probes,
                AVG(avg_latency_ms) as rolling_avg_latency,
                AVG(packet_loss_percent) as rolling_avg_loss,
                AVG(jitter_ms) as rolling_avg_jitter,
                MIN(min_latency_ms) as lowest_latency,
                MAX(max_latency_ms) as highest_latency
            FROM probe_logs
            WHERE probed_at >= datetime('now', '-' || ? || ' minutes')
        ");
        $stmt->execute([$minutes]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total_probes' => (int)($row['total_probes'] ?? 0),
            'avg_latency_ms' => round((float)($row['rolling_avg_latency'] ?? 0.0), 2),
            'avg_packet_loss_percent' => round((float)($row['rolling_avg_loss'] ?? 0.0), 1),
            'avg_jitter_ms' => round((float)($row['rolling_avg_jitter'] ?? 0.0), 2),
            'lowest_latency_ms' => round((float)($row['lowest_latency'] ?? 0.0), 2),
            'highest_latency_ms' => round((float)($row['highest_latency'] ?? 0.0), 2),
        ];
    }

    public function prune(int $retentionDays = 7): int
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare("
            DELETE FROM probe_logs
            WHERE probed_at < datetime('now', '-' || ? || ' days')
        ");
        $stmt->execute([$retentionDays]);
        return $stmt->rowCount();
    }

    private function hydrate(array $row): PingResultDto
    {
        return new PingResultDto(
            targetId: (string)$row['target_id'],
            host: (string)$row['host'],
            state: UplinkState::tryFrom((string)$row['state']) ?? UplinkState::UNKNOWN,
            packetsSent: (int)$row['packets_sent'],
            packetsReceived: (int)$row['packets_received'],
            packetLossPercent: (float)$row['packet_loss_percent'],
            minLatencyMs: $row['min_latency_ms'] !== null ? (float)$row['min_latency_ms'] : null,
            avgLatencyMs: $row['avg_latency_ms'] !== null ? (float)$row['avg_latency_ms'] : null,
            maxLatencyMs: $row['max_latency_ms'] !== null ? (float)$row['max_latency_ms'] : null,
            jitterMs: $row['jitter_ms'] !== null ? (float)$row['jitter_ms'] : null,
            latencies: [],
            errorMessage: $row['error_message'] ?? null,
            probedAt: new \DateTimeImmutable((string)$row['probed_at'])
        );
    }
}
