<?php

declare(strict_types=1);

namespace App\Model;

use App\Enum\CheckType;
use App\Enum\ServiceStatus;

class Monitor
{
    public function __construct(
        public ?int $id = null,
        public string $name = '',
        public string $groupName = 'Services',
        public ?string $description = null,
        public CheckType $type = CheckType::HTTP,
        public string $target = '',
        public int $expectedStatusCode = 200,
        public ?string $keyword = null,
        public int $timeoutSeconds = 5,
        public int $intervalSeconds = 60,
        public bool $isActive = true,
        public bool $isPrivate = false,
        public int $sortOrder = 0,
        public ServiceStatus $status = ServiceStatus::PENDING,
        public ?string $lastCheckedAt = null,
        public ?string $lastStatusChangeAt = null,
        public float $lastResponseTimeMs = 0.0,
        public int $consecutiveFailures = 0,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            name: (string) ($row['name'] ?? ''),
            groupName: (string) ($row['group_name'] ?? 'Services'),
            description: $row['description'] ?? null,
            type: CheckType::tryFrom((string) ($row['type'] ?? 'http')) ?? CheckType::HTTP,
            target: (string) ($row['target'] ?? ''),
            expectedStatusCode: (int) ($row['expected_status_code'] ?? 200),
            keyword: $row['keyword'] ?? null,
            timeoutSeconds: (int) ($row['timeout_seconds'] ?? 5),
            intervalSeconds: (int) ($row['interval_seconds'] ?? 60),
            isActive: (bool) ($row['is_active'] ?? true),
            isPrivate: (bool) ($row['is_private'] ?? false),
            sortOrder: (int) ($row['sort_order'] ?? 0),
            status: ServiceStatus::tryFrom((string) ($row['status'] ?? 'pending')) ?? ServiceStatus::PENDING,
            lastCheckedAt: $row['last_checked_at'] ?? null,
            lastStatusChangeAt: $row['last_status_change_at'] ?? null,
            lastResponseTimeMs: (float) ($row['last_response_time_ms'] ?? 0.0),
            consecutiveFailures: (int) ($row['consecutive_failures'] ?? 0),
            createdAt: $row['created_at'] ?? null,
            updatedAt: $row['updated_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'group_name' => $this->groupName,
            'description' => $this->description,
            'type' => $this->type->value,
            'target' => $this->target,
            'expected_status_code' => $this->expectedStatusCode,
            'keyword' => $this->keyword,
            'timeout_seconds' => $this->timeoutSeconds,
            'interval_seconds' => $this->intervalSeconds,
            'is_active' => $this->isActive,
            'is_private' => $this->isPrivate,
            'sort_order' => $this->sortOrder,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'last_checked_at' => $this->lastCheckedAt,
            'last_status_change_at' => $this->lastStatusChangeAt,
            'last_response_time_ms' => round($this->lastResponseTimeMs, 2),
            'consecutive_failures' => $this->consecutiveFailures,
        ];
    }
}
