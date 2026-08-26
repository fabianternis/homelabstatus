<?php

declare(strict_types=1);

namespace App\Model;

use App\Enum\ServiceStatus;

class CheckResult
{
    public function __construct(
        public ?int $id = null,
        public int $monitorId = 0,
        public ServiceStatus $status = ServiceStatus::ONLINE,
        public float $responseTimeMs = 0.0,
        public ?int $statusCode = null,
        public ?string $errorMessage = null,
        public ?string $checkedAt = null,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            monitorId: (int) ($row['monitor_id'] ?? 0),
            status: ServiceStatus::tryFrom((string) ($row['status'] ?? 'online')) ?? ServiceStatus::ONLINE,
            responseTimeMs: (float) ($row['response_time_ms'] ?? 0.0),
            statusCode: isset($row['status_code']) ? (int) $row['status_code'] : null,
            errorMessage: $row['error_message'] ?? null,
            checkedAt: $row['checked_at'] ?? null,
        );
    }
}
