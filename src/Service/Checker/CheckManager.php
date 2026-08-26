<?php

declare(strict_types=1);

namespace App\Service\Checker;

use App\Entity\Check;
use App\Entity\CheckExecution;
use App\Enum\UplinkState;
use App\Repository\CheckExecutionRepository;
use App\Repository\CheckRepository;
use App\Service\Audit\AuditLogger;

class CheckManager
{
    /**
     * @var CheckerInterface[]
     */
    private array $checkers;

    public function __construct(
        private readonly CheckRepository $checkRepository,
        private readonly CheckExecutionRepository $executionRepository,
        private readonly AuditLogger $auditLogger,
        HttpChecker $httpChecker
    ) {
        $this->checkers = [$httpChecker];
    }

    public function registerChecker(CheckerInterface $checker): void
    {
        $this->checkers[] = $checker;
    }

    public function getCheckerForType(string $type): ?CheckerInterface
    {
        foreach ($this->checkers as $checker) {
            if ($checker->supports($type)) {
                return $checker;
            }
        }
        return null;
    }

    /**
     * Runs all active checks of a specific type (e.g. 'http')
     * @return CheckExecution[]
     */
    public function runChecksOfType(string $type): array
    {
        $checks = $this->checkRepository->findByType($type, onlyEnabled: true);
        if (empty($checks)) {
            return [];
        }

        $checker = $this->getCheckerForType($type);
        if (!$checker) {
            return [];
        }

        $executionsMap = $checker->runMulti($checks);
        $results = [];
        $now = gmdate('Y-m-d H:i:s');

        foreach ($checks as $check) {
            $execution = $executionsMap[$check->id] ?? null;
            if ($execution !== null) {
                $oldStatus = $check->status;

                // 1. Record execution
                $this->executionRepository->record($execution);

                // 2. Update check
                $metrics = [
                    'duration_ms' => $execution->durationMs,
                    'http_code' => $execution->resultData['http_code'] ?? null,
                    'ttfb_ms' => $execution->resultData['ttfb_ms'] ?? null,
                    'ssl_days_remaining' => $execution->resultData['ssl_days_remaining'] ?? null,
                    'ssl_valid' => $execution->resultData['ssl_valid'] ?? null,
                ];
                $this->checkRepository->updateExecutionResult($check->id, $execution->status, $metrics, $now);

                // 3. Audit log if state changed
                if ($oldStatus !== UplinkState::UNKNOWN && $oldStatus !== $execution->status) {
                    $this->auditLogger->log(
                        event: 'status_changed',
                        description: "Check '{$check->name}' status changed from {$oldStatus->value} to {$execution->status->value}",
                        subjectType: Check::class,
                        subjectId: $check->id,
                        properties: [
                            'old_status' => $oldStatus->value,
                            'new_status' => $execution->status->value,
                            'metrics' => $metrics,
                        ],
                        logName: 'checks'
                    );
                }

                $results[] = $execution;
            }
        }

        return $results;
    }
}
