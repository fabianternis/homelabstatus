<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Connection;
use App\DTO\TargetDto;
use PDO;

class TargetRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    /**
     * @return TargetDto[]
     */
    public static function getDefaultTargets(): array
    {
        return [
            new TargetDto(
                id: 'cloudflare-primary',
                name: 'Cloudflare DNS (Primary)',
                host: '1.1.1.1',
                provider: 'Cloudflare Anycast',
                country: 'Global',
                isActive: true,
                sortOrder: 10
            ),
            new TargetDto(
                id: 'cloudflare-secondary',
                name: 'Cloudflare DNS (Secondary)',
                host: '1.0.0.1',
                provider: 'Cloudflare Anycast',
                country: 'Global',
                isActive: true,
                sortOrder: 20
            ),
            new TargetDto(
                id: 'google-primary',
                name: 'Google DNS (Primary)',
                host: '8.8.8.8',
                provider: 'Google Anycast',
                country: 'Global',
                isActive: true,
                sortOrder: 30
            ),
            new TargetDto(
                id: 'google-secondary',
                name: 'Google DNS (Secondary)',
                host: '8.8.4.4',
                provider: 'Google Anycast',
                country: 'Global',
                isActive: true,
                sortOrder: 40
            ),
            new TargetDto(
                id: 'quad9',
                name: 'Quad9 DNS',
                host: '9.9.9.9',
                provider: 'Quad9 Foundation',
                country: 'Global',
                isActive: true,
                sortOrder: 50
            ),
            new TargetDto(
                id: 'opendns',
                name: 'OpenDNS (Cisco)',
                host: '208.67.222.222',
                provider: 'Cisco Anycast',
                country: 'Global',
                isActive: true,
                sortOrder: 60
            ),
        ];
    }

    public function initDefaults(): void
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->query('SELECT COUNT(*) FROM uplink_targets');
        if ((int)$stmt->fetchColumn() === 0) {
            $insert = $pdo->prepare('
                INSERT OR IGNORE INTO uplink_targets (id, name, host, provider, country, is_active, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            foreach (self::getDefaultTargets() as $target) {
                $insert->execute([
                    $target->id,
                    $target->name,
                    $target->host,
                    $target->provider,
                    $target->country,
                    $target->isActive ? 1 : 0,
                    $target->sortOrder,
                ]);
            }
        }
    }

    /**
     * @return TargetDto[]
     */
    public function getActiveTargets(): array
    {
        $this->initDefaults();
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->query('SELECT * FROM uplink_targets WHERE is_active = 1 ORDER BY sort_order ASC, name ASC');

        $targets = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $targets[] = new TargetDto(
                id: (string)$row['id'],
                name: (string)$row['name'],
                host: (string)$row['host'],
                provider: (string)$row['provider'],
                country: (string)$row['country'],
                isActive: (bool)$row['is_active'],
                sortOrder: (int)$row['sort_order']
            );
        }

        return $targets;
    }

    /**
     * @return TargetDto[]
     */
    public function getAllTargets(): array
    {
        $this->initDefaults();
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->query('SELECT * FROM uplink_targets ORDER BY sort_order ASC, name ASC');

        $targets = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $targets[] = new TargetDto(
                id: (string)$row['id'],
                name: (string)$row['name'],
                host: (string)$row['host'],
                provider: (string)$row['provider'],
                country: (string)$row['country'],
                isActive: (bool)$row['is_active'],
                sortOrder: (int)$row['sort_order']
            );
        }

        return $targets;
    }

    public function findById(string $id): ?TargetDto
    {
        $this->initDefaults();
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare('SELECT * FROM uplink_targets WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return new TargetDto(
            id: (string)$row['id'],
            name: (string)$row['name'],
            host: (string)$row['host'],
            provider: (string)$row['provider'],
            country: (string)$row['country'],
            isActive: (bool)$row['is_active'],
            sortOrder: (int)$row['sort_order']
        );
    }
}
