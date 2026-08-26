<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PingResultDto;
use App\DTO\TargetDto;
use App\DTO\UplinkSummaryDto;
use App\Entity\Check;
use App\Entity\CheckExecution;
use App\Enum\UplinkState;
use App\Repository\CheckExecutionRepository;
use App\Repository\CheckRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Ping\PingRunnerInterface;
use App\Service\Tui\SparklineGenerator;
use Symfony\Component\Uid\Ulid;

class UplinkMonitorService
{
    public function __construct(
        private readonly CheckRepository $checkRepository,
        private readonly CheckExecutionRepository $executionRepository,
        private readonly PingRunnerInterface $pingRunner,
        private readonly UplinkHealthEvaluator $evaluator,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Executes parallel probes to all active checks of type 'uplink'
     */
    public function probeAll(int $packetsPerTarget = 2): UplinkSummaryDto
    {
        /** @var Check[] $checks */
        $checks = $this->checkRepository->findByType('uplink', onlyEnabled: true);

        // Convert checks to TargetDto for ping runner
        $targetList = [];
        $checkMap = [];
        foreach ($checks as $check) {
            $host = $check->config['host'] ?? '127.0.0.1';
            $targetDto = new TargetDto(
                id: $check->id,
                name: $check->name,
                host: $host,
                provider: $check->config['provider'] ?? 'Anycast',
                country: $check->config['country'] ?? 'Global',
                isActive: $check->isEnabled,
                sortOrder: $check->sortOrder
            );
            $targetList[] = $targetDto;
            $checkMap[$check->id] = $check;
        }

        $resultsMap = $this->pingRunner->pingMulti($targetList, $packetsPerTarget);
        $pingResults = [];
        $now = gmdate('Y-m-d H:i:s');

        foreach ($targetList as $target) {
            $result = $resultsMap[$target->id] ?? null;
            if ($result !== null) {
                $check = $checkMap[$target->id];
                $oldStatus = $check->status;

                // 1. Record Check Execution with ULID & JSON result_data
                $execution = new CheckExecution(
                    id: Ulid::generate(),
                    checkId: $check->id,
                    status: $result->state,
                    durationMs: $result->avgLatencyMs ?? 0.0,
                    resultData: [
                        'host' => $result->host,
                        'packets_sent' => $result->packetsSent,
                        'packets_received' => $result->packetsReceived,
                        'packet_loss_percent' => $result->packetLossPercent,
                        'min_latency_ms' => $result->minLatencyMs,
                        'avg_latency_ms' => $result->avgLatencyMs,
                        'max_latency_ms' => $result->maxLatencyMs,
                        'jitter_ms' => $result->jitterMs,
                        'latencies' => $result->latencies,
                    ],
                    errorMessage: $result->errorMessage,
                    executedAt: $now
                );
                $this->executionRepository->record($execution);

                // 2. Update Check table with latest metrics & status
                $metrics = [
                    'avg_latency_ms' => $result->avgLatencyMs,
                    'min_latency_ms' => $result->minLatencyMs,
                    'max_latency_ms' => $result->maxLatencyMs,
                    'jitter_ms' => $result->jitterMs,
                    'packet_loss_percent' => $result->packetLossPercent,
                ];
                $this->checkRepository->updateExecutionResult($check->id, $result->state, $metrics, $now);

                // 3. Audit log if state changed
                if ($oldStatus !== UplinkState::UNKNOWN && $oldStatus !== $result->state) {
                    $this->auditLogger->log(
                        event: 'status_changed',
                        description: "Check '{$check->name}' status changed from {$oldStatus->value} to {$result->state->value}",
                        subjectType: Check::class,
                        subjectId: $check->id,
                        properties: [
                            'old_status' => $oldStatus->value,
                            'new_status' => $result->state->value,
                            'metrics' => $metrics,
                        ],
                        logName: 'checks'
                    );
                }

                $pingResults[] = $result;
            }
        }

        return $this->evaluator->evaluate($pingResults);
    }

    /**
     * Returns the current status using the latest check state or triggers a fresh probe
     */
    public function getCurrentStatus(): UplinkSummaryDto
    {
        $checks = $this->checkRepository->findByType('uplink', onlyEnabled: true);
        if (empty($checks)) {
            return $this->probeAll(2);
        }

        $checkIds = array_map(fn(Check $c) => $c->id, $checks);
        $latestExecs = $this->executionRepository->getLatestForChecks($checkIds);

        if (empty($latestExecs)) {
            return $this->probeAll(2);
        }

        $results = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ($checks as $check) {
            $exec = $latestExecs[$check->id] ?? null;
            if ($exec !== null) {
                $rd = $exec->resultData;
                $results[] = new PingResultDto(
                    targetId: $check->id,
                    host: $rd['host'] ?? ($check->config['host'] ?? '127.0.0.1'),
                    state: $exec->status,
                    packetsSent: (int)($rd['packets_sent'] ?? 2),
                    packetsReceived: (int)($rd['packets_received'] ?? 2),
                    packetLossPercent: (float)($rd['packet_loss_percent'] ?? 0.0),
                    minLatencyMs: isset($rd['min_latency_ms']) ? (float)$rd['min_latency_ms'] : null,
                    avgLatencyMs: isset($rd['avg_latency_ms']) ? (float)$rd['avg_latency_ms'] : null,
                    maxLatencyMs: isset($rd['max_latency_ms']) ? (float)$rd['max_latency_ms'] : null,
                    jitterMs: isset($rd['jitter_ms']) ? (float)$rd['jitter_ms'] : null,
                    latencies: $rd['latencies'] ?? [],
                    errorMessage: $exec->errorMessage,
                    probedAt: $now
                );
            }
        }

        return $this->evaluator->evaluate($results);
    }

    /**
     * Returns historical time series with sparklines for all uplink checks
     */
    public function getHistoryWithSparklines(int $samplesPerTarget = 30): array
    {
        $checks = $this->checkRepository->findByType('uplink', onlyEnabled: true);
        $historyByCheck = [];

        foreach ($checks as $check) {
            $executions = $this->executionRepository->getHistoryForCheck($check->id, $samplesPerTarget);
            $latencyValues = [];
            $formattedHistory = [];

            foreach ($executions as $exec) {
                $rd = $exec->resultData;
                $lat = isset($rd['avg_latency_ms']) ? (float)$rd['avg_latency_ms'] : null;
                if ($lat !== null) {
                    $latencyValues[] = $lat;
                }

                $formattedHistory[] = [
                    'execution_id' => $exec->id,
                    'check_id' => $exec->checkId,
                    'status' => $exec->status->value,
                    'avg_latency_ms' => $lat,
                    'packet_loss_percent' => $rd['packet_loss_percent'] ?? 0.0,
                    'jitter_ms' => $rd['jitter_ms'] ?? null,
                    'executed_at' => $exec->executedAt,
                ];
            }

            $sparkline = SparklineGenerator::generate($latencyValues, 20);
            $latest = !empty($executions) ? end($executions) : null;

            $historyByCheck[$check->id] = [
                'check' => $check->toArray(),
                'latest' => $latest?->toArray(),
                'sparkline' => $sparkline,
                'samples_count' => count($executions),
                'history' => $formattedHistory,
            ];
        }

        $aggregates = $this->executionRepository->getRollingAggregates(60);

        return [
            'aggregates_last_60m' => $aggregates,
            'targets' => $historyByCheck,
        ];
    }
}
