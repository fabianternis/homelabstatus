<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PingResultDto;
use App\DTO\UplinkSummaryDto;
use App\Enum\UplinkState;

class UplinkHealthEvaluator
{
    /**
     * @param PingResultDto[] $results
     */
    public function evaluate(array $results): UplinkSummaryDto
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if (empty($results)) {
            return new UplinkSummaryDto(
                state: UplinkState::UNKNOWN,
                healthScore: 0,
                avgLatencyMs: 0.0,
                avgPacketLossPercent: 0.0,
                avgJitterMs: 0.0,
                activeTargetsCount: 0,
                healthyTargetsCount: 0,
                targets: [],
                evaluatedAt: $now
            );
        }

        $totalTargets = count($results);
        $healthyCount = 0;
        $reachableCount = 0;
        $latencies = [];
        $lossValues = [];
        $jitters = [];

        foreach ($results as $res) {
            $lossValues[] = $res->packetLossPercent;

            if ($res->state === UplinkState::EXCELLENT || $res->state === UplinkState::GOOD) {
                $healthyCount++;
            }

            if ($res->state !== UplinkState::OFFLINE) {
                $reachableCount++;
            }

            if ($res->avgLatencyMs !== null) {
                $latencies[] = $res->avgLatencyMs;
            }

            if ($res->jitterMs !== null) {
                $jitters[] = $res->jitterMs;
            }
        }

        $avgLatency = !empty($latencies) ? array_sum($latencies) / count($latencies) : 0.0;
        $avgLoss = !empty($lossValues) ? array_sum($lossValues) / count($lossValues) : 100.0;
        $avgJitter = !empty($jitters) ? array_sum($jitters) / count($jitters) : 0.0;

        // Health Score (0 - 100)
        // 1. Packet loss deduction (50 pts max)
        $lossPenalty = min(50.0, $avgLoss * 1.5);

        // 2. High latency deduction (only if average latency exceeds 80ms)
        $latencyPenalty = 0.0;
        if ($avgLatency > 80.0) {
            $latencyPenalty = min(30.0, ($avgLatency - 80.0) * 0.3);
        }

        // 3. Unreachable target deduction
        $unreachablePenalty = ($totalTargets - $reachableCount) * (50.0 / max(1, $totalTargets));

        $healthScore = (int)round(max(0.0, min(100.0, 100.0 - $lossPenalty - $latencyPenalty - $unreachablePenalty)));

        // State Determination
        $state = match (true) {
            $reachableCount === 0 || $avgLoss >= 80.0 => UplinkState::OFFLINE,
            $healthScore < 70 || $avgLoss > 15.0 || $avgLatency > 200.0 => UplinkState::DEGRADED,
            $healthScore >= 90 && $avgLoss === 0.0 && $avgLatency <= 80.0 => UplinkState::EXCELLENT,
            default => UplinkState::GOOD,
        };

        return new UplinkSummaryDto(
            state: $state,
            healthScore: $healthScore,
            avgLatencyMs: $avgLatency,
            avgPacketLossPercent: $avgLoss,
            avgJitterMs: $avgJitter,
            activeTargetsCount: $totalTargets,
            healthyTargetsCount: $healthyCount,
            targets: $results,
            evaluatedAt: $now
        );
    }
}
