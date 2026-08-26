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

        // Health Score Calculation (100 base)
        // 1. Packet Loss Penalty (up to 60 points deducted)
        $lossPenalty = min(60.0, $avgLoss * 0.6);

        // 2. Latency Penalty (up to 25 points deducted if latency exceeds 30ms baseline)
        $latencyPenalty = 0.0;
        if ($avgLatency > 30.0) {
            $latencyPenalty = min(25.0, ($avgLatency - 30.0) * 0.25);
        }

        // 3. Jitter Penalty (up to 15 points deducted if jitter exceeds 5ms)
        $jitterPenalty = 0.0;
        if ($avgJitter > 5.0) {
            $jitterPenalty = min(15.0, ($avgJitter - 5.0) * 0.5);
        }

        $healthScore = (int)round(max(0.0, min(100.0, 100.0 - $lossPenalty - $latencyPenalty - $jitterPenalty)));

        // State Determination
        $state = match (true) {
            $reachableCount === 0 || $avgLoss >= 90.0 => UplinkState::OFFLINE,
            $healthScore < 70 || $avgLoss > 5.0 || $avgLatency > 150.0 => UplinkState::DEGRADED,
            $healthScore >= 90 && $avgLoss === 0.0 && $avgLatency <= 45.0 => UplinkState::EXCELLENT,
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
