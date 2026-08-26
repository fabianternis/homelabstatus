<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Component\Uid\Ulid;

class AuditLog
{
    public string $id;
    public string $logName;
    public ?string $subjectType;
    public ?string $subjectId;
    public string $event;
    public ?string $causerType;
    public ?string $causerId;
    public ?array $properties;
    public string $description;
    public string $createdAt;

    public function __construct(
        ?string $id = null,
        string $logName = 'default',
        ?string $subjectType = null,
        ?string $subjectId = null,
        string $event = 'created',
        ?string $causerType = null,
        ?string $causerId = null,
        ?array $properties = null,
        string $description = '',
        ?string $createdAt = null
    ) {
        $this->id = $id ?? Ulid::generate();
        $this->logName = $logName;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->event = $event;
        $this->causerType = $causerType;
        $this->causerId = $causerId;
        $this->properties = $properties;
        $this->description = $description;
        $this->createdAt = $createdAt ?? gmdate('Y-m-d H:i:s');
    }

    public static function fromArray(array $row): self
    {
        $properties = null;
        if (isset($row['properties']) && $row['properties'] !== null) {
            $properties = is_string($row['properties']) ? json_decode($row['properties'], true) : $row['properties'];
        }

        return new self(
            id: (string)$row['id'],
            logName: (string)$row['log_name'],
            subjectType: $row['subject_type'] ?? null,
            subjectId: $row['subject_id'] ?? null,
            event: (string)$row['event'],
            causerType: $row['causer_type'] ?? null,
            causerId: $row['causer_id'] ?? null,
            properties: $properties,
            description: (string)$row['description'],
            createdAt: (string)$row['created_at']
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'log_name' => $this->logName,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'event' => $this->event,
            'causer_type' => $this->causerType,
            'causer_id' => $this->causerId,
            'properties' => $this->properties,
            'description' => $this->description,
            'created_at' => $this->createdAt,
        ];
    }
}
