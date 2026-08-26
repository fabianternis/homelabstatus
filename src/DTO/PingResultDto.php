<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\UplinkState;

readonly class PingResultDto
{
    /**
     * @param float[] $latencies
     */
    public function __construct(
        public string $targetId,
        public string $host,
        public UplinkState $state,
        public int $packetsSent,
        public int $packetsReceived,
        public float $packetLossPercent,
        public ?float $minLatencyMs,
        public ?float $avgLatencyMs,
        public ?float $maxLatencyMs,
        public ?float $jitterMs,
        public array $latencies,
        public ?string $errorMessage,
        public \DateTimeImmutable $probedAt
    ) {}

    public function toArray(): array
    {
        return [
            'target_id' => $this->targetId,
            'host' => $this->host,
            'state' => $this->state->value,
            'packets_sent' => $this->packetsSent,
            'packets_received' => $this->packetsReceived,
            'packet_loss_percent' => round($this->packetLossPercent, 1),
            'min_latency_ms' => $this->minLatencyMs !== null ? round($this->minLatencyMs, 2) : null,
            'avg_latency_ms' => $this->avgLatencyMs !== null ? round($this->avgLatencyMs, 2) : null,
            'max_latency_ms' => $this->maxLatencyMs !== null ? round($this->maxLatencyMs, 2) : null,
            'jitter_ms' => $this->jitterMs !== null ? round($this->jitterMs, 2) : null,
            'latencies' => $this->latencies,
            'error_message' => $this->errorMessage,
            'probed_at' => $this->probedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
