<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class Connection
{
    private ?PDO $pdo = null;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        #[Autowire(env: 'default::UPLINK_DB_PATH')]
        private readonly ?string $dbPath = null
    ) {}

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $path = $this->dbPath ?? '%kernel.project_dir%/var/data/homelabstatus.sqlite';
            $path = str_replace('%kernel.project_dir%', $this->projectDir, $path);

            if ($path !== ':memory:') {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
            }

            try {
                $this->pdo = new PDO("sqlite:{$path}", null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                if ($path !== ':memory:') {
                    $this->pdo->exec('PRAGMA journal_mode = WAL;');
                    $this->pdo->exec('PRAGMA synchronous = NORMAL;');
                }
                $this->pdo->exec('PRAGMA foreign_keys = ON;');

                $this->migrate();
            } catch (PDOException $e) {
                throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        return $this->pdo;
    }

    public function migrate(): void
    {
        $schema = <<<SQL
        -- Dynamic Checks Table with JSON configuration & SoftDeletes
        CREATE TABLE IF NOT EXISTS checks (
            id TEXT PRIMARY KEY, -- ULID (26 chars)
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            type TEXT NOT NULL, -- e.g. 'uplink', 'icmp_ping', 'http', 'tcp', 'dns'
            group_name TEXT NOT NULL DEFAULT 'Uplink Probes',
            description TEXT,
            is_enabled INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'unknown',
            config JSON NOT NULL, -- Type-specific JSON configuration (host, packets, timeout, headers, port, etc.)
            last_metrics JSON, -- Type-specific latest execution metrics (latency_ms, loss_percent, jitter_ms, etc.)
            last_executed_at TEXT,
            last_status_change_at TEXT,
            consecutive_failures INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now')),
            deleted_at TEXT -- SoftDeletes support
        );

        CREATE INDEX IF NOT EXISTS idx_checks_type_enabled ON checks(type, is_enabled, deleted_at);
        CREATE INDEX IF NOT EXISTS idx_checks_status ON checks(status);
        CREATE INDEX IF NOT EXISTS idx_checks_slug ON checks(slug);
        CREATE INDEX IF NOT EXISTS idx_checks_deleted_at ON checks(deleted_at);

        -- Check Executions Table with JSON result payload
        CREATE TABLE IF NOT EXISTS check_executions (
            id TEXT PRIMARY KEY, -- ULID (26 chars)
            check_id TEXT NOT NULL,
            status TEXT NOT NULL, -- e.g. 'excellent', 'good', 'degraded', 'offline'
            duration_ms REAL NOT NULL DEFAULT 0,
            result_data JSON NOT NULL, -- Type-specific JSON results (packets, latency samples, jitter, headers, etc.)
            error_message TEXT,
            executed_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_executions_check_time ON check_executions(check_id, executed_at DESC);
        CREATE INDEX IF NOT EXISTS idx_executions_time ON check_executions(executed_at DESC);

        -- Spatie-Style Audit / Activity Logs
        CREATE TABLE IF NOT EXISTS audit_logs (
            id TEXT PRIMARY KEY, -- ULID (26 chars)
            log_name TEXT NOT NULL DEFAULT 'default',
            subject_type TEXT,
            subject_id TEXT,
            event TEXT NOT NULL, -- 'created', 'updated', 'deleted', 'status_changed', 'executed'
            causer_type TEXT,
            causer_id TEXT,
            properties JSON, -- { "old": {...}, "attributes": {...} }
            description TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE INDEX IF NOT EXISTS idx_audit_logs_subject ON audit_logs(subject_type, subject_id);
        CREATE INDEX IF NOT EXISTS idx_audit_logs_event ON audit_logs(event);
        CREATE INDEX IF NOT EXISTS idx_audit_logs_time ON audit_logs(created_at DESC);

        -- Spatie-Style Roles & Permissions
        CREATE TABLE IF NOT EXISTS permissions (
            id TEXT PRIMARY KEY, -- ULID (26 chars)
            name TEXT NOT NULL UNIQUE,
            guard_name TEXT NOT NULL DEFAULT 'api',
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS roles (
            id TEXT PRIMARY KEY, -- ULID (26 chars)
            name TEXT NOT NULL UNIQUE,
            guard_name TEXT NOT NULL DEFAULT 'api',
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS role_has_permissions (
            permission_id TEXT NOT NULL,
            role_id TEXT NOT NULL,
            PRIMARY KEY (permission_id, role_id),
            FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        );
SQL;

        $this->pdo->exec($schema);
    }
}
