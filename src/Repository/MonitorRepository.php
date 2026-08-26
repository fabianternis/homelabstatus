<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Connection;
use App\Model\Monitor;
use PDO;

class MonitorRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    /**
     * @return array<string, Monitor[]>
     */
    public function findAllGrouped(bool $includePrivate = false): array
    {
        $pdo = $this->connection->getPdo();
        $sql = "SELECT * FROM monitors WHERE is_active = 1";
        if (!$includePrivate) {
            $sql .= " AND is_private = 0";
        }
        $sql .= " ORDER BY group_name ASC, sort_order ASC, name ASC";

        $stmt = $pdo->query($sql);
        $groups = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $monitor = Monitor::fromArray($row);
            $groups[$monitor->groupName][] = $monitor;
        }

        return $groups;
    }

    /**
     * @return Monitor[]
     */
    public function findAllActive(): array
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->query("SELECT * FROM monitors WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = Monitor::fromArray($row);
        }
        return $results;
    }

    /**
     * @return Monitor[]
     */
    public function findAll(): array
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->query("SELECT * FROM monitors ORDER BY group_name ASC, sort_order ASC, id ASC");
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = Monitor::fromArray($row);
        }
        return $results;
    }

    public function find(int $id): ?Monitor
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM monitors WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? Monitor::fromArray($row) : null;
    }

    public function save(Monitor $monitor): Monitor
    {
        $pdo = $this->connection->getPdo();
        $now = gmdate('Y-m-d H:i:s');

        if ($monitor->id === null) {
            $stmt = $pdo->prepare("
                INSERT INTO monitors (
                    name, group_name, description, type, target, expected_status_code,
                    keyword, timeout_seconds, interval_seconds, is_active, is_private,
                    sort_order, status, last_checked_at, last_status_change_at,
                    last_response_time_ms, consecutive_failures, created_at, updated_at
                ) VALUES (
                    :name, :group_name, :description, :type, :target, :expected_status_code,
                    :keyword, :timeout_seconds, :interval_seconds, :is_active, :is_private,
                    :sort_order, :status, :last_checked_at, :last_status_change_at,
                    :last_response_time_ms, :consecutive_failures, :created_at, :updated_at
                )
            ");
            $monitor->createdAt = $now;
            $monitor->updatedAt = $now;
        } else {
            $stmt = $pdo->prepare("
                UPDATE monitors SET
                    name = :name, group_name = :group_name, description = :description,
                    type = :type, target = :target, expected_status_code = :expected_status_code,
                    keyword = :keyword, timeout_seconds = :timeout_seconds, interval_seconds = :interval_seconds,
                    is_active = :is_active, is_private = :is_private, sort_order = :sort_order,
                    status = :status, last_checked_at = :last_checked_at,
                    last_status_change_at = :last_status_change_at, last_response_time_ms = :last_response_time_ms,
                    consecutive_failures = :consecutive_failures, updated_at = :updated_at
                WHERE id = :id
            ");
            $monitor->updatedAt = $now;
        }

        $params = [
            ':name' => $monitor->name,
            ':group_name' => $monitor->groupName,
            ':description' => $monitor->description,
            ':type' => $monitor->type->value,
            ':target' => $monitor->target,
            ':expected_status_code' => $monitor->expectedStatusCode,
            ':keyword' => $monitor->keyword,
            ':timeout_seconds' => $monitor->timeoutSeconds,
            ':interval_seconds' => $monitor->intervalSeconds,
            ':is_active' => $monitor->isActive ? 1 : 0,
            ':is_private' => $monitor->isPrivate ? 1 : 0,
            ':sort_order' => $monitor->sortOrder,
            ':status' => $monitor->status->value,
            ':last_checked_at' => $monitor->lastCheckedAt,
            ':last_status_change_at' => $monitor->lastStatusChangeAt,
            ':last_response_time_ms' => $monitor->lastResponseTimeMs,
            ':consecutive_failures' => $monitor->consecutiveFailures,
            ':updated_at' => $monitor->updatedAt,
        ];

        if ($monitor->id === null) {
            $params[':created_at'] = $monitor->createdAt;
            $stmt->execute($params);
            $monitor->id = (int) $pdo->lastInsertId();
        } else {
            $params[':id'] = $monitor->id;
            $stmt->execute($params);
        }

        return $monitor;
    }

    public function delete(int $id): bool
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare("DELETE FROM monitors WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
