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
            $path = $this->dbPath ?? '%kernel.project_dir%/var/data/uplink.sqlite';
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
        CREATE TABLE IF NOT EXISTS uplink_targets (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            host TEXT NOT NULL,
            provider TEXT NOT NULL,
            country TEXT NOT NULL DEFAULT 'Global',
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS probe_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target_id TEXT NOT NULL,
            host TEXT NOT NULL,
            state TEXT NOT NULL,
            packets_sent INTEGER NOT NULL,
            packets_received INTEGER NOT NULL,
            packet_loss_percent REAL NOT NULL,
            min_latency_ms REAL,
            avg_latency_ms REAL,
            max_latency_ms REAL,
            jitter_ms REAL,
            error_message TEXT,
            probed_at TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE INDEX IF NOT EXISTS idx_probe_logs_target_time ON probe_logs(target_id, probed_at DESC);
        CREATE INDEX IF NOT EXISTS idx_probe_logs_time ON probe_logs(probed_at DESC);
SQL;

        $this->pdo->exec($schema);
    }
}
