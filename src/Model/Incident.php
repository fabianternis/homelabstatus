<?php

declare(strict_types=1);

namespace App\Model;

use App\Enum\IncidentSeverity;

class Incident
{
    public function __construct(
        public ?int $id = null,
        public ?int $monitorId = null,
        public string $title = '',
        public IncidentSeverity $severity = IncidentSeverity::WARNING,
        public string $status = 'investigating', // investigating, identified, monitoring, resolved
        public string $message = '',
        public string $startedAt = '',
        public ?string $resolvedAt = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public ?string $monitorName = null,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            monitorId: isset($row['monitor_id']) ? (int) $row['monitor_id'] : null,
            title: (string) ($row['title'] ?? ''),
            severity: IncidentSeverity::tryFrom((string) ($row['severity'] ?? 'warning')) ?? IncidentSeverity::WARNING,
            status: (string) ($row['status'] ?? 'investigating'),
            message: (string) ($row['message'] ?? ''),
            startedAt: (string) ($row['started_at'] ?? gmdate('Y-m-d H:i:s')),
            resolvedAt: $row['resolved_at'] ?? null,
            createdAt: $row['created_at'] ?? null,
            updatedAt: $row['updated_at'] ?? null,
            monitorName: $row['monitor_name'] ?? null,
        );
    }
}
