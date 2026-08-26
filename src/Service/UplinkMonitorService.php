<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PingResultDto;
use App\DTO\TargetDto;
use App\DTO\UplinkSummaryDto;
use App\Repository\ProbeLogRepository;
use App\Repository\TargetRepository;
use App\Service\Ping\PingRunnerInterface;
use App\Service\Tui\SparklineGenerator;

class UplinkMonitorService
{
    public function __construct(
        private readonly TargetRepository $targetRepository,
        private readonly ProbeLogRepository $probeLogRepository,
        private readonly PingRunnerInterface $pingRunner,
        private readonly UplinkHealthEvaluator $evaluator
    ) {}

    /**
     * Executes parallel probes to all active external targets, records them, and returns summary
     */
    public function probeAll(int $packetsPerTarget = 2): UplinkSummaryDto
    {
        $targets = $this->targetRepository->getActiveTargets();
        $resultsMap = $this->pingRunner->pingMulti($targets, $packetsPerTarget);
        $results = [];

        foreach ($targets as $target) {
            $result = $resultsMap[$target->id] ?? null;
            if ($result !== null) {
                $this->probeLogRepository->record($result);
                $results[] = $result;
            }
        }

        return $this->evaluator->evaluate($results);
    }

    /**
     * Returns the current status using the most recent probe recordings (or runs a probe if no logs exist)
     */
    public function getCurrentStatus(): UplinkSummaryDto
    {
        $latest = $this->probeLogRepository->getLatestForAllTargets();
        if (empty($latest)) {
            return $this->probeAll(2);
        }

        return $this->evaluator->evaluate(array_values($latest));
    }

    /**
     * Returns time-series history with sparklines for each target
     * @return array<string, mixed>
     */
    public function getHistoryWithSparklines(int $samplesPerTarget = 30): array
    {
        $targets = $this->targetRepository->getActiveTargets();
        $historyByTarget = [];

        foreach ($targets as $target) {
            $logs = $this->probeLogRepository->getHistoryForTarget($target->id, $samplesPerTarget);
            $latencyValues = [];
            $lossValues = [];

            foreach ($logs as $log) {
                if ($log->avgLatencyMs !== null) {
                    $latencyValues[] = $log->avgLatencyMs;
                }
                $lossValues[] = $log->packetLossPercent;
            }

            $sparkline = SparklineGenerator::generate($latencyValues, 20);
            $latest = !empty($logs) ? end($logs) : null;

            $historyByTarget[$target->id] = [
                'target' => $target->toArray(),
                'latest' => $latest?->toArray(),
                'sparkline' => $sparkline,
                'samples_count' => count($logs),
                'history' => array_map(fn(PingResultDto $l) => $l->toArray(), $logs),
            ];
        }

        $aggregates = $this->probeLogRepository->getRollingAggregates(60);

        return [
            'aggregates_last_60m' => $aggregates,
            'targets' => $historyByTarget,
        ];
    }
}
