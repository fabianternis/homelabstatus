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
        #[Autowire(env: 'DB_PATH')]
        private readonly string $dbPath = '%kernel.project_dir%/var/data/homelabstatus.sqlite'
    ) {}

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $path = str_replace('%kernel.project_dir%', $this->projectDir, $this->dbPath);

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
                throw new \RuntimeException('Failed to connect to SQLite database: ' . $e->getMessage(), (int) $e->getCode(), $e);
            }
        }

        return $this->pdo;
    }

    public function migrate(): void
    {
        $schema = <<<SQL
        CREATE TABLE IF NOT EXISTS monitors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            group_name TEXT NOT NULL DEFAULT 'Services',
            description TEXT,
            type TEXT NOT NULL,
            target TEXT NOT NULL,
            expected_status_code INTEGER DEFAULT 200,
            keyword TEXT,
            timeout_seconds INTEGER DEFAULT 5,
            interval_seconds INTEGER DEFAULT 60,
            is_active INTEGER DEFAULT 1,
            is_private INTEGER DEFAULT 0,
            sort_order INTEGER DEFAULT 0,
            status TEXT DEFAULT 'pending',
            last_checked_at TEXT,
            last_status_change_at TEXT,
            last_response_time_ms REAL DEFAULT 0,
            consecutive_failures INTEGER DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE INDEX IF NOT EXISTS idx_monitors_status ON monitors(status);
        CREATE INDEX IF NOT EXISTS idx_monitors_group ON monitors(group_name);
        CREATE INDEX IF NOT EXISTS idx_monitors_active ON monitors(is_active);

        CREATE TABLE IF NOT EXISTS check_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            monitor_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            response_time_ms REAL NOT NULL,
            status_code INTEGER,
            error_message TEXT,
            checked_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (monitor_id) REFERENCES monitors(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_checks_monitor_time ON check_results(monitor_id, checked_at DESC);
        CREATE INDEX IF NOT EXISTS idx_checks_time ON check_results(checked_at);

        CREATE TABLE IF NOT EXISTS incidents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            monitor_id INTEGER,
            title TEXT NOT NULL,
            severity TEXT NOT NULL DEFAULT 'warning',
            status TEXT NOT NULL DEFAULT 'investigating',
            message TEXT NOT NULL,
            started_at TEXT NOT NULL DEFAULT (datetime('now')),
            resolved_at TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (monitor_id) REFERENCES monitors(id) ON DELETE SET NULL
        );

        CREATE INDEX IF NOT EXISTS idx_incidents_status ON incidents(status);
        CREATE INDEX IF NOT EXISTS idx_incidents_started ON incidents(started_at DESC);

        CREATE TABLE IF NOT EXISTS system_metrics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cpu_usage_percent REAL,
            memory_used_bytes INTEGER,
            memory_total_bytes INTEGER,
            disk_used_bytes INTEGER,
            disk_total_bytes INTEGER,
            load_1m REAL,
            load_5m REAL,
            load_15m REAL,
            uptime_seconds INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE INDEX IF NOT EXISTS idx_metrics_time ON system_metrics(created_at DESC);
SQL;

        $this->pdo->exec($schema);
    }
}
