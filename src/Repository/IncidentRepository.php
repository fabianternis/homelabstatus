<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Connection;
use App\Model\Incident;
use PDO;

class IncidentRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    /**
     * @return Incident[]
     */
    public function findActive(): array
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->query("
            SELECT i.*, m.name as monitor_name
            FROM incidents i
            LEFT JOIN monitors m ON m.id = i.monitor_id
            WHERE i.status != 'resolved'
            ORDER BY i.started_at DESC
        ");
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = Incident::fromArray($row);
        }
        return $results;
    }

    /**
     * @return Incident[]
     */
    public function findRecent(int $limit = 10): array
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare("
            SELECT i.*, m.name as monitor_name
            FROM incidents i
            LEFT JOIN monitors m ON m.id = i.monitor_id
            ORDER BY i.started_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = Incident::fromArray($row);
        }
        return $results;
    }

    public function save(Incident $incident): Incident
    {
        $pdo = $this->connection->getPdo();
        $now = gmdate('Y-m-d H:i:s');

        if ($incident->id === null) {
            $stmt = $pdo->prepare("
                INSERT INTO incidents (monitor_id, title, severity, status, message, started_at, resolved_at, created_at, updated_at)
                VALUES (:monitor_id, :title, :severity, :status, :message, :started_at, :resolved_at, :created_at, :updated_at)
            ");
            $incident->createdAt = $now;
            $incident->updatedAt = $now;
        } else {
            $stmt = $pdo->prepare("
                UPDATE incidents SET
                    monitor_id = :monitor_id,
                    title = :title,
                    severity = :severity,
                    status = :status,
                    message = :message,
                    started_at = :started_at,
                    resolved_at = :resolved_at,
                    updated_at = :updated_at
                WHERE id = :id
            ");
            $incident->updatedAt = $now;
        }

        $params = [
            ':monitor_id' => $incident->monitorId,
            ':title' => $incident->title,
            ':severity' => $incident->severity->value,
            ':status' => $incident->status,
            ':message' => $incident->message,
            ':started_at' => $incident->startedAt,
            ':resolved_at' => $incident->resolvedAt,
            ':updated_at' => $incident->updatedAt,
        ];

        if ($incident->id === null) {
            $params[':created_at'] = $incident->createdAt;
            $stmt->execute($params);
            $incident->id = (int) $pdo->lastInsertId();
        } else {
            $params[':id'] = $incident->id;
            $stmt->execute($params);
        }

        return $incident;
    }

    public function resolveForMonitor(int $monitorId): void
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare("
            UPDATE incidents 
            SET status = 'resolved', resolved_at = datetime('now'), updated_at = datetime('now')
            WHERE monitor_id = ? AND status != 'resolved'
        ");
        $stmt->execute([$monitorId]);
    }

    public function resolve(int $id): void
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare("
            UPDATE incidents 
            SET status = 'resolved', resolved_at = datetime('now'), updated_at = datetime('now')
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }
}
