<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UplinkState;
use Symfony\Component\Uid\Ulid;

class Check
{
    public string $id;
    public string $name;
    public string $slug;
    public string $type; // e.g. 'uplink', 'icmp_ping', 'http', 'tcp', 'dns'
    public string $groupName;
    public ?string $description;
    public bool $isEnabled;
    public UplinkState $status;
    public array $config; // Dynamic JSON configuration
    public ?array $lastMetrics; // Dynamic JSON latest execution metrics
    public ?string $lastExecutedAt;
    public ?string $lastStatusChangeAt;
    public int $consecutiveFailures;
    public int $sortOrder;
    public string $createdAt;
    public string $updatedAt;
    public ?string $deletedAt; // SoftDeletes support

    public function __construct(
        ?string $id = null,
        string $name = '',
        string $slug = '',
        string $type = 'uplink',
        string $groupName = 'Uplink Probes',
        ?string $description = null,
        bool $isEnabled = true,
        UplinkState $status = UplinkState::UNKNOWN,
        array $config = [],
        ?array $lastMetrics = null,
        ?string $lastExecutedAt = null,
        ?string $lastStatusChangeAt = null,
        int $consecutiveFailures = 0,
        int $sortOrder = 0,
        ?string $createdAt = null,
        ?string $updatedAt = null,
        ?string $deletedAt = null
    ) {
        $now = gmdate('Y-m-d H:i:s');
        $this->id = $id ?? Ulid::generate();
        $this->name = $name;
        $this->slug = $slug ?: strtolower(trim((string)preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
        $this->type = $type;
        $this->groupName = $groupName;
        $this->description = $description;
        $this->isEnabled = $isEnabled;
        $this->status = $status;
        $this->config = $config;
        $this->lastMetrics = $lastMetrics;
        $this->lastExecutedAt = $lastExecutedAt;
        $this->lastStatusChangeAt = $lastStatusChangeAt;
        $this->consecutiveFailures = $consecutiveFailures;
        $this->sortOrder = $sortOrder;
        $this->createdAt = $createdAt ?? $now;
        $this->updatedAt = $updatedAt ?? $now;
        $this->deletedAt = $deletedAt;
    }

    public function isTrashed(): bool
    {
        return $this->deletedAt !== null;
    }

    public static function fromArray(array $row): self
    {
        $config = [];
        if (isset($row['config'])) {
            $config = is_string($row['config']) ? (json_decode($row['config'], true) ?: []) : $row['config'];
        }

        $lastMetrics = null;
        if (isset($row['last_metrics']) && $row['last_metrics'] !== null) {
            $lastMetrics = is_string($row['last_metrics']) ? json_decode($row['last_metrics'], true) : $row['last_metrics'];
        }

        return new self(
            id: (string)$row['id'],
            name: (string)$row['name'],
            slug: (string)$row['slug'],
            type: (string)$row['type'],
            groupName: (string)($row['group_name'] ?? 'Uplink Probes'),
            description: $row['description'] ?? null,
            isEnabled: (bool)($row['is_enabled'] ?? true),
            status: UplinkState::tryFrom((string)($row['status'] ?? 'unknown')) ?? UplinkState::UNKNOWN,
            config: $config,
            lastMetrics: $lastMetrics,
            lastExecutedAt: $row['last_executed_at'] ?? null,
            lastStatusChangeAt: $row['last_status_change_at'] ?? null,
            consecutiveFailures: (int)($row['consecutive_failures'] ?? 0),
            sortOrder: (int)($row['sort_order'] ?? 0),
            createdAt: $row['created_at'] ?? null,
            updatedAt: $row['updated_at'] ?? null,
            deletedAt: $row['deleted_at'] ?? null
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'group_name' => $this->groupName,
            'description' => $this->description,
            'is_enabled' => $this->isEnabled,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'config' => $this->config,
            'last_metrics' => $this->lastMetrics,
            'last_executed_at' => $this->lastExecutedAt,
            'last_status_change_at' => $this->lastStatusChangeAt,
            'consecutive_failures' => $this->consecutiveFailures,
            'sort_order' => $this->sortOrder,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt,
        ];
    }
}
