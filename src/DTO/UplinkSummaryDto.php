<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\UplinkState;

readonly class UplinkSummaryDto
{
    /**
     * @param PingResultDto[] $targets
     */
    public function __construct(
        public UplinkState $state,
        public int $healthScore, // 0 to 100
        public float $avgLatencyMs,
        public float $avgPacketLossPercent,
        public float $avgJitterMs,
        public int $activeTargetsCount,
        public int $healthyTargetsCount,
        public array $targets,
        public \DateTimeImmutable $evaluatedAt
    ) {}

    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'health_score' => $this->healthScore,
            'metrics' => [
                'avg_latency_ms' => round($this->avgLatencyMs, 2),
                'avg_packet_loss_percent' => round($this->avgPacketLossPercent, 1),
                'avg_jitter_ms' => round($this->avgJitterMs, 2),
            ],
            'targets_summary' => [
                'total' => $this->activeTargetsCount,
                'healthy' => $this->healthyTargetsCount,
            ],
            'targets' => array_map(fn(PingResultDto $t) => $t->toArray(), $this->targets),
            'evaluated_at' => $this->evaluatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
