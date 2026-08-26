<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UplinkState;
use Symfony\Component\Uid\Ulid;

class CheckExecution
{
    public string $id;
    public string $checkId;
    public UplinkState $status;
    public float $durationMs;
    public array $resultData; // Dynamic JSON results
    public ?string $errorMessage;
    public string $executedAt;

    public function __construct(
        ?string $id = null,
        string $checkId = '',
        UplinkState $status = UplinkState::UNKNOWN,
        float $durationMs = 0.0,
        array $resultData = [],
        ?string $errorMessage = null,
        ?string $executedAt = null
    ) {
        $this->id = $id ?? Ulid::generate();
        $this->checkId = $checkId;
        $this->status = $status;
        $this->durationMs = $durationMs;
        $this->resultData = $resultData;
        $this->errorMessage = $errorMessage;
        $this->executedAt = $executedAt ?? gmdate('Y-m-d H:i:s');
    }

    public static function fromArray(array $row): self
    {
        $resultData = [];
        if (isset($row['result_data'])) {
            $resultData = is_string($row['result_data']) ? (json_decode($row['result_data'], true) ?: []) : $row['result_data'];
        }

        return new self(
            id: (string)$row['id'],
            checkId: (string)$row['check_id'],
            status: UplinkState::tryFrom((string)$row['status']) ?? UplinkState::UNKNOWN,
            durationMs: (float)($row['duration_ms'] ?? 0.0),
            resultData: $resultData,
            errorMessage: $row['error_message'] ?? null,
            executedAt: (string)$row['executed_at']
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'check_id' => $this->checkId,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'duration_ms' => round($this->durationMs, 2),
            'result_data' => $this->resultData,
            'error_message' => $this->errorMessage,
            'executed_at' => $this->executedAt,
        ];
    }
}
