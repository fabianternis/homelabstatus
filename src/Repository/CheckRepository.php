<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Connection;
use App\Entity\AuditLog;
use App\Entity\Check;
use App\Enum\UplinkState;
use PDO;
use Symfony\Component\Uid\Ulid;

class CheckRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function initDefaults(): void
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->query("SELECT COUNT(*) FROM checks WHERE type = 'uplink' AND deleted_at IS NULL");
        if ((int)$stmt->fetchColumn() === 0) {
            $defaults = [
                [
                    'id' => Ulid::generate(),
                    'name' => 'Cloudflare DNS (Primary)',
                    'slug' => 'cloudflare-dns-primary',
                    'type' => 'uplink',
                    'group_name' => 'Global Anycast DNS',
                    'description' => 'Cloudflare Anycast Resolver 1.1.1.1',
                    'config' => json_encode(['host' => '1.1.1.1', 'packets' => 2, 'timeout' => 2, 'provider' => 'Cloudflare Anycast', 'country' => 'Global']),
                    'sort_order' => 10,
                ],
                [
                    'id' => Ulid::generate(),
                    'name' => 'Cloudflare DNS (Secondary)',
                    'slug' => 'cloudflare-dns-secondary',
                    'type' => 'uplink',
                    'group_name' => 'Global Anycast DNS',
                    'description' => 'Cloudflare Anycast Resolver 1.0.0.1',
                    'config' => json_encode(['host' => '1.0.0.1', 'packets' => 2, 'timeout' => 2, 'provider' => 'Cloudflare Anycast', 'country' => 'Global']),
                    'sort_order' => 20,
                ],
                [
                    'id' => Ulid::generate(),
                    'name' => 'Google DNS (Primary)',
                    'slug' => 'google-dns-primary',
                    'type' => 'uplink',
                    'group_name' => 'Global Anycast DNS',
                    'description' => 'Google Anycast Resolver 8.8.8.8',
                    'config' => json_encode(['host' => '8.8.8.8', 'packets' => 2, 'timeout' => 2, 'provider' => 'Google Anycast', 'country' => 'Global']),
                    'sort_order' => 30,
                ],
                [
                    'id' => Ulid::generate(),
                    'name' => 'Google DNS (Secondary)',
                    'slug' => 'google-dns-secondary',
                    'type' => 'uplink',
                    'group_name' => 'Global Anycast DNS',
                    'description' => 'Google Anycast Resolver 8.8.4.4',
                    'config' => json_encode(['host' => '8.8.4.4', 'packets' => 2, 'timeout' => 2, 'provider' => 'Google Anycast', 'country' => 'Global']),
                    'sort_order' => 40,
                ],
                [
                    'id' => Ulid::generate(),
                    'name' => 'Quad9 DNS',
                    'slug' => 'quad9-dns',
                    'type' => 'uplink',
                    'group_name' => 'Global Anycast DNS',
                    'description' => 'Quad9 Foundation Resolver 9.9.9.9',
                    'config' => json_encode(['host' => '9.9.9.9', 'packets' => 2, 'timeout' => 2, 'provider' => 'Quad9 Foundation', 'country' => 'Global']),
                    'sort_order' => 50,
                ],
                [
                    'id' => Ulid::generate(),
                    'name' => 'OpenDNS (Cisco)',
                    'slug' => 'opendns-cisco',
                    'type' => 'uplink',
                    'group_name' => 'Global Anycast DNS',
                    'description' => 'Cisco OpenDNS Anycast Resolver 208.67.222.222',
                    'config' => json_encode(['host' => '208.67.222.222', 'packets' => 2, 'timeout' => 2, 'provider' => 'Cisco Anycast', 'country' => 'Global']),
                    'sort_order' => 60,
                ],
            ];

            $insert = $pdo->prepare('
                INSERT OR IGNORE INTO checks (id, name, slug, type, group_name, description, is_enabled, status, config, sort_order, created_at, updated_at)
                VALUES (:id, :name, :slug, :type, :group_name, :description, 1, "unknown", :config, :sort_order, datetime("now"), datetime("now"))
            ');

            foreach ($defaults as $d) {
                $insert->execute($d);
            }
        }
    }

    /**
     * @return Check[]
     */
    public function findByType(string $type = 'uplink', bool $onlyEnabled = true, bool $withTrashed = false): array
    {
        $this->initDefaults();
        $pdo = $this->connection->getPdo();

        $sql = 'SELECT * FROM checks WHERE type = :type';
        if ($onlyEnabled) {
            $sql .= ' AND is_enabled = 1';
        }
        if (!$withTrashed) {
            $sql .= ' AND deleted_at IS NULL';
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':type' => $type]);

        $checks = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $checks[] = Check::fromArray($row);
        }

        return $checks;
    }

    public function findById(string $id, bool $withTrashed = false): ?Check
    {
        $this->initDefaults();
        $pdo = $this->connection->getPdo();

        $sql = 'SELECT * FROM checks WHERE id = :id';
        if (!$withTrashed) {
            $sql .= ' AND deleted_at IS NULL';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Check::fromArray($row) : null;
    }

    public function findBySlug(string $slug, bool $withTrashed = false): ?Check
    {
        $this->initDefaults();
        $pdo = $this->connection->getPdo();

        $sql = 'SELECT * FROM checks WHERE slug = :slug';
        if (!$withTrashed) {
            $sql .= ' AND deleted_at IS NULL';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Check::fromArray($row) : null;
    }

    public function save(Check $check): void
    {
        $pdo = $this->connection->getPdo();
        $now = gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare('
            INSERT INTO checks (
                id, name, slug, type, group_name, description, is_enabled, status,
                config, last_metrics, last_executed_at, last_status_change_at,
                consecutive_failures, sort_order, created_at, updated_at, deleted_at
            ) VALUES (
                :id, :name, :slug, :type, :group_name, :description, :is_enabled, :status,
                :config, :last_metrics, :last_executed_at, :last_status_change_at,
                :consecutive_failures, :sort_order, :created_at, :updated_at, :deleted_at
            )
            ON CONFLICT(id) DO UPDATE SET
                name = excluded.name,
                slug = excluded.slug,
                type = excluded.type,
                group_name = excluded.group_name,
                description = excluded.description,
                is_enabled = excluded.is_enabled,
                status = excluded.status,
                config = excluded.config,
                last_metrics = excluded.last_metrics,
                last_executed_at = excluded.last_executed_at,
                last_status_change_at = excluded.last_status_change_at,
                consecutive_failures = excluded.consecutive_failures,
                sort_order = excluded.sort_order,
                updated_at = :now,
                deleted_at = excluded.deleted_at
        ');

        $stmt->execute([
            ':id' => $check->id,
            ':name' => $check->name,
            ':slug' => $check->slug,
            ':type' => $check->type,
            ':group_name' => $check->groupName,
            ':description' => $check->description,
            ':is_enabled' => $check->isEnabled ? 1 : 0,
            ':status' => $check->status->value,
            ':config' => json_encode($check->config, JSON_UNESCAPED_SLASHES),
            ':last_metrics' => $check->lastMetrics !== null ? json_encode($check->lastMetrics, JSON_UNESCAPED_SLASHES) : null,
            ':last_executed_at' => $check->lastExecutedAt,
            ':last_status_change_at' => $check->lastStatusChangeAt,
            ':consecutive_failures' => $check->consecutiveFailures,
            ':sort_order' => $check->sortOrder,
            ':created_at' => $check->createdAt,
            ':updated_at' => $check->updatedAt,
            ':deleted_at' => $check->deletedAt,
            ':now' => $now,
        ]);
    }

    public function updateExecutionResult(string $id, UplinkState $status, array $metrics, ?string $executedAt = null): void
    {
        $pdo = $this->connection->getPdo();
        $now = $executedAt ?? gmdate('Y-m-d H:i:s');

        // Check if status changed
        $currentStmt = $pdo->prepare('SELECT status FROM checks WHERE id = ?');
        $currentStmt->execute([$id]);
        $oldStatus = $currentStmt->fetchColumn();

        $statusChangedSql = ($oldStatus !== false && $oldStatus !== $status->value)
            ? ', last_status_change_at = :now'
            : '';

        $stmt = $pdo->prepare("
            UPDATE checks SET
                status = :status,
                last_metrics = :last_metrics,
                last_executed_at = :now,
                updated_at = :now
                {$statusChangedSql}
            WHERE id = :id
        ");

        $stmt->execute([
            ':status' => $status->value,
            ':last_metrics' => json_encode($metrics, JSON_UNESCAPED_SLASHES),
            ':now' => $now,
            ':id' => $id,
        ]);
    }

    /**
     * Soft delete a check
     */
    public function softDelete(string $id): bool
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare('UPDATE checks SET deleted_at = datetime("now"), updated_at = datetime("now") WHERE id = ? AND deleted_at IS NULL');
        return $stmt->execute([$id]) && $stmt->rowCount() > 0;
    }

    /**
     * Restore a soft-deleted check
     */
    public function restore(string $id): bool
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare('UPDATE checks SET deleted_at = NULL, updated_at = datetime("now") WHERE id = ? AND deleted_at IS NOT NULL');
        return $stmt->execute([$id]) && $stmt->rowCount() > 0;
    }

    /**
     * Force permanent delete
     */
    public function forceDelete(string $id): bool
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare('DELETE FROM checks WHERE id = ?');
        return $stmt->execute([$id]) && $stmt->rowCount() > 0;
    }
}
