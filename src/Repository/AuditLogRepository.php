<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Connection;
use App\Entity\AuditLog;
use PDO;

class AuditLogRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function record(AuditLog $log): void
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare('
            INSERT INTO audit_logs (id, log_name, subject_type, subject_id, event, causer_type, causer_id, properties, description, created_at)
            VALUES (:id, :log_name, :subject_type, :subject_id, :event, :causer_type, :causer_id, :properties, :description, :created_at)
        ');

        $stmt->execute([
            ':id' => $log->id,
            ':log_name' => $log->logName,
            ':subject_type' => $log->subjectType,
            ':subject_id' => $log->subjectId,
            ':event' => $log->event,
            ':causer_type' => $log->causerType,
            ':causer_id' => $log->causerId,
            ':properties' => $log->properties !== null ? json_encode($log->properties, JSON_UNESCAPED_SLASHES) : null,
            ':description' => $log->description,
            ':created_at' => $log->createdAt,
        ]);
    }

    /**
     * @return AuditLog[]
     */
    public function getRecent(int $limit = 50): array
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare('
            SELECT * FROM audit_logs
            ORDER BY created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $logs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $logs[] = AuditLog::fromArray($row);
        }

        return $logs;
    }
}
